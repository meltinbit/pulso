import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { ArrowLeft, ArrowDown } from 'lucide-react';

interface FunnelStep {
    id: number;
    order: number;
    name: string;
    event_name: string;
}

interface FunnelData {
    id: number;
    name: string;
    description: string | null;
    is_open: boolean;
    steps: FunnelStep[];
}

interface FunnelResult {
    step: string;
    users: number;
    abandonments: number;
    abandonment_rate: number;
}

interface FunnelShowProps {
    funnel: FunnelData;
    results: FunnelResult[];
    period: string;
    periods: Record<string, string>;
    [key: string]: unknown;
}

const periodKeys = ['7d', '14d', '30d', '90d'] as const;

export default function FunnelShow() {
    const { funnel, results, period, periods } = usePage<FunnelShowProps>().props;
    const [loadingPeriod, setLoadingPeriod] = useState<string | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Funnels', href: '/funnels' },
        { title: funnel.name, href: route('funnels.show', funnel.id) },
    ];

    const maxUsers = results[0]?.users || 1;

    function switchPeriod(key: string) {
        if (key === period) return;
        setLoadingPeriod(key);
        router.get(route('funnels.show', funnel.id), { period: key }, {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => setLoadingPeriod(null),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={funnel.name} />
            <div className="px-4 py-6 md:px-6">
                {/* Header */}
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <Button asChild variant="ghost" size="sm" className="h-8 w-8 p-0">
                            <Link href={route('funnels.index')}>
                                <ArrowLeft className="h-4 w-4" />
                            </Link>
                        </Button>
                        <div>
                            <h1 className="text-xl font-bold tracking-tight">{funnel.name}</h1>
                            {funnel.description && (
                                <p className="text-muted-foreground mt-0.5 text-sm">{funnel.description}</p>
                            )}
                        </div>
                    </div>
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
                </div>

                {results.length === 0 ? (
                    <Card className="animate-fade-up">
                        <CardContent className="flex flex-col items-center py-16 text-center">
                            <p className="text-muted-foreground text-sm">
                                No funnel data available for this period. This can happen if the events haven't been
                                triggered yet or the v1alpha API is not accessible.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-6">
                        {/* Funnel visualization */}
                        <Card className="animate-fade-up">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-muted-foreground text-sm font-medium uppercase tracking-wider">
                                    Funnel Visualization
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-1">
                                {results.map((step, i) => {
                                    const widthPercent = (step.users / maxUsers) * 100;
                                    return (
                                        <div key={i}>
                                            <div
                                                className="animate-fade-up"
                                                style={{ animationDelay: `${i * 80}ms` }}
                                            >
                                                <div className="mb-1.5 flex items-center justify-between">
                                                    <div className="flex items-center gap-2">
                                                        <span className="bg-muted text-muted-foreground flex h-6 w-6 items-center justify-center rounded font-mono text-xs font-bold">
                                                            {i + 1}
                                                        </span>
                                                        <span className="text-sm font-medium">{step.step}</span>
                                                    </div>
                                                    <div className="flex items-center gap-3">
                                                        <span className="font-mono text-sm font-bold">
                                                            {step.users.toLocaleString()}
                                                        </span>
                                                        <span className="text-muted-foreground text-xs">users</span>
                                                    </div>
                                                </div>
                                                <div className="bg-muted h-12 overflow-hidden rounded-lg">
                                                    <div
                                                        className="bg-primary/80 hover:bg-primary flex h-full items-center rounded-lg px-3 transition-all duration-700"
                                                        style={{ width: `${Math.max(widthPercent, 2)}%` }}
                                                    >
                                                        {widthPercent > 15 && (
                                                            <span className="text-primary-foreground font-mono text-xs font-medium">
                                                                {Math.round(widthPercent)}%
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                            {i < results.length - 1 && step.abandonments > 0 && (
                                                <div className="my-2 flex items-center justify-center gap-2 text-xs">
                                                    <ArrowDown className="h-3 w-3 text-red-400" />
                                                    <span className="text-red-400 font-medium">
                                                        {step.abandonment_rate}% drop
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        ({step.abandonments.toLocaleString()} left)
                                                    </span>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </CardContent>
                        </Card>

                        {/* Summary table */}
                        <Card className="animate-fade-up" style={{ animationDelay: '200ms' }}>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-muted-foreground text-sm font-medium uppercase tracking-wider">
                                    Step Details
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-muted-foreground border-b text-xs uppercase tracking-wider">
                                            <th className="pb-3 text-left font-medium">Step</th>
                                            <th className="pb-3 text-right font-medium">Users</th>
                                            <th className="pb-3 text-right font-medium">Drop-off</th>
                                            <th className="pb-3 text-right font-medium">Drop Rate</th>
                                            <th className="pb-3 text-right font-medium">Conversion</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {results.map((step, i) => (
                                            <tr key={i} className="border-b border-border/50">
                                                <td className="py-3">
                                                    <span className="text-muted-foreground mr-2 font-mono text-xs">{i + 1}.</span>
                                                    {step.step}
                                                </td>
                                                <td className="py-3 text-right font-mono font-medium">
                                                    {step.users.toLocaleString()}
                                                </td>
                                                <td className="py-3 text-right font-mono text-red-400">
                                                    {step.abandonments > 0 ? `-${step.abandonments.toLocaleString()}` : '—'}
                                                </td>
                                                <td className="py-3 text-right font-mono">
                                                    {step.abandonment_rate > 0 ? (
                                                        <span className="text-red-400">{step.abandonment_rate}%</span>
                                                    ) : '—'}
                                                </td>
                                                <td className="py-3 text-right font-mono font-medium">
                                                    {maxUsers > 0 ? `${Math.round((step.users / maxUsers) * 100)}%` : '—'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
