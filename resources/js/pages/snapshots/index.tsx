import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';

import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Camera, Loader2 } from 'lucide-react';
import { useState } from 'react';

interface Snapshot {
    id: number;
    snapshot_date: string;
    users: number;
    sessions: number;
    pageviews: number;
    bounce_rate: string;
    engagement_rate: string | null;
    pages_per_session: string | null;
    trend: string;
    trend_score: string;
    is_spike: boolean;
    is_drop: boolean;
    is_stall: boolean;
    users_delta_wow: string | null;
    sessions_delta_wow: string | null;
    sources: { id: number; source: string; medium: string; sessions: number }[];
    pages: { id: number; page_path: string; pageviews: number }[];
    search_queries: { id: number; query: string; clicks: number }[];
}

interface PaginatedSnapshots {
    data: Snapshot[];
    current_page: number;
    last_page: number;
    next_page_url: string | null;
    prev_page_url: string | null;
}

interface SnapshotsPageProps {
    hasProperty: boolean;
    property: { id: number; display_name: string; website_url: string | null } | null;
    snapshots: PaginatedSnapshots | null;
    flash?: { success?: string; error?: string };
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Snapshots', href: '/snapshots' }];

function trendBadge(trend: string) {
    const variants: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
        spike: 'default',
        improved: 'default',
        stall: 'secondary',
        declined: 'destructive',
        drop: 'destructive',
    };
    return <Badge variant={variants[trend] || 'secondary'}>{trend}</Badge>;
}

function deltaDisplay(value: string | null) {
    if (value === null) return <span className="text-muted-foreground">--</span>;
    const num = parseFloat(value);
    const color = num > 0 ? 'text-emerald-500' : num < 0 ? 'text-red-500' : 'text-muted-foreground';
    return <span className={color}>{num > 0 ? '+' : ''}{num}%</span>;
}

export default function SnapshotsIndex() {
    const { hasProperty, property, snapshots, flash } = usePage<SnapshotsPageProps>().props;
    const [generating, setGenerating] = useState(false);

    function handleGenerate() {
        setGenerating(true);
        router.post(route('snapshots.generate'), {}, {
            onFinish: () => setGenerating(false),
            preserveScroll: true,
        });
    }

    if (!hasProperty) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Snapshots" />
                <div className="flex flex-col items-center justify-center py-20 text-center">
                    <Camera className="text-muted-foreground mb-4 h-12 w-12" />
                    <p className="text-muted-foreground">Select a property to view snapshots</p>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Snapshots" />

            <div className="px-4 py-6 md:px-6">
                <div className="flex items-center justify-between">
                    <HeadingSmall
                        title={`Snapshots - ${property?.display_name}`}
                        description="Daily analytics snapshots with trend analysis"
                    />
                    <Button onClick={handleGenerate} disabled={generating} size="sm">
                        {generating ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Camera className="mr-2 h-4 w-4" />}
                        Generate now
                    </Button>
                </div>

                {flash?.success && (
                    <div className="mt-4 rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-3 text-sm text-emerald-400">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="mt-4 rounded-lg border border-red-500/20 bg-red-500/10 p-3 text-sm text-red-400">
                        {flash.error}
                    </div>
                )}

                <Card className="mt-6">
                    <CardHeader>
                        <CardTitle className="text-base">History</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {!snapshots || snapshots.data.length === 0 ? (
                            <p className="text-muted-foreground py-8 text-center text-sm">No snapshots yet. Click "Generate now" to create one.</p>
                        ) : (
                            <>
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Date</TableHead>
                                                <TableHead>Trend</TableHead>
                                                <TableHead className="text-right">Score</TableHead>
                                                <TableHead className="text-right">Users</TableHead>
                                                <TableHead className="text-right">Sessions</TableHead>
                                                <TableHead className="text-right">Pageviews</TableHead>
                                                <TableHead className="text-right">Bounce</TableHead>
                                                <TableHead className="text-right">Engage</TableHead>
                                                <TableHead className="text-right">Users WoW</TableHead>
                                                <TableHead className="text-right">Sources</TableHead>
                                                <TableHead className="text-right">Pages</TableHead>
                                                <TableHead className="text-right">Queries</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {snapshots.data.map((s) => (
                                                <TableRow key={s.id}>
                                                    <TableCell className="font-mono text-xs">{s.snapshot_date}</TableCell>
                                                    <TableCell>{trendBadge(s.trend)}</TableCell>
                                                    <TableCell className="text-right font-mono text-xs">{parseFloat(s.trend_score).toFixed(1)}</TableCell>
                                                    <TableCell className="text-right font-mono">{s.users.toLocaleString()}</TableCell>
                                                    <TableCell className="text-right font-mono">{s.sessions.toLocaleString()}</TableCell>
                                                    <TableCell className="text-right font-mono">{s.pageviews.toLocaleString()}</TableCell>
                                                    <TableCell className="text-right font-mono text-xs">{parseFloat(s.bounce_rate).toFixed(1)}%</TableCell>
                                                    <TableCell className="text-right font-mono text-xs">
                                                        {s.engagement_rate ? `${(parseFloat(s.engagement_rate) * 100).toFixed(0)}%` : '--'}
                                                    </TableCell>
                                                    <TableCell className="text-right text-xs">{deltaDisplay(s.users_delta_wow)}</TableCell>
                                                    <TableCell className="text-muted-foreground text-right text-xs">{s.sources.length}</TableCell>
                                                    <TableCell className="text-muted-foreground text-right text-xs">{s.pages.length}</TableCell>
                                                    <TableCell className="text-muted-foreground text-right text-xs">{s.search_queries.length}</TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>

                                {snapshots.last_page > 1 && (
                                    <div className="mt-4 flex items-center justify-between">
                                        <p className="text-muted-foreground text-xs">
                                            Page {snapshots.current_page} of {snapshots.last_page}
                                        </p>
                                        <div className="flex gap-2">
                                            {snapshots.prev_page_url && (
                                                <Button size="sm" variant="outline" onClick={() => router.get(snapshots.prev_page_url!)}>
                                                    Previous
                                                </Button>
                                            )}
                                            {snapshots.next_page_url && (
                                                <Button size="sm" variant="outline" onClick={() => router.get(snapshots.next_page_url!)}>
                                                    Next
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
