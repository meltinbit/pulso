import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { ArrowRight, BarChart3 } from 'lucide-react';

const periodKeys = ['7d', '14d', '30d', '90d'] as const;

interface ReportLayoutProps {
    title: string;
    routeName: string;
    hasProperty: boolean;
    period: string;
    periods: Record<string, string>;
    children: React.ReactNode;
}

export function ReportLayout({ title, routeName, hasProperty, period, periods, children }: ReportLayoutProps) {
    const [loadingPeriod, setLoadingPeriod] = useState<string | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title, href: route(routeName) },
    ];

    function switchPeriod(key: string) {
        if (key === period) return;
        setLoadingPeriod(key);
        router.get(route(routeName), { period: key }, { preserveState: true, preserveScroll: true, onFinish: () => setLoadingPeriod(null) });
    }

    if (!hasProperty) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title={title} />
                <div className="flex min-h-[60vh] flex-col items-center justify-center px-4">
                    <div className="animate-fade-up text-center">
                        <div className="bg-muted mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-2xl">
                            <BarChart3 className="text-primary h-7 w-7" />
                        </div>
                        <h2 className="text-2xl font-bold tracking-tight">No property selected</h2>
                        <p className="text-muted-foreground mt-2 max-w-sm text-sm leading-relaxed">
                            Add a GA4 property to view this report.
                        </p>
                        <Button asChild className="mt-6" size="sm">
                            <Link href="/properties">
                                Manage Properties
                                <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
                            </Link>
                        </Button>
                    </div>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="px-4 py-6 md:px-6">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <h1 className="text-xl font-bold tracking-tight">{title}</h1>
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
                {children}
            </div>
        </AppLayout>
    );
}
