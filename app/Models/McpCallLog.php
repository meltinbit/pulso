<?php

namespace App\Models;

use Database\Factories\McpCallLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * One MCP tools/call request: which tool, which agent, how heavy the answer
 * was for the agent's context and whether it failed. Kept for 90 days.
 */
class McpCallLog extends Model
{
    /** @use HasFactory<McpCallLogFactory> */
    use HasFactory, Prunable;

    public const UPDATED_AT = null;

    public const RETENTION_DAYS = 90;

    protected $fillable = [
        'tool',
        'agent',
        'arguments',
        'response_bytes',
        'is_error',
        'error',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'response_bytes' => 'integer',
            'is_error' => 'boolean',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * @return Builder<McpCallLog>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
