import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { Monitor, Smartphone, Tablet } from 'lucide-react';
import { Cell, Pie, PieChart, ResponsiveContainer } from 'recharts';

interface EventItem {
    name: string;
    count: number;
    users: number;
    label: string;
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

const CHANNEL_COLORS: Record<string, string> = {
    'Organic Search': 'hsl(172, 65%, 45%)',
    'Direct': 'hsl(200, 60%, 55%)',
    'Organic Social': 'hsl(280, 55%, 60%)',
    'Referral': 'hsl(35, 80%, 60%)',
    'Paid Search': 'hsl(340, 65%, 58%)',
    'Email': 'hsl(150, 50%, 45%)',
    'Paid Social': 'hsl(310, 55%, 55%)',
};

export function CountriesWidget({ data, delay = 0 }: { data: DimensionItem[]; delay?: number }) {
    if (data.length === 0) return null;
    const max = data[0]?.value ?? 1;

    return (
        <Card className="animate-fade-up" style={{ animationDelay: `${delay}ms` }}>
            <CardHeader className="pb-3">
                <CardTitle className="text-muted-foreground text-sm font-medium uppercase tracking-wider">
                    Top Countries
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-2.5">
                {data.map((item, i) => (
                    <div key={item.name} className="group flex items-center gap-3">
                        <span className="text-muted-foreground w-5 text-right font-mono text-xs">{i + 1}</span>
                        <div className="flex-1">
                            <div className="mb-1 flex items-center justify-between">
                                <span className="text-sm">{item.name}</span>
                                <div className="flex items-center gap-2">
                                    <span className="font-mono text-sm font-medium">{item.value.toLocaleString()}</span>
                                    <span className="text-muted-foreground w-10 text-right font-mono text-xs">
                                        {item.percent}%
                                    </span>
                                </div>
                            </div>
                            <div className="bg-muted h-1 overflow-hidden rounded-full">
                                <div
                                    className="bg-primary h-full rounded-full transition-all duration-500"
                                    style={{ width: `${(item.value / max) * 100}%` }}
                                />
                            </div>
                        </div>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

export function DevicesWidget({ data, delay = 0 }: { data: DimensionItem[]; delay?: number }) {
    if (data.length === 0) return null;
    const total = data.reduce((sum, d) => sum + d.value, 0);

    const chartData = data.map((d) => ({
        ...d,
        fill: DEVICE_COLORS[d.name.toLowerCase()] ?? 'hsl(215, 12%, 45%)',
    }));

    return (
        <Card className="animate-fade-up" style={{ animationDelay: `${delay}ms` }}>
            <CardHeader className="pb-3">
                <CardTitle className="text-muted-foreground text-sm font-medium uppercase tracking-wider">
                    Devices
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="flex items-center gap-6">
                    <div className="h-[120px] w-[120px] shrink-0">
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie
                                    data={chartData}
                                    dataKey="value"
                                    cx="50%"
                                    cy="50%"
                                    innerRadius={32}
                                    outerRadius={55}
                                    strokeWidth={2}
                                    stroke="hsl(var(--card))"
                                >
                                    {chartData.map((entry, i) => (
                                        <Cell key={i} fill={entry.fill} />
                                    ))}
                                </Pie>
                            </PieChart>
                        </ResponsiveContainer>
                    </div>
                    <div className="flex-1 space-y-3">
                        {data.map((item) => {
                            const Icon = DEVICE_ICONS[item.name.toLowerCase()];
                            const color = DEVICE_COLORS[item.name.toLowerCase()] ?? 'hsl(215, 12%, 45%)';
                            return (
                                <div key={item.name} className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <div className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: color }} />
                                        {Icon && <Icon className="text-muted-foreground h-3.5 w-3.5" />}
                                        <span className="text-sm capitalize">{item.name}</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono text-sm font-medium">{item.value.toLocaleString()}</span>
                                        <span className="text-muted-foreground w-10 text-right font-mono text-xs">
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
    );
}

export function PagesWidget({ data, delay = 0 }: { data: PageItem[]; delay?: number }) {
    if (data.length === 0) return null;

    return (
        <Card className="animate-fade-up" style={{ animationDelay: `${delay}ms` }}>
            <CardHeader className="pb-3">
                <CardTitle className="text-muted-foreground text-sm font-medium uppercase tracking-wider">
                    Top Pages
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="space-y-2">
                    <div className="text-muted-foreground flex items-center gap-3 text-xs font-medium uppercase tracking-wider">
                        <span className="w-5" />
                        <span className="flex-1">Page</span>
                        <span className="w-16 text-right">Views</span>
                        <span className="w-14 text-right">Users</span>
                    </div>
                    {data.map((page, i) => (
                        <div
                            key={page.path}
                            className="hover:bg-muted/50 flex items-center gap-3 rounded-md px-0 py-1.5 transition-colors"
                        >
                            <span className="text-muted-foreground w-5 text-right font-mono text-xs">{i + 1}</span>
                            <div className="min-w-0 flex-1">
                                <p className="truncate font-mono text-xs">{page.path}</p>
                                {page.title && (
                                    <p className="text-muted-foreground mt-0.5 truncate text-xs">{page.title}</p>
                                )}
                            </div>
                            <span className="w-16 text-right font-mono text-sm font-medium">
                                {page.views.toLocaleString()}
                            </span>
                            <span className="text-muted-foreground w-14 text-right font-mono text-xs">
                                {page.users.toLocaleString()}
                            </span>
                        </div>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}

const EVENT_DESCRIPTIONS: Record<string, string> = {
    page_view: 'Every time someone views a page on your site',
    session_start: 'A new visit begins (user arrives on your site)',
    first_visit: 'Someone visits your site for the very first time',
    scroll: 'User scrolled to the bottom of a page (90%+)',
    click: 'Click on a link leading to another website (e.g. App Store)',
    user_engagement: 'User was actively engaged (10+ seconds on page)',
    view_search_results: 'Someone used the search on your site',
    file_download: 'A file was downloaded (PDF, ZIP, etc.)',
    form_start: 'User started filling out a form',
    form_submit: 'User submitted a form',
    purchase: 'A purchase was completed',
    add_to_cart: 'Item added to shopping cart',
    begin_checkout: 'User started checkout process',
    sign_up: 'New user registration',
    login: 'User logged in',
};

export function EventsWidget({ data, delay = 0 }: { data: EventItem[]; delay?: number }) {
    if (data.length === 0) return null;

    return (
        <Card className="animate-fade-up lg:col-span-2" style={{ animationDelay: `${delay}ms` }}>
            <CardHeader className="pb-3">
                <CardTitle className="text-muted-foreground text-sm font-medium uppercase tracking-wider">
                    Top Events
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="space-y-1">
                    <div className="text-muted-foreground flex items-center gap-3 pb-1 text-xs font-medium uppercase tracking-wider">
                        <span className="flex-1">Event</span>
                        <span className="w-20 text-right">Count</span>
                        <span className="w-16 text-right">Users</span>
                    </div>
                    {data.map((event) => (
                        <div
                            key={event.name}
                            className="hover:bg-muted/50 group flex items-center gap-3 rounded-md py-2 transition-colors"
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2">
                                    <span className="text-sm font-medium">{event.label}</span>
                                    {event.name === 'click' && (
                                        <span className="rounded bg-blue-500/10 px-1.5 py-0.5 text-xs font-medium text-blue-400">
                                            outbound
                                        </span>
                                    )}
                                </div>
                                {EVENT_DESCRIPTIONS[event.name] && (
                                    <p className="text-muted-foreground mt-0.5 text-xs leading-relaxed">
                                        {EVENT_DESCRIPTIONS[event.name]}
                                    </p>
                                )}
                            </div>
                            <span className="w-20 text-right font-mono text-sm font-medium">
                                {event.count.toLocaleString()}
                            </span>
                            <span className="text-muted-foreground w-16 text-right font-mono text-xs">
                                {event.users.toLocaleString()}
                            </span>
                        </div>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}

export function ChannelsWidget({ data, delay = 0 }: { data: DimensionItem[]; delay?: number }) {
    if (data.length === 0) return null;
    const max = data[0]?.value ?? 1;

    return (
        <Card className="animate-fade-up" style={{ animationDelay: `${delay}ms` }}>
            <CardHeader className="pb-3">
                <CardTitle className="text-muted-foreground text-sm font-medium uppercase tracking-wider">
                    Traffic Channels
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-2.5">
                {data.map((item) => {
                    const color = CHANNEL_COLORS[item.name] ?? 'hsl(215, 12%, 55%)';
                    return (
                        <div key={item.name}>
                            <div className="mb-1 flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <div className="h-2 w-2 rounded-full" style={{ backgroundColor: color }} />
                                    <span className="text-sm">{item.name}</span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className="font-mono text-sm font-medium">{item.value.toLocaleString()}</span>
                                    <span className="text-muted-foreground w-10 text-right font-mono text-xs">
                                        {item.percent}%
                                    </span>
                                </div>
                            </div>
                            <div className="bg-muted h-1.5 overflow-hidden rounded-full">
                                <div
                                    className="h-full rounded-full transition-all duration-500"
                                    style={{ width: `${(item.value / max) * 100}%`, backgroundColor: color }}
                                />
                            </div>
                        </div>
                    );
                })}
            </CardContent>
        </Card>
    );
}
