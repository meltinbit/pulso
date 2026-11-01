<?php

use App\Models\GaProperty;
use App\Models\PropertySearchQuery;
use App\Services\KeywordGapService;

beforeEach(function () {
    $this->property = GaProperty::factory()->create();
});

function gapRow(string $keyword, ?int $volume = 100): array
{
    return ['keyword' => $keyword, 'volume' => $volume];
}

test('marks keywords the site ranks for with weighted position and totals', function () {
    PropertySearchQuery::factory()->for($this->property, 'gaProperty')->create([
        'date' => now()->subDays(5), 'query' => 'calcolo imu', 'page' => 'https://example.com/a', 'clicks' => 10, 'impressions' => 100, 'position' => 4.0,
    ]);
    PropertySearchQuery::factory()->for($this->property, 'gaProperty')->create([
        'date' => now()->subDays(6), 'query' => 'calcolo imu', 'page' => 'https://example.com/b', 'clicks' => 2, 'impressions' => 300, 'position' => 8.0,
    ]);

    $annotated = app(KeywordGapService::class)->annotate($this->property, [gapRow('calcolo imu'), gapRow('aliquote imu', 500)]);

    expect($annotated[0]['gsc'])->toBe(['position' => 7.0, 'impressions' => 400, 'clicks' => 12]);
    expect($annotated[1]['gsc'])->toBeNull();
    expect($annotated[1]['volume'])->toBe(500);
});

test('matches queries ignoring case, accents and extra spaces', function () {
    PropertySearchQuery::factory()->for($this->property, 'gaProperty')->create([
        'date' => now()->subDay(), 'query' => 'perché  Ferrara', 'clicks' => 1, 'impressions' => 10, 'position' => 3.0,
    ]);

    $annotated = app(KeywordGapService::class)->annotate($this->property, [gapRow('perche ferrara')]);

    expect($annotated[0]['gsc'])->toBe(['position' => 3.0, 'impressions' => 10, 'clicks' => 1]);
});

test('ignores queries older than 90 days and from other properties', function () {
    PropertySearchQuery::factory()->for($this->property, 'gaProperty')->create([
        'date' => now()->subDays(120), 'query' => 'vecchia', 'impressions' => 10, 'position' => 2.0,
    ]);
    PropertySearchQuery::factory()->create(['date' => now()->subDay(), 'query' => 'altrui', 'impressions' => 10, 'position' => 2.0]);

    $annotated = app(KeywordGapService::class)->annotate($this->property, [gapRow('vecchia'), gapRow('altrui')]);

    expect($annotated[0]['gsc'])->toBeNull();
    expect($annotated[1]['gsc'])->toBeNull();
});

test('summarizes present and missing keywords', function () {
    $summary = app(KeywordGapService::class)->summarize([
        ['keyword' => 'a', 'volume' => 100, 'gsc' => ['position' => 1.0, 'impressions' => 1, 'clicks' => 0]],
        ['keyword' => 'b', 'volume' => 250, 'gsc' => null],
        ['keyword' => 'c', 'volume' => null, 'gsc' => null],
    ]);

    expect($summary)->toBe(['total' => 3, 'present' => 1, 'missing' => 2, 'missing_volume' => 250]);
});
