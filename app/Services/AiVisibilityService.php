<?php

namespace App\Services;

use App\Exceptions\OpenAiApiException;
use App\Models\AiVisibilityCheck;
use App\Models\AiVisibilityPrompt;
use App\Models\GaProperty;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Prompt tracking for GEO: asks every active prompt to ChatGPT (OpenAI API
 * with web search), records whether the answer cites the property, and
 * never spends more than the monthly cap set in the settings.
 */
class AiVisibilityService
{
    public const API_KEY_SETTING = 'openai_api_key';

    public const MODEL_SETTING = 'ai_visibility_model';

    public const CAP_SETTING = 'ai_visibility_monthly_cap_eur';

    public const FREQUENCY_SETTING = 'ai_visibility_frequency';

    public const BUDGET_ALERT_SETTING = 'ai_visibility_budget_alerted_month';

    public const KEY_INVALID_SETTING = 'ai_visibility_key_invalid';

    public const FREQUENCIES = ['weekly', 'biweekly', 'off'];

    public const DEFAULT_CAP_EUR = 3.0;

    public const MAX_ACTIVE_PROMPTS_PER_PROPERTY = 30;

    private const EXCERPT_LENGTH = 1500;

    public function __construct(
        private SettingService $settings,
        private OpenAiResponsesClient $openAi,
        private TelegramNotificationService $telegram,
    ) {}

    public function apiKey(User $user): ?string
    {
        return $this->settings->get($user->id, self::API_KEY_SETTING) ?: null;
    }

    public function model(User $user): string
    {
        return $this->settings->get($user->id, self::MODEL_SETTING) ?: config('services.openai.default_model');
    }

    public function monthlyCapEur(User $user): float
    {
        $cap = $this->settings->get($user->id, self::CAP_SETTING);

        return $cap === null || $cap === '' ? self::DEFAULT_CAP_EUR : (float) $cap;
    }

    public function frequency(User $user): string
    {
        $frequency = $this->settings->get($user->id, self::FREQUENCY_SETTING);

        return in_array($frequency, self::FREQUENCIES, true) ? $frequency : 'weekly';
    }

    /**
     * What the checks of the current month cost, in USD. The cap is in
     * euro and the comparison counts one dollar as one euro: OpenAI bills
     * in dollars and the dollar is worth less, so the cap is never crossed.
     */
    public function spentThisMonthUsd(User $user): float
    {
        return round((float) AiVisibilityCheck::query()
            ->whereIn('ga_property_id', $user->gaProperties()->select('id'))
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_usd'), 4);
    }

    /**
     * Expected monthly cost of the active prompts at the chosen frequency,
     * with the usual cost of one question (about 1.2 cents on the default model).
     */
    public function estimatedMonthlyCostUsd(User $user): float
    {
        $prompts = AiVisibilityPrompt::query()
            ->where('is_active', true)
            ->whereIn('ga_property_id', $user->gaProperties()->where('is_active', true)->select('id'))
            ->count();

        $runsPerMonth = match ($this->frequency($user)) {
            'weekly' => 4.33,
            'biweekly' => 2.17,
            default => 0,
        };

        return round($prompts * $runsPerMonth * $this->openAi->cost($this->model($user), 8_000, 800, 1), 2);
    }

    /**
     * Ask every active prompt once (prompts already checked today are
     * skipped), stopping before the monthly cap.
     *
     * @return array{status: string, checked: int, skipped_for_budget: int, errors: int, spent_month_usd: float}
     */
    public function run(User $user, ?int $propertyId = null, bool $force = false): array
    {
        $result = ['status' => 'ok', 'checked' => 0, 'skipped_for_budget' => 0, 'errors' => 0, 'spent_month_usd' => 0.0];
        $apiKey = $this->apiKey($user);

        if ($apiKey === null) {
            return ['status' => 'no_api_key'] + $result;
        }

        if (! $force && ! $this->isDue($user)) {
            return ['status' => 'not_due'] + $result;
        }

        $model = $this->model($user);
        $capEur = $this->monthlyCapEur($user);
        $spent = $this->spentThisMonthUsd($user);
        $today = now()->toDateString();

        $prompts = AiVisibilityPrompt::query()
            ->where('is_active', true)
            ->whereHas('gaProperty', fn ($query) => $query->where('user_id', $user->id)->where('is_active', true)->whereNotNull('website_url'))
            ->when($propertyId, fn ($query, int $id) => $query->where('ga_property_id', $id))
            ->whereDoesntHave('checks', fn ($query) => $query->whereDate('run_date', $today))
            ->with('gaProperty')
            ->orderBy('ga_property_id')
            ->orderBy('id')
            ->get();

        foreach ($prompts as $index => $prompt) {
            if ($spent + $this->openAi->estimatedCost($model) > $capEur) {
                $result['status'] = 'budget_reached';
                $result['skipped_for_budget'] = $prompts->count() - $index;
                $this->alertBudget($user, $spent, $capEur);

                break;
            }

            try {
                $answer = $this->openAi->ask($apiKey, $model, $prompt->prompt);
            } catch (OpenAiApiException $e) {
                if ($e->isAuthError) {
                    $this->settings->set($user->id, self::KEY_INVALID_SETTING, '1', 'ai_visibility');
                    $result['status'] = 'api_key_error';
                    Log::warning("AI visibility stopped for user {$user->id}: {$e->getMessage()}");

                    break;
                }

                $result['errors']++;
                Log::warning("AI visibility check failed for prompt {$prompt->id}: {$e->getMessage()}");

                continue;
            }

            $this->store($prompt, $model, $answer, $today);
            $spent += $answer['cost_usd'];
            $result['checked']++;
        }

        if ($result['status'] !== 'api_key_error') {
            $this->settings->set($user->id, self::KEY_INVALID_SETTING, null, 'ai_visibility');
        }

        $result['spent_month_usd'] = round($spent, 4);

        return $result;
    }

    /**
     * Whether the property shows up in an answer: cited among the sources
     * (position = rank of its domain among the cited domains) or named in
     * the text through its domain or brand terms.
     *
     * @param  list<array{url: string, title: string}>  $citations
     * @return array{mentioned: bool, position: ?int, cited_url: ?string, cited_domains: list<string>}
     */
    public function analyze(GaProperty $property, string $text, array $citations): array
    {
        $host = GoogleAdsReportService::host($property->website_url);
        $domains = [];
        $citedUrl = null;

        foreach ($citations as $citation) {
            $domain = GoogleAdsReportService::host($citation['url']);

            if ($domain === null) {
                continue;
            }

            if (! in_array($domain, $domains, true)) {
                $domains[] = $domain;
            }

            if ($citedUrl === null && $host !== null && $this->sameSite($domain, $host)) {
                $citedUrl = $this->withoutTracking($citation['url']);
            }
        }

        $position = null;

        foreach ($domains as $index => $domain) {
            if ($host !== null && $this->sameSite($domain, $host)) {
                $position = $index + 1;

                break;
            }
        }

        $terms = array_filter(array_merge([$host], $property->ai_brand_terms ?? []));
        $named = collect($terms)->contains(fn (string $term): bool => Str::contains($text, $term, ignoreCase: true));

        return [
            'mentioned' => $position !== null || $named,
            'position' => $position,
            'cited_url' => $citedUrl,
            'cited_domains' => $domains,
        ];
    }

    private function isDue(User $user): bool
    {
        $frequency = $this->frequency($user);

        if ($frequency === 'off') {
            return false;
        }

        if ($frequency === 'weekly') {
            return true;
        }

        $lastRun = AiVisibilityCheck::query()
            ->whereIn('ga_property_id', $user->gaProperties()->select('id'))
            ->max('run_date');

        return $lastRun === null || Carbon::parse($lastRun)->lte(now()->subDays(13));
    }

    /**
     * @param  array{text: string, citations: list<array{url: string, title: string}>, input_tokens: int, output_tokens: int, searches: int, cost_usd: float}  $answer
     */
    private function store(AiVisibilityPrompt $prompt, string $model, array $answer, string $date): void
    {
        $analysis = $this->analyze($prompt->gaProperty, $answer['text'], $answer['citations']);

        $prompt->checks()->create([
            'ga_property_id' => $prompt->ga_property_id,
            'run_date' => $date,
            'engine' => 'openai',
            'model' => $model,
            'mentioned' => $analysis['mentioned'],
            'position' => $analysis['position'],
            'cited_url' => $analysis['cited_url'],
            'cited_domains' => $analysis['cited_domains'],
            'answer_excerpt' => Str::limit($answer['text'], self::EXCERPT_LENGTH),
            'input_tokens' => $answer['input_tokens'],
            'output_tokens' => $answer['output_tokens'],
            'searches' => $answer['searches'],
            'cost_usd' => $answer['cost_usd'],
        ]);
    }

    private function alertBudget(User $user, float $spent, float $capEur): void
    {
        $month = now()->format('Y-m');

        if ($this->settings->get($user->id, self::BUDGET_ALERT_SETTING) === $month) {
            return;
        }

        if ($this->telegram->sendAiVisibilityBudgetAlert($user->id, $spent, $capEur)) {
            $this->settings->set($user->id, self::BUDGET_ALERT_SETTING, $month, 'ai_visibility');
        }
    }

    private function sameSite(string $domain, string $host): bool
    {
        return $domain === $host || str_ends_with($domain, '.'.$host);
    }

    /**
     * OpenAI appends utm_source=openai to cited links: drop tracking
     * parameters so the same page always has the same URL.
     */
    private function withoutTracking(string $url): string
    {
        $parts = parse_url($url);

        if (! isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);
        $query = array_filter($query, fn (string $key): bool => ! str_starts_with($key, 'utm_'), ARRAY_FILTER_USE_KEY);

        return strtok($url, '?').($query === [] ? '' : '?'.http_build_query($query)).(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
