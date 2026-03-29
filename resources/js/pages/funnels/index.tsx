import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { ArrowRight, Filter, Plus, Trash2 } from 'lucide-react';

interface FunnelItem {
    id: number;
    name: string;
    description: string | null;
    is_open: boolean;
    steps_count: number;
    created_at: string;
}

interface FunnelsProps {
    funnels: FunnelItem[];
    hasProperty: boolean;
    flash?: { success?: string };
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Funnels', href: '/funnels' }];

export default function FunnelsIndex() {
    const { funnels, hasProperty, flash } = usePage<FunnelsProps>().props;

    if (!hasProperty) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Funnels" />
                <div className="flex min-h-[60vh] flex-col items-center justify-center px-4">
                    <div className="animate-fade-up text-center">
                        <div className="bg-muted mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-2xl">
                            <Filter className="text-primary h-7 w-7" />
                        </div>
                        <h2 className="text-2xl font-bold tracking-tight">No property selected</h2>
                        <p className="text-muted-foreground mt-2 text-sm">Add a GA4 property first to create funnels.</p>
                        <Button asChild className="mt-6" size="sm">
                            <Link href="/properties">
                                Manage Properties <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
                            </Link>
                        </Button>
                    </div>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Funnels" />
            <div className="px-4 py-6 md:px-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-xl font-bold tracking-tight">Funnels</h1>
                    <Button asChild size="sm">
                        <Link href={route('funnels.create')}>
                            <Plus className="mr-1.5 h-4 w-4" /> New Funnel
                        </Link>
                    </Button>
                </div>

                {flash?.success && (
                    <div className="animate-fade-up mb-6 rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-3 text-sm text-emerald-400">
                        {flash.success}
                    </div>
                )}

                {funnels.length === 0 ? (
                    <div className="animate-fade-up flex flex-col items-center py-16 text-center">
                        <div className="bg-muted mb-4 flex h-12 w-12 items-center justify-center rounded-xl">
                            <Filter className="text-muted-foreground h-6 w-6" />
                        </div>
                        <p className="text-muted-foreground text-sm">No funnels yet. Create one to track user journeys.</p>
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {funnels.map((funnel, i) => (
                            <Card
                                key={funnel.id}
                                className="animate-fade-up group cursor-pointer transition-shadow hover:shadow-md"
                                style={{ animationDelay: `${i * 50}ms` }}
                            >
                                <Link href={route('funnels.show', funnel.id)} className="block">
                                    <CardContent className="p-5">
                                        <div className="flex items-start justify-between">
                                            <div className="min-w-0 flex-1">
                                                <h3 className="truncate font-semibold">{funnel.name}</h3>
                                                {funnel.description && (
                                                    <p className="text-muted-foreground mt-1 line-clamp-2 text-sm">
                                                        {funnel.description}
                                                    </p>
                                                )}
                                                <div className="text-muted-foreground mt-3 flex items-center gap-3 text-xs">
                                                    <span>{funnel.steps_count} steps</span>
                                                    <span>{funnel.is_open ? 'Open' : 'Closed'} funnel</span>
                                                </div>
                                            </div>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                className="ml-2 h-7 w-7 shrink-0 p-0 text-red-500 opacity-0 transition-opacity group-hover:opacity-100 hover:text-red-400"
                                                onClick={(e) => {
                                                    e.preventDefault();
                                                    router.delete(route('funnels.destroy', funnel.id));
                                                }}
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Link>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
