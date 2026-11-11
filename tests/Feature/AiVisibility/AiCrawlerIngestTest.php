<?php

use App\Models\AiCrawlerVisit;
use App\Models\GaProperty;
use App\Services\AiCrawlers;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-11-11 10:00:00');
    $this->property = GaProperty::factory()->create(['crawler_token_hash' => hash('sha256', 'pcr_secret'), 'timezone' => 'Europe/Rome']);
});

it('counts ai crawler visits per day, bot and page and ignores other agents', function () {
    $hits = [
        ['user_agent' => 'Mozilla/5.0 (compatible; OAI-SearchBot/1.3; +https://openai.com/searchbot)', 'path' => '/forfettario/?utm_source=x', 'status' => 200, 'at' => '2026-11-11T08:00:00+00:00'],
        ['user_agent' => 'Mozilla/5.0 (compatible; OAI-SearchBot/1.3)', 'path' => '/forfettario/', 'status' => 404, 'at' => '2026-11-11T09:00:00+00:00'],
        ['user_agent' => 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', 'path' => '/', 'at' => '2026-11-10T23:30:00+00:00'],
        ['user_agent' => 'Mozilla/5.0 (Macintosh) Safari', 'path' => '/'],
    ];

    $this->withToken('pcr_secret')->postJson(route('api.ai-crawlers'), ['hits' => $hits])
        ->assertAccepted()
        ->assertJson(['accepted' => 3, 'ignored' => 1]);

    $this->withToken('pcr_secret')->postJson(route('api.ai-crawlers'), ['hits' => [$hits[0]]])->assertAccepted();

    $search = AiCrawlerVisit::where('bot', 'OAI-SearchBot')->sole();
    expect($search->path)->toBe('/forfettario/')
        ->and($search->hits)->toBe(3)
        ->and($search->last_status)->toBe(404)
        ->and($search->date->toDateString())->toBe('2026-11-11');

    expect(AiCrawlerVisit::where('bot', 'ClaudeBot')->sole()->date->toDateString())->toBe('2026-11-11');
});

it('rejects a missing or wrong token', function () {
    $this->postJson(route('api.ai-crawlers'), ['hits' => [['user_agent' => 'GPTBot', 'path' => '/']]])->assertUnauthorized();
    $this->withToken('pcr_wrong')->postJson(route('api.ai-crawlers'), ['hits' => [['user_agent' => 'GPTBot', 'path' => '/']]])->assertUnauthorized();

    expect(AiCrawlerVisit::count())->toBe(0);
});

it('keeps the readme middleware in sync with the crawler list', function () {
    expect(file_get_contents(base_path('README.md')))->toContain("private const BOTS = '".AiCrawlers::pattern()."';");
});

it('recognises every listed crawler and nothing else', function () {
    foreach (array_keys(AiCrawlers::BOTS) as $bot) {
        expect(AiCrawlers::identify("Mozilla/5.0 (compatible; {$bot}/1.0)"))->toBe($bot);
    }

    expect(AiCrawlers::identify('Mozilla/5.0 (compatible; Googlebot/2.1)'))->toBeNull();
});
