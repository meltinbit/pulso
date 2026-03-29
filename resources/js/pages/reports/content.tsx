import { usePage } from '@inertiajs/react';
import { useState } from 'react';

import { ReportLayout } from '@/components/report-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Search } from 'lucide-react';

interface PageItem {
    path: string;
    title: string;
    views: number;
    users: number;
    bounceRate: number;
    avgDuration: string;
}

interface ContentProps {
    pages: PageItem[];
    hasProperty: boolean;
    period: string;
    periods: Record<string, string>;
    [key: string]: unknown;
}

export default function ContentReport() {
    const { pages, hasProperty, period, periods } = usePage<ContentProps>().props;
    const [filter, setFilter] = useState('');

    const filteredPages = filter
        ? pages.filter(
              (p) =>
                  p.path.toLowerCase().includes(filter.toLowerCase()) ||
                  p.title.toLowerCase().includes(filter.toLowerCase()),
          )
        : pages;

    const maxViews = filteredPages[0]?.views ?? 1;

    return (
        <ReportLayout
            title="Content"
            routeName="reports.content"
            hasProperty={hasProperty}
            period={period}
            periods={periods}
        >
            <Card className="animate-fade-up">
                <CardHeader className="pb-3">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <CardTitle className="text-muted-foreground text-sm font-medium uppercase tracking-wider">
                            Top Pages
                        </CardTitle>
                        <div className="relative w-full sm:w-64">
                            <Search className="text-muted-foreground absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2" />
                            <Input
                                placeholder="Filter pages..."
                                value={filter}
                                onChange={(e) => setFilter(e.target.value)}
                                className="pl-9"
                            />
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-xs uppercase tracking-wider">
                                    <th className="pb-3 text-left font-medium">#</th>
                                    <th className="pb-3 text-left font-medium">Page</th>
                                    <th className="pb-3 text-right font-medium">Views</th>
                                    <th className="pb-3 text-right font-medium">Users</th>
                                    <th className="pb-3 text-right font-medium">Bounce</th>
                                    <th className="pb-3 text-right font-medium">Avg. Time</th>
                                    <th className="hidden pb-3 text-left font-medium sm:table-cell" style={{ width: '20%' }} />
                                </tr>
                            </thead>
                            <tbody>
                                {filteredPages.map((page, i) => (
                                    <tr key={page.path} className="hover:bg-muted/50 border-b border-border/50 transition-colors">
                                        <td className="text-muted-foreground py-3 font-mono text-xs">{i + 1}</td>
                                        <td className="max-w-xs py-3">
                                            <p className="truncate font-mono text-xs font-medium">{page.path}</p>
                                            {page.title && (
                                                <p className="text-muted-foreground mt-0.5 truncate text-xs">{page.title}</p>
                                            )}
                                        </td>
                                        <td className="py-3 text-right font-mono">{page.views.toLocaleString()}</td>
                                        <td className="py-3 text-right font-mono">{page.users.toLocaleString()}</td>
                                        <td className="py-3 text-right font-mono">{page.bounceRate}%</td>
                                        <td className="py-3 text-right font-mono">{page.avgDuration}</td>
                                        <td className="hidden py-3 sm:table-cell">
                                            <div className="bg-muted h-2 overflow-hidden rounded-full">
                                                <div
                                                    className="bg-primary h-full rounded-full transition-all duration-500"
                                                    style={{ width: `${(page.views / maxViews) * 100}%` }}
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {filteredPages.length === 0 && (
                            <p className="text-muted-foreground py-8 text-center text-sm">No pages match your filter.</p>
                        )}
                    </div>
                </CardContent>
            </Card>
        </ReportLayout>
    );
}
