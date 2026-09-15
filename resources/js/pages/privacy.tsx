import { Head, Link } from '@inertiajs/react';

const lastUpdated = 'September 15, 2026';
const contactEmail = 'fabio@meltinbit.com';

export default function Privacy() {
    return (
        <>
            <Head title="Privacy Policy" />
            <div className="min-h-screen bg-[#0a0a0a] px-6 py-12 text-white lg:py-20">
                <div className="mx-auto max-w-2xl">
                    <Link href={route('home')} className="text-sm text-white/40 transition hover:text-white/70">
                        &larr; Pulso
                    </Link>

                    <h1 className="mt-8 mb-2 text-4xl font-bold tracking-tight">Privacy Policy</h1>
                    <p className="mb-12 text-sm text-white/40">Last updated: {lastUpdated}</p>

                    <div className="space-y-10 text-base leading-relaxed text-white/70">
                        <section>
                            <h2 className="mb-3 text-lg font-semibold text-white">Overview</h2>
                            <p>
                                Pulso is a self-hosted analytics dashboard operated by MeltinBit for internal use. It connects to Google Analytics 4
                                and Google Search Console to display reports about websites the account owner manages. This policy explains what data
                                Pulso accesses and how it is handled.
                            </p>
                        </section>

                        <section>
                            <h2 className="mb-3 text-lg font-semibold text-white">Data accessed from Google</h2>
                            <p className="mb-3">When you connect a Google account, Pulso requests access to:</p>
                            <ul className="list-disc space-y-2 pl-6">
                                <li>Your basic Google profile (name and email address), to identify the connection.</li>
                                <li>Google Analytics properties and reports (users, sessions, pages, traffic sources, events).</li>
                                <li>Google Search Console data (search queries, clicks, impressions, sitemaps, URL inspection).</li>
                            </ul>
                            <p className="mt-3">Pulso only reads this data. It does not modify your Analytics or Search Console configuration.</p>
                        </section>

                        <section>
                            <h2 className="mb-3 text-lg font-semibold text-white">How data is used and stored</h2>
                            <ul className="list-disc space-y-2 pl-6">
                                <li>Data is used solely to generate the dashboards, reports and alerts shown inside Pulso.</li>
                                <li>Reports are stored as daily snapshots in Pulso's own database, hosted on a private server.</li>
                                <li>OAuth access and refresh tokens are stored encrypted.</li>
                                <li>Data is not sold, shared with third parties, or used for advertising.</li>
                            </ul>
                        </section>

                        <section>
                            <h2 className="mb-3 text-lg font-semibold text-white">Google API Services User Data Policy</h2>
                            <p>
                                Pulso's use and transfer of information received from Google APIs adheres to the{' '}
                                <a
                                    href="https://developers.google.com/terms/api-services-user-data-policy"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-[#2dd4a8] hover:underline"
                                >
                                    Google API Services User Data Policy
                                </a>
                                , including the Limited Use requirements.
                            </p>
                        </section>

                        <section>
                            <h2 className="mb-3 text-lg font-semibold text-white">Revoking access and deleting data</h2>
                            <p>
                                You can disconnect a Google account at any time from Pulso, which stops all further data access. You can also revoke
                                access from your{' '}
                                <a
                                    href="https://myaccount.google.com/permissions"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-[#2dd4a8] hover:underline"
                                >
                                    Google Account permissions
                                </a>
                                . To request deletion of all stored data, contact us at the address below.
                            </p>
                        </section>

                        <section>
                            <h2 className="mb-3 text-lg font-semibold text-white">Contact</h2>
                            <p>
                                <a href={`mailto:${contactEmail}`} className="text-[#2dd4a8] hover:underline">
                                    {contactEmail}
                                </a>
                            </p>
                        </section>
                    </div>
                </div>
            </div>
        </>
    );
}
