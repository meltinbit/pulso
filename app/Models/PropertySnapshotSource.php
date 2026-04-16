<?php

namespace App\Models;

use Database\Factories\PropertySnapshotSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertySnapshotSource extends Model
{
    /** @use HasFactory<PropertySnapshotSourceFactory> */
    use HasFactory;

    protected $fillable = [
        'property_snapshot_id',
        'source',
        'medium',
        'sessions',
        'users',
    ];

    protected function casts(): array
    {
        return [
            'sessions' => 'integer',
            'users' => 'integer',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PropertySnapshot::class, 'property_snapshot_id');
    }
}
