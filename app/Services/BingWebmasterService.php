<?php

namespace App\Services;

use App\Exceptions\BingApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Thin client for the Bing Webmaster Tools JSON API, authenticated with an
 * API key. Every stats method returns the whole history Bing keeps (about
 * 16 months, 6 for crawl stats): the API takes no date range.
 */
class BingWebmasterService
{
    private const BASE_URL = 'https://ssl.bing.com/webmaster/api.svc/json/';

    /** Bing's own ceiling for one SubmitUrlBatch call. */
    public const MAX_SUBMIT_BATCH = 500;

    /**
     * @return list<array{url: string, is_verified: bool}>
     */
    public function userSites(string $apiKey): array
    {
        return array_values(array_map(fn (array $site): array => [
            'url' => (string) ($site['Url'] ?? ''),
            'is_verified' => (bool) ($site['IsVerified'] ?? false),
        ], $this->get($apiKey, 'GetUserSites')));
    }

    /**
     * @return list<array{date: string, clicks: int, impressions: int}>
     */
    public function trafficStats(string $apiKey, string $siteUrl): array
    {
        return array_values(array_map(fn (array $row): array => [
            'date' => $this->parseDate($row['Date'] ?? null),
            'clicks' => (int) ($row['Clicks'] ?? 0),
            'impressions' => (int) ($row['Impressions'] ?? 0),
        ], $this->get($apiKey, 'GetRankAndTrafficStats', ['siteUrl' => $siteUrl])));
    }

    /**
     * Weekly query stats. Each row is dated on a Friday and covers the seven
     * days before it, so week_start is that date minus 7 days.
     *
     * @return list<array{week_start: string, value: string, clicks: int, impressions: int, avg_impression_position: ?float, avg_click_position: ?float}>
     */
    public function queryStats(string $apiKey, string $siteUrl): array
    {
        return $this->weeklyStats($this->get($apiKey, 'GetQueryStats', ['siteUrl' => $siteUrl]));
    }

    /**
     * Weekly page stats, in the same shape as queryStats (value is the URL).
     *
     * @return list<array{week_start: string, value: string, clicks: int, impressions: int, avg_impression_position: ?float, avg_click_position: ?float}>
     */
    public function pageStats(string $apiKey, string $siteUrl): array
    {
        return $this->weeklyStats($this->get($apiKey, 'GetPageStats', ['siteUrl' => $siteUrl]));
    }

    /**
     * @return list<array<string, int|string>>
     */
    public function crawlStats(string $apiKey, string $siteUrl, array $counters): array
    {
        return array_values(array_map(function (array $row) use ($counters): array {
            $stat = ['date' => $this->parseDate($row['Date'] ?? null)];

            foreach ($counters as $column => $field) {
                $stat[$column] = (int) ($row[$field] ?? 0);
            }

            return $stat;
        }, $this->get($apiKey, 'GetCrawlStats', ['siteUrl' => $siteUrl])));
    }

    /**
     * @return array{daily: int, monthly: int}
     */
    public function urlSubmissionQuota(string $apiKey, string $siteUrl): array
    {
        $quota = $this->get($apiKey, 'GetUrlSubmissionQuota', ['siteUrl' => $siteUrl]);

        return [
            'daily' => (int) ($quota['DailyQuota'] ?? 0),
            'monthly' => (int) ($quota['MonthlyQuota'] ?? 0),
        ];
    }

    /**
     * @param  list<string>  $urls
     */
    public function submitUrlBatch(string $apiKey, string $siteUrl, array $urls): void
    {
        $this->send($apiKey, 'SubmitUrlBatch', [], ['siteUrl' => $siteUrl, 'urlList' => array_values($urls)]);
    }

    /**
     * Bing dates look like /Date(1753401600000)/ or /Date(1753401600000-0700)/:
     * milliseconds since the epoch, at midnight UTC.
     */
    public function parseDate(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/\/Date\((-?\d+)/', $value, $matches)) {
            throw new BingApiException("Data Bing non riconosciuta: {$this->stringify($value)}");
        }

        return Carbon::createFromTimestampMsUTC((int) $matches[1])->toDateString();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{week_start: string, value: string, clicks: int, impressions: int, avg_impression_position: ?float, avg_click_position: ?float}>
     */
    private function weeklyStats(array $rows): array
    {
        return array_values(array_map(fn (array $row): array => [
            'week_start' => Carbon::parse($this->parseDate($row['Date'] ?? null))->subDays(7)->toDateString(),
            'value' => (string) ($row['Query'] ?? ''),
            'clicks' => (int) ($row['Clicks'] ?? 0),
            'impressions' => (int) ($row['Impressions'] ?? 0),
            'avg_impression_position' => $this->position($row['AvgImpressionPosition'] ?? null),
            'avg_click_position' => $this->position($row['AvgClickPosition'] ?? null),
        ], $rows));
    }

    /** Bing sends -1 when there is no position. */
    private function position(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value >= 0 ? round((float) $value, 1) : null;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<mixed>
     */
    private function get(string $apiKey, string $method, array $query = []): array
    {
        $data = $this->send($apiKey, $method, $query);

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function send(string $apiKey, string $method, array $query, ?array $body = null): mixed
    {
        $url = self::BASE_URL.$method.'?'.http_build_query($query + ['apikey' => $apiKey]);

        try {
            $request = Http::acceptJson()
                ->timeout(60)
                ->connectTimeout(5)
                ->retry(2, 1000, fn (Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()), throw: false);

            $response = $body === null ? $request->get($url) : $request->asJson()->post($url, $body);
        } catch (ConnectionException $e) {
            throw new BingApiException('Bing Webmaster Tools non risponde: riprova più tardi.');
        }

        if ($response->failed()) {
            throw $this->translateError($response);
        }

        return $response->json('d');
    }

    private function translateError(Response $response): BingApiException
    {
        $message = (string) ($response->json('Message') ?? $response->json('message') ?? Str::limit($response->body(), 200));
        $isAuthError = in_array($response->status(), [401, 403], true)
            || Str::contains($message, ['InvalidApiKey', 'Invalid API key', 'NotAuthorized', 'UserBlocked'], ignoreCase: true);

        if ($isAuthError) {
            return new BingApiException('La API key di Bing non è valida o è stata revocata: generane una nuova in Bing Webmaster Tools (Impostazioni → Accesso API) e salvala in Impostazioni → Bing.', isAuthError: true);
        }

        if (Str::contains($message, ['Quota', 'ThrottleUser'], ignoreCase: true)) {
            return new BingApiException("Quota Bing esaurita: {$message}");
        }

        return new BingApiException("Errore Bing Webmaster Tools (HTTP {$response->status()}): {$message}");
    }

    private function stringify(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }
}
