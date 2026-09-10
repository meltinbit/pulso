<?php

namespace App\Models;

use Database\Factories\PropertySearchQueryFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single Search Console (date, query, page) row for a property.
 */
class PropertySearchQuery extends Model
{
    /** @use HasFactory<PropertySearchQueryFactory> */
    use HasFactory;

    protected $fillable = [
        'ga_property_id',
        'date',
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

    /**
     * Stored as a plain `Y-m-d` string on every driver, so that rows written
     * through Eloquent and rows bulk inserted by the sync service compare
     * equal.
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
