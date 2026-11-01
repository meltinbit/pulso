<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Number of Google Ads API operations spent on a given day.
 */
class GoogleAdsDailyUsage extends Model
{
    protected $table = 'google_ads_daily_usage';

    protected $fillable = [
        'date',
        'operations',
    ];

    protected function casts(): array
    {
        return [
            'operations' => 'integer',
        ];
    }
}
