<?php

namespace App\Models;

use Database\Factories\PropertySnapshotEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertySnapshotEvent extends Model
{
    /** @use HasFactory<PropertySnapshotEventFactory> */
    use HasFactory;

    protected $fillable = [
        'property_snapshot_id',
        'event_name',
        'event_count',
        'total_users',
    ];

    protected function casts(): array
    {
        return [
            'event_count' => 'integer',
            'total_users' => 'integer',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PropertySnapshot::class, 'property_snapshot_id');
    }
}
