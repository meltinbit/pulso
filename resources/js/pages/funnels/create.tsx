import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { GripVertical, Plus, Trash2 } from 'lucide-react';

const COMMON_EVENTS = [
    'first_open', 'first_visit', 'session_start', 'page_view',
    'sign_up', 'login', 'purchase', 'add_to_cart', 'begin_checkout',
    'scroll', 'click', 'file_download', 'form_submit',
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Funnels', href: '/funnels' },
    { title: 'New Funnel', href: '/funnels/create' },
];

interface StepData {
    name: string;
    event_name: string;
}

export default function FunnelCreate() {
    const { data, setData, post, processing, errors } = useForm<{
        name: string;
        description: string;
        is_open: boolean;
        steps: StepData[];
    }>({
        name: '',
        description: '',
        is_open: false,
        steps: [
            { name: '', event_name: '' },
            { name: '', event_name: '' },
        ],
    });

    const addStep = () => setData('steps', [...data.steps, { name: '', event_name: '' }]);

    const removeStep = (i: number) => {
        if (data.steps.length <= 2) return;
        setData('steps', data.steps.filter((_, idx) => idx !== i));
    };

    const updateStep = (i: number, field: keyof StepData, value: string) => {
        const updated = [...data.steps];
        updated[i] = { ...updated[i], [field]: value };
        setData('steps', updated);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('funnels.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Funnel" />
            <div className="px-4 py-6 md:px-6">
                <div className="mx-auto max-w-2xl">
                    <HeadingSmall
                        title="Create Funnel"
                        description="Define a sequence of events to track how users move through your site"
                    />

                    <form onSubmit={submit} className="mt-6 space-y-6">
                        {/* Funnel info */}
                        <Card className="animate-fade-up">
                            <CardContent className="space-y-4 p-5">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Funnel Name</Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="e.g. Onboarding, Checkout, Feature Adoption"
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="description">Description (optional)</Label>
                                    <Input
                                        id="description"
                                        value={data.description}
                                        onChange={(e) => setData('description', e.target.value)}
                                        placeholder="What does this funnel measure?"
                                    />
                                </div>

                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="is_open"
                                        checked={data.is_open}
                                        onCheckedChange={(checked) => setData('is_open', checked === true)}
                                    />
                                    <div>
                                        <Label htmlFor="is_open" className="cursor-pointer">Open Funnel</Label>
                                        <p className="text-muted-foreground text-xs">
                                            Users can enter at any step. Uncheck for strict sequential order.
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Steps */}
                        <div className="space-y-3">
                            <div className="flex items-center justify-between">
                                <Label className="text-sm font-medium">Steps (min 2)</Label>
                                <Button type="button" variant="outline" size="sm" onClick={addStep}>
                                    <Plus className="mr-1.5 h-3.5 w-3.5" /> Add Step
                                </Button>
                            </div>

                            {data.steps.map((step, i) => (
                                <Card key={i} className="animate-fade-up" style={{ animationDelay: `${i * 50}ms` }}>
                                    <CardContent className="p-4">
                                        <div className="flex items-start gap-3">
                                            <div className="bg-muted text-muted-foreground flex h-8 w-8 shrink-0 items-center justify-center rounded-lg font-mono text-sm font-bold">
                                                {i + 1}
                                            </div>
                                            <div className="flex-1 space-y-3">
                                                <div className="grid gap-2 sm:grid-cols-2">
                                                    <div>
                                                        <Label className="text-xs">Step Name</Label>
                                                        <Input
                                                            value={step.name}
                                                            onChange={(e) => updateStep(i, 'name', e.target.value)}
                                                            placeholder="e.g. Opens App"
                                                            required
                                                        />
                                                        <InputError message={(errors as any)[`steps.${i}.name`]} />
                                                    </div>
                                                    <div>
                                                        <Label className="text-xs">GA4 Event Name</Label>
                                                        <Input
                                                            value={step.event_name}
                                                            onChange={(e) => updateStep(i, 'event_name', e.target.value)}
                                                            placeholder="e.g. first_open"
                                                            required
                                                            list={`events-${i}`}
                                                        />
                                                        <datalist id={`events-${i}`}>
                                                            {COMMON_EVENTS.map((ev) => (
                                                                <option key={ev} value={ev} />
                                                            ))}
                                                        </datalist>
                                                        <InputError message={(errors as any)[`steps.${i}.event_name`]} />
                                                    </div>
                                                </div>
                                            </div>
                                            {data.steps.length > 2 && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-8 w-8 shrink-0 p-0 text-red-500 hover:text-red-400"
                                                    onClick={() => removeStep(i)}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                            <InputError message={errors.steps} />
                        </div>

                        {/* Submit */}
                        <div className="flex items-center gap-3">
                            <Button disabled={processing}>Create Funnel</Button>
                            <Button asChild variant="ghost">
                                <Link href={route('funnels.index')}>Cancel</Link>
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
