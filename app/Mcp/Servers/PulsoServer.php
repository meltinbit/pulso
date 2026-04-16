<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetPropertySnapshotsTool;
use App\Mcp\Tools\GetPropertySourcesTool;
use App\Mcp\Tools\GetPropertySummaryTool;
use App\Mcp\Tools\ListPropertiesTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Pulso')]
#[Version('1.0.0')]
#[Instructions('Pulso is a GA4 analytics dashboard. This server exposes daily snapshots of GA4 properties with metrics (users, sessions, pageviews, bounce rate), week-over-week and 30-day deltas, trend analysis (spike/improved/stall/declined/drop), anomaly detection, and traffic source breakdowns. Use these tools to analyze website performance trends, identify anomalies, and provide actionable insights for each property.')]
class PulsoServer extends Server
{
    protected array $tools = [
        ListPropertiesTool::class,
        GetPropertySnapshotsTool::class,
        GetPropertySourcesTool::class,
        GetPropertySummaryTool::class,
    ];
}
