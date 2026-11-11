import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface ToolRow {
    tool: string;
    calls: number;
    errors: number;
    avg_bytes: number | null;
    max_bytes: number | null;
    total_bytes: number | null;
    avg_ms: number | null;
}

interface AgentRow {
    agent: string;
    calls: number;
    errors: number;
    total_bytes: number | null;
}

interface LogRow {
    id: number;
    tool: string;
    agent: string | null;
    arguments: Record<string, unknown> | null;
    response_bytes: number | null;
    is_error: boolean;
    error: string | null;
    duration_ms: number;
    created_at: string;
}

interface Props {
    days: number;
    periods: number[];
    onlyErrors: boolean;
    byTool: ToolRow[];
    byAgent: AgentRow[];
    recent: LogRow[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'MCP log', href: '/mcp-log' }];

/** Rough token estimate: about 3.5 bytes of JSON per token. */
function tokens(bytes: number | null): string {
    if (bytes === null) {
        return '–';
    }
    const value = Math.round(Number(bytes) / 3.5);
    return value >= 1000 ? `${(value / 1000).toFixed(1)}k` : String(value);
}

function shortTool(tool: string): string {
    return tool.replace(/-tool$/, '');
}

export default function McpLogPage({ days, periods, onlyErrors, byTool, byAgent, recent }: Props) {
    const query = (params: { days?: number; errors?: boolean }) => {
        const search = new URLSearchParams({ days: String(params.days ?? days) });
        if (params.errors ?? onlyErrors) {
            search.set('errors', '1');
        }
        return `/mcp-log?${search.toString()}`;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="MCP log" />

            <div className="space-y-6 px-4 py-6 md:px-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <HeadingSmall
                        title="MCP log"
                        description="Tool calls by agent, with the weight of each answer in the agent's context (≈ tokens). Name the agent with ?agent=seo in the connector URL."
                    />
                    <div className="flex gap-1 text-sm">
                        {periods.map((period) => (
                            <Link
                                key={period}
                                href={query({ days: period })}
                                className={`rounded-md px-2.5 py-1 ${period === days ? 'bg-muted font-medium' : 'text-muted-foreground'}`}
                            >
                                {period}d
                            </Link>
                        ))}
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">By tool</CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        {byTool.length === 0 ? (
                            <p className="text-muted-foreground py-6 text-center text-sm">No MCP calls in this period.</p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Tool</TableHead>
                                        <TableHead className="text-right">Calls</TableHead>
                                        <TableHead className="text-right">Errors</TableHead>
                                        <TableHead className="text-right">Avg tokens</TableHead>
                                        <TableHead className="text-right">Max tokens</TableHead>
                                        <TableHead className="text-right">Total tokens</TableHead>
                                        <TableHead className="text-right">Avg ms</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {byTool.map((row) => (
                                        <TableRow key={row.tool}>
                                            <TableCell className="font-mono text-xs">{shortTool(row.tool)}</TableCell>
                                            <TableCell className="text-right">{row.calls}</TableCell>
                                            <TableCell className={`text-right ${Number(row.errors) > 0 ? 'text-red-500' : ''}`}>
                                                {row.errors}
                                            </TableCell>
                                            <TableCell className="text-right">{tokens(row.avg_bytes)}</TableCell>
                                            <TableCell className="text-right">{tokens(row.max_bytes)}</TableCell>
                                            <TableCell className="text-right">{tokens(row.total_bytes)}</TableCell>
                                            <TableCell className="text-right">{row.avg_ms ?? '–'}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                {byAgent.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">By agent</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-6 text-sm">
                            {byAgent.map((row) => (
                                <div key={row.agent}>
                                    <div className="font-medium">{row.agent || 'unnamed'}</div>
                                    <div className="text-muted-foreground">
                                        {row.calls} calls · {row.errors} errors · {tokens(row.total_bytes)} tokens
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="text-base">Latest calls</CardTitle>
                        <Link href={query({ errors: !onlyErrors })} className="text-muted-foreground text-sm underline">
                            {onlyErrors ? 'Show all' : 'Only errors'}
                        </Link>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>When</TableHead>
                                    <TableHead>Agent</TableHead>
                                    <TableHead>Tool</TableHead>
                                    <TableHead>Arguments</TableHead>
                                    <TableHead className="text-right">Tokens</TableHead>
                                    <TableHead className="text-right">ms</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recent.map((row) => (
                                    <TableRow key={row.id} className="align-top">
                                        <TableCell className="text-xs whitespace-nowrap">{row.created_at.replace('T', ' ').slice(0, 19)}</TableCell>
                                        <TableCell>{row.agent ?? '–'}</TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {shortTool(row.tool)}
                                            {row.is_error && (
                                                <div className="mt-1 max-w-md whitespace-normal">
                                                    <Badge variant="destructive">error</Badge> <span className="text-red-500">{row.error}</span>
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground max-w-xs truncate font-mono text-xs">
                                            {row.arguments ? JSON.stringify(row.arguments) : ''}
                                        </TableCell>
                                        <TableCell className="text-right">{tokens(row.response_bytes)}</TableCell>
                                        <TableCell className="text-right">{row.duration_ms}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
