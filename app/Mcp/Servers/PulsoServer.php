<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetPropertyEventsTool;
use App\Mcp\Tools\GetPropertyPagesTool;
use App\Mcp\Tools\GetPropertySearchQueriesTool;
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
#[Instructions('Pulso is a GA4 analytics dashboard with Google Search Console integration. It stores daily snapshots of GA4 properties with metrics (users, sessions, pageviews, bounce rate, engagement rate), week-over-week and 30-day deltas, trend analysis, anomaly detection, traffic sources, top pages, search queries (clicks, impressions, CTR, position), and GA4 events (standard and custom, e.g. calcolo_eseguito, feedback_calcolatore).

Workflow: start with list-properties for an overview, then use get-property-summary for a single property deep dive. For trend analysis over time, use get-property-snapshots, get-property-sources, get-property-pages, get-property-search-queries, and get-property-events with date ranges (from/to). All range tools default to 30 days but accept custom ranges for deeper analysis.

Note: Search Console data has a 2-3 day delay from Google, so the most recent days may not have search query data. The summary tool automatically falls back to the latest snapshot that has search queries. GA4 events are captured per daily snapshot (top 50 by eventCount) — rare custom events with very low counts may not appear.')]
class PulsoServer extends Server
{
    protected array $tools = [
        ListPropertiesTool::class,
        GetPropertySnapshotsTool::class,
        GetPropertySourcesTool::class,
        GetPropertyPagesTool::class,
        GetPropertySearchQueriesTool::class,
        GetPropertyEventsTool::class,
        GetPropertySummaryTool::class,
    ];
}
