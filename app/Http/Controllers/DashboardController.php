<?php

namespace App\Http\Controllers;

use App\Services\ConnectionHealthService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin home: the health of connections and data, not analytics. Reports are
 * produced by the agents through MCP.
 */
class DashboardController extends Controller
{
    public function __construct(
        private ConnectionHealthService $health,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('dashboard/health', [
            'health' => $this->health->report($request->user()),
        ]);
    }
}
