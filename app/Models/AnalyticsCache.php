<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsCache extends Model
{
    protected $table = 'analytics_cache';

    protected $fillable = [
        'ga_property_id',
        'cache_key',
        'report_type',
        'payload',
        'params',
        'tokens_used',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'params' => 'array',
            'tokens_used' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function gaProperty(): BelongsTo
    {
        return $this->belongsTo(GaProperty::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
