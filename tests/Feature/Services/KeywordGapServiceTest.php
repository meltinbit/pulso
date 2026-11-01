<?php

use App\Models\GaProperty;
use App\Models\PropertySearchQuery;
use App\Services\KeywordGapService;

beforeEach(function () {
    $this->property = GaProperty::factory()->create();
});

function gapRow(string $keyword, ?int $volume = 100, array $monthly = []): array
{
    return ['keyword' => $keyword, 'volume' => $volume, 'monthly' => $monthly, 'competition_index' => 0, 'cpc_low' => null, 'cpc_high' => null];
}

function imuSeries(): array
{
    return [['month' => '2025-10', 'searches' => 18100], ['month' => '2026-09', 'searches' => 12100]];
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
        ['keyword' => 'a', 'volume' => 100, 'gsc' => ['position' => 1.0, 'impressions' => 1, 'clicks' => 0], 'variant_of' => null],
        ['keyword' => 'b', 'volume' => 250, 'gsc' => null, 'variant_of' => null],
        ['keyword' => 'c', 'volume' => null, 'gsc' => null, 'variant_of' => null],
    ]);

    expect($summary)->toBe(['total' => 3, 'present' => 1, 'variants' => 0, 'missing' => 2, 'missing_volume' => 250]);
});

test('links close variants with identical metrics to the keyword the site ranks for', function () {
    PropertySearchQuery::factory()->for($this->property, 'gaProperty')->create([
        'date' => now()->subDay(), 'query' => 'calcolo imu', 'clicks' => 5, 'impressions' => 50, 'position' => 13.2,
    ]);

    $service = app(KeywordGapService::class);
    $annotated = $service->annotate($this->property, [
        gapRow('calcolo imu', 33100, imuSeries()),
        gapRow('calcolatori imu', 33100, imuSeries()),
        gapRow('calcolatore per imu', 33100, imuSeries()),
        gapRow('calcolo aci bollo', 12100, imuSeries()),
    ]);

    expect($annotated[0]['variant_of'])->toBeNull();
    expect($annotated[1]['variant_of'])->toBe('calcolo imu');
    expect($annotated[2]['variant_of'])->toBe('calcolo imu');
    expect($annotated[3]['variant_of'])->toBeNull();
    expect(KeywordGapService::isGap($annotated[1]))->toBeFalse();
    expect(KeywordGapService::isGap($annotated[3]))->toBeTrue();

    expect($service->summarize($annotated))->toBe(['total' => 4, 'present' => 3, 'variants' => 2, 'missing' => 1, 'missing_volume' => 12100]);
});

test('counts a group of missing variants only once in the missed volume', function () {
    $service = app(KeywordGapService::class);
    $annotated = $service->annotate($this->property, [
        gapRow('calcolo bollo auto', 8100, imuSeries()),
        gapRow('calcolatore bollo auto', 8100, imuSeries()),
        gapRow('bollo moto', 900, imuSeries()),
    ]);

    expect($service->summarize($annotated))->toMatchArray(['missing' => 3, 'missing_volume' => 9000]);
});

test('does not treat low volume keywords with identical metrics as variants', function () {
    PropertySearchQuery::factory()->for($this->property, 'gaProperty')->create([
        'date' => now()->subDay(), 'query' => 'imu ferrara', 'impressions' => 5, 'position' => 2.0,
    ]);

    $annotated = app(KeywordGapService::class)->annotate($this->property, [
        gapRow('imu ferrara', 10, imuSeries()),
        gapRow('tari bologna', 10, imuSeries()),
    ]);

    expect($annotated[1]['variant_of'])->toBeNull();
});
