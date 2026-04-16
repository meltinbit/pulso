<?php

namespace App\Models;

use Database\Factories\PropertySnapshotSearchQueryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertySnapshotSearchQuery extends Model
{
    /** @use HasFactory<PropertySnapshotSearchQueryFactory> */
    use HasFactory;

    protected $fillable = [
        'property_snapshot_id',
        'query',
        'page',
        'clicks',
        'impressions',
        'ctr',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'clicks' => 'integer',
            'impressions' => 'integer',
            'ctr' => 'decimal:2',
            'position' => 'decimal:1',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PropertySnapshot::class, 'property_snapshot_id');
    }
}
