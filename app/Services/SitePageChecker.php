<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Requests site pages the way a crawler does (no redirects followed) and
 * reads what matters for indexing: status, redirect target, canonical,
 * noindex and response time.
 */
class SitePageChecker
{
    public const USER_AGENT = 'Mozilla/5.0 (compatible; PulsoCheck/1.0; site health check)';

    /** Requests in flight at once, to stay gentle with the site. */
    private const CONCURRENCY = 8;

    /** Bytes of HTML read to find the head tags. */
    private const HEAD_BYTES = 262144;

    /**
     * @param  list<string>  $urls
     * @return list<array{url: string, status: ?int, location: ?string, canonical: ?string, noindex: bool, ms: ?int, error: ?string}>
     */
    public function check(array $urls): array
    {
        $results = [];

        foreach (array_chunk($urls, self::CONCURRENCY) as $chunk) {
            $started = microtime(true);
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $url) => $pool->withUserAgent(self::USER_AGENT)->withoutRedirecting()->connectTimeout(5)->timeout(15)->get($url),
                $chunk,
            ));
            $elapsed = (int) round((microtime(true) - $started) * 1000);

            foreach ($chunk as $index => $url) {
                $results[] = $this->read($url, $responses[$index] ?? null, $elapsed);
            }
        }

        return $results;
    }

    /**
     * @return array{url: string, status: ?int, location: ?string, canonical: ?string, noindex: bool, ms: ?int, error: ?string}
     */
    private function read(string $url, mixed $response, int $chunkMs): array
    {
        if (! $response instanceof Response) {
            return [
                'url' => $url, 'status' => null, 'location' => null, 'canonical' => null, 'noindex' => false, 'ms' => null,
                'error' => $response instanceof ConnectionException || $response instanceof Throwable ? class_basename($response) : 'NoResponse',
            ];
        }

        $location = $response->header('Location') ?: null;
        $html = $response->successful() ? substr($response->body(), 0, self::HEAD_BYTES) : '';
        $transferTime = $response->handlerStats()['total_time'] ?? null;

        return [
            'url' => $url,
            'status' => $response->status(),
            'location' => $location === null ? null : $this->absolute($location, $url),
            'canonical' => $this->canonical($response, $html, $url),
            'noindex' => $this->noindex($response, $html),
            'ms' => is_numeric($transferTime) ? (int) round($transferTime * 1000) : $chunkMs,
            'error' => null,
        ];
    }

    private function canonical(Response $response, string $html, string $url): ?string
    {
        foreach (array_filter(explode(',', $response->header('Link'))) as $link) {
            if (preg_match('/<([^>]+)>\s*;\s*rel="?canonical"?/i', $link, $match)) {
                return $this->absolute(trim($match[1]), $url);
            }
        }

        foreach ($this->tags($html, 'link') as $attributes) {
            if (in_array('canonical', explode(' ', strtolower($attributes['rel'] ?? '')), true) && filled($attributes['href'] ?? null)) {
                return $this->absolute(html_entity_decode(trim($attributes['href'])), $url);
            }
        }

        return null;
    }

    private function noindex(Response $response, string $html): bool
    {
        foreach ($response->toPsrResponse()->getHeader('X-Robots-Tag') as $value) {
            if ($this->deniesIndex($value, true)) {
                return true;
            }
        }

        foreach ($this->tags($html, 'meta') as $attributes) {
            $name = strtolower($attributes['name'] ?? '');

            if (in_array($name, ['robots', 'googlebot'], true) && $this->deniesIndex($attributes['content'] ?? '', false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a robots directive list contains noindex or none. Header values
     * may be prefixed with a user agent ("otherbot: noindex"), which only
     * counts when it names Google or nobody.
     */
    private function deniesIndex(string $value, bool $isHeader): bool
    {
        $value = strtolower($value);

        if ($isHeader && preg_match('/^\s*([a-z0-9_-]+)\s*:\s*(.*)$/', $value, $match) && ! in_array($match[1], ['unavailable_after', 'max-snippet', 'max-image-preview', 'max-video-preview'], true)) {
            if (! in_array($match[1], ['googlebot', 'robots'], true)) {
                return false;
            }

            $value = $match[2];
        }

        return (bool) preg_match('/(^|[\s,])(noindex|none)($|[\s,])/', $value);
    }

    /**
     * Attributes of every tag of a name inside the document head.
     *
     * @return list<array<string, string>>
     */
    private function tags(string $html, string $name): array
    {
        $head = preg_split('/<\/head\s*>/i', $html)[0] ?? '';

        if (! preg_match_all('/<'.$name.'\b([^>]*)>/i', $head, $tags)) {
            return [];
        }

        return array_map(function (string $attributes): array {
            preg_match_all('/([a-zA-Z:-]+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/', $attributes, $pairs, PREG_SET_ORDER);

            return collect($pairs)->mapWithKeys(fn (array $pair): array => [strtolower($pair[1]) => trim($pair[2], '"\'')])->all();
        }, $tags[1]);
    }

    /**
     * Resolve a possibly relative URL against the page it was found on.
     */
    private function absolute(string $target, string $base): string
    {
        if (preg_match('#^https?://#i', $target)) {
            return $target;
        }

        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($base, PHP_URL_HOST);

        if (str_starts_with($target, '//')) {
            return $scheme.':'.$target;
        }

        if (str_starts_with($target, '/')) {
            return "{$scheme}://{$host}{$target}";
        }

        $path = parse_url($base, PHP_URL_PATH) ?: '/';

        return "{$scheme}://{$host}".substr($path, 0, (int) strrpos($path, '/') + 1).$target;
    }
}
