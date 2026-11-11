<?php

namespace App\Http\Middleware;

use App\Models\McpCallLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Records every MCP tools/call: tool, agent, arguments, how many bytes of
 * text the answer put in the agent's context, duration and errors. The agent
 * is named with ?agent=seo in the connector URL or the X-Pulso-Agent header.
 */
class LogMcpToolCalls
{
    private const MAX_ARGUMENTS_BYTES = 2000;

    public function handle(Request $request, Closure $next): Response
    {
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ($payload['method'] ?? null) !== 'tools/call') {
            return $next($request);
        }

        $startedAt = hrtime(true);
        $response = $next($request);

        try {
            $this->record($request, $payload, $response, (int) ((hrtime(true) - $startedAt) / 1_000_000));
        } catch (Throwable $e) {
            Log::warning("Failed to log MCP call: {$e->getMessage()}");
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(Request $request, array $payload, Response $response, int $durationMs): void
    {
        $body = $response instanceof StreamedResponse ? null : json_decode((string) $response->getContent(), true);
        [$bytes, $error] = $this->inspectReply($body, $response);

        McpCallLog::create([
            'tool' => Str::limit((string) ($payload['params']['name'] ?? 'unknown'), 100, ''),
            'agent' => $this->agent($request),
            'arguments' => $this->arguments($payload['params']['arguments'] ?? null),
            'response_bytes' => $bytes,
            'is_error' => $error !== null,
            'error' => $error === null ? null : Str::limit($error, 500),
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Bytes of text returned to the agent and the error message, if any.
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function inspectReply(mixed $body, Response $response): array
    {
        if (! is_array($body)) {
            return [null, $response->isSuccessful() ? null : "HTTP {$response->getStatusCode()}"];
        }

        if (isset($body['error'])) {
            return [null, (string) ($body['error']['message'] ?? 'JSON-RPC error')];
        }

        $texts = collect($body['result']['content'] ?? [])
            ->map(fn (mixed $item): string => is_array($item) ? (string) ($item['text'] ?? '') : '');

        $bytes = $texts->sum(fn (string $text): int => strlen($text));
        $isError = (bool) ($body['result']['isError'] ?? false);

        return [$bytes, $isError ? ($texts->first(fn (string $text): bool => $text !== '') ?? 'Tool error') : null];
    }

    private function agent(Request $request): ?string
    {
        $agent = $request->query('agent') ?: $request->header('X-Pulso-Agent');

        return is_string($agent) && $agent !== '' ? Str::limit($agent, 100, '') : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arguments(mixed $arguments): ?array
    {
        if (! is_array($arguments) || $arguments === []) {
            return null;
        }

        $json = (string) json_encode($arguments);

        return strlen($json) <= self::MAX_ARGUMENTS_BYTES
            ? $arguments
            : ['_truncated' => Str::limit($json, self::MAX_ARGUMENTS_BYTES)];
    }
}
