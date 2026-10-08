import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Bar, CartesianGrid, ComposedChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

import { KpiCard } from '@/components/kpi-card';
import { ReportLayout } from '@/components/report-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { AlertTriangle, Coins, Loader2, RefreshCw } from 'lucide-react';

interface Metrics {
    earnings: number;
    page_views: number;
    impressions: number;
    clicks: number;
    ad_requests: number;
    matched_ad_requests: number;
    page_rpm: number;
    impression_rpm: number;
    page_ctr: number;
    cpc: number;
    coverage: number;
}

interface DailyRow extends Metrics {
    date: string;
}

interface BreakdownRow extends Metrics {
    name: string;
}

interface Report {
    from: string;
    to: string;
    currency: string;
    totals: Metrics;
    previous: { from: string; to: string; totals: Metrics };
    changes: Record<string, number | null>;
    daily: DailyRow[];
    breakdowns: Record<'country' | 'platform' | 'ad_unit', BreakdownRow[]>;
    last_synced_at: string | null;
}

type Status = 'missing_scope' | 'missing_website' | 'no_data' | 'ready';

interface AdSenseProps {
    hasProperty: boolean;
    property: { id: number; display_name: string; website_url: string | null } | null;
    status: Status | null;
    period: string;
    periods: Record<string, string>;
    report: Report | null;
    flash?: { success?: string; error?: string };
    [key: string]: unknown;
}

const breakdownTitles: Record<keyof Report['breakdowns'], string> = {
    country: 'Countries',
    platform: 'Platforms',
    ad_unit: 'Ad units',
};

function trendOf(change: number | null | undefined): 'up' | 'down' | 'neutral' {
    if (change === null || change === undefined || change === 0) return 'neutral';
    return change > 0 ? 'up' : 'down';
}

function comparisonOf(change: number | null | undefined) {
    if (change === null || change === undefined) return { value: 'n/a', label: 'vs previous period' };
    return { value: `${change > 0 ? '+' : ''}${change}%`, label: 'vs previous period' };
}

export default function AdSenseIndex() {
    const { hasProperty, status, period, periods, report, flash } = usePage<AdSenseProps>().props;
    const [syncing, setSyncing] = useState(false);

    function handleSync() {
        setSyncing(true);
        router.post(route('adsense.sync'), {}, { preserveScroll: true, onFinish: () => setSyncing(false) });
    }

    const money = new Intl.NumberFormat('it-IT', { style: 'currency', currency: report?.currency ?? 'EUR' });
    const number = new Intl.NumberFormat('it-IT');

    return (
        <ReportLayout title="AdSense" routeName="adsense.index" hasProperty={hasProperty} period={period} periods={periods}>
            {flash?.success && (
                <div className="mb-4 rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-3 text-sm text-emerald-500">{flash.success}</div>
            )}
            {flash?.error && <div className="mb-4 rounded-lg border border-red-500/20 bg-red-500/10 p-3 text-sm text-red-500">{flash.error}</div>}

            {status !== 'ready' && <StatusNotice status={status} syncing={syncing} onSync={handleSync} />}

            {status === 'ready' && report && (
                <div className="space-y-6">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-muted-foreground text-xs">
                            {report.from} → {report.to}
                            {report.last_synced_at && <> · last sync {new Date(report.last_synced_at).toLocaleString('it-IT')}</>}
                            {' · '}estimated earnings, today is partial
                        </p>
                        <Button onClick={handleSync} disabled={syncing} size="sm" variant="outline">
                            {syncing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <RefreshCw className="mr-2 h-4 w-4" />}
                            Sync now
                        </Button>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <KpiCard
                            title="Earnings"
                            value={money.format(report.totals.earnings)}
                            comparison={comparisonOf(report.changes.earnings)}
                            trend={trendOf(report.changes.earnings)}
                            description="Estimated earnings on this domain. Final amounts are settled by Google at month end."
                            accent
                        />
                        <KpiCard
                            title="Page RPM"
                            value={money.format(report.totals.page_rpm)}
                            comparison={comparisonOf(report.changes.page_rpm)}
                            trend={trendOf(report.changes.page_rpm)}
                            description="Earnings per 1,000 page views."
                            delay={50}
                        />
                        <KpiCard
                            title="Page views"
                            value={number.format(report.totals.page_views)}
                            comparison={comparisonOf(report.changes.page_views)}
                            trend={trendOf(report.changes.page_views)}
                            description="Page views counted by AdSense (pages with ad code)."
                            delay={100}
                        />
                        <KpiCard
                            title="Impressions"
                            value={number.format(report.totals.impressions)}
                            comparison={comparisonOf(report.changes.impressions)}
                            trend={trendOf(report.changes.impressions)}
                            delay={150}
                        />
                        <KpiCard
                            title="Clicks"
                            value={number.format(report.totals.clicks)}
                            comparison={comparisonOf(report.changes.clicks)}
                            trend={trendOf(report.changes.clicks)}
                            delay={200}
                        />
                        <KpiCard
                            title="Page CTR"
                            value={`${report.totals.page_ctr}%`}
                            comparison={comparisonOf(report.changes.page_ctr)}
                            trend={trendOf(report.changes.page_ctr)}
                            description="Ad clicks per 100 page views."
                            delay={250}
                        />
                        <KpiCard
                            title="CPC"
                            value={money.format(report.totals.cpc)}
                            comparison={comparisonOf(report.changes.cpc)}
                            trend={trendOf(report.changes.cpc)}
                            description="Average earnings per click."
                            delay={300}
                        />
                        <KpiCard
                            title="Coverage"
                            value={`${report.totals.coverage}%`}
                            comparison={comparisonOf(report.changes.coverage)}
                            trend={trendOf(report.changes.coverage)}
                            description="Share of ad requests that returned an ad."
                            delay={350}
                        />
                    </div>

                    <EarningsChart daily={report.daily} money={money} />

                    <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
                        {(Object.keys(breakdownTitles) as (keyof Report['breakdowns'])[]).map((key) => (
                            <BreakdownTable key={key} title={breakdownTitles[key]} rows={report.breakdowns[key]} money={money} number={number} />
                        ))}
                    </div>
                </div>
            )}
        </ReportLayout>
    );
}

function StatusNotice({ status, syncing, onSync }: { status: Status | null; syncing: boolean; onSync: () => void }) {
    const content: Record<Exclude<Status, 'ready'>, { title: string; body: string; action: React.ReactNode }> = {
        missing_scope: {
            title: 'AdSense access not granted',
            body: 'This property’s Google account was connected before Pulso asked for AdSense access. Enable the “AdSense Management API” in your Google Cloud project, then reconnect the account to grant it.',
            action: (
                <Button asChild size="sm">
                    <Link href={route('settings.google')}>Go to Google accounts</Link>
                </Button>
            ),
        },
        missing_website: {
            title: 'Website URL missing',
            body: 'AdSense reports are filtered by domain. Set the website URL of this property so Pulso can match it to your AdSense site.',
            action: (
                <Button asChild size="sm">
                    <Link href="/properties">Manage properties</Link>
                </Button>
            ),
        },
        no_data: {
            title: 'No AdSense data yet',
            body: 'Run the first sync: the last 7 days are downloaded right away, the history of the last 13 months in background.',
            action: (
                <Button onClick={onSync} disabled={syncing} size="sm">
                    {syncing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <RefreshCw className="mr-2 h-4 w-4" />}
                    Sync now
                </Button>
            ),
        },
    };

    if (!status || status === 'ready') return null;

    const { title, body, action } = content[status];
    const Icon = status === 'no_data' ? Coins : AlertTriangle;

    return (
        <Card className="animate-fade-up">
            <CardContent className="flex flex-col items-start gap-4 p-6 sm:flex-row sm:items-center">
                <div className="bg-muted flex h-12 w-12 shrink-0 items-center justify-center rounded-xl">
                    <Icon className="text-primary h-5 w-5" />
                </div>
                <div className="flex-1">
                    <h2 className="font-semibold">{title}</h2>
                    <p className="text-muted-foreground mt-1 text-sm leading-relaxed">{body}</p>
                </div>
                {action}
            </CardContent>
        </Card>
    );
}

function EarningsChart({ daily, money }: { daily: DailyRow[]; money: Intl.NumberFormat }) {
    const axisTick = { fontSize: 11, fontFamily: 'JetBrains Mono', fill: 'hsl(215, 12%, 45%)' };

    return (
        <Card className="animate-fade-up" style={{ animationDelay: '400ms' }}>
            <CardHeader className="pb-2">
                <CardTitle className="text-muted-foreground text-sm font-medium tracking-wider uppercase">Daily earnings & page RPM</CardTitle>
            </CardHeader>
            <CardContent className="pt-0">
                <div className="h-[320px]">
                    <ResponsiveContainer width="100%" height="100%">
                        <ComposedChart data={daily} margin={{ top: 10, right: 0, left: -10, bottom: 0 }}>
                            <CartesianGrid strokeDasharray="3 3" stroke="hsl(220, 15%, 18%)" vertical={false} />
                            <XAxis dataKey="date" tick={axisTick} tickLine={false} axisLine={false} interval="preserveStartEnd" />
                            <YAxis yAxisId="earnings" tick={axisTick} tickLine={false} axisLine={false} width={55} />
                            <YAxis yAxisId="rpm" orientation="right" tick={axisTick} tickLine={false} axisLine={false} width={45} />
                            <Tooltip
                                formatter={(value, name) => [money.format(Number(value)), name]}
                                contentStyle={{
                                    borderRadius: '10px',
                                    border: '1px solid hsl(220, 15%, 20%)',
                                    backgroundColor: 'hsl(220, 18%, 12%)',
                                    fontSize: '12px',
                                    fontFamily: 'JetBrains Mono',
                                    color: 'hsl(210, 20%, 93%)',
                                    boxShadow: '0 8px 32px rgba(0,0,0,0.3)',
                                }}
                                labelStyle={{ color: 'hsl(215, 12%, 55%)', marginBottom: '4px' }}
                            />
                            <Bar yAxisId="earnings" dataKey="earnings" name="Earnings" fill="hsl(172, 65%, 45%)" radius={[3, 3, 0, 0]} />
                            <Line
                                yAxisId="rpm"
                                type="monotone"
                                dataKey="page_rpm"
                                name="Page RPM"
                                stroke="hsl(38, 92%, 55%)"
                                strokeWidth={2}
                                dot={false}
                            />
                        </ComposedChart>
                    </ResponsiveContainer>
                </div>
            </CardContent>
        </Card>
    );
}

function BreakdownTable({
    title,
    rows,
    money,
    number,
}: {
    title: string;
    rows: BreakdownRow[];
    money: Intl.NumberFormat;
    number: Intl.NumberFormat;
}) {
    const maxEarnings = rows[0]?.earnings || 1;

    return (
        <Card className="animate-fade-up">
            <CardHeader className="pb-3">
                <CardTitle className="text-muted-foreground text-sm font-medium tracking-wider uppercase">{title}</CardTitle>
            </CardHeader>
            <CardContent>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-muted-foreground border-b text-xs tracking-wider uppercase">
                                <th className="pb-3 text-left font-medium">Name</th>
                                <th className="pb-3 text-right font-medium">Earnings</th>
                                <th className="pb-3 text-right font-medium">RPM</th>
                                <th className="pb-3 text-right font-medium">Views</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.name} className="hover:bg-muted/50 border-border/50 border-b transition-colors">
                                    <td className="max-w-[180px] py-2.5">
                                        <p className="truncate text-xs font-medium" title={row.name}>
                                            {row.name}
                                        </p>
                                        <div className="bg-muted mt-1 h-1 overflow-hidden rounded-full">
                                            <div
                                                className="bg-primary h-full rounded-full"
                                                style={{ width: `${(row.earnings / maxEarnings) * 100}%` }}
                                            />
                                        </div>
                                    </td>
                                    <td className="py-2.5 text-right font-mono text-xs">{money.format(row.earnings)}</td>
                                    <td className="py-2.5 text-right font-mono text-xs">{money.format(row.page_rpm)}</td>
                                    <td className="py-2.5 text-right font-mono text-xs">{number.format(row.page_views)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {rows.length === 0 && <p className="text-muted-foreground py-6 text-center text-sm">No data in this period.</p>}
                </div>
            </CardContent>
        </Card>
    );
}
