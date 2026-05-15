<?php

namespace App\Http\Middleware;

use App\Models\McpToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureMcpTokenAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $this->extractToken($request);

        if (! $plainTextToken) {
            return $this->unauthorizedResponse();
        }

        $token = McpToken::query()
            ->with('user')
            ->where('token_hash', McpToken::hashToken($plainTextToken))
            ->first();

        if (! $token || ! $token->user) {
            return $this->unauthorizedResponse();
        }

        Auth::onceUsingId($token->user_id);
        $request->attributes->set('mcp_token', $token);

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        $queryToken = $request->query('token');
        if (is_string($queryToken) && $queryToken !== '') {
            return $queryToken;
        }

        $headerToken = $request->bearerToken() ?: $request->header('X-MCP-Token');

        return is_string($headerToken) && $headerToken !== '' ? $headerToken : null;
    }

    private function unauthorizedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Missing or invalid MCP token.',
        ], 401);
    }
}
