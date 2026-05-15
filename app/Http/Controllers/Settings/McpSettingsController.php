<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\McpToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class McpSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $token = $request->user()->mcpToken;

        return Inertia::render('settings/mcp', [
            'endpoint_url' => url('/mcp/pulso'),
            'has_token' => $token !== null,
            'last_used_at' => $token?->last_used_at?->toIso8601String(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $plainTextToken = $this->generatePlainTextToken();

        McpToken::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'token_hash' => McpToken::hashToken($plainTextToken),
                'last_used_at' => null,
            ],
        );

        return back()
            ->with('success', 'MCP token generated.')
            ->with('mcp_token', $plainTextToken);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->mcpToken()?->delete();

        return back()->with('success', 'MCP token revoked.');
    }

    private function generatePlainTextToken(): string
    {
        return 'pulso_mcp_'.Str::random(48);
    }
}
