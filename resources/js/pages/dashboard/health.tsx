import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2 } from 'lucide-react';

interface Health {
    connections: { id: number; email: string; status: 'ok' | 'expired' | 'inactive'; authorized_at: string | null; issue: string | null }[];
    properties: {
        id: number;
        name: string;
        website_url: string | null;
        last_snapshot: string | null;
        last_search_console: string | null;
        last_adsense: string | null;
        last_bing: string | null;
        issues: string[];
    }[];
    bing: { has_key: boolean; key_invalid: boolean; issue: string | null };
    meta: { has_token: boolean; token_invalid: boolean; issue: string | null };
    ai_visibility: { has_key: boolean; spent_month_usd: number; cap_eur: number; issue: string | null };
    google_ads: { configured: boolean; missing: string[]; operations_today: number; daily_limit: number };
    mcp: { has_token: boolean; last_used_at: string | null; calls_24h: number; errors_24h: number };
    failed_jobs: { count_7d: number; latest: { job: string; failed_at: string; error: string } | null };
    issues_count: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Health', href: '/dashboard' }];

function Status({ ok, label }: { ok: boolean; label: string }) {
    return <Badge variant={ok ? 'secondary' : 'destructive'}>{label}</Badge>;
}

function Issue({ children }: { children: React.ReactNode }) {
    return (
        <p className="flex items-start gap-2 text-sm text-red-500">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
            <span>{children}</span>
        </p>
    );
}

export default function HealthPage({ health }: { health: Health }) {
    const { connections, properties, google_ads: ads, mcp, failed_jobs: failedJobs } = health;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Health" />

            <div className="space-y-6 px-4 py-6 md:px-6">
                <div className="flex items-center justify-between gap-4">
                    <HeadingSmall
                        title="Health"
                        description="Connections, data freshness and API quotas. Reports are produced by the agents through MCP."
                    />
                    {health.issues_count === 0 ? (
                        <span className="flex items-center gap-1.5 text-sm text-emerald-500">
                            <CheckCircle2 className="h-4 w-4" /> All good
                        </span>
                    ) : (
                        <span className="flex items-center gap-1.5 text-sm text-red-500">
                            <AlertTriangle className="h-4 w-4" /> {health.issues_count} to fix
                        </span>
                    )}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Connections</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {connections.length === 0 && (
                            <Issue>
                                No Google account connected. Connect one in{' '}
                                <Link href="/settings/google" className="underline">
                                    Settings → Google
                                </Link>
                                .
                            </Issue>
                        )}
                        {health.bing.issue && (
                            <Issue>
                                {health.bing.issue}{' '}
                                <Link href="/settings/bing" className="underline">
                                    Open Bing settings
                                </Link>
                            </Issue>
                        )}
                        {health.meta.issue && (
                            <Issue>
                                {health.meta.issue}{' '}
                                <Link href="/settings/meta" className="underline">
                                    Open Meta settings
                                </Link>
                            </Issue>
                        )}
                        {health.ai_visibility.issue && (
                            <Issue>
                                {health.ai_visibility.issue}{' '}
                                <Link href="/settings/ai-visibility" className="underline">
                                    Open AI visibility settings
                                </Link>
                            </Issue>
                        )}
                        {connections.map((connection) => (
                            <div key={connection.id} className="space-y-1">
                                <div className="flex items-center gap-2 text-sm">
                                    <Status ok={connection.status === 'ok'} label={connection.status} />
                                    <span className="font-medium">{connection.email}</span>
                                    {connection.authorized_at && <span className="text-muted-foreground">authorized {connection.authorized_at}</span>}
                                </div>
                                {connection.issue && <Issue>{connection.issue}</Issue>}
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Properties</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Property</TableHead>
                                        <TableHead>Last GA4 snapshot</TableHead>
                                        <TableHead>Last Search Console day</TableHead>
                                        <TableHead>Last AdSense day</TableHead>
                                        <TableHead>Last Bing day</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {properties.map((property) => (
                                        <TableRow key={property.id} className="align-top">
                                            <TableCell>
                                                <div className="font-medium">{property.name}</div>
                                                <div className="text-muted-foreground text-xs">{property.website_url ?? 'no website URL'}</div>
                                                {property.issues.length > 0 && (
                                                    <div className="mt-2 max-w-xl space-y-1 whitespace-normal">
                                                        {property.issues.map((issue) => (
                                                            <Issue key={issue}>{issue}</Issue>
                                                        ))}
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell>{property.last_snapshot ?? '–'}</TableCell>
                                            <TableCell>{property.last_search_console ?? '–'}</TableCell>
                                            <TableCell>{property.last_adsense ?? '–'}</TableCell>
                                            <TableCell>{property.last_bing ?? '–'}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Google Ads API</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            {ads.configured ? (
                                <p>
                                    <span className="font-medium">{ads.operations_today}</span> / {ads.daily_limit} operations today
                                </p>
                            ) : (
                                <Issue>Not configured: missing {ads.missing.join(', ')} in .env.</Issue>
                            )}
                            {ads.configured && ads.operations_today >= ads.daily_limit && (
                                <Issue>Daily limit reached: keyword tools answer again tomorrow (GOOGLE_ADS_DAILY_OPERATIONS_LIMIT).</Issue>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">MCP</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            {!mcp.has_token ? (
                                <Issue>
                                    No MCP token: create one in{' '}
                                    <Link href="/settings/mcp" className="underline">
                                        Settings → MCP
                                    </Link>
                                    .
                                </Issue>
                            ) : (
                                <>
                                    <p>Last used: {mcp.last_used_at ?? 'never'}</p>
                                    {health.ai_visibility.has_key && (
                                        <p>
                                            AI visibility: ${health.ai_visibility.spent_month_usd.toFixed(2)} of{' '}
                                            {health.ai_visibility.cap_eur.toFixed(2)} € this month
                                        </p>
                                    )}
                                    <p>
                                        {mcp.calls_24h} calls in 24 h, {mcp.errors_24h} errors ·{' '}
                                        <Link href="/mcp-log" className="underline">
                                            MCP log
                                        </Link>
                                    </p>
                                </>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Background jobs</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            {failedJobs.count_7d === 0 ? (
                                <p>No failed jobs in the last 7 days.</p>
                            ) : (
                                <>
                                    <Issue>{failedJobs.count_7d} failed jobs in the last 7 days.</Issue>
                                    {failedJobs.latest && (
                                        <p className="text-muted-foreground break-words">
                                            Latest: {failedJobs.latest.job} at {failedJobs.latest.failed_at} — {failedJobs.latest.error}
                                        </p>
                                    )}
                                </>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
