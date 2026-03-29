import { usePage } from '@inertiajs/react';

import { ReportLayout } from '@/components/report-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

interface Channel {
    name: string;
    sessions: number;
    users: number;
    bounceRate: number;
    avgDuration: number;
    percent: number;
}

interface Source {
    source: string;
    medium: string;
    sessions: number;
    users: number;
    percent: number;
}

interface TrafficProps {
    channels: Channel[];
    sources: Source[];
    hasProperty: boolean;
    period: string;
    periods: Record<string, string>;
    [key: string]: unknown;
}

const CHANNEL_COLORS: Record<string, string> = {
    'Organic Search': 'hsl(172, 65%, 45%)',
    'Direct': 'hsl(200, 60%, 55%)',
    'Organic Social': 'hsl(280, 55%, 60%)',
    'Referral': 'hsl(35, 80%, 60%)',
    'Paid Search': 'hsl(340, 65%, 58%)',
    'Email': 'hsl(150, 50%, 45%)',
    'Paid Social': 'hsl(310, 55%, 55%)',
};

function formatDuration(seconds: number): string {
    const m = Math.floor(seconds / 60);
    const s = Math.round(seconds % 60);
    return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

export default function TrafficReport() {
    const { channels, sources, hasProperty, period, periods } = usePage<TrafficProps>().props;

    return (
        <ReportLayout
            title="Traffic"
            routeName="reports.traffic"
            hasProperty={hasProperty}
            period={period}
            periods={periods}
        >
            <div className="space-y-6">
                {/* Channel Bar Chart */}
                <Card className="animate-fade-up">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-muted-foreground text-sm font-medium uppercase tracking-wider">
                            Sessions by Channel
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="h-[300px]">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={channels} margin={{ top: 10, right: 10, left: -10, bottom: 0 }}>
                                    <CartesianGrid strokeDasharray="3 3" stroke="hsl(220, 15%, 18%)" vertical={false} />
                                    <XAxis
                                        dataKey="name"
                                        tick={{ fontSize: 12, fill: 'hsl(215, 12%, 55%)' }}
                                        tickLine={false}
                                        axisLine={false}
                                    />
                                    <YAxis
                                        tick={{ fontSize: 12, fontFamily: 'JetBrains Mono', fill: 'hsl(215, 12%, 55%)' }}
                                        tickLine={false}
                                        axisLine={false}
                                        width={50}
                                    />
                                    <Tooltip
                                        contentStyle={{
                                            borderRadius: '10px',
                                            border: '1px solid hsl(220, 15%, 20%)',
                                            backgroundColor: 'hsl(220, 18%, 12%)',
                                            fontSize: '12px',
                                            color: 'hsl(210, 20%, 93%)',
                                        }}
                                    />
                                    <Bar dataKey="sessions" name="Sessions" radius={[4, 4, 0, 0]} fill="hsl(172, 65%, 45%)" />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </CardContent>
                </Card>

                {/* Channels Table */}
                <Card className="animate-fade-up" style={{ animationDelay: '100ms' }}>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-muted-foreground text-sm font-medium uppercase tracking-wider">
                            Channel Breakdown
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-xs uppercase tracking-wider">
                                        <th className="pb-3 text-left font-medium">Channel</th>
                                        <th className="pb-3 text-right font-medium">Sessions</th>
                                        <th className="pb-3 text-right font-medium">Users</th>
                                        <th className="pb-3 text-right font-medium">Share</th>
                                        <th className="pb-3 text-right font-medium">Bounce</th>
                                        <th className="pb-3 text-right font-medium">Avg. Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {channels.map((ch) => (
                                        <tr key={ch.name} className="hover:bg-muted/50 border-b border-border/50 transition-colors">
                                            <td className="py-3">
                                                <div className="flex items-center gap-2">
                                                    <div
                                                        className="h-2.5 w-2.5 rounded-full"
                                                        style={{ backgroundColor: CHANNEL_COLORS[ch.name] ?? 'hsl(215, 12%, 55%)' }}
                                                    />
                                                    {ch.name}
                                                </div>
                                            </td>
                                            <td className="py-3 text-right font-mono">{ch.sessions.toLocaleString()}</td>
                                            <td className="py-3 text-right font-mono">{ch.users.toLocaleString()}</td>
                                            <td className="py-3 text-right font-mono">{ch.percent}%</td>
                                            <td className="py-3 text-right font-mono">{ch.bounceRate}%</td>
                                            <td className="py-3 text-right font-mono">{formatDuration(ch.avgDuration)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* Sources Table */}
                <Card className="animate-fade-up" style={{ animationDelay: '200ms' }}>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-muted-foreground text-sm font-medium uppercase tracking-wider">
                            Top Sources
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-xs uppercase tracking-wider">
                                        <th className="pb-3 text-left font-medium">Source / Medium</th>
                                        <th className="pb-3 text-right font-medium">Sessions</th>
                                        <th className="pb-3 text-right font-medium">Users</th>
                                        <th className="pb-3 text-right font-medium">Share</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {sources.map((s, i) => (
                                        <tr key={i} className="hover:bg-muted/50 border-b border-border/50 transition-colors">
                                            <td className="py-3">
                                                <span className="font-medium">{s.source}</span>
                                                <span className="text-muted-foreground"> / {s.medium}</span>
                                            </td>
                                            <td className="py-3 text-right font-mono">{s.sessions.toLocaleString()}</td>
                                            <td className="py-3 text-right font-mono">{s.users.toLocaleString()}</td>
                                            <td className="py-3 text-right font-mono">{s.percent}%</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </ReportLayout>
    );
}
