import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';

import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Check, Plus, Trash2 } from 'lucide-react';

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

interface SavedProperty {
    id: number;
    property_id: string;
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

export default function PropertiesIndex() {
    const { available, saved, flash } = usePage<PropertiesPageProps>().props;

    const savedPropertyIds = new Set(saved.map((s) => s.property_id));

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

                {flash?.success && (
                    <div className="animate-fade-up mt-4 rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-3 text-sm text-emerald-400">
                        {flash.success}
                    </div>
                )}

                <div className="mt-6 grid gap-6 lg:grid-cols-2">
                    {/* Available */}
                    <Card className="animate-fade-up">
                        <CardHeader>
                            <CardTitle className="text-base">Available from Google</CardTitle>
                            <CardDescription>Properties accessible from your connected accounts</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {Object.keys(groupedAvailable).length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    No properties found. Connect a Google account in Settings first.
                                </p>
                            ) : (
                                <div className="space-y-5">
                                    {Object.entries(groupedAvailable).map(([accountName, properties]) => (
                                        <div key={accountName}>
                                            <p className="text-muted-foreground mb-2 text-xs font-medium uppercase tracking-wider">
                                                {accountName}
                                            </p>
                                            <div className="space-y-2">
                                                {properties.map((property) => {
                                                    const isAdded = savedPropertyIds.has(property.property_id);
                                                    return (
                                                        <div
                                                            key={property.property_id}
                                                            className="group flex items-center justify-between rounded-lg border p-3 transition-colors hover:bg-accent/50"
                                                        >
                                                            <div className="min-w-0 flex-1">
                                                                <p className="truncate text-sm font-medium">
                                                                    {property.display_name}
                                                                </p>
                                                                <p className="text-muted-foreground font-mono text-xs">
                                                                    {property.property_id}
                                                                </p>
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
                                        <div
                                            key={property.id}
                                            className="animate-fade-up group flex items-center justify-between rounded-lg border p-3 transition-colors hover:bg-accent/50"
                                            style={{ animationDelay: `${150 + i * 50}ms` }}
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">{property.display_name}</p>
                                                <p className="text-muted-foreground font-mono text-xs">
                                                    {property.property_id}
                                                    {property.ga_connection && (
                                                        <span> &middot; {property.ga_connection.google_email}</span>
                                                    )}
                                                </p>
                                            </div>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                className="ml-3 h-7 w-7 p-0 text-red-500 opacity-0 transition-opacity group-hover:opacity-100 hover:text-red-400"
                                                onClick={() => handleRemove(property.id)}
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
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
