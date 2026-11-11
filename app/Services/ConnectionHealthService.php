<?php

namespace App\Services;

use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\McpCallLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the admin health report from stored data only (no live API calls):
 * Google connections, freshness of each property's data, Google Ads quota,
 * MCP usage and failed jobs. Every problem carries the fix to apply.
 */
class ConnectionHealthService
{
    /** Snapshots are for yesterday, so two days without one means a gap. */
    private const SNAPSHOT_STALE_DAYS = 2;

    /** Search Console lags 2-3 days behind. */
    private const SEARCH_CONSOLE_STALE_DAYS = 5;

    private const ADSENSE_STALE_DAYS = 2;

    /** Bing daily data lags about 3 days. */
    private const BING_STALE_DAYS = 6;

    public function __construct(
        private SettingService $settings,
        private GoogleAdsClient $googleAds,
    ) {}

    /**
     * @return array{
     *     connections: list<array{id: int, email: string, status: string, authorized_at: ?string, issue: ?string}>,
     *     properties: list<array{id: int, name: string, website_url: ?string, last_snapshot: ?string, last_search_console: ?string, last_adsense: ?string, last_bing: ?string, issues: list<string>}>,
     *     bing: array{has_key: bool, key_invalid: bool, issue: ?string},
     *     google_ads: array{configured: bool, missing: list<string>, operations_today: int, daily_limit: int},
     *     mcp: array{has_token: bool, last_used_at: ?string, calls_24h: int, errors_24h: int},
     *     failed_jobs: array{count_7d: int, latest: ?array{job: string, failed_at: string, error: string}},
     *     issues_count: int
     * }
     */
    public function report(User $user): array
    {
        $bing = $this->bing($user);

        $connections = $user->gaConnections()->orderBy('google_email')->get()
            ->map(fn (GaConnection $connection): array => $this->connection($connection))
            ->values()
            ->all();

        $properties = $user->gaProperties()
            ->where('is_active', true)
            ->withMax('snapshots', 'snapshot_date')
            ->withMax('searchQueries', 'date')
            ->withMax('adsenseMetrics', 'date')
            ->withMax('bingTraffic', 'date')
            ->orderBy('display_name')
            ->get()
            ->map(fn (GaProperty $property): array => $this->property($property, $bing['has_key']))
            ->values()
            ->all();

        $googleAds = [
            'configured' => $this->googleAds->isConfigured(),
            'missing' => $this->googleAds->missingSettings(),
            'operations_today' => $this->googleAds->operationsToday(),
            'daily_limit' => $this->googleAds->dailyLimit(),
        ];

        $failedJobs = $this->failedJobs();

        $issuesCount = collect($connections)->whereNotNull('issue')->count()
            + ($bing['issue'] === null ? 0 : 1)
            + collect($properties)->sum(fn (array $property): int => count($property['issues']))
            + ($googleAds['configured'] ? 0 : 1)
            + ($googleAds['operations_today'] >= $googleAds['daily_limit'] ? 1 : 0)
            + ($failedJobs['count_7d'] > 0 ? 1 : 0);

        return [
            'connections' => $connections,
            'properties' => $properties,
            'bing' => $bing,
            'google_ads' => $googleAds,
            'mcp' => $this->mcp($user),
            'failed_jobs' => $failedJobs,
            'issues_count' => $issuesCount,
        ];
    }

    /**
     * @return array{id: int, email: string, status: string, authorized_at: ?string, issue: ?string}
     */
    private function connection(GaConnection $connection): array
    {
        $alerted = $this->settings->get($connection->user_id, "google_connection_alerted_{$connection->id}") === '1';

        [$status, $issue] = match (true) {
            ! $connection->is_active => ['inactive', 'Connessione disattivata: ricollega l\'account in Impostazioni → Google.'],
            $alerted => ['expired', 'Token Google scaduto o revocato: ricollega l\'account in Impostazioni → Google.'],
            default => ['ok', null],
        };

        return [
            'id' => $connection->id,
            'email' => $connection->google_email,
            'status' => $status,
            'authorized_at' => $connection->authorized_at?->toDateString(),
            'issue' => $issue,
        ];
    }

    /**
     * @return array{id: int, name: string, website_url: ?string, last_snapshot: ?string, last_search_console: ?string, last_adsense: ?string, issues: list<string>}
     */
    private function property(GaProperty $property, bool $hasBingKey): array
    {
        $lastSnapshot = $this->date($property->snapshots_max_snapshot_date);
        $lastSearchConsole = $this->date($property->search_queries_max_date);
        $lastAdsense = $this->date($property->adsense_metrics_max_date);
        $lastBing = $this->date($property->bing_traffic_max_date);

        $issues = [];

        if (! $property->website_url) {
            $issues[] = 'Manca l\'URL del sito: senza, Search Console e AdSense non si abbinano (Proprietà).';
        }

        if ($lastSnapshot === null) {
            $issues[] = 'Nessuno snapshot GA4: generane uno da Snapshots o controlla la connessione Google.';
        } elseif ($this->olderThan($lastSnapshot, self::SNAPSHOT_STALE_DAYS)) {
            $issues[] = "Ultimo snapshot GA4 del {$lastSnapshot}: lo scheduler o la connessione Google non funzionano.";
        }

        if ($property->website_url && $lastSearchConsole === null) {
            $issues[] = 'Nessun dato Search Console: il sito non è verificato in Search Console con l\'account collegato, o l\'URL non corrisponde.';
        } elseif ($lastSearchConsole !== null && $this->olderThan($lastSearchConsole, self::SEARCH_CONSOLE_STALE_DAYS)) {
            $issues[] = "Ultimi dati Search Console del {$lastSearchConsole}: la sincronizzazione delle 05:00 non va a buon fine.";
        }

        if ($lastAdsense !== null && $this->olderThan($lastAdsense, self::ADSENSE_STALE_DAYS)) {
            $issues[] = "Ultimi dati AdSense del {$lastAdsense}: la sincronizzazione AdSense non va a buon fine.";
        }

        if ($hasBingKey && $property->website_url && $property->bing_site_url === null) {
            $issues[] = 'Non collegata a Bing: verifica il sito in Bing Webmaster Tools o collegalo a mano in Impostazioni → Bing.';
        } elseif ($lastBing !== null && $this->olderThan($lastBing, self::BING_STALE_DAYS)) {
            $issues[] = "Ultimi dati Bing del {$lastBing}: la sincronizzazione Bing delle 06:00 non va a buon fine.";
        }

        return [
            'id' => $property->id,
            'name' => $property->display_name,
            'website_url' => $property->website_url,
            'last_snapshot' => $lastSnapshot,
            'last_search_console' => $lastSearchConsole,
            'last_adsense' => $lastAdsense,
            'last_bing' => $lastBing,
            'issues' => $issues,
        ];
    }

    /**
     * @return array{has_key: bool, key_invalid: bool, issue: ?string}
     */
    private function bing(User $user): array
    {
        $hasKey = filled($this->settings->get($user->id, BingSyncService::SETTING_KEY));
        $invalid = $hasKey && $this->settings->get($user->id, BingSyncService::ALERTED_SETTING_KEY) === '1';

        return [
            'has_key' => $hasKey,
            'key_invalid' => $invalid,
            'issue' => match (true) {
                ! $hasKey => 'API key Bing non impostata: salvala in Impostazioni → Bing.',
                $invalid => 'La API key Bing non è più valida: generane una nuova e salvala in Impostazioni → Bing.',
                default => null,
            },
        ];
    }

    /**
     * @return array{has_token: bool, last_used_at: ?string, calls_24h: int, errors_24h: int}
     */
    private function mcp(User $user): array
    {
        $since = now()->subDay();

        return [
            'has_token' => $user->mcpToken()->exists(),
            'last_used_at' => $user->mcpToken?->last_used_at?->toDateTimeString(),
            'calls_24h' => McpCallLog::where('created_at', '>=', $since)->count(),
            'errors_24h' => McpCallLog::where('created_at', '>=', $since)->where('is_error', true)->count(),
        ];
    }

    /**
     * @return array{count_7d: int, latest: ?array{job: string, failed_at: string, error: string}}
     */
    private function failedJobs(): array
    {
        $query = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7));
        $latest = (clone $query)->orderByDesc('failed_at')->first();

        return [
            'count_7d' => $query->count(),
            'latest' => $latest ? [
                'job' => (string) (json_decode($latest->payload, true)['displayName'] ?? 'job'),
                'failed_at' => (string) $latest->failed_at,
                'error' => Str::limit(Str::before((string) $latest->exception, "\n"), 300),
            ] : null,
        ];
    }

    private function date(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toDateString() : null;
    }

    private function olderThan(string $date, int $days): bool
    {
        return Carbon::parse($date)->lt(now()->startOfDay()->subDays($days));
    }
}
