<?php

use App\Models\AiCrawlerVisit;
use App\Models\GaProperty;
use App\Services\AiCrawlers;
use App\Services\AiCrawlerVerifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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

it('verifies visits against the vendor ip ranges and counts spoofed ones apart', function () {
    Http::fake([
        'openai.com/searchbot.json' => Http::response(['prefixes' => [['ipv4Prefix' => '20.42.10.176/28'], ['ipv6Prefix' => '2600:1f28::/32']]]),
        '*' => Http::response('', 500),
    ]);

    $agent = 'Mozilla/5.0 (compatible; OAI-SearchBot/1.3)';

    $this->withToken('pcr_secret')->postJson(route('api.ai-crawlers'), ['hits' => [
        ['user_agent' => $agent, 'path' => '/', 'ip' => '20.42.10.180'],
        ['user_agent' => $agent, 'path' => '/', 'ip' => '2600:1f28::1'],
        ['user_agent' => $agent, 'path' => '/', 'ip' => '203.0.113.9'],
        ['user_agent' => $agent, 'path' => '/'],
        ['user_agent' => 'Mozilla/5.0 (compatible; Bytespider)', 'path' => '/', 'ip' => '203.0.113.9'],
        ['user_agent' => 'Mozilla/5.0 (compatible; GPTBot/1.2)', 'path' => '/', 'ip' => '203.0.113.9'],
    ]])->assertAccepted()->assertJson(['accepted' => 6, 'verified' => 2, 'spoofed' => 1]);

    $search = AiCrawlerVisit::where('bot', 'OAI-SearchBot')->sole();
    expect([$search->hits, $search->verified_hits, $search->spoofed_hits])->toBe([4, 2, 1])
        ->and(AiCrawlerVisit::where('bot', 'GPTBot')->sole()->spoofed_hits)->toBe(0);
});

it('keeps the last good ip list when a vendor is down', function () {
    $verifier = app(AiCrawlerVerifier::class);

    Http::fake(['openai.com/gptbot.json' => Http::sequence()
        ->push(['prefixes' => [['ipv4Prefix' => '132.196.86.0/24']]])
        ->push('', 500)
        ->push('', 500)]);

    expect($verifier->verify('GPTBot', '132.196.86.5'))->toBeTrue();

    Cache::forget('ai-crawlers:ranges:'.sha1('https://openai.com/gptbot.json'));

    expect($verifier->verify('GPTBot', '132.196.86.5'))->toBeTrue()
        ->and($verifier->verify('GPTBot', '8.8.8.8'))->toBeFalse()
        ->and($verifier->verify('Bytespider', '8.8.8.8'))->toBeNull()
        ->and($verifier->verify('GPTBot', null))->toBeNull();
});

it('verifies amazonbot with forward-confirmed reverse dns', function () {
    $verifier = $this->partialMock(AiCrawlerVerifier::class, function ($mock) {
        $mock->shouldReceive('hostFor')->with('52.70.240.171')->andReturn('52-70-240-171.crawl.amazonbot.amazon');
        $mock->shouldReceive('addressesFor')->with('52-70-240-171.crawl.amazonbot.amazon')->andReturn(['52.70.240.171']);
        $mock->shouldReceive('hostFor')->with('198.51.100.7')->andReturn('fake.crawl.amazonbot.amazon.evil.example');
        $mock->shouldReceive('hostFor')->with('198.51.100.8')->andReturn('x.crawl.amazonbot.amazon');
        $mock->shouldReceive('addressesFor')->with('x.crawl.amazonbot.amazon')->andReturn(['52.70.240.1']);
    });

    expect($verifier->verify('Amazonbot', '52.70.240.171'))->toBeTrue()
        ->and($verifier->verify('Amazonbot', '198.51.100.7'))->toBeFalse()
        ->and($verifier->verify('Amazonbot', '198.51.100.8'))->toBeFalse();
});
