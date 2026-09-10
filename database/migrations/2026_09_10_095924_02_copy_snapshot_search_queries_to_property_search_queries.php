<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move the existing snapshot-scoped Search Console rows to the new
     * property-scoped table, using the snapshot date as the row date.
     */
    public function up(): void
    {
        if (! Schema::hasTable('property_snapshot_search_queries')) {
            return;
        }

        DB::table('property_snapshot_search_queries')
            ->join('property_snapshots', 'property_snapshots.id', '=', 'property_snapshot_search_queries.property_snapshot_id')
            ->select([
                'property_snapshots.ga_property_id',
                'property_snapshots.snapshot_date',
                'property_snapshot_search_queries.id',
                'property_snapshot_search_queries.query',
                'property_snapshot_search_queries.page',
                'property_snapshot_search_queries.clicks',
                'property_snapshot_search_queries.impressions',
                'property_snapshot_search_queries.ctr',
                'property_snapshot_search_queries.position',
                'property_snapshot_search_queries.created_at',
                'property_snapshot_search_queries.updated_at',
            ])
            ->orderBy('property_snapshot_search_queries.id')
            ->chunk(500, function (Collection $rows) {
                DB::table('property_search_queries')->insert($rows->map(fn (object $row): array => [
                    'ga_property_id' => $row->ga_property_id,
                    'date' => substr((string) $row->snapshot_date, 0, 10),
                    'query' => $row->query,
                    'page' => $row->page,
                    'clicks' => $row->clicks,
                    'impressions' => $row->impressions,
                    'ctr' => $row->ctr,
                    'position' => $row->position,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ])->all());
            });
    }

    /**
     * Copy the rows back into the recreated snapshot-scoped table.
     */
    public function down(): void
    {
        if (! Schema::hasTable('property_snapshot_search_queries')) {
            return;
        }

        DB::table('property_search_queries')
            ->join('property_snapshots', function ($join) {
                $join->on('property_snapshots.ga_property_id', '=', 'property_search_queries.ga_property_id')
                    ->on('property_snapshots.snapshot_date', '=', 'property_search_queries.date');
            })
            ->select([
                'property_snapshots.id as property_snapshot_id',
                'property_search_queries.id',
                'property_search_queries.query',
                'property_search_queries.page',
                'property_search_queries.clicks',
                'property_search_queries.impressions',
                'property_search_queries.ctr',
                'property_search_queries.position',
                'property_search_queries.created_at',
                'property_search_queries.updated_at',
            ])
            ->orderBy('property_search_queries.id')
            ->chunk(500, function (Collection $rows) {
                DB::table('property_snapshot_search_queries')->insert($rows->map(fn (object $row): array => [
                    'property_snapshot_id' => $row->property_snapshot_id,
                    'query' => $row->query,
                    'page' => mb_substr((string) $row->page, 0, 255),
                    'clicks' => $row->clicks,
                    'impressions' => $row->impressions,
                    'ctr' => $row->ctr,
                    'position' => $row->position,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ])->all());
            });
    }
};
