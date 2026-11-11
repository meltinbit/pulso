<?php

namespace App\Models;

use Database\Factories\AiCrawlerVisitFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visits of one AI crawler to one page of a property on one day, reported
 * by the site itself (see the ReportAiCrawlers middleware in the README).
 */
class AiCrawlerVisit extends Model
{
    /** @use HasFactory<AiCrawlerVisitFactory> */
    use HasFactory, MassPrunable;

    /** Days of history kept. */
    public const RETENTION_DAYS = 400;

    protected $fillable = [
        'ga_property_id',
        'date',
        'bot',
        'path',
        'path_hash',
        'hits',
        'verified_hits',
        'spoofed_hits',
        'last_status',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'last_seen_at' => 'datetime',
        ];
    }

    public function gaProperty(): BelongsTo
    {
        return $this->belongsTo(GaProperty::class);
    }

    public function prunable(): Builder
    {
        return static::where('date', '<', now()->subDays(self::RETENTION_DAYS)->toDateString());
    }
}
