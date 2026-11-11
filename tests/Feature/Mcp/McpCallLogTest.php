<?php

use App\Models\GaProperty;
use App\Models\McpCallLog;
use App\Models\McpToken;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->user = User::factory()->create();
    McpToken::create(['user_id' => $this->user->id, 'token_hash' => McpToken::hashToken('plain-token')]);
});

function callMcpTool(string $tool, array $arguments, string $query = 'token=plain-token'): TestResponse
{
    return test()->postJson("/mcp/pulso?{$query}", [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ]);
}

it('logs a successful tool call with agent, arguments and response size', function () {
    GaProperty::factory()->for($this->user)->create(['display_name' => 'Calcolato']);

    callMcpTool('list-properties-tool', [], 'token=plain-token&agent=seo')->assertOk();

    $log = McpCallLog::sole();

    expect($log->tool)->toBe('list-properties-tool')
        ->and($log->agent)->toBe('seo')
        ->and($log->arguments)->toBeNull()
        ->and($log->response_bytes)->toBeGreaterThan(10)
        ->and($log->is_error)->toBeFalse()
        ->and($log->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('logs tool errors with their message', function () {
    callMcpTool('get-property-snapshots-tool', [])->assertOk();

    $log = McpCallLog::sole();

    expect($log->tool)->toBe('get-property-snapshots-tool')
        ->and($log->is_error)->toBeTrue()
        ->and($log->error)->toContain('property');
});

it('reads the agent from the header', function () {
    test()->withHeader('X-Pulso-Agent', 'ads');

    callMcpTool('list-properties-tool', [])->assertOk();

    expect(McpCallLog::sole()->agent)->toBe('ads');
});

it('does not log other mcp methods or unauthenticated calls', function () {
    $this->postJson('/mcp/pulso?token=plain-token', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ])->assertOk();

    callMcpTool('list-properties-tool', [], 'token=wrong')->assertUnauthorized();

    expect(McpCallLog::count())->toBe(0);
});

it('prunes logs older than the retention period', function () {
    McpCallLog::factory()->create(['created_at' => now()->subDays(McpCallLog::RETENTION_DAYS + 1)]);
    McpCallLog::factory()->create(['created_at' => now()->subDays(5)]);

    $this->artisan('model:prune', ['--model' => [McpCallLog::class]])->assertSuccessful();

    expect(McpCallLog::count())->toBe(1);
});
