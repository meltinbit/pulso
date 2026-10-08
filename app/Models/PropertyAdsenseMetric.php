<?php

namespace App\Models;

use Database\Factories\PropertyAdsenseMetricFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One day of AdSense metrics for a property, either the day total
 * (dimension "total") or a single breakdown row (country, platform, ad unit).
 */
class PropertyAdsenseMetric extends Model
{
    /** @use HasFactory<PropertyAdsenseMetricFactory> */
    use HasFactory;

    public const DIMENSION_TOTAL = 'total';

    public const DIMENSION_COUNTRY = 'country';

    public const DIMENSION_PLATFORM = 'platform';

    public const DIMENSION_AD_UNIT = 'ad_unit';

    /**
     * Local dimension name => AdSense report dimension (null for the day total).
     *
     * @var array<string, string|null>
     */
    public const DIMENSIONS = [
        self::DIMENSION_TOTAL => null,
        self::DIMENSION_COUNTRY => 'COUNTRY_NAME',
        self::DIMENSION_PLATFORM => 'PLATFORM_TYPE_NAME',
        self::DIMENSION_AD_UNIT => 'AD_UNIT_NAME',
    ];

    protected $fillable = [
        'ga_property_id',
        'date',
        'dimension',
        'dimension_value',
        'earnings',
        'page_views',
        'impressions',
        'clicks',
        'ad_requests',
        'matched_ad_requests',
    ];

    protected function casts(): array
    {
        return [
            'earnings' => 'decimal:4',
            'page_views' => 'integer',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'ad_requests' => 'integer',
            'matched_ad_requests' => 'integer',
        ];
    }

    /**
     * Stored as a plain `Y-m-d` string on every driver, like the Search
     * Console rows, so Eloquent writes and bulk inserts compare equal.
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?Carbon => $value === null ? null : Carbon::parse($value)->startOfDay(),
            set: fn (Carbon|string $value): string => $value instanceof Carbon ? $value->toDateString() : Carbon::parse($value)->toDateString(),
        );
    }

    public function gaProperty(): BelongsTo
    {
        return $this->belongsTo(GaProperty::class);
    }
}
