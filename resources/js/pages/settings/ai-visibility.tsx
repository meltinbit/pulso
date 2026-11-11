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

interface PropertyRow {
    id: number;
    display_name: string;
    website_url: string | null;
    active_prompts: number;
    brand_terms: string[];
    last_run: string | null;
}

interface AiVisibilityProps {
    hasKey: boolean;
    keyInvalid: boolean;
    model: string;
    capEur: number;
    frequency: string;
    frequencies: string[];
    spentThisMonthUsd: number;
    expectedMonthUsd: number;
    properties: PropertyRow[];
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'AI visibility settings', href: '/settings/ai-visibility' }];

export default function AiVisibilitySettings({
    hasKey,
    keyInvalid,
    model,
    capEur,
    frequency,
    frequencies,
    spentThisMonthUsd,
    expectedMonthUsd,
    properties,
}: AiVisibilityProps) {
    const { flash } = usePage<{ flash?: { success?: string } }>().props;
    const { data, setData, put, errors, processing, recentlySuccessful } = useForm({
        openai_api_key: '',
        model,
        monthly_cap_eur: String(capEur),
        frequency,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('settings.ai-visibility.update'), { preserveScroll: true, onSuccess: () => setData('openai_api_key', '') });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="AI visibility settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="AI visibility (ChatGPT)"
                        description="Pulso asks the tracked prompts to ChatGPT through the OpenAI API with web search and records whether the answers cite your sites. The agents manage the prompts with set-ai-visibility-prompts."
                    />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="openai_api_key">OpenAI API key</Label>
                            <Input
                                id="openai_api_key"
                                type="password"
                                value={data.openai_api_key}
                                onChange={(e) => setData('openai_api_key', e.target.value)}
                                placeholder={hasKey ? '******** (saved — enter a new key to replace it)' : 'sk-...'}
                            />
                            {keyInvalid && <p className="text-sm text-red-500">The saved key was rejected by OpenAI: enter a new one.</p>}
                            <InputError message={errors.openai_api_key} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="model">Model</Label>
                                <Input id="model" value={data.model} onChange={(e) => setData('model', e.target.value)} />
                                <InputError message={errors.model} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="monthly_cap_eur">Monthly cap (€)</Label>
                                <Input
                                    id="monthly_cap_eur"
                                    type="number"
                                    step="0.5"
                                    min="0"
                                    value={data.monthly_cap_eur}
                                    onChange={(e) => setData('monthly_cap_eur', e.target.value)}
                                />
                                <InputError message={errors.monthly_cap_eur} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="frequency">Frequency</Label>
                                <select
                                    id="frequency"
                                    className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                    value={data.frequency}
                                    onChange={(e) => setData('frequency', e.target.value)}
                                >
                                    {frequencies.map((option) => (
                                        <option key={option} value={option}>
                                            {option}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <p className="text-muted-foreground text-sm">
                            This month: ${spentThisMonthUsd.toFixed(2)} spent · ≈ ${expectedMonthUsd.toFixed(2)} expected with the active prompts ·
                            cap {capEur.toFixed(2)} €. Dollars are counted as euro, so the cap is never exceeded; when it is reached the remaining
                            prompts wait for next month.
                        </p>

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

                {hasKey && (
                    <>
                        <Separator />

                        <div className="space-y-4">
                            <div className="flex items-center justify-between gap-4">
                                <HeadingSmall title="Sites" description="Prompts tracked per site. Runs every Monday at 07:00." />
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => router.post(route('settings.ai-visibility.run'), {}, { preserveScroll: true })}
                                >
                                    Run now
                                </Button>
                            </div>

                            {flash?.success && <p className="text-sm text-emerald-500">{flash.success}</p>}

                            <div className="space-y-2">
                                {properties.map((property) => (
                                    <div
                                        key={property.id}
                                        className="flex flex-col gap-1 rounded-lg border p-3 text-sm sm:flex-row sm:justify-between"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">{property.display_name}</p>
                                            <p className="text-muted-foreground text-xs">
                                                {property.website_url ?? 'no website URL: not tracked'}
                                                {property.brand_terms.length > 0 && ` · brand: ${property.brand_terms.join(', ')}`}
                                            </p>
                                        </div>
                                        <p className="text-muted-foreground shrink-0">
                                            {property.active_prompts} prompts{property.last_run && ` · last run ${property.last_run}`}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </>
                )}
            </SettingsLayout>
        </AppLayout>
    );
}
