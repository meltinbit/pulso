import { useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Loader2, MapPin, X } from 'lucide-react';

export interface GeoLocation {
    id: string;
    name: string;
}

interface GeoTarget {
    id: string;
    name: string;
    type: string | null;
    canonical_name: string | null;
}

interface GeoTargetPickerProps {
    value: GeoLocation[];
    onChange: (locations: GeoLocation[]) => void;
    max?: number;
}

/**
 * Autocomplete over Google Ads geo target constants (country, region, province, city).
 */
export function GeoTargetPicker({ value, onChange, max = 10 }: GeoTargetPickerProps) {
    const [query, setQuery] = useState('');
    const [targets, setTargets] = useState<GeoTarget[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [open, setOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const term = query.trim();
        if (term.length < 2) {
            setTargets([]);
            setError(null);
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(async () => {
            setLoading(true);
            try {
                const res = await fetch(route('keywords.geo-targets', { q: term }), {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                const json = await res.json();
                if (!res.ok) {
                    setError(json.message ?? 'Geo target search failed.');
                    setTargets([]);
                } else {
                    setError(null);
                    setTargets(json.targets ?? []);
                }
                setOpen(true);
            } catch (e) {
                if ((e as Error).name !== 'AbortError') setError('Geo target search failed.');
            } finally {
                setLoading(false);
            }
        }, 350);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [query]);

    useEffect(() => {
        function onClickOutside(event: MouseEvent) {
            if (containerRef.current && !containerRef.current.contains(event.target as Node)) setOpen(false);
        }
        document.addEventListener('mousedown', onClickOutside);
        return () => document.removeEventListener('mousedown', onClickOutside);
    }, []);

    function select(target: GeoTarget) {
        if (!value.some((location) => location.id === target.id)) {
            onChange([...value, { id: target.id, name: target.type ? `${target.name} (${target.type})` : target.name }].slice(0, max));
        }
        setQuery('');
        setOpen(false);
    }

    return (
        <div ref={containerRef} className="relative">
            <div className="mb-2 flex flex-wrap gap-1.5">
                {value.map((location) => (
                    <Badge key={location.id} variant="secondary" className="gap-1 pr-1">
                        <MapPin className="h-3 w-3" />
                        {location.name}
                        <button
                            type="button"
                            aria-label={`Remove ${location.name}`}
                            onClick={() => onChange(value.filter((item) => item.id !== location.id))}
                            className="hover:bg-muted rounded-sm p-0.5"
                        >
                            <X className="h-3 w-3" />
                        </button>
                    </Badge>
                ))}
            </div>
            <div className="relative">
                <Input
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    onFocus={() => targets.length > 0 && setOpen(true)}
                    placeholder={value.length >= max ? `Max ${max} locations` : 'Add city, province, region…'}
                    disabled={value.length >= max}
                />
                {loading && <Loader2 className="text-muted-foreground absolute top-2.5 right-2.5 h-4 w-4 animate-spin" />}
            </div>
            {open && (error || targets.length > 0) && (
                <div className="bg-popover text-popover-foreground absolute z-50 mt-1 max-h-64 w-full overflow-auto rounded-md border p-1 shadow-md">
                    {error && <div className="p-2 text-xs text-red-500">{error}</div>}
                    {targets.map((target) => (
                        <button
                            key={target.id}
                            type="button"
                            onClick={() => select(target)}
                            className="hover:bg-accent hover:text-accent-foreground flex w-full flex-col items-start rounded-sm px-2 py-1.5 text-left text-sm"
                        >
                            <span>
                                {target.name}
                                {target.type && <span className="text-muted-foreground ml-1.5 text-xs">{target.type}</span>}
                            </span>
                            {target.canonical_name && <span className="text-muted-foreground text-xs">{target.canonical_name}</span>}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
