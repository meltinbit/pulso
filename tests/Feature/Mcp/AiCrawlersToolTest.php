<?php

use App\Mcp\Servers\PulsoServer;
use App\Mcp\Tools\GetAiCrawlersTool;
use App\Models\AiCrawlerVisit;
use App\Models\GaProperty;
use App\Models\PropertyAnnotation;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-11-11 10:00:00');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->property = GaProperty::factory()->for($this->user)->create([
        'website_url' => 'https://calcolato.it',
        'crawler_token_hash' => hash('sha256', 'x'),
    ]);
});

function crawlerVisit(GaProperty $property, string $date, string $bot, string $path, int $hits, int $status = 200): void
{
    AiCrawlerVisit::factory()->for($property, 'gaProperty')->create([
        'date' => $date, 'bot' => $bot, 'path' => $path, 'path_hash' => sha1($path), 'hits' => $hits,
        'last_status' => $status, 'last_seen_at' => $date.' 08:00:00',
    ]);
}

it('summarises crawler visits by bot, category, day and page', function () {
    crawlerVisit($this->property, '2026-11-09', 'OAI-SearchBot', '/forfettario/', 5);
    crawlerVisit($this->property, '2026-11-10', 'ChatGPT-User', '/forfettario/', 2);
    crawlerVisit($this->property, '2026-11-10', 'GPTBot', '/vecchia/', 3, 404);
    crawlerVisit($this->property, '2026-09-01', 'GPTBot', '/fuori-periodo/', 9);

    PropertyAnnotation::factory()->for($this->property, 'gaProperty')->create(['date' => '2026-11-05', 'type' => 'content', 'urls' => ['https://calcolato.it/forfettario/', 'https://calcolato.it/nuova/']]);

    PulsoServer::tool(GetAiCrawlersTool::class, ['property_id' => $this->property->id])
        ->assertOk()
        ->assertSee('"totals":{"hits":10,"pages":2,"by_category":{"training":3,"search":5,"user":2}}')
        ->assertSee('["OAI-SearchBot","OpenAI","search",5,1,"2026-11-09 08:00"]')
        ->assertSee('["2026-11-10",3,0,2]')
        ->assertSee('["\/forfettario\/",7,["OAI-SearchBot","ChatGPT-User"]')
        ->assertSee('"errors":[{"path":"\/vecchia\/","status":404,"bots":["GPTBot"]}]')
        ->assertSee('"new_content_not_visited":[{"path":"\/nuova\/","published":"2026-11-05"}]')
        ->assertDontSee('fuori-periodo');
});

it('explains how to connect a site without a token', function () {
    $this->property->forceFill(['crawler_token_hash' => null])->save();

    PulsoServer::tool(GetAiCrawlersTool::class, ['property_id' => $this->property->id])
        ->assertHasErrors(['Impostazioni → AI visibility']);
});
