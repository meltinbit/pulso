import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { type FormEvent, useMemo, useState } from 'react';

import { type GeoLocation, GeoTargetPicker } from '@/components/geo-target-picker';
import { Sparkline } from '@/components/sparkline';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { AlertTriangle, ArrowDown, ArrowUp, ArrowUpDown, Download, KeyRound, Loader2, Search } from 'lucide-react';

interface KeywordRow {
    keyword: string;
    volume: number | null;
    monthly: { month: string; searches: number }[];
    trend: number | null;
    competition: 'LOW' | 'MEDIUM' | 'HIGH' | null;
    competition_index: number | null;
    cpc_low: number | null;
    cpc_high: number | null;
    gsc?: { position: number; impressions: number; clicks: number } | null;
}

interface KeywordsProps {
    property: { id: number; display_name: string; website_url: string | null } | null;
    configured: boolean;
    usage: { today: number; limit: number };
    filters: { seeds: string; url: string; location: GeoLocation[]; language: string; limit: number };
    languages: string[];
    results: KeywordRow[] | null;
    summary: { total: number; present: number; missing: number; missing_volume: number } | null;
    gapDays: number;
    error: string | null;
    [key: string]: unknown;
}

type SortKey = 'keyword' | 'volume' | 'trend' | 'competition_index' | 'cpc_high' | 'position';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Keywords', href: '/keywords' }];

const competitionStyles: Record<string, string> = {
    LOW: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    MEDIUM: 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    HIGH: 'border-red-500/30 bg-red-500/10 text-red-600 dark:text-red-400',
};

function sortValue(row: KeywordRow, key: SortKey): number | string | null {
    if (key === 'position') return row.gsc?.position ?? null;
    return row[key];
}

function exportCsv(rows: KeywordRow[], withGsc: boolean) {
    const header = ['keyword', 'volume', 'trend_pct', 'competition', 'competition_index', 'cpc_low', 'cpc_high'];
    if (withGsc) header.push('gsc_position', 'gsc_impressions', 'gsc_clicks');
    const months = rows[0]?.monthly.map((m) => m.month) ?? [];
    header.push(...months);

    const escape = (value: unknown) => {
        const text = value === null || value === undefined ? '' : String(value);
        return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
    };

    const lines = rows.map((row) => {
        const cells: unknown[] = [row.keyword, row.volume, row.trend, row.competition, row.competition_index, row.cpc_low, row.cpc_high];
        if (withGsc) cells.push(row.gsc?.position, row.gsc?.impressions, row.gsc?.clicks);
        cells.push(...months.map((month) => row.monthly.find((m) => m.month === month)?.searches));
        return cells.map(escape).join(',');
    });

    const blob = new Blob(['﻿' + [header.join(','), ...lines].join('\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `keywords-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(url);
}

export default function KeywordsIndex() {
    const { property, configured, usage, filters, languages, results, summary, gapDays, error } = usePage<KeywordsProps>().props;

    const [seeds, setSeeds] = useState(filters.seeds);
    const [url, setUrl] = useState(filters.url);
    const [locations, setLocations] = useState<GeoLocation[]>(filters.location);
    const [language, setLanguage] = useState(filters.language);
    const [loading, setLoading] = useState(false);
    const [onlyGap, setOnlyGap] = useState(false);
    const [sort, setSort] = useState<{ key: SortKey; dir: 'asc' | 'desc' }>({ key: 'volume', dir: 'desc' });

    const withGsc = property !== null && results !== null;
    const money = (value: number | null) =>
        value === null ? '–' : value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const rows = useMemo(() => {
        const filtered = (results ?? []).filter((row) => !onlyGap || !row.gsc);
        return [...filtered].sort((a, b) => {
            const av = sortValue(a, sort.key);
            const bv = sortValue(b, sort.key);
            if (av === null && bv === null) return 0;
            if (av === null) return 1;
            if (bv === null) return -1;
            const cmp = typeof av === 'string' ? av.localeCompare(bv as string) : (av as number) - (bv as number);
            return sort.dir === 'asc' ? cmp : -cmp;
        });
    }, [results, onlyGap, sort]);

    function submit(event: FormEvent) {
        event.preventDefault();
        setLoading(true);
        router.get(
            route('keywords.index'),
            {
                seeds,
                url: url || undefined,
                language,
                location: locations.map((location) => `${location.id}:${location.name}`),
            },
            { preserveState: true, preserveScroll: true, onFinish: () => setLoading(false) },
        );
    }

    function toggleSort(key: SortKey) {
        setSort((current) =>
            current.key === key
                ? { key, dir: current.dir === 'asc' ? 'desc' : 'asc' }
                : { key, dir: key === 'keyword' || key === 'position' ? 'asc' : 'desc' },
        );
    }

    function SortHead({ label, column, className }: { label: string; column: SortKey; className?: string }) {
        const Icon = sort.key !== column ? ArrowUpDown : sort.dir === 'asc' ? ArrowUp : ArrowDown;
        return (
            <TableHead className={className}>
                <button type="button" onClick={() => toggleSort(column)} className="hover:text-foreground inline-flex items-center gap-1">
                    {label}
                    <Icon className={cn('h-3 w-3', sort.key !== column && 'opacity-40')} />
                </button>
            </TableHead>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Keywords" />
            <div className="px-4 py-6 md:px-6">
                <div className="mb-6 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-xl font-bold tracking-tight">Keywords</h1>
                        <p className="text-muted-foreground text-sm">
                            Google Ads Keyword Planner
                            {property && ` · compared with ${property.display_name} Search Console queries (last ${gapDays} days)`}
                        </p>
                    </div>
                    <span className="text-muted-foreground text-xs">
                        API operations today: {usage.today} / {usage.limit}
                    </span>
                </div>

                {!configured && (
                    <div className="mb-4 flex items-start gap-2 rounded-lg border border-amber-500/20 bg-amber-500/10 p-3 text-sm text-amber-600 dark:text-amber-400">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                        Google Ads is not configured. Add the GOOGLE_ADS_* variables to .env (see docs/google-ads-keywords.md).
                    </div>
                )}

                {error && (
                    <div className="mb-4 flex items-start gap-2 rounded-lg border border-red-500/20 bg-red-500/10 p-3 text-sm text-red-500">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                        {error}
                    </div>
                )}

                <Card className="mb-6">
                    <CardContent className="pt-6">
                        <form onSubmit={submit} className="grid grid-cols-1 gap-4 lg:grid-cols-12">
                            <div className="space-y-1.5 lg:col-span-4">
                                <Label htmlFor="seeds">Seed keywords</Label>
                                <Input
                                    id="seeds"
                                    value={seeds}
                                    onChange={(e) => setSeeds(e.target.value)}
                                    placeholder="e.g. calcolo imu, aliquote imu"
                                />
                                <p className="text-muted-foreground text-xs">Comma separated, max 20.</p>
                            </div>
                            <div className="space-y-1.5 lg:col-span-3">
                                <Label htmlFor="url">URL (optional)</Label>
                                <Input id="url" type="url" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://…" />
                            </div>
                            <div className="space-y-1.5 lg:col-span-3">
                                <Label>Locations</Label>
                                <GeoTargetPicker value={locations} onChange={setLocations} />
                            </div>
                            <div className="space-y-1.5 lg:col-span-1">
                                <Label>Language</Label>
                                <Select value={language} onValueChange={setLanguage}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {languages.map((code) => (
                                            <SelectItem key={code} value={code}>
                                                {code.toUpperCase()}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex items-start lg:col-span-1 lg:pt-6">
                                <Button type="submit" className="w-full" disabled={loading || !configured || (!seeds.trim() && !url.trim())}>
                                    {loading ? <Loader2 className="h-4 w-4 animate-spin" /> : <Search className="h-4 w-4" />}
                                    <span className="lg:sr-only">Search</span>
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                {summary && (
                    <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <Stat label="Ideas" value={summary.total.toLocaleString()} />
                        <Stat label="Site present" value={summary.present.toLocaleString()} />
                        <Stat label="Gap (not present)" value={summary.missing.toLocaleString()} />
                        <Stat label="Missed monthly searches" value={`≈ ${summary.missing_volume.toLocaleString()}`} />
                    </div>
                )}

                {results === null ? (
                    <div className="text-muted-foreground flex flex-col items-center justify-center py-16 text-center text-sm">
                        <KeyRound className="mb-3 h-8 w-8 opacity-40" />
                        Enter seed keywords or a URL to get ideas with volumes, competition and CPC.
                    </div>
                ) : (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between gap-4 space-y-0">
                            <CardTitle className="text-base">
                                {rows.length.toLocaleString()} keywords
                                <span className="text-muted-foreground ml-2 text-xs font-normal">volumes are Google approximations</span>
                            </CardTitle>
                            <div className="flex items-center gap-4">
                                {withGsc && (
                                    <label className="flex items-center gap-2 text-sm">
                                        <Switch checked={onlyGap} onCheckedChange={setOnlyGap} />
                                        Only gap
                                    </label>
                                )}
                                <Button variant="outline" size="sm" onClick={() => exportCsv(rows, withGsc)} disabled={rows.length === 0}>
                                    <Download className="h-4 w-4" />
                                    CSV
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <SortHead label="Keyword" column="keyword" />
                                        <SortHead label="Volume" column="volume" className="text-right" />
                                        <SortHead label="Trend" column="trend" />
                                        <SortHead label="Competition" column="competition_index" />
                                        <SortHead label="CPC (top of page)" column="cpc_high" className="text-right" />
                                        {withGsc && <SortHead label="GSC position" column="position" className="text-right" />}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((row) => (
                                        <TableRow key={row.keyword}>
                                            <TableCell className="font-medium">{row.keyword}</TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.volume === null ? '–' : row.volume.toLocaleString()}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <Sparkline points={row.monthly} />
                                                    {row.trend !== null && (
                                                        <span
                                                            className={cn(
                                                                'text-xs tabular-nums',
                                                                row.trend > 0
                                                                    ? 'text-emerald-500'
                                                                    : row.trend < 0
                                                                      ? 'text-red-500'
                                                                      : 'text-muted-foreground',
                                                            )}
                                                        >
                                                            {row.trend > 0 ? '+' : ''}
                                                            {Math.round(row.trend)}%
                                                        </span>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {row.competition ? (
                                                    <Badge variant="outline" className={competitionStyles[row.competition]}>
                                                        {row.competition.toLowerCase()}
                                                        {row.competition_index !== null && (
                                                            <span className="ml-1 opacity-70">{row.competition_index}</span>
                                                        )}
                                                    </Badge>
                                                ) : (
                                                    <span className="text-muted-foreground">–</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right text-xs tabular-nums">
                                                {row.cpc_low === null && row.cpc_high === null
                                                    ? '–'
                                                    : `${money(row.cpc_low)} – ${money(row.cpc_high)}`}
                                            </TableCell>
                                            {withGsc && (
                                                <TableCell className="text-right">
                                                    {row.gsc ? (
                                                        <span
                                                            className="tabular-nums"
                                                            title={`${row.gsc.impressions.toLocaleString()} impressions · ${row.gsc.clicks.toLocaleString()} clicks`}
                                                        >
                                                            {row.gsc.position.toFixed(1)}
                                                            <span className="text-muted-foreground ml-1.5 text-xs">
                                                                {row.gsc.impressions.toLocaleString()} impr.
                                                            </span>
                                                        </span>
                                                    ) : (
                                                        <Badge variant="outline" className="text-muted-foreground">
                                                            not present
                                                        </Badge>
                                                    )}
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-lg border p-3">
            <div className="text-muted-foreground text-xs">{label}</div>
            <div className="mt-1 text-lg font-semibold tabular-nums">{value}</div>
        </div>
    );
}
