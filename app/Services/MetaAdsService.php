<?php

namespace App\Services;

use App\Exceptions\MetaAdsApiException;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only client for the Meta Marketing API (ad accounts and Insights),
 * authenticated with a system user token with ads_read saved encrypted in
 * the user's settings. Answers are cached for an hour: Insights are not
 * real time and Meta rate limits every ad account.
 */
class MetaAdsService
{
    public const SETTING_KEY = 'meta_access_token';

    public const DEFAULT_ACCOUNT_SETTING_KEY = 'meta_default_ad_account';

    public const INVALID_SETTING_KEY = 'meta_token_invalid';

    private const BASE_URL = 'https://graph.facebook.com/';

    private const CACHE_MINUTES = 60;

    private const MAX_ROWS = 5000;

    /** Insights fields read for every level. */
    private const METRIC_FIELDS = ['spend', 'impressions', 'reach', 'frequency', 'inline_link_clicks', 'actions', 'action_values'];

    /** Account status codes of the Marketing API. */
    private const ACCOUNT_STATUSES = [
        1 => 'ACTIVE', 2 => 'DISABLED', 3 => 'UNSETTLED', 7 => 'PENDING_RISK_REVIEW', 8 => 'PENDING_SETTLEMENT',
        9 => 'IN_GRACE_PERIOD', 100 => 'PENDING_CLOSURE', 101 => 'CLOSED', 201 => 'ANY_ACTIVE', 202 => 'ANY_CLOSED',
    ];

    public function __construct(private SettingService $settings) {}

    public function token(User $user): ?string
    {
        return $this->settings->get($user->id, self::SETTING_KEY) ?: null;
    }

    /**
     * Ad accounts the token can read.
     *
     * @return list<array{id: string, name: string, status: string, currency: ?string, timezone: ?string}>
     *
     * @throws MetaAdsApiException
     */
    public function accountsForToken(string $token): array
    {
        return array_map(fn (array $account): array => [
            'id' => (string) ($account['account_id'] ?? ''),
            'name' => (string) ($account['name'] ?? ''),
            'status' => self::ACCOUNT_STATUSES[(int) ($account['account_status'] ?? 0)] ?? (string) ($account['account_status'] ?? ''),
            'currency' => $account['currency'] ?? null,
            'timezone' => $account['timezone_name'] ?? null,
        ], $this->get($token, 'me/adaccounts', ['fields' => 'account_id,name,account_status,currency,timezone_name']));
    }

    /**
     * @return list<array{id: string, name: string, status: string, currency: ?string, timezone: ?string}>
     *
     * @throws MetaAdsApiException
     */
    public function accounts(User $user): array
    {
        return $this->asUser($user, fn (string $token): array => $this->accountsForToken($token));
    }

    /**
     * Insights rows of an ad account over a date range (both days included),
     * at account, campaign, adset or ad level, optionally split by
     * breakdowns and limited to one campaign.
     *
     * @param  list<string>  $breakdowns
     * @return list<array{cost: float, impressions: int, reach: int, frequency: ?float, clicks: int, actions: array<string, float>, action_values: array<string, float>, currency: ?string, attributes: array<string, string>}>
     *
     * @throws MetaAdsApiException
     */
    public function insights(User $user, string $accountId, string $level, Carbon $from, Carbon $to, array $breakdowns = [], ?string $campaignId = null): array
    {
        $attributes = match ($level) {
            'campaign' => ['campaign_id', 'campaign_name', 'objective'],
            'adset' => ['campaign_name', 'adset_id', 'adset_name'],
            'ad' => ['campaign_name', 'adset_name', 'ad_id', 'ad_name'],
            default => [],
        };

        $query = [
            'level' => $level,
            'time_range' => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
            'fields' => implode(',', array_merge(['account_currency'], $attributes, self::METRIC_FIELDS)),
            'use_unified_attribution_setting' => 'true',
            'limit' => 500,
        ];

        if ($breakdowns !== []) {
            $query['breakdowns'] = implode(',', $breakdowns);
        }

        if ($campaignId !== null) {
            $query['filtering'] = json_encode([['field' => 'campaign.id', 'operator' => 'EQUAL', 'value' => preg_replace('/\D/', '', $campaignId)]]);
        }

        $rows = $this->asUser($user, fn (string $token): array => $this->get($token, 'act_'.self::normalizeAccountId($accountId).'/insights', $query));

        return array_map(fn (array $row): array => [
            'cost' => round((float) ($row['spend'] ?? 0), 2),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'reach' => (int) ($row['reach'] ?? 0),
            'frequency' => isset($row['frequency']) ? round((float) $row['frequency'], 2) : null,
            'clicks' => (int) ($row['inline_link_clicks'] ?? 0),
            'actions' => $this->actionMap($row['actions'] ?? []),
            'action_values' => $this->actionMap($row['action_values'] ?? []),
            'currency' => $row['account_currency'] ?? null,
            'attributes' => array_map('strval', array_intersect_key($row, array_flip(array_merge($attributes, $breakdowns)))),
        ], $rows);
    }

    /**
     * The account a tool uses when none is given: the one chosen in the
     * settings, or the only account the token reads.
     *
     * @throws MetaAdsApiException
     */
    public function resolveAccountId(User $user, ?string $accountId): string
    {
        $resolved = self::normalizeAccountId($accountId) ?? self::normalizeAccountId($this->settings->get($user->id, self::DEFAULT_ACCOUNT_SETTING_KEY));

        if ($resolved !== null) {
            return $resolved;
        }

        $accounts = $this->accounts($user);

        if (count($accounts) === 1) {
            return $accounts[0]['id'];
        }

        throw new MetaAdsApiException(count($accounts) === 0
            ? 'Il token Meta non vede nessun account pubblicitario: assegnalo all\'utente di sistema in Business Manager.'
            : 'Più account pubblicitari Meta: passa account_id (vedi list-meta-ad-accounts) o scegli quello di default in Impostazioni → Meta.');
    }

    public static function normalizeAccountId(?string $accountId): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $accountId);

        return $digits === '' ? null : $digits;
    }

    /**
     * Run a call with the user's token and flag the token as invalid when
     * Meta rejects it, so the Health page can say so.
     *
     * @template T
     *
     * @param  callable(string): T  $call
     * @return T
     *
     * @throws MetaAdsApiException
     */
    private function asUser(User $user, callable $call): mixed
    {
        $token = $this->token($user);

        if ($token === null) {
            throw new MetaAdsApiException('Meta Ads non è configurato: salva il token dell\'utente di sistema in Impostazioni → Meta.');
        }

        try {
            $result = $call($token);
        } catch (MetaAdsApiException $e) {
            if ($e->isAuthError) {
                $this->settings->set($user->id, self::INVALID_SETTING_KEY, '1', 'meta');
            }

            throw $e;
        }

        if ($this->settings->get($user->id, self::INVALID_SETTING_KEY) === '1') {
            $this->settings->set($user->id, self::INVALID_SETTING_KEY, null, 'meta');
        }

        return $result;
    }

    /**
     * GET an edge, following the paging cursors, cached for an hour.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     *
     * @throws MetaAdsApiException
     */
    private function get(string $token, string $path, array $query): array
    {
        $version = config('services.meta_ads.api_version', 'v27.0');

        return Cache::remember(
            'meta_ads.'.hash('sha256', $token.$version.$path.json_encode($query)),
            now()->addMinutes(self::CACHE_MINUTES),
            function () use ($token, $version, $path, $query): array {
                $rows = [];
                $url = self::BASE_URL.$version.'/'.$path;
                $params = $query;

                do {
                    $response = $this->send($token, $url, $params);
                    $rows = array_merge($rows, $response['data'] ?? []);
                    $url = $response['paging']['next'] ?? null;
                    $params = [];
                } while ($url !== null && count($rows) < self::MAX_ROWS);

                return $rows;
            },
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws MetaAdsApiException
     */
    private function send(string $token, string $url, array $query): array
    {
        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->timeout(60)
                ->connectTimeout(5)
                ->retry(2, 1000, fn (Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()), throw: false)
                ->get($url, $query);
        } catch (ConnectionException) {
            throw new MetaAdsApiException('La Marketing API di Meta non risponde: riprova più tardi.');
        }

        if ($response->failed()) {
            throw $this->translateError($response);
        }

        return $response->json() ?? [];
    }

    private function translateError(Response $response): MetaAdsApiException
    {
        $code = (int) $response->json('error.code');
        $message = (string) $response->json('error.message', '');

        return match (true) {
            $code === 190 => new MetaAdsApiException('Il token Meta non è più valido: generane uno nuovo per l\'utente di sistema e salvalo in Impostazioni → Meta.', isAuthError: true),
            in_array($code, [10, 200, 294], true) || ($code >= 200 && $code <= 299) => new MetaAdsApiException('Il token Meta non ha accesso a questo account: serve il permesso ads_read e l\'account assegnato all\'utente di sistema. Dettaglio: '.$message),
            in_array($code, [4, 17, 32, 613, 80000, 80004], true) => new MetaAdsApiException('Limite di chiamate della Marketing API raggiunto per questo account: riprova tra qualche minuto.'),
            $code === 2635 => new MetaAdsApiException('Versione della Marketing API non più supportata: aggiorna META_ADS_API_VERSION.'),
            default => new MetaAdsApiException('Errore della Marketing API di Meta'.($message !== '' ? ': '.$message : '.')),
        };
    }

    /**
     * @param  list<array{action_type?: string, value?: string}>  $actions
     * @return array<string, float>
     */
    private function actionMap(array $actions): array
    {
        $map = [];

        foreach ($actions as $action) {
            if (isset($action['action_type'])) {
                $map[$action['action_type']] = (float) ($action['value'] ?? 0);
            }
        }

        return $map;
    }
}
