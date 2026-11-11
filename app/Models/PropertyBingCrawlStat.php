<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bing crawl stats of a property for one day: pages crawled, pages in the
 * index, HTTP status counts and crawl errors.
 */
class PropertyBingCrawlStat extends Model
{
    public const COUNTERS = [
        'crawled_pages' => 'CrawledPages',
        'in_index' => 'InIndex',
        'in_links' => 'InLinks',
        'crawl_errors' => 'CrawlErrors',
        'code_2xx' => 'Code2xx',
        'code_301' => 'Code301',
        'code_302' => 'Code302',
        'code_4xx' => 'Code4xx',
        'code_5xx' => 'Code5xx',
        'blocked_by_robots_txt' => 'BlockedByRobotsTxt',
        'dns_failures' => 'DnsFailures',
        'connection_timeout' => 'ConnectionTimeout',
        'contains_malware' => 'ContainsMalware',
        'all_other_codes' => 'AllOtherCodes',
    ];

    public $timestamps = false;

    /** Dates are plain days: stored without a time so day ranges match on every driver. */
    protected $dateFormat = 'Y-m-d';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date'] + array_fill_keys(array_keys(self::COUNTERS), 'integer');
    }

    public function gaProperty(): BelongsTo
    {
        return $this->belongsTo(GaProperty::class);
    }
}
