<?php

namespace App\Models;

use Database\Factories\GaPropertyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GaProperty extends Model
{
    /** @use HasFactory<GaPropertyFactory> */
    use HasFactory;

    protected $hidden = [
        'crawler_token_hash',
    ];

    protected $fillable = [
        'user_id',
        'ga_connection_id',
        'property_id',
        'display_name',
        'website_url',
        'gsc_site_url',
        'bing_site_url',
        'ai_brand_terms',
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
            'ai_brand_terms' => 'array',
        ];
    }

    protected $attributes = [
        'timezone' => 'Europe/Rome',
        'currency' => 'EUR',
        'is_active' => true,
    ];

    /**
     * Whether the property is backed by a GA4 property. Search Console only
     * properties have no GA4 data: no snapshots, events or funnels.
     */
    public function hasAnalytics(): bool
    {
        return $this->property_id !== null;
    }

    /**
     * @param  Builder<GaProperty>  $query
     */
    public function scopeWithAnalytics(Builder $query): void
    {
        $query->whereNotNull('property_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function gaConnection(): BelongsTo
    {
        return $this->belongsTo(GaConnection::class);
    }

    public function analyticsCache(): HasMany
    {
        return $this->hasMany(AnalyticsCache::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(PropertySnapshot::class);
    }

    public function searchQueries(): HasMany
    {
        return $this->hasMany(PropertySearchQuery::class);
    }

    public function adsenseMetrics(): HasMany
    {
        return $this->hasMany(PropertyAdsenseMetric::class);
    }

    public function bingTraffic(): HasMany
    {
        return $this->hasMany(PropertyBingTraffic::class);
    }

    public function bingSearchStats(): HasMany
    {
        return $this->hasMany(PropertyBingSearchStat::class);
    }

    public function bingCrawlStats(): HasMany
    {
        return $this->hasMany(PropertyBingCrawlStat::class);
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(PropertyAnnotation::class);
    }

    public function aiVisibilityPrompts(): HasMany
    {
        return $this->hasMany(AiVisibilityPrompt::class);
    }

    public function aiVisibilityChecks(): HasMany
    {
        return $this->hasMany(AiVisibilityCheck::class);
    }

    public function aiCrawlerVisits(): HasMany
    {
        return $this->hasMany(AiCrawlerVisit::class);
    }
}
