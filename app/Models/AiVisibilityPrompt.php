<?php

namespace App\Models;

use Database\Factories\AiVisibilityPromptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A question a real user could ask an AI assistant, tracked over time to see
 * whether the answer cites the property.
 */
class AiVisibilityPrompt extends Model
{
    /** @use HasFactory<AiVisibilityPromptFactory> */
    use HasFactory;

    protected $fillable = [
        'ga_property_id',
        'prompt',
        'is_active',
        'agent',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function gaProperty(): BelongsTo
    {
        return $this->belongsTo(GaProperty::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(AiVisibilityCheck::class);
    }
}
