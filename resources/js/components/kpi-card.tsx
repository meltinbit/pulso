import { Card, CardContent } from '@/components/ui/card';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { ArrowDown, ArrowUp, HelpCircle, Minus } from 'lucide-react';

interface KpiCardProps {
    title: string;
    value: string | number;
    description?: string;
    comparison?: {
        value: string | number;
        label: string;
    };
    trend?: 'up' | 'down' | 'neutral';
    accent?: boolean;
    delay?: number;
    className?: string;
}

export function KpiCard({ title, value, description, comparison, trend, accent, delay = 0, className }: KpiCardProps) {
    return (
        <Card
            className={cn(
                'animate-fade-up transition-shadow duration-300 hover:shadow-md',
                accent && 'border-primary/30 bg-primary/5 dark:bg-primary/5',
                className,
            )}
            style={{ animationDelay: `${delay}ms` }}
        >
            <CardContent className="p-5">
                <div className="flex items-center gap-1.5">
                    <p className="text-muted-foreground text-sm font-medium uppercase tracking-wider">{title}</p>
                    {description && (
                        <TooltipProvider delayDuration={0}>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <HelpCircle className="text-muted-foreground/50 h-3.5 w-3.5 cursor-help" />
                                </TooltipTrigger>
                                <TooltipContent side="top" className="max-w-[240px] text-sm leading-relaxed">
                                    {description}
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    )}
                </div>
                <p className="mt-2 font-mono text-3xl font-bold tracking-tight">{value}</p>
                {comparison && (
                    <div className="mt-2 flex items-center gap-1.5 text-sm">
                        <span
                            className={cn(
                                'flex items-center gap-0.5 rounded-full px-2 py-0.5 font-medium',
                                trend === 'up' && 'bg-emerald-500/10 text-emerald-500',
                                trend === 'down' && 'bg-red-500/10 text-red-500',
                                trend === 'neutral' && 'bg-muted text-muted-foreground',
                            )}
                        >
                            {trend === 'up' && <ArrowUp className="h-3.5 w-3.5" />}
                            {trend === 'down' && <ArrowDown className="h-3.5 w-3.5" />}
                            {trend === 'neutral' && <Minus className="h-3.5 w-3.5" />}
                            {comparison.value}
                        </span>
                        <span className="text-muted-foreground">{comparison.label}</span>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
