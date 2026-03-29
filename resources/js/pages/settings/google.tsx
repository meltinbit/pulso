import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, Link, useForm } from '@inertiajs/react';
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

interface GaConnection {
    id: number;
    google_email: string;
    google_name: string | null;
    is_active: boolean;
    created_at: string;
}

interface GoogleSettingsProps {
    settings: {
        google_client_id: string;
        google_client_secret: string;
    };
    connections: GaConnection[];
    hasCredentials: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Google settings',
        href: '/settings/google',
    },
];

export default function GoogleSettings({ settings, connections, hasCredentials }: GoogleSettingsProps) {
    const { data, setData, put, errors, processing, recentlySuccessful } = useForm({
        google_client_id: settings.google_client_id,
        google_client_secret: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('settings.google.update'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Google settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Google API Credentials"
                        description="Configure your Google OAuth credentials for Analytics access"
                    />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="google_client_id">Client ID</Label>
                            <Input
                                id="google_client_id"
                                className="mt-1 block w-full"
                                value={data.google_client_id}
                                onChange={(e) => setData('google_client_id', e.target.value)}
                                required
                                placeholder="xxxx.apps.googleusercontent.com"
                            />
                            <InputError className="mt-2" message={errors.google_client_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="google_client_secret">Client Secret</Label>
                            <Input
                                id="google_client_secret"
                                type="password"
                                className="mt-1 block w-full"
                                value={data.google_client_secret}
                                onChange={(e) => setData('google_client_secret', e.target.value)}
                                required
                                placeholder={settings.google_client_secret ? 'Leave blank to keep current' : 'Client secret'}
                            />
                            <InputError className="mt-2" message={errors.google_client_secret} />
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

                <Separator />

                <div className="space-y-6">
                    <HeadingSmall
                        title="Google Accounts"
                        description="Manage connected Google accounts for Analytics data"
                    />

                    {hasCredentials ? (
                        <div className="space-y-4">
                            <Button asChild variant="outline" size="sm">
                                <a href={route('google.redirect')}>Connect Google Account</a>
                            </Button>

                            {connections.length > 0 ? (
                                <div className="space-y-3">
                                    {connections.map((connection) => (
                                        <div
                                            key={connection.id}
                                            className="flex items-center justify-between rounded-lg border p-4"
                                        >
                                            <div>
                                                <p className="font-medium">{connection.google_email}</p>
                                                {connection.google_name && (
                                                    <p className="text-muted-foreground text-sm">{connection.google_name}</p>
                                                )}
                                            </div>
                                            <div className="flex items-center gap-3">
                                                <Badge variant={connection.is_active ? 'default' : 'secondary'}>
                                                    {connection.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                                {connection.is_active && (
                                                    <Link
                                                        href={route('google.disconnect', connection.id)}
                                                        method="delete"
                                                        as="button"
                                                        className="text-sm text-red-600 hover:text-red-800"
                                                    >
                                                        Disconnect
                                                    </Link>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-muted-foreground text-sm">No Google accounts connected yet.</p>
                            )}
                        </div>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            Save your Google API credentials above before connecting an account.
                        </p>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
