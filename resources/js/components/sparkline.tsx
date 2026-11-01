import { Line, LineChart, ResponsiveContainer, Tooltip, YAxis } from 'recharts';

interface SparklineProps {
    points: { month: string; searches: number }[];
}

export function Sparkline({ points }: SparklineProps) {
    if (points.length < 2) {
        return <span className="text-muted-foreground text-xs">–</span>;
    }

    return (
        <div className="h-7 w-24">
            <ResponsiveContainer width="100%" height="100%">
                <LineChart data={points} margin={{ top: 2, right: 2, bottom: 2, left: 2 }}>
                    <YAxis hide domain={['dataMin', 'dataMax']} />
                    <Tooltip
                        cursor={false}
                        wrapperStyle={{ zIndex: 50 }}
                        contentStyle={{ fontSize: 11, padding: '2px 6px', borderRadius: 6 }}
                        labelFormatter={(_, payload) => payload?.[0]?.payload?.month ?? ''}
                        formatter={(value) => [Number(value).toLocaleString(), 'searches']}
                    />
                    <Line
                        type="monotone"
                        dataKey="searches"
                        stroke="var(--color-primary, currentColor)"
                        strokeWidth={1.5}
                        dot={false}
                        isAnimationActive={false}
                    />
                </LineChart>
            </ResponsiveContainer>
        </div>
    );
}
