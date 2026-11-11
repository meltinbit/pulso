<?php

namespace App\Services;

use App\Exceptions\GoogleAdsApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only Google Ads reports (accounts, campaigns, search terms) through
 * GAQL, already aggregated so MCP answers stay small. Results are cached for
 * an hour: Google Ads data is not real time and every call costs an
 * operation of the daily quota.
 */
class GoogleAdsReportService
{
    private const CACHE_MINUTES = 60;

    private const MAX_ROWS = 10000;

    public function __construct(private GoogleAdsClient $client) {}

    /**
     * Client accounts reachable from the manager account (or the configured
     * account itself when there is no manager).
     *
     * @return list<array{id: string, name: string, manager: bool, status: string, currency: ?string}>
     */
    public function accounts(): array
    {
        $root = $this->client->loginCustomerId() ?? $this->client->customerId();

        $rows = $this->search($root, 'SELECT customer_client.id, customer_client.descriptive_name, customer_client.manager, customer_client.status, customer_client.currency_code, customer_client.level FROM customer_client WHERE customer_client.level <= 1');

        return array_values(array_map(fn (array $row): array => [
            'id' => (string) ($row['customerClient']['id'] ?? ''),
            'name' => (string) ($row['customerClient']['descriptiveName'] ?? ''),
            'manager' => (bool) ($row['customerClient']['manager'] ?? false),
            'status' => (string) ($row['customerClient']['status'] ?? ''),
            'currency' => $row['customerClient']['currencyCode'] ?? null,
        ], $rows));
    }

    /**
     * Campaign totals over a date range, removed campaigns excluded.
     *
     * @return array{currency: ?string, campaigns: list<array{id: string, name: string, status: string, channel: string, cost: float, clicks: int, impressions: int, conversions: float, conversion_value: float}>}
     */
    public function campaigns(string $customerId, Carbon $from, Carbon $to): array
    {
        $rows = $this->search($customerId, sprintf(
            "SELECT customer.currency_code, campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, metrics.cost_micros, metrics.clicks, metrics.impressions, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date BETWEEN '%s' AND '%s' AND campaign.status != 'REMOVED'",
            $from->toDateString(),
            $to->toDateString(),
        ));

        return [
            'currency' => $rows[0]['customer']['currencyCode'] ?? null,
            'campaigns' => array_values(array_map(fn (array $row): array => [
                'id' => (string) ($row['campaign']['id'] ?? ''),
                'name' => (string) ($row['campaign']['name'] ?? ''),
                'status' => (string) ($row['campaign']['status'] ?? ''),
                'channel' => (string) ($row['campaign']['advertisingChannelType'] ?? ''),
            ] + $this->metrics($row['metrics'] ?? []), $rows)),
        ];
    }

    /**
     * Search terms over a date range, summed across ad groups. Performance
     * Max terms are not included (Google keeps them in a separate view).
     *
     * @return list<array{term: string, status: string, cost: float, clicks: int, impressions: int, conversions: float, conversion_value: float}>
     */
    public function searchTerms(string $customerId, Carbon $from, Carbon $to, ?string $campaignId = null): array
    {
        $rows = $this->search($customerId, sprintf(
            "SELECT search_term_view.search_term, search_term_view.status, metrics.cost_micros, metrics.clicks, metrics.impressions, metrics.conversions, metrics.conversions_value FROM search_term_view WHERE segments.date BETWEEN '%s' AND '%s' AND metrics.impressions > 0%s ORDER BY metrics.impressions DESC LIMIT %d",
            $from->toDateString(),
            $to->toDateString(),
            $campaignId ? ' AND campaign.id = '.preg_replace('/\D/', '', $campaignId) : '',
            self::MAX_ROWS,
        ));

        $terms = [];

        foreach ($rows as $row) {
            $term = (string) ($row['searchTermView']['searchTerm'] ?? '');
            $metrics = $this->metrics($row['metrics'] ?? []);
            $status = (string) ($row['searchTermView']['status'] ?? 'NONE');

            if (! isset($terms[$term])) {
                $terms[$term] = ['term' => $term, 'status' => $status] + $metrics;

                continue;
            }

            foreach ($metrics as $key => $value) {
                $terms[$term][$key] += $value;
            }

            if ($status === 'ADDED' || $status === 'EXCLUDED') {
                $terms[$term]['status'] = $status;
            }
        }

        return array_values($terms);
    }

    /**
     * The account requests go to: the given one, or GOOGLE_ADS_CUSTOMER_ID.
     *
     * @throws GoogleAdsApiException
     */
    public function resolveCustomerId(?string $customerId): string
    {
        $resolved = $this->client->normalizeCustomerId($customerId) ?? $this->client->normalizeCustomerId($this->client->customerId());

        if ($resolved === null) {
            throw new GoogleAdsApiException('Nessun account Google Ads indicato: passa customer_id (vedi list-ads-accounts) o imposta GOOGLE_ADS_CUSTOMER_ID.', 'NO_CUSTOMER');
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array{cost: float, clicks: int, impressions: int, conversions: float, conversion_value: float}
     */
    private function metrics(array $metrics): array
    {
        return [
            'cost' => round(((int) ($metrics['costMicros'] ?? 0)) / 1_000_000, 2),
            'clicks' => (int) ($metrics['clicks'] ?? 0),
            'impressions' => (int) ($metrics['impressions'] ?? 0),
            'conversions' => round((float) ($metrics['conversions'] ?? 0), 2),
            'conversion_value' => round((float) ($metrics['conversionsValue'] ?? 0), 2),
        ];
    }

    /**
     * Run a GAQL query, following pages, cached for an hour.
     *
     * @return list<array<string, mixed>>
     *
     * @throws GoogleAdsApiException
     */
    private function search(string $customerId, string $query): array
    {
        return Cache::remember(
            'google_ads.search.'.hash('sha256', $customerId.$query),
            now()->addMinutes(self::CACHE_MINUTES),
            function () use ($customerId, $query): array {
                $rows = [];
                $pageToken = null;

                do {
                    $body = ['query' => $query] + ($pageToken ? ['pageToken' => $pageToken] : []);
                    $response = $this->client->post("customers/{$customerId}/googleAds:search", $body);
                    $rows = array_merge($rows, $response['results'] ?? []);
                    $pageToken = $response['nextPageToken'] ?? null;
                } while ($pageToken && count($rows) < self::MAX_ROWS);

                return $rows;
            },
        );
    }
}
