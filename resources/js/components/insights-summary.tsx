import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { CheckCircle2, AlertTriangle, XCircle, TrendingUp, TrendingDown, Minus } from 'lucide-react';

type Rating = 'good' | 'warning' | 'bad' | 'neutral';

interface Insight {
    label: string;
    message: string;
    rating: Rating;
}

interface InsightsSummaryProps {
    bounceRate: number;
    avgSessionDuration: string;
    usersToday: number;
    usersSameDayLastWeek: number;
    totalUsers: number;
    totalSessions: number;
    topChannel?: string;
    topCountry?: string;
    mobilePercent?: number;
    delay?: number;
}

function parseSeconds(duration: string): number {
    const match = duration.match(/(?:(\d+)m\s*)?(\d+)s/);
    if (!match) return 0;
    return (parseInt(match[1] || '0') * 60) + parseInt(match[2] || '0');
}

function ratingIcon(rating: Rating) {
    switch (rating) {
        case 'good':
            return <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-400" />;
        case 'warning':
            return <AlertTriangle className="h-4 w-4 shrink-0 text-amber-400" />;
        case 'bad':
            return <XCircle className="h-4 w-4 shrink-0 text-red-400" />;
        default:
            return <Minus className="h-4 w-4 shrink-0 text-muted-foreground" />;
    }
}

function ratingBg(rating: Rating) {
    switch (rating) {
        case 'good':
            return 'bg-emerald-500/5 border-emerald-500/20';
        case 'warning':
            return 'bg-amber-500/5 border-amber-500/20';
        case 'bad':
            return 'bg-red-500/5 border-red-500/20';
        default:
            return 'bg-muted/50 border-border';
    }
}

export function InsightsSummary({
    bounceRate,
    avgSessionDuration,
    usersToday,
    usersSameDayLastWeek,
    totalUsers,
    totalSessions,
    topChannel,
    topCountry,
    mobilePercent,
    delay = 0,
}: InsightsSummaryProps) {
    const seconds = parseSeconds(avgSessionDuration);
    const todayChange = usersSameDayLastWeek > 0
        ? Math.round(((usersToday - usersSameDayLastWeek) / usersSameDayLastWeek) * 100)
        : 0;
    const sessionsPerUser = totalUsers > 0 ? (totalSessions / totalUsers).toFixed(1) : '0';

    const insights: Insight[] = [];

    // Bounce rate insight
    if (bounceRate < 35) {
        insights.push({
            label: 'Bounce rate',
            message: `Excellent at ${bounceRate}% — visitors are exploring your site well.`,
            rating: 'good',
        });
    } else if (bounceRate < 55) {
        insights.push({
            label: 'Bounce rate',
            message: `${bounceRate}% is normal — most visitors look at more than one page.`,
            rating: 'good',
        });
    } else if (bounceRate < 70) {
        insights.push({
            label: 'Bounce rate',
            message: `${bounceRate}% is a bit high — many visitors leave after one page. Consider improving your content or internal links.`,
            rating: 'warning',
        });
    } else {
        insights.push({
            label: 'Bounce rate',
            message: `${bounceRate}% is high — most visitors leave immediately. Check if your landing pages match what users expect.`,
            rating: 'bad',
        });
    }

    // Session duration insight
    if (seconds >= 180) {
        insights.push({
            label: 'Time on site',
            message: `Great — visitors spend ${avgSessionDuration} on average. They're finding your content valuable.`,
            rating: 'good',
        });
    } else if (seconds >= 60) {
        insights.push({
            label: 'Time on site',
            message: `${avgSessionDuration} average is decent. Consider adding engaging content to keep visitors longer.`,
            rating: 'good',
        });
    } else if (seconds >= 20) {
        insights.push({
            label: 'Time on site',
            message: `${avgSessionDuration} is short — visitors aren't staying long. Your pages may need more compelling content.`,
            rating: 'warning',
        });
    } else {
        insights.push({
            label: 'Time on site',
            message: `Only ${avgSessionDuration} on average — visitors leave almost immediately. There may be a UX or content issue.`,
            rating: 'bad',
        });
    }

    // Today's traffic insight
    if (todayChange > 20) {
        insights.push({
            label: 'Today vs last week',
            message: `Traffic is up ${todayChange}% compared to the same day last week. Something is working well!`,
            rating: 'good',
        });
    } else if (todayChange > -10) {
        insights.push({
            label: 'Today vs last week',
            message: `Traffic is stable compared to last week (${todayChange > 0 ? '+' : ''}${todayChange}%).`,
            rating: 'neutral',
        });
    } else {
        insights.push({
            label: 'Today vs last week',
            message: `Traffic is down ${Math.abs(todayChange)}% from last week. Check if there's a specific cause.`,
            rating: 'warning',
        });
    }

    // Engagement insight
    if (parseFloat(sessionsPerUser) >= 2) {
        insights.push({
            label: 'Returning visitors',
            message: `Users visit ${sessionsPerUser} times on average — good sign of loyalty.`,
            rating: 'good',
        });
    } else {
        insights.push({
            label: 'Returning visitors',
            message: `${sessionsPerUser} sessions per user — most are one-time visitors. Consider email capture or remarketing.`,
            rating: 'neutral',
        });
    }

    // Top channel
    if (topChannel) {
        insights.push({
            label: 'Main traffic source',
            message: `Most of your traffic comes from "${topChannel}".`,
            rating: 'neutral',
        });
    }

    // Mobile
    if (mobilePercent !== undefined) {
        if (mobilePercent > 60) {
            insights.push({
                label: 'Mobile traffic',
                message: `${mobilePercent}% of visitors are on mobile — make sure your site is fast and mobile-friendly.`,
                rating: 'neutral',
            });
        } else if (mobilePercent > 40) {
            insights.push({
                label: 'Device split',
                message: `Balanced traffic: ${mobilePercent}% mobile, ${100 - mobilePercent}% desktop.`,
                rating: 'neutral',
            });
        }
    }

    return (
        <Card className="animate-fade-up" style={{ animationDelay: `${delay}ms` }}>
            <CardContent className="p-5">
                <p className="text-muted-foreground mb-4 text-sm font-medium uppercase tracking-wider">
                    How is your site doing?
                </p>
                <div className="grid gap-3 sm:grid-cols-2">
                    {insights.map((insight) => (
                        <div
                            key={insight.label}
                            className={cn('flex items-start gap-3 rounded-lg border px-4 py-3.5', ratingBg(insight.rating))}
                        >
                            {ratingIcon(insight.rating)}
                            <div className="min-w-0">
                                <p className="text-sm font-semibold">{insight.label}</p>
                                <p className="text-muted-foreground mt-0.5 text-xs leading-relaxed">
                                    {insight.message}
                                </p>
                            </div>
                        </div>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}
