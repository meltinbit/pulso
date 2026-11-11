<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Checks that a visit claiming to be an AI crawler comes from its vendor:
 * against the IP ranges the vendor publishes, or with forward-confirmed
 * reverse DNS where the vendor documents that instead (Amazon). Lists are
 * cached for a day and the last good copy is kept if a vendor is down.
 */
class AiCrawlerVerifier
{
    /** Seconds a downloaded list or DNS answer is reused. */
    private const TTL = 86400;

    /** Seconds before retrying a list that failed to download. */
    private const RETRY_AFTER = 600;

    /**
     * True when the IP belongs to the bot's vendor, false when it does not,
     * null when it cannot be told (no IP, vendor without a public list,
     * list unavailable).
     */
    public function verify(string $bot, ?string $ip): ?bool
    {
        $source = AiCrawlers::BOTS[$bot]['verify'] ?? null;

        if ($ip === null || $source === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        if (isset($source['dns'])) {
            return $this->verifyDns($ip, $source['dns']);
        }

        $ranges = [];

        foreach ($source['ips'] as $url) {
            $list = $this->ranges($url);

            if ($list === null) {
                return null;
            }

            $ranges = [...$ranges, ...$list];
        }

        return IpUtils::checkIp($ip, $ranges);
    }

    /**
     * CIDR prefixes of a vendor list in the googlebot.json format, or null
     * when it has never been downloaded successfully.
     *
     * @return list<string>|null
     */
    public function ranges(string $url): ?array
    {
        $key = 'ai-crawlers:ranges:'.sha1($url);
        $fresh = Cache::get($key);

        if (is_array($fresh)) {
            return $fresh;
        }

        $lastGood = Cache::get($key.':last');

        if (Cache::has($key.':failed')) {
            return $lastGood;
        }

        try {
            $prefixes = Http::timeout(5)->retry(2, 200, throw: false)->get($url)->throw()->json('prefixes');
            $ranges = collect(is_array($prefixes) ? $prefixes : [])
                ->map(fn ($prefix): ?string => is_array($prefix) ? ($prefix['ipv4Prefix'] ?? $prefix['ipv6Prefix'] ?? null) : null)
                ->filter()
                ->values()
                ->all();
        } catch (Throwable) {
            $ranges = [];
        }

        if ($ranges === []) {
            Cache::put($key.':failed', true, self::RETRY_AFTER);

            return $lastGood;
        }

        Cache::put($key, $ranges, self::TTL);
        Cache::forever($key.':last', $ranges);

        return $ranges;
    }

    /**
     * Forward-confirmed reverse DNS: the IP's host name must end with the
     * vendor domain and resolve back to the same IP.
     */
    private function verifyDns(string $ip, string $domain): bool
    {
        return Cache::remember('ai-crawlers:dns:'.$domain.':'.$ip, self::TTL, function () use ($ip, $domain): bool {
            $host = $this->hostFor($ip);

            if ($host === null || ! str_ends_with(strtolower(rtrim($host, '.')), '.'.$domain)) {
                return false;
            }

            return in_array(inet_pton($ip), array_map(fn (string $address): string|false => @inet_pton($address), $this->addressesFor($host)), true);
        });
    }

    /**
     * Host name of an IP from its PTR record.
     */
    public function hostFor(string $ip): ?string
    {
        $host = @gethostbyaddr($ip);

        return $host === false || $host === $ip ? null : $host;
    }

    /**
     * IPv4 and IPv6 addresses of a host name.
     *
     * @return list<string>
     */
    public function addressesFor(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }
}
