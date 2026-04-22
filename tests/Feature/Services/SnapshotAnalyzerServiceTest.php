<?php

use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\PropertySnapshot;
use App\Models\PropertySnapshotEvent;
use App\Models\PropertySnapshotSource;
use App\Services\SnapshotAnalyzerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    $this->connection = GaConnection::factory()->create();
    $this->property = GaProperty::factory()->create([
        'user_id' => $this->connection->user_id,
        'ga_connection_id' => $this->connection->id,
        'property_id' => '123456789',
    ]);
});

function fakeGaResponses(int $users = 500, int $sessions = 700, int $pageviews = 2000, float $bounceRate = 45.5, int $duration = 120, int $usersWeekAgo = 400, float $usersAvg30d = 450): void
{
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::sequence()
            // Yesterday data
            ->push([
                'rows' => [[
                    'metricValues' => [
                        ['value' => (string) $users],
                        ['value' => (string) $sessions],
                        ['value' => (string) $pageviews],
                        ['value' => (string) $bounceRate],
                        ['value' => (string) $duration],
                    ],
                ]],
            ])
            // Week ago data
            ->push([
                'rows' => [[
                    'metricValues' => [
                        ['value' => (string) $usersWeekAgo],
                        ['value' => (string) round($sessions * 0.8)],
                        ['value' => (string) round($pageviews * 0.9)],
                        ['value' => (string) ($bounceRate + 2)],
                        ['value' => (string) ($duration - 10)],
                    ],
                ]],
            ])
            // 30-day average
            ->push([
                'rows' => [[
                    'metricValues' => [
                        ['value' => (string) $usersAvg30d],
                        ['value' => (string) round($sessions * 0.85)],
                    ],
                ]],
            ])
            // Sources
            ->push([
                'rows' => [
                    [
                        'dimensionValues' => [['value' => 'google'], ['value' => 'organic']],
                        'metricValues' => [['value' => '300'], ['value' => '250']],
                    ],
                    [
                        'dimensionValues' => [['value' => 'direct'], ['value' => '(none)']],
                        'metricValues' => [['value' => '200'], ['value' => '150']],
                    ],
                ],
            ])
            // Engagement metrics
            ->push([
                'rows' => [[
                    'metricValues' => [
                        ['value' => '450'],
                        ['value' => '0.6428'],
                    ],
                ]],
            ])
            // Pages
            ->push([
                'rows' => [
                    [
                        'dimensionValues' => [['value' => '/'], ['value' => 'Home']],
                        'metricValues' => [
                            ['value' => '800'],
                            ['value' => '600'],
                            ['value' => '0.35'],
                            ['value' => '95'],
                            ['value' => '0.65'],
                        ],
                    ],
                    [
                        'dimensionValues' => [['value' => '/about'], ['value' => 'About']],
                        'metricValues' => [
                            ['value' => '200'],
                            ['value' => '150'],
                            ['value' => '0.55'],
                            ['value' => '45'],
                            ['value' => '0.65'],
                        ],
                    ],
                ],
            ])
            // Events
            ->push([
                'rows' => [
                    [
                        'dimensionValues' => [['value' => 'page_view']],
                        'metricValues' => [['value' => '2000'], ['value' => '500']],
                    ],
                    [
                        'dimensionValues' => [['value' => 'calcolo_eseguito']],
                        'metricValues' => [['value' => '120'], ['value' => '80']],
                    ],
                ],
            ]),
        'searchconsole.googleapis.com/*' => Http::response([
            'rows' => [
                ['keys' => ['calcolatore imu', 'https://example.com/calcolatore'], 'clicks' => 50, 'impressions' => 1000, 'ctr' => 0.05, 'position' => 3.2],
            ],
        ]),
    ]);
}

test('analyze creates a snapshot with correct metrics', function () {
    fakeGaResponses();

    $service = app(SnapshotAnalyzerService::class);
    $snapshot = $service->analyze($this->property, Carbon::yesterday());

    expect($snapshot)->toBeInstanceOf(PropertySnapshot::class);
    expect($snapshot->users)->toBe(500);
    expect($snapshot->sessions)->toBe(700);
    expect($snapshot->pageviews)->toBe(2000);
    expect((float) $snapshot->bounce_rate)->toBe(45.50);
    expect($snapshot->avg_session_duration)->toBe(120);
    expect($snapshot->trend)->toBeString();
    expect($snapshot->ga_property_id)->toBe($this->property->id);
});

test('analyze creates snapshot sources', function () {
    fakeGaResponses();

    $service = app(SnapshotAnalyzerService::class);
    $snapshot = $service->analyze($this->property, Carbon::yesterday());

    expect(PropertySnapshotSource::where('property_snapshot_id', $snapshot->id)->count())->toBe(2);

    $googleSource = $snapshot->sources->firstWhere('source', 'google');
    expect($googleSource->medium)->toBe('organic');
    expect($googleSource->sessions)->toBe(300);
    expect($googleSource->users)->toBe(250);
});

test('analyze creates snapshot events', function () {
    fakeGaResponses();

    $service = app(SnapshotAnalyzerService::class);
    $snapshot = $service->analyze($this->property, Carbon::yesterday());

    expect(PropertySnapshotEvent::where('property_snapshot_id', $snapshot->id)->count())->toBe(2);

    $custom = $snapshot->events->firstWhere('event_name', 'calcolo_eseguito');
    expect($custom->event_count)->toBe(120);
    expect($custom->total_users)->toBe(80);
});

test('analyze computes WoW delta correctly', function () {
    fakeGaResponses(users: 500, usersWeekAgo: 400);

    $service = app(SnapshotAnalyzerService::class);
    $snapshot = $service->analyze($this->property, Carbon::yesterday());

    // (500 - 400) / 400 * 100 = 25%
    expect((float) $snapshot->users_delta_wow)->toBe(25.00);
    expect($snapshot->trend)->toBe('improved');
});

test('analyze detects spike when WoW delta exceeds 50%', function () {
    fakeGaResponses(users: 800, usersWeekAgo: 400, usersAvg30d: 450);

    $service = app(SnapshotAnalyzerService::class);
    $snapshot = $service->analyze($this->property, Carbon::yesterday());

    // (800 - 400) / 400 * 100 = 100%
    expect($snapshot->trend)->toBe('spike');
    expect($snapshot->is_spike)->toBeTrue();
});

test('analyze detects drop when WoW delta below -30%', function () {
    fakeGaResponses(users: 200, usersWeekAgo: 500, usersAvg30d: 450);

    $service = app(SnapshotAnalyzerService::class);
    $snapshot = $service->analyze($this->property, Carbon::yesterday());

    // (200 - 500) / 500 * 100 = -60%
    expect($snapshot->trend)->toBe('drop');
    expect($snapshot->is_drop)->toBeTrue();
});

test('analyze updates existing snapshot instead of duplicating', function () {
    // Pre-create a snapshot for yesterday
    $existing = PropertySnapshot::factory()->create([
        'ga_property_id' => $this->property->id,
        'snapshot_date' => Carbon::yesterday()->startOfDay(),
        'users' => 300,
        'trend' => 'stall',
    ]);

    fakeGaResponses();

    $service = app(SnapshotAnalyzerService::class);
    $snapshot = $service->analyze($this->property, Carbon::yesterday());

    expect(PropertySnapshot::where('ga_property_id', $this->property->id)->count())->toBe(1);
    expect($snapshot->users)->toBe(500);
    expect($snapshot->id)->toBe($existing->id);
});

test('categorizeTrend returns correct categories', function () {
    $service = app(SnapshotAnalyzerService::class);

    expect($service->categorizeTrend(60))->toBe('spike');
    expect($service->categorizeTrend(25))->toBe('improved');
    expect($service->categorizeTrend(0))->toBe('stall');
    expect($service->categorizeTrend(-5))->toBe('stall');
    expect($service->categorizeTrend(-20))->toBe('declined');
    expect($service->categorizeTrend(-40))->toBe('drop');
});

test('computeTrendScore is clamped between -100 and 100', function () {
    $service = app(SnapshotAnalyzerService::class);

    expect($service->computeTrendScore(200, 200))->toBe(100.00);
    expect($service->computeTrendScore(-200, -200))->toBe(-100.00);
    expect($service->computeTrendScore(0, 0))->toBe(0.00);
});

test('computeTrendScore weights WoW 60% and 30d 40%', function () {
    $service = app(SnapshotAnalyzerService::class);

    // 20 * 0.6 + 10 * 0.4 = 12 + 4 = 16
    expect($service->computeTrendScore(20, 10))->toBe(16.00);
});

test('detectAnomaly identifies spike above 50% of avg', function () {
    $service = app(SnapshotAnalyzerService::class);

    $result = $service->detectAnomaly(800, 400);
    expect($result['is_spike'])->toBeTrue();
    expect($result['is_drop'])->toBeFalse();
});

test('detectAnomaly identifies drop below -30% of avg', function () {
    $service = app(SnapshotAnalyzerService::class);

    $result = $service->detectAnomaly(200, 400);
    expect($result['is_spike'])->toBeFalse();
    expect($result['is_drop'])->toBeTrue();
});

test('detectAnomaly handles zero average gracefully', function () {
    $service = app(SnapshotAnalyzerService::class);

    $result = $service->detectAnomaly(100, 0);
    expect($result['is_spike'])->toBeFalse();
    expect($result['is_drop'])->toBeFalse();
});
