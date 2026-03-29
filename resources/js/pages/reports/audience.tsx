import { usePage } from '@inertiajs/react';

import { ReportLayout } from '@/components/report-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Monitor, Smartphone, Tablet } from 'lucide-react';
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';

interface DimensionItem {
    name: string;
    value: number;
    extra: number;
    percent: number;
}

interface AudienceProps {
    countries: DimensionItem[];
    devices: DimensionItem[];
    browsers: DimensionItem[];
    newVsReturning: DimensionItem[];
    hasProperty: boolean;
    period: string;
    periods: Record<string, string>;
    [key: string]: unknown;
}

const DEVICE_COLORS: Record<string, string> = {
    desktop: 'hsl(172, 65%, 45%)',
    mobile: 'hsl(200, 60%, 55%)',
    tablet: 'hsl(35, 80%, 60%)',
};

const DEVICE_ICONS: Record<string, React.ElementType> = {
    desktop: Monitor,
    mobile: Smartphone,
    tablet: Tablet,
};

const NVR_COLORS: Record<string, string> = {
    new: 'hsl(172, 65%, 45%)',
    returning: 'hsl(280, 55%, 60%)',
};

export default function AudienceReport() {
    const { countries, devices, browsers, newVsReturning, hasProperty, period, periods } =
        usePage<AudienceProps>().props;

    const deviceChartData = devices.map((d) => ({
        ...d,
        fill: DEVICE_COLORS[d.name.toLowerCase()] ?? 'hsl(215, 12%, 55%)',
    }));

    const nvrChartData = newVsReturning.map((d) => ({
        ...d,
        fill: NVR_COLORS[d.name.toLowerCase()] ?? 'hsl(215, 12%, 55%)',
    }));

    return (
        <ReportLayout
            title="Audience"
            routeName="reports.audience"
            hasProperty={hasProperty}
            period={period}
            periods={periods}
        >
            <div className="space-y-6">
                {/* Top row: Devices + New vs Returning */}
                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Devices */}
                    <Card className="animate-fade-up">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-muted-foreground text-sm font-medium uppercase tracking-wider">
                                Devices
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-8">
                                <div className="h-[160px] w-[160px] shrink-0">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <PieChart>
                                            <Pie
                                                data={deviceChartData}
                                                dataKey="value"
                                                cx="50%"
                                                cy="50%"
                                                innerRadius={42}
                                                outerRadius={72}
                                                strokeWidth={2}
                                                stroke="hsl(var(--card))"
                                            >
                                                {deviceChartData.map((entry, i) => (
                                                    <Cell key={i} fill={entry.fill} />
                                                ))}
                                            </Pie>
                                            <Tooltip
                                                contentStyle={{
                                                    borderRadius: '10px',
                                                    border: '1px solid hsl(220, 15%, 20%)',
                                                    backgroundColor: 'hsl(220, 18%, 12%)',
                                                    fontSize: '13px',
                                                    color: 'hsl(210, 20%, 93%)',
                                                }}
                                            />
                                        </PieChart>
                                    </ResponsiveContainer>
                                </div>
                                <div className="flex-1 space-y-4">
                                    {devices.map((item) => {
                                        const Icon = DEVICE_ICONS[item.name.toLowerCase()];
                                        const color = DEVICE_COLORS[item.name.toLowerCase()] ?? 'hsl(215, 12%, 55%)';
                                        return (
                                            <div key={item.name} className="flex items-center justify-between">
                                                <div className="flex items-center gap-2.5">
                                                    <div className="h-3 w-3 rounded-full" style={{ backgroundColor: color }} />
                                                    {Icon && <Icon className="text-muted-foreground h-4 w-4" />}
                                                    <span className="text-sm capitalize">{item.name}</span>
                                                </div>
                                                <div className="flex items-center gap-3">
                                                    <span className="font-mono text-sm font-medium">{item.value.toLocaleString()}</span>
                                                    <span className="text-muted-foreground w-12 text-right font-mono text-xs">
                                                        {item.percent}%
                                                    </span>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* New vs Returning */}
                    <Card className="animate-fade-up" style={{ animationDelay: '50ms' }}>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-muted-foreground text-sm font-medium uppercase tracking-wider">
                                New vs Returning
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-8">
                                <div className="h-[160px] w-[160px] shrink-0">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <PieChart>
                                            <Pie
                                                data={nvrChartData}
                                                dataKey="value"
                                                cx="50%"
                                                cy="50%"
                                                innerRadius={42}
                                                outerRadius={72}
                                                strokeWidth={2}
                                                stroke="hsl(var(--card))"
                                            >
                                                {nvrChartData.map((entry, i) => (
                                                    <Cell key={i} fill={entry.fill} />
                                                ))}
                                            </Pie>
                                            <Tooltip
                                                contentStyle={{
                                                    borderRadius: '10px',
                                                    border: '1px solid hsl(220, 15%, 20%)',
                                                    backgroundColor: 'hsl(220, 18%, 12%)',
                                                    fontSize: '13px',
                                                    color: 'hsl(210, 20%, 93%)',
                                                }}
                                            />
                                        </PieChart>
                                    </ResponsiveContainer>
                                </div>
                                <div className="flex-1 space-y-4">
                                    {newVsReturning.map((item) => {
                                        const color = NVR_COLORS[item.name.toLowerCase()] ?? 'hsl(215, 12%, 55%)';
                                        return (
                                            <div key={item.name} className="flex items-center justify-between">
                                                <div className="flex items-center gap-2.5">
                                                    <div className="h-3 w-3 rounded-full" style={{ backgroundColor: color }} />
                                                    <span className="text-sm capitalize">{item.name} visitors</span>
                                                </div>
                                                <div className="flex items-center gap-3">
                                                    <span className="font-mono text-sm font-medium">{item.value.toLocaleString()}</span>
                                                    <span className="text-muted-foreground w-12 text-right font-mono text-xs">
                                                        {item.percent}%
                                                    </span>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Countries */}
                <Card className="animate-fade-up" style={{ animationDelay: '100ms' }}>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-muted-foreground text-sm font-medium uppercase tracking-wider">
                            Top Countries
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-xs uppercase tracking-wider">
                                        <th className="pb-3 text-left font-medium">#</th>
                                        <th className="pb-3 text-left font-medium">Country</th>
                                        <th className="pb-3 text-right font-medium">Users</th>
                                        <th className="pb-3 text-right font-medium">Sessions</th>
                                        <th className="pb-3 text-right font-medium">Share</th>
                                        <th className="hidden pb-3 sm:table-cell" style={{ width: '25%' }} />
                                    </tr>
                                </thead>
                                <tbody>
                                    {countries.map((c, i) => (
                                        <tr key={c.name} className="hover:bg-muted/50 border-b border-border/50 transition-colors">
                                            <td className="text-muted-foreground py-3 font-mono text-xs">{i + 1}</td>
                                            <td className="py-3 font-medium">{c.name}</td>
                                            <td className="py-3 text-right font-mono">{c.value.toLocaleString()}</td>
                                            <td className="py-3 text-right font-mono">{c.extra.toLocaleString()}</td>
                                            <td className="py-3 text-right font-mono">{c.percent}%</td>
                                            <td className="hidden py-3 sm:table-cell">
                                                <div className="bg-muted h-2 overflow-hidden rounded-full">
                                                    <div
                                                        className="bg-primary h-full rounded-full transition-all duration-500"
                                                        style={{ width: `${c.percent}%` }}
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* Browsers */}
                <Card className="animate-fade-up" style={{ animationDelay: '150ms' }}>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-muted-foreground text-sm font-medium uppercase tracking-wider">
                            Browsers
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {browsers.map((b) => (
                                <div key={b.name} className="flex items-center justify-between rounded-lg border p-3">
                                    <span className="text-sm">{b.name}</span>
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono text-sm font-medium">{b.value.toLocaleString()}</span>
                                        <span className="text-muted-foreground font-mono text-xs">{b.percent}%</span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </ReportLayout>
    );
}
