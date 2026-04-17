import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Loader2, Send } from 'lucide-react';

interface TelegramSettingsProps {
    settings: {
        telegram_bot_token: string;
        telegram_chat_id: string;
    };
    isConfigured: boolean;
    flash?: { success?: string; error?: string };
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Telegram settings',
        href: '/settings/telegram',
    },
];

export default function TelegramSettings({ settings, isConfigured }: TelegramSettingsProps) {
    const { flash } = usePage<TelegramSettingsProps>().props;
    const { data, setData, put, errors, processing, recentlySuccessful } = useForm({
        telegram_bot_token: '',
        telegram_chat_id: settings.telegram_chat_id,
    });

    const [testing, setTesting] = useState(false);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('settings.telegram.update'));
    };

    function handleTest() {
        setTesting(true);
        router.post(route('settings.telegram.test'), {}, {
            onFinish: () => setTesting(false),
            preserveScroll: true,
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Telegram settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Telegram"
                        description="Configure Telegram bot for daily snapshot notifications"
                    />

                    {flash?.success && (
                        <div className="rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-3 text-sm text-emerald-400">
                            {flash.success}
                        </div>
                    )}
                    {flash?.error && (
                        <div className="rounded-lg border border-red-500/20 bg-red-500/10 p-3 text-sm text-red-400">
                            {flash.error}
                        </div>
                    )}

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="telegram_bot_token">Bot Token</Label>
                            <Input
                                id="telegram_bot_token"
                                type="password"
                                className="mt-1 block w-full"
                                value={data.telegram_bot_token}
                                onChange={(e) => setData('telegram_bot_token', e.target.value)}
                                placeholder={isConfigured ? 'Leave blank to keep current' : 'Enter bot token from @BotFather'}
                            />
                            <InputError className="mt-2" message={errors.telegram_bot_token} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="telegram_chat_id">Chat ID</Label>
                            <Input
                                id="telegram_chat_id"
                                className="mt-1 block w-full"
                                value={data.telegram_chat_id}
                                onChange={(e) => setData('telegram_chat_id', e.target.value)}
                                placeholder="Your chat or group ID"
                            />
                            <p className="text-muted-foreground text-xs">
                                Send /start to your bot, then use @userinfobot to get your Chat ID.
                            </p>
                            <InputError className="mt-2" message={errors.telegram_chat_id} />
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

                {isConfigured && (
                    <>
                        <Separator />

                        <div className="space-y-4">
                            <HeadingSmall
                                title="Test"
                                description="Send a test message to verify your configuration"
                            />

                            <Button variant="outline" size="sm" onClick={handleTest} disabled={testing}>
                                {testing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Send className="mr-2 h-4 w-4" />}
                                Send test message
                            </Button>
                        </div>
                    </>
                )}
            </SettingsLayout>
        </AppLayout>
    );
}
