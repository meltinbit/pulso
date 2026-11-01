<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A cached Google Ads keyword planning response, keyed by a hash of its input.
 */
class KeywordResearchCache extends Model
{
    protected $table = 'keyword_research_cache';

    protected $fillable = [
        'cache_key',
        'operation',
        'params',
        'payload',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'payload' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
