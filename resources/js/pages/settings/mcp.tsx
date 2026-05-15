import HeadingSmall from '@/components/heading-small';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface McpSettingsProps {
    endpoint_url: string;
    has_token: boolean;
    last_used_at: string | null;
    flash?: {
        success?: string;
        error?: string;
        mcp_token?: string;
    };
    [key: string]: unknown;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'MCP settings',
        href: '/settings/mcp',
    },
];

export default function McpSettings({ endpoint_url, has_token, last_used_at }: McpSettingsProps) {
    const { flash } = usePage<McpSettingsProps>().props;
    const plainTextToken = flash?.mcp_token;
    const tokenizedEndpoint = plainTextToken ? `${endpoint_url}?token=${plainTextToken}` : endpoint_url;

    const generateToken = (event: FormEvent) => {
        event.preventDefault();
        router.post(route('settings.mcp.store'), {}, { preserveScroll: true });
    };

    const revokeToken = () => {
        router.delete(route('settings.mcp.destroy'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="MCP settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Model Context Protocol"
                        description="Protect the Pulso MCP endpoint with a user-scoped token."
                    />

                    <div className="space-y-4 rounded-lg border p-4">
                        <div className="space-y-1">
                            <Label htmlFor="mcp-endpoint">Endpoint</Label>
                            <Input id="mcp-endpoint" value={tokenizedEndpoint} readOnly />
                            <p className="text-muted-foreground text-xs">
                                Use this URL in MCP clients. The token can also be sent with `Authorization: Bearer ...` or `X-MCP-Token`.
                            </p>
                        </div>

                        <div className="text-sm">
                            <span className="font-medium">Status:</span> {has_token ? 'Protected by token' : 'No token configured'}
                        </div>

                        {last_used_at ? (
                            <p className="text-muted-foreground text-sm">
                                Last used: {new Date(last_used_at).toLocaleString()}
                            </p>
                        ) : has_token ? (
                            <p className="text-muted-foreground text-sm">The current token has not been used yet.</p>
                        ) : null}

                        {flash?.success ? (
                            <Alert>
                                <AlertTitle>Updated</AlertTitle>
                                <AlertDescription>{flash.success}</AlertDescription>
                            </Alert>
                        ) : null}

                        {flash?.error ? (
                            <Alert variant="destructive">
                                <AlertTitle>Error</AlertTitle>
                                <AlertDescription>{flash.error}</AlertDescription>
                            </Alert>
                        ) : null}

                        {plainTextToken ? (
                            <Alert>
                                <AlertTitle>Copy this token now</AlertTitle>
                                <AlertDescription className="space-y-3">
                                    <p>The plain token is shown only once after generation.</p>
                                    <Input value={plainTextToken} readOnly />
                                </AlertDescription>
                            </Alert>
                        ) : null}

                        <div className="flex flex-wrap gap-3">
                            <Button onClick={generateToken}>{has_token ? 'Regenerate token' : 'Generate token'}</Button>
                            {has_token ? (
                                <Button variant="outline" onClick={revokeToken}>
                                    Revoke token
                                </Button>
                            ) : null}
                        </div>
                    </div>
                </div>

                <Separator />

                <div className="space-y-4">
                    <HeadingSmall
                        title="How to use it"
                        description="Attach the token to the MCP endpoint for the current user."
                    />

                    <div className="space-y-2 text-sm">
                        <p>`{endpoint_url}?token=YOUR_TOKEN`</p>
                        <p>or send `Authorization: Bearer YOUR_TOKEN`.</p>
                        <p>The token only authorizes access to properties owned by your user.</p>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
