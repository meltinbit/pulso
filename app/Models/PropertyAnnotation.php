<?php

namespace App\Models;

use Database\Factories\PropertyAnnotationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change made to a site on a day (content, technical fix, campaign or
 * deploy), written by an agent or by hand, to explain the trends around it.
 */
class PropertyAnnotation extends Model
{
    /** @use HasFactory<PropertyAnnotationFactory> */
    use HasFactory;

    public const TYPES = ['content', 'technical', 'campaign', 'deploy'];

    protected $fillable = [
        'ga_property_id',
        'date',
        'type',
        'description',
        'urls',
        'agent',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'urls' => 'array',
        ];
    }

    public function gaProperty(): BelongsTo
    {
        return $this->belongsTo(GaProperty::class);
    }

    /**
     * Compact form returned by the MCP tools: nulls left out.
     *
     * @return array{id: int, date: string, type: string, description: string, urls?: list<string>, agent?: string}
     */
    public function toCompactArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'type' => $this->type,
            'description' => $this->description,
            'urls' => $this->urls ?: null,
            'agent' => $this->agent,
        ], fn (mixed $value): bool => $value !== null);
    }
}
