<?php

namespace App\Http\Controllers;

use App\Models\McpCallLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * MCP calls per tool and agent, to see which tools weigh most on the agents'
 * context and which ones fail.
 */
class McpLogController extends Controller
{
    private const PERIODS = [1, 7, 30, 90];

    public function index(Request $request): Response
    {
        $days = in_array($request->integer('days'), self::PERIODS, true) ? $request->integer('days') : 7;
        $since = now()->subDays($days);

        $byTool = McpCallLog::query()
            ->where('created_at', '>=', $since)
            ->select('tool')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('SUM(CASE WHEN is_error THEN 1 ELSE 0 END) as errors')
            ->selectRaw('ROUND(AVG(response_bytes)) as avg_bytes')
            ->selectRaw('MAX(response_bytes) as max_bytes')
            ->selectRaw('SUM(response_bytes) as total_bytes')
            ->selectRaw('ROUND(AVG(duration_ms)) as avg_ms')
            ->groupBy('tool')
            ->orderByDesc('total_bytes')
            ->get();

        $byAgent = McpCallLog::query()
            ->where('created_at', '>=', $since)
            ->select(DB::raw("COALESCE(agent, '') as agent"))
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('SUM(CASE WHEN is_error THEN 1 ELSE 0 END) as errors')
            ->selectRaw('SUM(response_bytes) as total_bytes')
            ->groupBy(DB::raw("COALESCE(agent, '')"))
            ->orderByDesc('calls')
            ->get();

        $recent = McpCallLog::query()
            ->where('created_at', '>=', $since)
            ->when($request->boolean('errors'), fn ($query) => $query->where('is_error', true))
            ->latest('id')
            ->limit(100)
            ->get();

        return Inertia::render('mcp-log/index', [
            'days' => $days,
            'periods' => self::PERIODS,
            'onlyErrors' => $request->boolean('errors'),
            'byTool' => $byTool,
            'byAgent' => $byAgent,
            'recent' => $recent,
        ]);
    }
}
