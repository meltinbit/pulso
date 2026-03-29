<?php

namespace App\Services;

use App\Exceptions\GaApiException;
use App\Exceptions\GaPermissionException;
use App\Exceptions\GaQuotaExceededException;
use App\Models\AnalyticsCache;
use App\Models\GaProperty;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GaClientService
{
    public function __construct(
        private GoogleTokenService $tokenService,
    ) {}

    /**
     * Run a standard GA4 report (v1beta).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function runReport(GaProperty $property, array $params): array
    {
        $cacheKey = $this->buildCacheKey('core', $property->id, $params);

        return $this->remember($property, $cacheKey, 'core', function () use ($property, $params) {
            $token = $this->tokenService->getFreshToken($property->gaConnection);

            $response = Http::withToken($token)
                ->timeout(30)
                ->connectTimeout(5)
                ->retry(2, 1000, throw: false)
                ->post(
                    "https://analyticsdata.googleapis.com/v1beta/properties/{$property->property_id}:runReport",
                    array_merge($params, ['returnPropertyQuota' => true])
                );

            $this->handleApiErrors($response);

            return $response->json();
        });
    }

    /**
     * Run a realtime report (v1beta). Short TTL cache.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function runRealtimeReport(GaProperty $property, array $params): array
    {
        $cacheKey = $this->buildCacheKey('realtime', $property->id, $params);

        return $this->remember($property, $cacheKey, 'realtime', function () use ($property, $params) {
            $token = $this->tokenService->getFreshToken($property->gaConnection);

            $response = Http::withToken($token)
                ->timeout(15)
                ->connectTimeout(5)
                ->post(
                    "https://analyticsdata.googleapis.com/v1beta/properties/{$property->property_id}:runRealtimeReport",
                    $params
                );

            $this->handleApiErrors($response);

            return $response->json();
        });
    }

    /**
     * Run a funnel report (v1alpha — different format).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function runFunnelReport(GaProperty $property, array $params): array
    {
        $cacheKey = $this->buildCacheKey('funnel', $property->id, $params);

        return $this->remember($property, $cacheKey, 'funnel', function () use ($property, $params) {
            $token = $this->tokenService->getFreshToken($property->gaConnection);

            $response = Http::withToken($token)
                ->timeout(30)
                ->connectTimeout(5)
                ->post(
                    "https://analyticsdata.googleapis.com/v1alpha/properties/{$property->property_id}:runFunnelReport",
                    $params
                );

            $this->handleApiErrors($response);

            return $response->json();
        });
    }

    /**
     * Invalidate all cache entries for a property.
     */
    public function clearCache(GaProperty $property): int
    {
        return AnalyticsCache::where('ga_property_id', $property->id)->delete();
    }

    /**
     * Cache wrapper: returns cached data if valid, otherwise executes callback and caches result.
     *
     * @return array<string, mixed>
     */
    private function remember(GaProperty $property, string $key, string $type, callable $fn): array
    {
        $cached = AnalyticsCache::where([
            'ga_property_id' => $property->id,
            'cache_key' => $key,
        ])->where('expires_at', '>', now())->first();

        if ($cached) {
            return $cached->payload;
        }

        $result = $fn();

        $ttl = match ($type) {
            'realtime' => config('ga.cache_ttl_realtime', 60),
            'funnel' => config('ga.cache_ttl_funnel', 7200),
            default => config('ga.cache_ttl_core', 3600),
        };

        AnalyticsCache::updateOrCreate(
            ['ga_property_id' => $property->id, 'cache_key' => $key],
            [
                'report_type' => $type,
                'payload' => $result,
                'expires_at' => now()->addSeconds($ttl),
                'tokens_used' => data_get($result, 'propertyQuota.tokensPerHour.consumed', 0),
            ]
        );

        return $result;
    }

    private function buildCacheKey(string $type, int $propertyId, array $params): string
    {
        return hash('sha256', $type.$propertyId.serialize($params));
    }

    private function handleApiErrors(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        match ($response->status()) {
            429 => throw new GaQuotaExceededException,
            403 => throw new GaPermissionException,
            default => throw new GaApiException($response->body()),
        };
    }
}
