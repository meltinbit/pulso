<?php

use App\Models\McpCallLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('aggregates mcp calls by tool and agent', function () {
    McpCallLog::factory()->count(2)->create(['tool' => 'get-property-summary-tool', 'agent' => 'seo', 'response_bytes' => 1000]);
    McpCallLog::factory()->failed('Boom')->create(['tool' => 'get-property-summary-tool', 'agent' => 'seo', 'response_bytes' => null]);
    McpCallLog::factory()->create(['tool' => 'list-properties-tool', 'agent' => null, 'response_bytes' => 100]);
    McpCallLog::factory()->create(['tool' => 'old-tool', 'created_at' => now()->subDays(10)]);

    $this->actingAs(User::factory()->create())->get(route('mcp-log.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('mcp-log/index')
            ->where('days', 7)
            ->has('byTool', 2)
            ->where('byTool.0.tool', 'get-property-summary-tool')
            ->where('byTool.0.calls', 3)
            ->where('byTool.0.errors', 1)
            ->has('byAgent', 2)
            ->has('recent', 4));
});

it('filters the latest calls to errors', function () {
    McpCallLog::factory()->create();
    McpCallLog::factory()->failed()->create();

    $this->actingAs(User::factory()->create())->get(route('mcp-log.index', ['errors' => 1, 'days' => 30]))
        ->assertInertia(fn (Assert $page) => $page->where('days', 30)->has('recent', 1));
});
