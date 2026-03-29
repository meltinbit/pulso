<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FunnelStep extends Model
{
    /** @use HasFactory<\Database\Factories\FunnelStepFactory> */
    use HasFactory;

    protected $fillable = [
        'funnel_id',
        'order',
        'name',
        'event_name',
        'conditions',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'conditions' => 'array',
        ];
    }

    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }
}
