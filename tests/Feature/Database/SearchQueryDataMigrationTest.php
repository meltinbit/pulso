<?php

use App\Models\GaProperty;
use App\Models\PropertySearchQuery;
use App\Models\PropertySnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The data migration runs once against real production rows, so it is worth
 * exercising it with data rather than only against the empty test database.
 */
it('copies snapshot scoped search queries to the property level', function () {
    Schema::create('property_snapshot_search_queries', function (Blueprint $table) {
        $table->id();
        $table->foreignId('property_snapshot_id')->constrained('property_snapshots')->cascadeOnDelete();
        $table->string('query');
        $table->string('page')->nullable();
        $table->integer('clicks')->default(0);
        $table->integer('impressions')->default(0);
        $table->decimal('ctr', 5, 2)->default(0);
        $table->decimal('position', 5, 1)->default(0);
        $table->timestamps();
    });

    $property = GaProperty::factory()->create();
    $snapshot = PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => '2026-05-17',
    ]);

    DB::table('property_snapshot_search_queries')->insert([
        'property_snapshot_id' => $snapshot->id,
        'query' => 'calcolatore imu',
        'page' => '/imu',
        'clicks' => 12,
        'impressions' => 300,
        'ctr' => 4.0,
        'position' => 3.5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_09_10_095924_02_copy_snapshot_search_queries_to_property_search_queries.php');
    $migration->up();

    $copied = PropertySearchQuery::sole();

    expect($copied->ga_property_id)->toBe($property->id);
    expect($copied->date->toDateString())->toBe('2026-05-17');
    expect($copied->query)->toBe('calcolatore imu');
    expect($copied->page)->toBe('/imu');
    expect($copied->clicks)->toBe(12);

    Schema::dropIfExists('property_snapshot_search_queries');
});
