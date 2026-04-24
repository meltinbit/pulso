import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

interface Property {
    id: number;
    display_name: string;
    website_url: string | null;
    is_active: boolean;
}

interface SnapshotSettingsProps {
    settings: {
        snapshot_enabled: string;
        snapshot_time: string;
        snapshot_telegram: string;
    };
    properties: Property[];
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Snapshot settings',
        href: '/settings/snapshots',
    },
];

export default function SnapshotSettings({ settings, properties }: SnapshotSettingsProps) {
    const { data, setData, put, processing, recentlySuccessful } = useForm({
        snapshot_enabled: settings.snapshot_enabled,
        snapshot_time: settings.snapshot_time,
        snapshot_telegram: settings.snapshot_telegram,
        active_properties: properties.filter((p) => p.is_active).map((p) => p.id),
    });

    function toggleProperty(id: number, checked: boolean) {
        if (checked) {
            setData('active_properties', [...data.active_properties, id]);
        } else {
            setData('active_properties', data.active_properties.filter((pid) => pid !== id));
        }
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('settings.snapshots.update'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Snapshot settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Daily Snapshots"
                        description="Configure the daily snapshot generation and notifications"
                    />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="flex items-center justify-between rounded-lg border p-4">
                            <div>
                                <Label className="text-sm font-medium">Enable daily snapshots</Label>
                                <p className="text-muted-foreground text-xs">
                                    Automatically generate analytics snapshots every day
                                </p>
                            </div>
                            <Switch
                                checked={data.snapshot_enabled === '1'}
                                onCheckedChange={(checked) => setData('snapshot_enabled', checked ? '1' : '0')}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="snapshot_time">Schedule time (UTC)</Label>
                            <Input
                                id="snapshot_time"
                                type="text"
                                className="w-32"
                                value={data.snapshot_time}
                                onChange={(e) => setData('snapshot_time', e.target.value)}
                                placeholder="09:00"
                                maxLength={5}
                            />
                            <p className="text-muted-foreground text-xs">
                                Format HH:MM in UTC. Default: 09:00. Runs at the top of the selected hour (minutes are ignored).
                            </p>
                        </div>

                        <div className="flex items-center justify-between rounded-lg border p-4">
                            <div>
                                <Label className="text-sm font-medium">Telegram notifications</Label>
                                <p className="text-muted-foreground text-xs">
                                    Send a daily digest via Telegram after snapshot generation
                                </p>
                            </div>
                            <Switch
                                checked={data.snapshot_telegram === '1'}
                                onCheckedChange={(checked) => setData('snapshot_telegram', checked ? '1' : '0')}
                            />
                        </div>

                        <Separator />

                        <div className="space-y-4">
                            <div>
                                <Label className="text-sm font-medium">Properties</Label>
                                <p className="text-muted-foreground text-xs">
                                    Select which properties to include in daily snapshots
                                </p>
                            </div>

                            {properties.length === 0 ? (
                                <p className="text-muted-foreground text-sm">No properties added yet.</p>
                            ) : (
                                <div className="space-y-2">
                                    {properties.map((property) => (
                                        <label
                                            key={property.id}
                                            className="flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition-colors hover:bg-accent/50"
                                        >
                                            <Checkbox
                                                checked={data.active_properties.includes(property.id)}
                                                onCheckedChange={(checked) => toggleProperty(property.id, !!checked)}
                                            />
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-medium">{property.display_name}</p>
                                                {property.website_url && (
                                                    <p className="text-muted-foreground text-xs">{property.website_url}</p>
                                                )}
                                            </div>
                                        </label>
                                    ))}
                                </div>
                            )}
                        </div>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>Save</Button>

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
            </SettingsLayout>
        </AppLayout>
    );
}
