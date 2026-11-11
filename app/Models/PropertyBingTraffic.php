<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bing clicks and impressions of a property for one day.
 */
class PropertyBingTraffic extends Model
{
    public $timestamps = false;

    /** Dates are plain days: stored without a time so day ranges match on every driver. */
    protected $dateFormat = 'Y-m-d';

    protected $table = 'property_bing_traffic';

    protected $fillable = ['ga_property_id', 'date', 'clicks', 'impressions'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'clicks' => 'integer',
            'impressions' => 'integer',
        ];
    }

    public function gaProperty(): BelongsTo
    {
        return $this->belongsTo(GaProperty::class);
    }
}
