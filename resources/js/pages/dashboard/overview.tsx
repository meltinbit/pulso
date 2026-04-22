import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { ChannelsWidget, CountriesWidget, DevicesWidget, EventsWidget, PagesWidget } from '@/components/dashboard-widgets';
import { InsightsSummary } from '@/components/insights-summary';
import { KpiCard } from '@/components/kpi-card';
import { TrendChart } from '@/components/trend-chart';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { ArrowRight, Globe, Info, Link2 } from 'lucide-react';

interface OverviewData {
    kpis: {
        users: number;
        sessions: number;
        bounceRate: number;
        avgSessionDuration: string;
    };
    trend: { date: string; activeUsers: number; sessions: number }[];
}

interface TodayData {
    usersToday: number;
    usersSameDayLastWeek: number;
}

interface DimensionItem {
    name: string;
    value: number;
    percent: number;
}

interface PageItem {
    path: string;
    title: string;
    views: number;
    users: number;
}

interface EventItem {
    name: string;
    label: string;
    phrase: string | null;
    count: number;
    users: number;
    users_previous: number;
    delta_users_pct: number | null;
    is_custom: boolean;
}

interface DashboardProps {
    overview: OverviewData | null;
    today?: TodayData;
    countries?: DimensionItem[];
    devices?: DimensionItem[];
    pages?: PageItem[];
    channels?: DimensionItem[];
    events?: EventItem[];
    realtime: number;
    property: { id: number; display_name: string; property_id: string } | null;
    hasProperty: boolean;
    hasConnection?: boolean;
    period: string;
    periods: Record<string, string>;
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

const periodKeys = ['7d', '14d', '30d', '90d'] as const;

export default function DashboardOverview() {
    const { overview, today, countries, devices, pages, channels, events, realtime, property, hasProperty, hasConnection, period, periods } =
        usePage<DashboardProps>().props;
    const [realtimeUsers, setRealtimeUsers] = useState(realtime);
    const [loadingPeriod, setLoadingPeriod] = useState<string | null>(null);

    useEffect(() => {
        setRealtimeUsers(realtime);
    }, [realtime]);

    useEffect(() => {
        setLoadingPeriod(null);
    }, [period]);

    useEffect(() => {
        if (!property) return;

        const poll = setInterval(async () => {
            try {
                const res = await fetch(route('api.realtime', property.id));
                const data = await res.json();
                setRealtimeUsers(data.activeUsers);
            } catch {
                // Silently fail polling
            }
        }, 60_000);

        return () => clearInterval(poll);
    }, [property?.id]);

    function switchPeriod(key: string) {
        if (key === period) return;
        setLoadingPeriod(key);
        router.get(
            route('dashboard'),
            { period: key },
            { preserveState: true, preserveScroll: true },
        );
    }

    if (!hasProperty) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Dashboard" />
                <div className="flex min-h-[60vh] flex-col items-center justify-center px-4">
                    <div className="animate-fade-up text-center">
                        <div className="bg-muted mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-2xl">
                            {hasConnection ? (
                                <Globe className="text-primary h-7 w-7" />
                            ) : (
                                <Link2 className="text-primary h-7 w-7" />
                            )}
                        </div>
                        {hasConnection ? (
                            <>
                                <h2 className="text-2xl font-bold tracking-tight">Add a property</h2>
                                <p className="text-muted-foreground mt-2 max-w-sm text-sm leading-relaxed">
                                    Select a GA4 property to start monitoring your analytics data.
                                </p>
                                <Button asChild className="mt-6" size="sm">
                                    <Link href="/properties">
                                        Manage Properties
                                        <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
                                    </Link>
                                </Button>
                            </>
                        ) : (
                            <>
                                <h2 className="text-2xl font-bold tracking-tight">Connect Google Analytics</h2>
                                <p className="text-muted-foreground mt-2 max-w-sm text-sm leading-relaxed">
                                    Link your Google account to access GA4 property data.
                                </p>
                                <Button asChild className="mt-6" size="sm">
                                    <Link href="/settings/google">
                                        Connect Account
                                        <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
                                    </Link>
                                </Button>
                            </>
                        )}
                    </div>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <div className="bg-noise relative px-4 py-6 md:px-6">
                {/* Header */}
                <div className="relative z-10 mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-xl font-bold tracking-tight">{property?.display_name}</h1>
                        <p className="text-muted-foreground mt-0.5 font-mono text-xs">{property?.property_id}</p>
                    </div>

                    <div className="flex items-center gap-4">
                        {/* Period Selector */}
                        <div className="bg-muted/50 flex items-center rounded-lg border p-0.5">
                            {periodKeys.map((key) => (
                                <button
                                    key={key}
                                    onClick={() => switchPeriod(key)}
                                    disabled={loadingPeriod !== null}
                                    className={cn(
                                        'rounded-md px-3 py-1.5 text-xs font-medium transition-all',
                                        period === key
                                            ? 'bg-background text-foreground shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground',
                                        loadingPeriod === key && 'animate-pulse',
                                    )}
                                >
                                    {periods[key] ?? key}
                                </button>
                            ))}
                        </div>

                        {/* Realtime */}
                        <div className="flex items-center gap-3 rounded-full border px-4 py-2">
                            <span className="pulse-glow inline-block h-2 w-2 rounded-full bg-emerald-400" />
                            <span className="font-mono text-xl font-bold tabular-nums">{realtimeUsers}</span>
                            <span className="text-muted-foreground text-xs font-medium uppercase tracking-wider">
                                live
                            </span>
                        </div>
                    </div>
                </div>

                {overview && (
                    <div className="relative z-10 space-y-6">
                        {/* KPI Grid */}
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                            <KpiCard
                                title="Active now"
                                description="People browsing your site right now. Updates every 60 seconds."
                                value={realtimeUsers}
                                accent
                                delay={0}
                            />
                            <KpiCard
                                title="Users today"
                                description="Unique visitors today. Compared to the same day last week to spot trends."
                                value={today?.usersToday?.toLocaleString() ?? '0'}
                                comparison={
                                    today
                                        ? {
                                              value: today.usersSameDayLastWeek.toLocaleString(),
                                              label: 'last week',
                                          }
                                        : undefined
                                }
                                trend={
                                    today && today.usersToday > today.usersSameDayLastWeek
                                        ? 'up'
                                        : today && today.usersToday < today.usersSameDayLastWeek
                                          ? 'down'
                                          : 'neutral'
                                }
                                delay={50}
                            />
                            <KpiCard
                                title="Users"
                                description="Unique visitors in the selected period. One person visiting 5 times counts as 1 user."
                                value={overview.kpis.users.toLocaleString()}
                                delay={100}
                            />
                            <KpiCard
                                title="Sessions"
                                description="Total visits in the period. One user can have multiple sessions (e.g. morning and evening)."
                                value={overview.kpis.sessions.toLocaleString()}
                                delay={150}
                            />
                            <KpiCard
                                title="Bounce rate"
                                description="Percentage of visitors who left after viewing just one page. Lower is better — it means people explore your site."
                                value={`${overview.kpis.bounceRate}%`}
                                delay={200}
                            />
                            <KpiCard
                                title="Avg. session"
                                description="Average time a visitor spends on your site per visit. Longer usually means more engagement."
                                value={overview.kpis.avgSessionDuration}
                                delay={250}
                            />
                        </div>

                        {/* Insights */}
                        <InsightsSummary
                            bounceRate={overview.kpis.bounceRate}
                            avgSessionDuration={overview.kpis.avgSessionDuration}
                            usersToday={today?.usersToday ?? 0}
                            usersSameDayLastWeek={today?.usersSameDayLastWeek ?? 0}
                            totalUsers={overview.kpis.users}
                            totalSessions={overview.kpis.sessions}
                            topChannel={channels?.[0]?.name}
                            mobilePercent={
                                devices
                                    ? devices.find((d) => d.name.toLowerCase() === 'mobile')?.percent
                                    : undefined
                            }
                            delay={300}
                        />

                        {/* Info banner */}
                        <div className="flex items-start gap-3 rounded-lg border border-blue-500/20 bg-blue-500/5 px-4 py-3">
                            <Info className="mt-0.5 h-4 w-4 shrink-0 text-blue-400" />
                            <p className="text-muted-foreground text-xs leading-relaxed">
                                <strong className="text-foreground">Tip:</strong> These numbers include your own visits.
                                To exclude internal traffic, set up a filter in{' '}
                                <a
                                    href={`https://analytics.google.com/analytics/web/#/a0p${property?.property_id}/admin/streams`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-primary underline underline-offset-2"
                                >
                                    GA4 Admin &rarr; Data Streams &rarr; Configure tag settings &rarr; Define internal traffic
                                </a>
                                {' '}and add your IP address.
                            </p>
                        </div>

                        {/* Trend Chart */}
                        <TrendChart
                            data={overview.trend}
                            title={`Active Users — ${periods[period] ?? period}`}
                        />

                        {/* Widgets Grid */}
                        <div className="grid gap-4 lg:grid-cols-2">
                            <ChannelsWidget data={channels ?? []} delay={400} />
                            <DevicesWidget data={devices ?? []} delay={450} />
                            <CountriesWidget data={countries ?? []} delay={500} />
                            <PagesWidget data={pages ?? []} delay={550} />
                            <EventsWidget data={events ?? []} delay={600} />
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
