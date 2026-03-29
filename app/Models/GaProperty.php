<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GaProperty extends Model
{
    /** @use HasFactory<\Database\Factories\GaPropertyFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'ga_connection_id',
        'property_id',
        'display_name',
        'website_url',
        'timezone',
        'currency',
        'is_active',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    protected $attributes = [
        'timezone' => 'Europe/Rome',
        'currency' => 'EUR',
        'is_active' => true,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function gaConnection(): BelongsTo
    {
        return $this->belongsTo(GaConnection::class);
    }

    public function funnels(): HasMany
    {
        return $this->hasMany(Funnel::class);
    }

    public function analyticsCache(): HasMany
    {
        return $this->hasMany(AnalyticsCache::class);
    }
}
