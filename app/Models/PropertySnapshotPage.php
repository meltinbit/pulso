<?php

namespace App\Models;

use Database\Factories\PropertySnapshotPageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertySnapshotPage extends Model
{
    /** @use HasFactory<PropertySnapshotPageFactory> */
    use HasFactory;

    protected $fillable = [
        'property_snapshot_id',
        'page_path',
        'page_title',
        'pageviews',
        'users',
        'bounce_rate',
        'avg_engagement_time',
        'engagement_rate',
    ];

    protected function casts(): array
    {
        return [
            'pageviews' => 'integer',
            'users' => 'integer',
            'bounce_rate' => 'decimal:2',
            'avg_engagement_time' => 'integer',
            'engagement_rate' => 'decimal:2',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PropertySnapshot::class, 'property_snapshot_id');
    }
}
