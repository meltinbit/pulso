# Pulso

![Pulso](public/pulso.png)

GA4 Dashboard SaaS for agencies and freelancers. Built with Laravel 12, Inertia.js v2, React 19, shadcn/ui, and Tailwind CSS v4. Includes daily analytics snapshots with trend analysis, Google Search Console integration, Telegram notifications, and an MCP server for AI-powered insights via Claude.ai.

## Requirements

- PHP 8.2+
- MySQL 8.0+
- Node.js 20+
- Composer

## Installation

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

## Development

```bash
# Start all services
composer run dev

# Or separately
php artisan serve --port=8002
npm run dev
```

## Docker

Run the app with Docker Compose (includes MySQL 8.0):

```bash
# Build and start
docker compose up -d
```

The app will be available at `http://localhost:8123`.

You can set the database password via environment variable:

```bash
DB_PASSWORD=mypassword docker compose up -d
```

Data is persisted in Docker volumes (`mysql-data` for the database, `app-storage` for uploads/logs). The app container includes Nginx, PHP-FPM, queue worker, and scheduler.

To stop:
```bash
docker compose down        # keeps data
docker compose down -v     # removes data volumes
```

## Google Cloud Console Setup

### 1. Create a Google Cloud Project

- Go to [console.cloud.google.com](https://console.cloud.google.com)
- Create a new project (e.g. "Pulso")

### 2. Enable Required APIs

Enable **all three** APIs. You can use the direct links below (replace `YOUR_PROJECT_ID` with your numeric project ID) or find them in APIs & Services > Library:

| API | Purpose | Direct Enable Link |
|-----|---------|-------------------|
| **Google Analytics Data API** | Fetch reports, metrics, realtime data | `https://console.developers.google.com/apis/api/analyticsdata.googleapis.com/overview?project=YOUR_PROJECT_ID` |
| **Google Analytics Admin API** | List accessible GA4 properties | `https://console.developers.google.com/apis/api/analyticsadmin.googleapis.com/overview?project=YOUR_PROJECT_ID` |
| **Google Search Console API** | Fetch search queries, impressions, CTR, position | `https://console.developers.google.com/apis/api/searchconsole.googleapis.com/overview?project=YOUR_PROJECT_ID` |

> **Important:** All three APIs must be enabled. The Admin API is needed to list properties, the Data API for analytics data, and the Search Console API for search query data. If any is missing you'll get a 403 "SERVICE_DISABLED" error. After enabling, wait 1-2 minutes for propagation.

### 3. Configure OAuth Consent Screen

Go to APIs & Services > OAuth consent screen:

- **User Type:** External
- **App name:** Pulso
- **Scopes** — add these three:

| Scope | Purpose |
|-------|---------|
| `https://www.googleapis.com/auth/analytics.readonly` | Read GA4 report data |
| `https://www.googleapis.com/auth/analytics.edit` | Read property list from Admin API (read-only despite the name) |
| `https://www.googleapis.com/auth/webmasters.readonly` | Read Search Console data (queries, impressions, CTR, position) |

- **Test users:** Add any Gmail accounts that will use the app during development

### 4. Create OAuth Credentials

Go to APIs & Services > Credentials > Create Credentials > OAuth Client ID:

- **Application type:** Web application
- **Authorized redirect URIs:**

```
http://127.0.0.1:8002/auth/google/callback
http://localhost:8002/auth/google/callback
http://localhost:8123/auth/google/callback
```

> **Note:** Add both `127.0.0.1` and `localhost` variants. For Docker, use port `8123`. For local dev, use port `8002`. The port must match your `APP_URL` in `.env`.

Copy the **Client ID** and **Client Secret**.

### 5. Save Credentials in the App

Credentials are stored in the database, not in `.env`:

1. Log in to the app
2. Go to **Settings > Google**
3. Enter Client ID and Client Secret
4. Click Save
5. Click **Connect Google Account**

## Environment Variables

Google, Telegram, and snapshot settings are managed per-user via the UI (**Settings**). The only `.env` values are cache TTLs:

```env
# Cache TTL in seconds
GA_CACHE_TTL_CORE=3600        # 1 hour for standard reports
GA_CACHE_TTL_REALTIME=60      # 1 minute for realtime
GA_CACHE_TTL_FUNNEL=7200      # 2 hours for funnel reports
```

## Daily Snapshots

Pulso generates daily analytics snapshots for each monitored GA4 property. Snapshots include:

- **Core metrics**: users, sessions, pageviews, bounce rate, engagement rate, pages per session
- **Deltas**: week-over-week and 30-day comparisons
- **Trend analysis**: spike, improved, stall, declined, drop (with composite score)
- **Traffic sources**: top 10 sources with sessions and users
- **Top pages**: top 20 pages with per-page bounce rate and engagement
- **Search queries**: top 20 Google Search Console queries with clicks, impressions, CTR, position

### Configuration (Settings > Snapshots)

All snapshot settings are per-user and configurable from the UI:

- **Enable/disable** daily snapshot generation
- **Schedule time** (UTC) — default 09:00
- **Telegram notifications** — on/off per user
- **Properties** — select which properties to include

### Scheduled Jobs

| Job | Schedule | Purpose |
|-----|----------|---------|
| `RefreshAnalyticsCache` | 02:00 UTC | Refreshes the GA4 analytics cache |
| `GenerateDailySnapshots` | 09:00 UTC | Generates daily snapshots per user, sends Telegram if enabled |

The snapshot job iterates over all users, checking each user's settings for enabled/disabled, active properties, and Telegram preferences.

### Manual Snapshot Generation

Generate snapshots manually via the UI (**Snapshots > Generate now**) or CLI:

```bash
# Yesterday (default)
php artisan snapshots:generate

# Specific date range (backfill)
php artisan snapshots:generate --from=2026-03-17 --to=2026-04-15

# Single property, no Telegram notification
php artisan snapshots:generate --from=2026-04-01 --to=2026-04-15 --property=3 --no-telegram
```

| Option | Description |
|--------|-------------|
| `--from` | Start date, YYYY-MM-DD (default: yesterday) |
| `--to` | End date, YYYY-MM-DD (default: same as `--from`) |
| `--property` | Generate only for a specific property ID |
| `--no-telegram` | Skip Telegram digest |

> **Note:** Google Search Console data has a 2-3 day delay. Snapshots for the most recent days won't include search query data.

## Telegram Notifications

Pulso can send daily digest reports via Telegram after snapshot generation.

### Setup (Settings > Telegram)

1. Create a bot via [@BotFather](https://t.me/BotFather) and copy the **Bot Token**
2. Send `/start` to your bot, then use [@userinfobot](https://t.me/userinfobot) to get your **Chat ID**
3. Go to **Settings > Telegram**, enter both values, and click Save
4. Click **Send test message** to verify

Telegram credentials are stored encrypted in the database per-user — no `.env` configuration needed.

## MCP Server (AI Integration)

Pulso exposes an MCP server at `/mcp/pulso` that allows AI clients (like Claude.ai) to analyze GA4 snapshot data.

### Available Tools

| Tool | Description |
|------|-------------|
| `list-properties` | List all active GA4 properties with latest trend |
| `get-property-snapshots` | Get daily snapshots for a property within a date range |
| `get-property-sources` | Get aggregated traffic sources for a property |
| `get-property-pages` | Get top pages with per-page engagement metrics |
| `get-property-search-queries` | Get top Google Search Console queries |
| `get-property-summary` | Comprehensive summary with averages, anomalies, sources, pages, and queries |

All tools support date ranges (`from`/`to`) for trend analysis over time.

### Connecting from Claude.ai

Add Pulso as a remote MCP server using your deployment URL:

```
https://your-domain.com/mcp/pulso
```

All tools are read-only and expose snapshot data for AI-driven analysis.

## Testing

```bash
# Run all tests
php artisan test

# Run specific test file
php artisan test --filter=DashboardTest

# Run with compact output
php artisan test --compact
```

## Code Style

```bash
# Format PHP files
vendor/bin/pint

# Format only changed files
vendor/bin/pint --dirty
```
