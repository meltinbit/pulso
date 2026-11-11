import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

interface AdAccount {
    id: string;
    name: string;
    status: string;
    currency: string | null;
    timezone: string | null;
}

interface MetaSettingsProps {
    hasToken: boolean;
    accounts: AdAccount[];
    defaultAccount: string | null;
    error: string | null;
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Meta settings', href: '/settings/meta' }];

export default function MetaSettings({ hasToken, accounts, defaultAccount, error }: MetaSettingsProps) {
    const { flash } = usePage<{ flash?: { success?: string } }>().props;
    const { data, setData, put, errors, processing, recentlySuccessful, reset } = useForm({ meta_access_token: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('settings.meta.update'), { preserveScroll: true, onSuccess: () => reset() });
    };

    const chooseAccount = (accountId: string) => {
        router.put(route('settings.meta.account'), { account_id: accountId || null }, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Meta settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Meta Ads"
                        description="Read-only access to Facebook and Instagram ads. In Business Manager → Users → System users, create a system user, assign it your ad accounts and generate a token with the ads_read permission."
                    />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="meta_access_token">System user token</Label>
                            <Input
                                id="meta_access_token"
                                type="password"
                                className="mt-1 block w-full"
                                value={data.meta_access_token}
                                onChange={(e) => setData('meta_access_token', e.target.value)}
                                required
                                placeholder={hasToken ? '******** (saved — enter a new token to replace it)' : 'Access token'}
                            />
                            <InputError className="mt-2" message={errors.meta_access_token} />
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

                {hasToken && (
                    <>
                        <Separator />

                        <div className="space-y-4">
                            <HeadingSmall
                                title="Ad accounts"
                                description="The default account is used by the MCP tools when the agent does not pass account_id."
                            />

                            {flash?.success && <p className="text-sm text-emerald-500">{flash.success}</p>}
                            {error && <p className="text-sm text-red-500">{error}</p>}

                            {accounts.length > 0 && (
                                <div className="grid gap-2">
                                    <Label htmlFor="default_account">Default account</Label>
                                    <select
                                        id="default_account"
                                        className="border-input bg-background h-9 rounded-md border px-2 text-sm sm:max-w-md"
                                        value={defaultAccount ?? ''}
                                        onChange={(e) => chooseAccount(e.target.value)}
                                    >
                                        <option value="">None (the agent must choose)</option>
                                        {accounts.map((account) => (
                                            <option key={account.id} value={account.id}>
                                                {account.name} · {account.id} · {account.status} · {account.currency}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}
                        </div>
                    </>
                )}
            </SettingsLayout>
        </AppLayout>
    );
}
