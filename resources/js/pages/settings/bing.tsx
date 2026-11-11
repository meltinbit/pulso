import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

interface BingSite {
    url: string;
    is_verified: boolean;
    property_id: number | null;
}

interface PropertyRow {
    id: number;
    display_name: string;
    website_url: string | null;
    bing_site_url: string | null;
    last_bing_day: string | null;
}

interface BingSettingsProps {
    hasKey: boolean;
    sites: BingSite[];
    error: string | null;
    properties: PropertyRow[];
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Bing settings', href: '/settings/bing' }];

export default function BingSettings({ hasKey, sites, error, properties }: BingSettingsProps) {
    const { flash } = usePage<{ flash?: { success?: string } }>().props;
    const { data, setData, put, errors, processing, recentlySuccessful, reset } = useForm({ bing_api_key: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('settings.bing.update'), { preserveScroll: true, onSuccess: () => reset() });
    };

    const linkSite = (property: PropertyRow, siteUrl: string) => {
        router.put(route('settings.bing.property', property.id), { bing_site_url: siteUrl || null }, { preserveScroll: true });
    };

    const verifiedSites = sites.filter((site) => site.is_verified);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bing settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Bing Webmaster Tools"
                        description="One API key covers every site verified in your Bing account. Create it in Bing Webmaster Tools → Settings → API access."
                    />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="bing_api_key">API key</Label>
                            <Input
                                id="bing_api_key"
                                type="password"
                                className="mt-1 block w-full"
                                value={data.bing_api_key}
                                onChange={(e) => setData('bing_api_key', e.target.value)}
                                required
                                placeholder={hasKey ? '******** (saved — enter a new key to replace it)' : 'Bing API key'}
                            />
                            <InputError className="mt-2" message={errors.bing_api_key} />
                        </div>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>Verify and save</Button>
                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">Saved</p>
                            </Transition>
                        </div>
                    </form>
                </div>

                {hasKey && (
                    <>
                        <Separator />

                        <div className="space-y-4">
                            <div className="flex items-center justify-between gap-4">
                                <HeadingSmall
                                    title="Sites"
                                    description="Properties are linked to the verified Bing site with the same domain. Change the link here if needed."
                                />
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => router.post(route('settings.bing.sync'), {}, { preserveScroll: true })}
                                >
                                    Sync now
                                </Button>
                            </div>

                            {flash?.success && <p className="text-sm text-emerald-500">{flash.success}</p>}
                            {error && <p className="text-sm text-red-500">{error}</p>}

                            <div className="space-y-3">
                                {properties.map((property) => (
                                    <div
                                        key={property.id}
                                        className="flex flex-col gap-2 rounded-lg border p-3 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">{property.display_name}</p>
                                            <p className="text-muted-foreground text-xs">
                                                {property.website_url ?? 'no website URL'}
                                                {property.last_bing_day && ` · Bing data up to ${property.last_bing_day}`}
                                            </p>
                                        </div>
                                        <select
                                            className="border-input bg-background h-9 rounded-md border px-2 text-sm sm:max-w-xs"
                                            value={property.bing_site_url ?? ''}
                                            onChange={(e) => linkSite(property, e.target.value)}
                                        >
                                            <option value="">Not linked</option>
                                            {verifiedSites.map((site) => (
                                                <option key={site.url} value={site.url}>
                                                    {site.url}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                ))}
                            </div>

                            {sites.some((site) => !site.is_verified) && (
                                <div className="space-y-1">
                                    <p className="text-sm font-medium">Not verified in Bing</p>
                                    <div className="flex flex-wrap gap-2">
                                        {sites
                                            .filter((site) => !site.is_verified)
                                            .map((site) => (
                                                <Badge key={site.url} variant="destructive">
                                                    {site.url}
                                                </Badge>
                                            ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    </>
                )}
            </SettingsLayout>
        </AppLayout>
    );
}
