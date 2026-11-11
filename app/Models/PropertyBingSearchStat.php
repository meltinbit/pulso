<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bing stats of one query or one page for one week (week_start is a Friday,
 * the week runs to the following Thursday).
 */
class PropertyBingSearchStat extends Model
{
    public const DIMENSION_QUERY = 'query';

    public const DIMENSION_PAGE = 'page';

    public $timestamps = false;

    /** Dates are plain days: stored without a time so day ranges match on every driver. */
    protected $dateFormat = 'Y-m-d';

    protected $fillable = [
        'ga_property_id',
        'dimension',
        'week_start',
        'value',
        'clicks',
        'impressions',
        'avg_impression_position',
        'avg_click_position',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'clicks' => 'integer',
            'impressions' => 'integer',
            'avg_impression_position' => 'float',
            'avg_click_position' => 'float',
        ];
    }

    public function gaProperty(): BelongsTo
    {
        return $this->belongsTo(GaProperty::class);
    }
}
