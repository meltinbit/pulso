import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Check, Globe, Pencil, Plus, Trash2 } from 'lucide-react';

interface AvailableProperty {
    property_id: string;
    display_name: string;
    account_name: string;
    website_url: string | null;
    timezone: string;
    currency: string;
    ga_connection_id: number;
    connection_email: string;
}

interface AvailableSite {
    site_url: string;
    website_url: string;
    ga_connection_id: number;
    connection_email: string;
    covered_by: string | null;
}

interface SavedProperty {
    id: number;
    property_id: string | null;
    gsc_site_url: string | null;
    display_name: string;
    website_url: string | null;
    timezone: string;
    is_active: boolean;
    ga_connection: {
        id: number;
        google_email: string;
    } | null;
}

interface PropertiesPageProps {
    available: AvailableProperty[];
    availableSites: AvailableSite[];
    saved: SavedProperty[];
    connections: { id: number; google_email: string; google_name: string | null }[];
    flash?: { success?: string; error?: string };
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Properties',
        href: '/properties',
    },
];

function MonitoredPropertyCard({ property, index, onRemove }: { property: SavedProperty; index: number; onRemove: (id: number) => void }) {
    const [editing, setEditing] = useState(false);
    const { data, setData, put, processing } = useForm({
        website_url: property.website_url || '',
    });

    function handleSave(e: React.FormEvent) {
        e.preventDefault();
        put(route('properties.update', property.id), {
            onSuccess: () => setEditing(false),
            preserveScroll: true,
        });
    }

    return (
        <div
            className="animate-fade-up group hover:bg-accent/50 rounded-lg border p-3 transition-colors"
            style={{ animationDelay: `${150 + index * 50}ms` }}
        >
            <div className="flex items-center justify-between">
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{property.display_name}</p>
                    <p className="text-muted-foreground font-mono text-xs">
                        {property.property_id ?? (
                            <Badge variant="outline" className="mr-1 px-1.5 py-0 font-sans text-[10px]">
                                Solo Search Console
                            </Badge>
                        )}
                        {property.ga_connection && <span> &middot; {property.ga_connection.google_email}</span>}
                    </p>
                </div>
                <div className="ml-3 flex items-center gap-1">
                    <Button
                        size="sm"
                        variant="ghost"
                        className="h-7 w-7 p-0 opacity-0 transition-opacity group-hover:opacity-100"
                        onClick={() => setEditing(!editing)}
                    >
                        <Pencil className="h-3.5 w-3.5" />
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        className="h-7 w-7 p-0 text-red-500 opacity-0 transition-opacity group-hover:opacity-100 hover:text-red-400"
                        onClick={() => onRemove(property.id)}
                    >
                        <Trash2 className="h-3.5 w-3.5" />
                    </Button>
                </div>
            </div>

            {!editing && property.website_url && (
                <p className="text-muted-foreground mt-1 flex items-center gap-1 text-xs">
                    <Globe className="h-3 w-3" />
                    {property.website_url}
                </p>
            )}

            {!editing && !property.website_url && (
                <button onClick={() => setEditing(true)} className="text-muted-foreground hover:text-foreground mt-1 flex items-center gap-1 text-xs">
                    <Globe className="h-3 w-3" />
                    Set website URL
                </button>
            )}

            {editing && (
                <form onSubmit={handleSave} className="mt-2 flex items-center gap-2">
                    <Input
                        type="url"
                        placeholder="https://example.com"
                        value={data.website_url}
                        onChange={(e) => setData('website_url', e.target.value)}
                        className="h-7 text-xs"
                    />
                    <Button type="submit" size="sm" className="h-7 text-xs" disabled={processing}>
                        Save
                    </Button>
                </form>
            )}
        </div>
    );
}

export default function PropertiesIndex() {
    const { available, availableSites, saved, flash } = usePage<PropertiesPageProps>().props;

    const savedPropertyIds = new Set(saved.map((s) => s.property_id));
    const savedSiteUrls = new Set(saved.map((s) => s.gsc_site_url));

    function handleAddSite(site: AvailableSite) {
        router.post(route('properties.store-search-console'), {
            site_url: site.site_url,
            ga_connection_id: site.ga_connection_id,
        });
    }

    function handleAdd(property: AvailableProperty) {
        router.post(route('properties.store'), {
            property_id: property.property_id,
            ga_connection_id: property.ga_connection_id,
            display_name: property.display_name,
            website_url: property.website_url,
            timezone: property.timezone,
            currency: property.currency,
        });
    }

    function handleRemove(id: number) {
        router.delete(route('properties.destroy', id));
    }

    // Group available properties by account
    const groupedAvailable = available.reduce(
        (acc, prop) => {
            const key = prop.account_name || 'Other';
            if (!acc[key]) acc[key] = [];
            acc[key].push(prop);
            return acc;
        },
        {} as Record<string, AvailableProperty[]>,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Properties" />

            <div className="px-4 py-6 md:px-6">
                <HeadingSmall title="GA4 Properties" description="Select which properties to monitor in your dashboard" />

                {flash?.error && (
                    <div className="animate-fade-up mt-4 rounded-lg border border-red-500/20 bg-red-500/10 p-3 text-sm text-red-400">
                        {flash.error}
                    </div>
                )}

                {flash?.success && (
                    <div className="animate-fade-up mt-4 rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-3 text-sm text-emerald-400">
                        {flash.success}
                    </div>
                )}

                <div className="mt-6 grid gap-6 lg:grid-cols-2">
                    <div className="min-w-0 space-y-6">
                        {/* Available */}
                        <Card className="animate-fade-up">
                            <CardHeader>
                                <CardTitle className="text-base">Available from Google</CardTitle>
                                <CardDescription>Properties accessible from your connected accounts</CardDescription>
                            </CardHeader>
                            <CardContent>
                                {Object.keys(groupedAvailable).length === 0 ? (
                                    <p className="text-muted-foreground text-sm">No properties found. Connect a Google account in Settings first.</p>
                                ) : (
                                    <div className="space-y-5">
                                        {Object.entries(groupedAvailable).map(([accountName, properties]) => (
                                            <div key={accountName}>
                                                <p className="text-muted-foreground mb-2 text-xs font-medium tracking-wider uppercase">
                                                    {accountName}
                                                </p>
                                                <div className="space-y-2">
                                                    {properties.map((property) => {
                                                        const isAdded = savedPropertyIds.has(property.property_id);
                                                        return (
                                                            <div
                                                                key={property.property_id}
                                                                className="group hover:bg-accent/50 flex items-center justify-between rounded-lg border p-3 transition-colors"
                                                            >
                                                                <div className="min-w-0 flex-1">
                                                                    <p className="truncate text-sm font-medium">{property.display_name}</p>
                                                                    <p className="text-muted-foreground font-mono text-xs">{property.property_id}</p>
                                                                </div>
                                                                <div className="ml-3">
                                                                    {isAdded ? (
                                                                        <Badge variant="secondary" className="gap-1 text-xs">
                                                                            <Check className="h-3 w-3" />
                                                                            Added
                                                                        </Badge>
                                                                    ) : (
                                                                        <Button
                                                                            size="sm"
                                                                            variant="outline"
                                                                            className="h-7 text-xs"
                                                                            onClick={() => handleAdd(property)}
                                                                        >
                                                                            <Plus className="mr-1 h-3 w-3" />
                                                                            Add
                                                                        </Button>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Search Console only */}
                        <Card className="animate-fade-up" style={{ animationDelay: '50ms' }}>
                            <CardHeader>
                                <CardTitle className="text-base">Solo Search Console</CardTitle>
                                <CardDescription>
                                    Siti verificati in Search Console senza una proprietà GA4: Search Console, Bing e indicizzazione, niente snapshot
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {availableSites.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">Nessun sito Search Console trovato negli account collegati.</p>
                                ) : (
                                    <div className="space-y-2">
                                        {availableSites.map((site) => {
                                            const isAdded = savedSiteUrls.has(site.site_url);
                                            return (
                                                <div
                                                    key={site.site_url}
                                                    className="hover:bg-accent/50 flex items-center justify-between rounded-lg border p-3 transition-colors"
                                                >
                                                    <div className="min-w-0 flex-1">
                                                        <p className="truncate text-sm font-medium">{site.website_url}</p>
                                                        <p className="text-muted-foreground truncate font-mono text-xs">
                                                            {site.site_url} &middot; {site.connection_email}
                                                        </p>
                                                        {!isAdded && site.covered_by && (
                                                            <p className="text-muted-foreground mt-0.5 text-xs">Già coperto da {site.covered_by}</p>
                                                        )}
                                                    </div>
                                                    <div className="ml-3">
                                                        {isAdded ? (
                                                            <Badge variant="secondary" className="gap-1 text-xs">
                                                                <Check className="h-3 w-3" />
                                                                Added
                                                            </Badge>
                                                        ) : (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                className="h-7 text-xs"
                                                                onClick={() => handleAddSite(site)}
                                                            >
                                                                <Plus className="mr-1 h-3 w-3" />
                                                                Add
                                                            </Button>
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {/* Monitored */}
                    <Card className="animate-fade-up" style={{ animationDelay: '100ms' }}>
                        <CardHeader>
                            <CardTitle className="text-base">Monitored</CardTitle>
                            <CardDescription>Properties tracked in your dashboard</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {saved.length === 0 ? (
                                <div className="flex flex-col items-center py-8 text-center">
                                    <div className="bg-muted mb-3 flex h-10 w-10 items-center justify-center rounded-xl">
                                        <Plus className="text-muted-foreground h-5 w-5" />
                                    </div>
                                    <p className="text-muted-foreground text-sm">No properties added yet</p>
                                    <p className="text-muted-foreground mt-1 text-xs">Add one from the list</p>
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    {saved.map((property, i) => (
                                        <MonitoredPropertyCard key={property.id} property={property} index={i} onRemove={handleRemove} />
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
