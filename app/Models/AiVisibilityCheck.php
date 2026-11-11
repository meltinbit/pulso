<?php

namespace App\Models;

use Database\Factories\AiVisibilityCheckFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer of an AI engine to a tracked prompt: whether the property was
 * cited, at which position among the cited sites, and what it cost.
 */
class AiVisibilityCheck extends Model
{
    /** @use HasFactory<AiVisibilityCheckFactory> */
    use HasFactory;

    protected $fillable = [
        'ai_visibility_prompt_id',
        'ga_property_id',
        'run_date',
        'engine',
        'model',
        'mentioned',
        'position',
        'cited_url',
        'cited_domains',
        'answer_excerpt',
        'input_tokens',
        'output_tokens',
        'searches',
        'cost_usd',
    ];

    protected function casts(): array
    {
        return [
            'run_date' => 'date:Y-m-d',
            'mentioned' => 'boolean',
            'cited_domains' => 'array',
            'cost_usd' => 'float',
        ];
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(AiVisibilityPrompt::class, 'ai_visibility_prompt_id');
    }

    public function gaProperty(): BelongsTo
    {
        return $this->belongsTo(GaProperty::class);
    }
}
