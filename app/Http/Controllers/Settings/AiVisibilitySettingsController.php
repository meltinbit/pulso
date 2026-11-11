<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\OpenAiApiException;
use App\Http\Controllers\Controller;
use App\Jobs\RunAiVisibilityChecks;
use App\Models\GaProperty;
use App\Services\AiVisibilityService;
use App\Services\OpenAiResponsesClient;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AiVisibilitySettingsController extends Controller
{
    public function __construct(
        private SettingService $settings,
        private AiVisibilityService $aiVisibility,
        private OpenAiResponsesClient $openAi,
    ) {}

    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/ai-visibility', [
            'hasKey' => $this->aiVisibility->apiKey($user) !== null,
            'keyInvalid' => $this->settings->get($user->id, AiVisibilityService::KEY_INVALID_SETTING) === '1',
            'model' => $this->aiVisibility->model($user),
            'capEur' => $this->aiVisibility->monthlyCapEur($user),
            'frequency' => $this->aiVisibility->frequency($user),
            'frequencies' => AiVisibilityService::FREQUENCIES,
            'spentThisMonthUsd' => $this->aiVisibility->spentThisMonthUsd($user),
            'expectedMonthUsd' => $this->aiVisibility->estimatedMonthlyCostUsd($user),
            'properties' => $user->gaProperties()
                ->where('is_active', true)
                ->withCount(['aiVisibilityPrompts as active_prompts' => fn ($query) => $query->where('is_active', true)])
                ->withMax('aiVisibilityChecks', 'run_date')
                ->orderBy('display_name')
                ->get()
                ->map(fn (GaProperty $property): array => [
                    'id' => $property->id,
                    'display_name' => $property->display_name,
                    'website_url' => $property->website_url,
                    'active_prompts' => $property->active_prompts,
                    'brand_terms' => $property->ai_brand_terms ?? [],
                    'last_run' => $property->ai_visibility_checks_max_run_date ? substr((string) $property->ai_visibility_checks_max_run_date, 0, 10) : null,
                ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'openai_api_key' => ['nullable', 'string', 'max:300'],
            'model' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9.\-]+$/'],
            'monthly_cap_eur' => ['required', 'numeric', 'min:0', 'max:1000'],
            'frequency' => ['required', Rule::in(AiVisibilityService::FREQUENCIES)],
        ]);

        $userId = $request->user()->id;

        if (filled($validated['openai_api_key'] ?? null)) {
            try {
                $this->openAi->verifyKey($validated['openai_api_key']);
            } catch (OpenAiApiException $e) {
                throw ValidationException::withMessages(['openai_api_key' => $e->getMessage()]);
            }

            $this->settings->set($userId, AiVisibilityService::API_KEY_SETTING, $validated['openai_api_key'], 'ai_visibility', encrypted: true);
            $this->settings->set($userId, AiVisibilityService::KEY_INVALID_SETTING, null, 'ai_visibility');
        }

        $this->settings->set($userId, AiVisibilityService::MODEL_SETTING, $validated['model'], 'ai_visibility');
        $this->settings->set($userId, AiVisibilityService::CAP_SETTING, (string) round((float) $validated['monthly_cap_eur'], 2), 'ai_visibility');
        $this->settings->set($userId, AiVisibilityService::FREQUENCY_SETTING, $validated['frequency'], 'ai_visibility');

        return back()->with('success', 'Impostazioni AI visibility salvate.');
    }

    public function run(Request $request): RedirectResponse
    {
        abort_if($this->aiVisibility->apiKey($request->user()) === null, 422, 'Salva prima la API key OpenAI.');

        RunAiVisibilityChecks::dispatch(force: true);

        return back()->with('success', 'Controllo avviato: i risultati arrivano in qualche minuto, entro il tetto mensile.');
    }
}
