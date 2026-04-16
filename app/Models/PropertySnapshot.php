<?php

namespace App\Models;

use Database\Factories\PropertySnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertySnapshot extends Model
{
    /** @use HasFactory<PropertySnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'ga_property_id',
        'snapshot_date',
        'period',
        'users',
        'sessions',
        'pageviews',
        'bounce_rate',
        'avg_session_duration',
        'top_sources',
        'users_delta_wow',
        'sessions_delta_wow',
        'pageviews_delta_wow',
        'bounce_delta_wow',
        'users_delta_30d',
        'sessions_delta_30d',
        'trend',
        'trend_score',
        'is_spike',
        'is_drop',
        'is_stall',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'users' => 'integer',
            'sessions' => 'integer',
            'pageviews' => 'integer',
            'bounce_rate' => 'decimal:2',
            'avg_session_duration' => 'integer',
            'top_sources' => 'array',
            'users_delta_wow' => 'decimal:2',
            'sessions_delta_wow' => 'decimal:2',
            'pageviews_delta_wow' => 'decimal:2',
            'bounce_delta_wow' => 'decimal:2',
            'users_delta_30d' => 'decimal:2',
            'sessions_delta_30d' => 'decimal:2',
            'trend_score' => 'decimal:2',
            'is_spike' => 'boolean',
            'is_drop' => 'boolean',
            'is_stall' => 'boolean',
        ];
    }

    public function gaProperty(): BelongsTo
    {
        return $this->belongsTo(GaProperty::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(PropertySnapshotSource::class);
    }
}
