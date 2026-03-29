import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

interface TrendDataPoint {
    date: string;
    activeUsers: number;
    sessions: number;
}

interface TrendChartProps {
    data: TrendDataPoint[];
    title?: string;
}

export function TrendChart({ data, title = 'Active Users — Last 30 Days' }: TrendChartProps) {
    return (
        <Card className="animate-fade-up" style={{ animationDelay: '350ms' }}>
            <CardHeader className="pb-2">
                <CardTitle className="text-muted-foreground text-sm font-medium uppercase tracking-wider">
                    {title}
                </CardTitle>
            </CardHeader>
            <CardContent className="pt-0">
                <div className="h-[320px]">
                    <ResponsiveContainer width="100%" height="100%">
                        <AreaChart data={data} margin={{ top: 10, right: 10, left: -10, bottom: 0 }}>
                            <defs>
                                <linearGradient id="gradientUsers" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stopColor="hsl(172, 65%, 45%)" stopOpacity={0.3} />
                                    <stop offset="95%" stopColor="hsl(172, 65%, 45%)" stopOpacity={0} />
                                </linearGradient>
                            </defs>
                            <CartesianGrid
                                strokeDasharray="3 3"
                                stroke="hsl(220, 15%, 18%)"
                                vertical={false}
                            />
                            <XAxis
                                dataKey="date"
                                tick={{ fontSize: 11, fontFamily: 'JetBrains Mono', fill: 'hsl(215, 12%, 45%)' }}
                                tickLine={false}
                                axisLine={false}
                                interval="preserveStartEnd"
                            />
                            <YAxis
                                tick={{ fontSize: 11, fontFamily: 'JetBrains Mono', fill: 'hsl(215, 12%, 45%)' }}
                                tickLine={false}
                                axisLine={false}
                                width={45}
                            />
                            <Tooltip
                                contentStyle={{
                                    borderRadius: '10px',
                                    border: '1px solid hsl(220, 15%, 20%)',
                                    backgroundColor: 'hsl(220, 18%, 12%)',
                                    fontSize: '12px',
                                    fontFamily: 'JetBrains Mono',
                                    color: 'hsl(210, 20%, 93%)',
                                    boxShadow: '0 8px 32px rgba(0,0,0,0.3)',
                                }}
                                labelStyle={{ color: 'hsl(215, 12%, 55%)', marginBottom: '4px' }}
                            />
                            <Area
                                type="monotone"
                                dataKey="activeUsers"
                                name="Active Users"
                                stroke="hsl(172, 65%, 45%)"
                                strokeWidth={2}
                                fill="url(#gradientUsers)"
                                dot={false}
                                activeDot={{
                                    r: 5,
                                    fill: 'hsl(172, 65%, 45%)',
                                    stroke: 'hsl(220, 18%, 10%)',
                                    strokeWidth: 2,
                                }}
                            />
                        </AreaChart>
                    </ResponsiveContainer>
                </div>
            </CardContent>
        </Card>
    );
}
