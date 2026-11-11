import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

interface Annotation {
    id: number;
    property: string;
    date: string;
    type: string;
    description: string;
    urls: string[];
    agent: string | null;
}

interface Props {
    annotations: {
        data: Annotation[];
        current_page: number;
        last_page: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    properties: { id: number; display_name: string }[];
    types: string[];
    filters: { property: number | null; type: string | null };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Annotations', href: '/annotations' }];

export default function AnnotationsPage({ annotations, properties, types, filters }: Props) {
    const filter = (changes: Partial<Props['filters']>) => {
        const next = { ...filters, ...changes };
        router.get('/annotations', Object.fromEntries(Object.entries(next).filter(([, value]) => value !== null && value !== '')), {
            preserveScroll: true,
        });
    };

    const remove = (annotation: Annotation) => {
        if (confirm(`Delete "${annotation.description}"?`)) {
            router.delete(route('annotations.destroy', annotation.id), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Annotations" />

            <div className="space-y-6 px-4 py-6 md:px-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <HeadingSmall
                        title="Annotations"
                        description="The change log of each site, written by the agents with add-annotation. Trend tools return it next to the data."
                    />
                    <div className="flex gap-2">
                        <select
                            className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            value={filters.property ?? ''}
                            onChange={(e) => filter({ property: e.target.value ? Number(e.target.value) : null })}
                        >
                            <option value="">All sites</option>
                            {properties.map((property) => (
                                <option key={property.id} value={property.id}>
                                    {property.display_name}
                                </option>
                            ))}
                        </select>
                        <select
                            className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            value={filters.type ?? ''}
                            onChange={(e) => filter({ type: e.target.value || null })}
                        >
                            <option value="">All types</option>
                            {types.map((type) => (
                                <option key={type} value={type}>
                                    {type}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                <Card>
                    <CardContent className="overflow-x-auto pt-6">
                        {annotations.data.length === 0 ? (
                            <p className="text-muted-foreground py-6 text-center text-sm">No annotations yet.</p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Site</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Change</TableHead>
                                        <TableHead>Agent</TableHead>
                                        <TableHead />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {annotations.data.map((annotation) => (
                                        <TableRow key={annotation.id} className="align-top">
                                            <TableCell className="whitespace-nowrap">{annotation.date}</TableCell>
                                            <TableCell className="whitespace-nowrap">{annotation.property}</TableCell>
                                            <TableCell>
                                                <Badge variant="secondary">{annotation.type}</Badge>
                                            </TableCell>
                                            <TableCell className="max-w-xl whitespace-normal">
                                                <div>{annotation.description}</div>
                                                {annotation.urls.map((url) => (
                                                    <div key={url} className="text-muted-foreground truncate text-xs">
                                                        {url}
                                                    </div>
                                                ))}
                                            </TableCell>
                                            <TableCell>{annotation.agent ?? '–'}</TableCell>
                                            <TableCell className="text-right">
                                                <button
                                                    type="button"
                                                    onClick={() => remove(annotation)}
                                                    className="text-sm text-red-600 hover:text-red-800"
                                                >
                                                    Delete
                                                </button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}

                        {annotations.last_page > 1 && (
                            <div className="mt-4 flex items-center justify-between text-sm">
                                {annotations.prev_page_url ? <Link href={annotations.prev_page_url}>← Newer</Link> : <span />}
                                <span className="text-muted-foreground">
                                    Page {annotations.current_page} of {annotations.last_page}
                                </span>
                                {annotations.next_page_url ? <Link href={annotations.next_page_url}>Older →</Link> : <span />}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
