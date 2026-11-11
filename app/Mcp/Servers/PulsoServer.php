<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddAnnotationTool;
use App\Mcp\Tools\GetAdsCampaignsTool;
use App\Mcp\Tools\GetAdsSearchTermsTool;
use App\Mcp\Tools\GetKeywordGapTool;
use App\Mcp\Tools\GetKeywordIdeasTool;
use App\Mcp\Tools\GetKeywordMetricsTool;
use App\Mcp\Tools\GetMetaAdsTool;
use App\Mcp\Tools\GetMetaBreakdownTool;
use App\Mcp\Tools\GetMetaCampaignsTool;
use App\Mcp\Tools\GetPaidOrganicGapTool;
use App\Mcp\Tools\GetPropertyAdSenseTool;
use App\Mcp\Tools\GetPropertyBingSearchTool;
use App\Mcp\Tools\GetPropertyBingTrafficTool;
use App\Mcp\Tools\GetPropertyEventsTool;
use App\Mcp\Tools\GetPropertyFunnelTool;
use App\Mcp\Tools\GetPropertyIndexStatusTool;
use App\Mcp\Tools\GetPropertyPagesTool;
use App\Mcp\Tools\GetPropertySearchQueriesTool;
use App\Mcp\Tools\GetPropertySnapshotsTool;
use App\Mcp\Tools\GetPropertySourcesTool;
use App\Mcp\Tools\GetPropertySummaryTool;
use App\Mcp\Tools\ListAdsAccountsTool;
use App\Mcp\Tools\ListAnnotationsTool;
use App\Mcp\Tools\ListMetaAdAccountsTool;
use App\Mcp\Tools\ListPropertiesTool;
use App\Mcp\Tools\SearchGeoTargetsTool;
use App\Mcp\Tools\SubmitUrlsToBingTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Pulso')]
#[Version('1.0.0')]
#[Instructions('Pulso is a GA4 analytics dashboard with Google Search Console, Bing Webmaster Tools and Google AdSense integrations. It stores daily snapshots of GA4 properties with metrics (users, sessions, pageviews, bounce rate, engagement rate), week-over-week and 30-day deltas, trend analysis, anomaly detection, traffic sources, top pages, URL index inspection status (indexed vs excluded with coverage reasons), and GA4 events (standard and custom, e.g. calcolo_eseguito, feedback_calcolatore). Search Console query data (clicks, impressions, CTR, position) is stored separately from the snapshots, as complete per-day query/page rows going back up to 16 months. AdSense revenue (estimated earnings, page views, impressions, clicks, ad requests, with derived RPM, CTR, CPC and coverage) is stored per day for each property, filtered to the domain of the property website URL, with breakdowns by country, platform and ad unit.

Workflow: start with list-properties for an overview, then use get-property-summary for a single property deep dive. For trend analysis over time, use get-property-snapshots, get-property-sources, get-property-pages, get-property-search-queries, get-property-events and get-property-adsense with date ranges (from/to). Use get-property-funnel to measure conversion between ordered GA4 events or page visits (live from GA4, not snapshots). Use get-property-index-status for URL-level indexing diagnostics, either by passing explicit URLs or by discovering them from the sitemap. All range tools default to 30 days but accept custom ranges for deeper analysis.

Note: Search Console data has a 2-3 day delay from Google, so the most recent days may not have search query data yet, and the last few days keep being revised upward as Google consolidates them. The summary tool automatically falls back to the latest day that has search queries. Because query rows are stored per day rather than as a truncated top list, get-property-search-queries sums a date range correctly: totals are real range totals, and CTR and position are weighted by impressions. Landing pages are returned as full URLs; get-property-search-queries can be filtered to one page or a URL prefix (full URL or path such as /blog/) and to queries containing a text, with up to 500 results. URL inspection is live URL-level data from Search Console, not a historical snapshot, and Google does not expose the full Page Indexing report in bulk via API. GA4 events are captured per daily snapshot (top 50 by eventCount) — rare custom events with very low counts may not appear. AdSense data includes partial earnings for today, and estimated earnings for the last few days are still adjusted by Google; amounts are in the property currency.

Change log: every site has annotations (content, technical, campaign, deploy). Call add-annotation whenever you publish or modify something on a site, with the day it went live. The trend tools (snapshots, sources, pages, events, search queries, AdSense, Bing; summary for the last 30 days) return the annotations of their range, so causes and effects come in one call; use list-annotations to check past changes before proposing an experiment.

Bing Webmaster Tools: get-property-bing-search returns Bing queries or pages (weekly data, Friday to Thursday, so ranges are widened to whole weeks), get-property-bing-traffic returns daily clicks and impressions with the previous period and crawl health (pages in the Bing index, crawl errors, 4xx/5xx), and get-property-summary includes a Bing section. Bing feeds Copilot, DuckDuckGo and several AI search engines, so it doubles as a GEO signal. submit-urls-to-bing asks Bing to recrawl changed pages, within a small daily quota per site. Bing data lags about 3 days.

Keyword research (Google Ads Keyword Planner): use get-keyword-ideas for ideas from seeds or a URL, get-keyword-metrics to check specific keywords with 12 monthly volumes, and get-keyword-gap to see which ideas the property already ranks for in Search Console (last 90 days) and which are missing. Use search-geo-targets to resolve a place (e.g. a province vs a city) to a geo target ID. Defaults are Italian and Italy. Volumes are Google approximations, results are cached for 30 days, and keyword output is columnar (cols + rows) to save tokens.

Google Ads (read only): list-ads-accounts lists the accounts, get-ads-campaigns returns campaign cost, conversions, CPA and ROAS with the previous period, get-ads-search-terms returns the real queries behind the ads (spend without conversions included, Performance Max excluded), and get-paid-organic-gap crosses the converting search terms with Search Console to find queries that convert in Ads but have no strong organic page (only ad groups landing on the property domain; get-ads-search-terms takes the same filter as site). Ads tools default to complete days (up to yesterday) and amounts are in the account currency.

Meta Ads, Facebook and Instagram (read only): list-meta-ad-accounts lists the ad accounts, get-meta-campaigns returns campaign cost, reach, frequency, link clicks, conversions, CPA and ROAS with the previous period, get-meta-ads compares ads or ad sets, and get-meta-breakdown splits results by age, gender, country, region, placement or device. Conversions are one Meta action type: the tools pick the most meaningful one the account has (purchase, registration, lead, app install...) and say which; pass conversion_action to choose another from available_actions. Same field names as the Google Ads tools, so the two platforms can be compared directly.')]
class PulsoServer extends Server
{
    protected array $tools = [
        ListPropertiesTool::class,
        AddAnnotationTool::class,
        ListAnnotationsTool::class,
        GetPropertySnapshotsTool::class,
        GetPropertySourcesTool::class,
        GetPropertyPagesTool::class,
        GetPropertySearchQueriesTool::class,
        GetPropertyIndexStatusTool::class,
        GetPropertyEventsTool::class,
        GetPropertyFunnelTool::class,
        GetPropertyAdSenseTool::class,
        GetPropertySummaryTool::class,
        GetPropertyBingSearchTool::class,
        GetPropertyBingTrafficTool::class,
        SubmitUrlsToBingTool::class,
        GetKeywordIdeasTool::class,
        GetKeywordMetricsTool::class,
        GetKeywordGapTool::class,
        SearchGeoTargetsTool::class,
        ListAdsAccountsTool::class,
        GetAdsCampaignsTool::class,
        GetAdsSearchTermsTool::class,
        GetPaidOrganicGapTool::class,
        ListMetaAdAccountsTool::class,
        GetMetaCampaignsTool::class,
        GetMetaAdsTool::class,
        GetMetaBreakdownTool::class,
    ];
}
