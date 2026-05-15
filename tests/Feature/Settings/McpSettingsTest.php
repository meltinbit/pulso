<?php

use App\Models\McpToken;
use App\Models\User;

test('mcp settings page requires authentication', function () {
    $this->get('/settings/mcp')->assertRedirect('/login');
});

test('user can generate an mcp token', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/settings/mcp');

    $response->assertRedirect();
    $response->assertSessionHas('success', 'MCP token generated.');
    $response->assertSessionHas('mcp_token');

    $token = McpToken::where('user_id', $user->id)->first();

    expect($token)->not->toBeNull();
    expect($token->getRawOriginal('token_hash'))->toHaveLength(64);
});

test('user can revoke an mcp token', function () {
    $user = User::factory()->create();

    McpToken::create([
        'user_id' => $user->id,
        'token_hash' => McpToken::hashToken('plain-token'),
    ]);

    $response = $this->actingAs($user)->delete('/settings/mcp');

    $response->assertRedirect();
    $response->assertSessionHas('success', 'MCP token revoked.');
    expect(McpToken::where('user_id', $user->id)->exists())->toBeFalse();
});

test('mcp route rejects requests without a token', function () {
    $response = $this->postJson('/mcp/pulso', [
        'jsonrpc' => '2.0',
        'id' => 'init',
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-03-26',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1.0.0'],
        ],
    ]);

    $response->assertUnauthorized();
});
