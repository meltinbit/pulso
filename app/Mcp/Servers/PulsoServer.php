<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetPropertyAdSenseTool;
use App\Mcp\Tools\GetPropertyEventsTool;
use App\Mcp\Tools\GetPropertyIndexStatusTool;
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
#[Instructions('Pulso is a GA4 analytics dashboard with Google Search Console and Google AdSense integrations. It stores daily snapshots of GA4 properties with metrics (users, sessions, pageviews, bounce rate, engagement rate), week-over-week and 30-day deltas, trend analysis, anomaly detection, traffic sources, top pages, URL index inspection status (indexed vs excluded with coverage reasons), and GA4 events (standard and custom, e.g. calcolo_eseguito, feedback_calcolatore). Search Console query data (clicks, impressions, CTR, position) is stored separately from the snapshots, as complete per-day query/page rows going back up to 16 months. AdSense revenue (estimated earnings, page views, impressions, clicks, ad requests, with derived RPM, CTR, CPC and coverage) is stored per day for each property, filtered to the domain of the property website URL, with breakdowns by country, platform and ad unit.

Workflow: start with list-properties for an overview, then use get-property-summary for a single property deep dive. For trend analysis over time, use get-property-snapshots, get-property-sources, get-property-pages, get-property-search-queries, get-property-events and get-property-adsense with date ranges (from/to). Use get-property-index-status for URL-level indexing diagnostics, either by passing explicit URLs or by discovering them from the sitemap. All range tools default to 30 days but accept custom ranges for deeper analysis.

Note: Search Console data has a 2-3 day delay from Google, so the most recent days may not have search query data yet, and the last few days keep being revised upward as Google consolidates them. The summary tool automatically falls back to the latest day that has search queries. Because query rows are stored per day rather than as a truncated top list, get-property-search-queries sums a date range correctly: totals are real range totals, and CTR and position are weighted by impressions. Landing pages are returned as full URLs. URL inspection is live URL-level data from Search Console, not a historical snapshot, and Google does not expose the full Page Indexing report in bulk via API. GA4 events are captured per daily snapshot (top 50 by eventCount) — rare custom events with very low counts may not appear. AdSense data includes partial earnings for today, and estimated earnings for the last few days are still adjusted by Google; amounts are in the property currency.')]
class PulsoServer extends Server
{
    protected array $tools = [
        ListPropertiesTool::class,
        GetPropertySnapshotsTool::class,
        GetPropertySourcesTool::class,
        GetPropertyPagesTool::class,
        GetPropertySearchQueriesTool::class,
        GetPropertyIndexStatusTool::class,
        GetPropertyEventsTool::class,
        GetPropertyAdSenseTool::class,
        GetPropertySummaryTool::class,
    ];
}
