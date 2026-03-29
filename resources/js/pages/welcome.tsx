import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

export default function Welcome() {
    const { auth } = usePage<SharedData>().props;

    return (
        <>
            <Head title="Welcome">
                <link rel="preconnect" href="https://fonts.bunny.net" />
                <link
                    href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700|space-mono:400,700"
                    rel="stylesheet"
                />
            </Head>
            <div className="relative flex min-h-screen flex-col items-center justify-center overflow-hidden bg-[#0a0a0a] p-6 text-white lg:p-8">
                {/* Background grid */}
                <div
                    className="pointer-events-none absolute inset-0 opacity-[0.03]"
                    style={{
                        backgroundImage:
                            'linear-gradient(rgba(255,255,255,.4) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.4) 1px, transparent 1px)',
                        backgroundSize: '64px 64px',
                    }}
                />

                {/* Ambient glow */}
                <div className="pointer-events-none absolute top-[-20%] left-1/2 h-[600px] w-[800px] -translate-x-1/2 rounded-full bg-[#2dd4a8] opacity-[0.06] blur-[120px]" />

                <header className="relative z-10 mb-12 w-full max-w-3xl text-sm">
                    <nav className="flex items-center justify-end gap-4">
                        <a
                            href="https://github.com/meltinbit/pulso"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1.5 px-5 py-1.5 text-sm text-white/60 transition hover:text-white"
                        >
                            <svg className="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12Z" />
                            </svg>
                            GitHub
                        </a>
                        {auth.user ? (
                            <Link
                                href={route('dashboard')}
                                className="rounded-md border border-white/10 px-5 py-1.5 text-sm text-white/80 transition hover:border-white/25 hover:text-white"
                            >
                                Dashboard
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href={route('login')}
                                    className="px-5 py-1.5 text-sm text-white/60 transition hover:text-white"
                                >
                                    Log in
                                </Link>
                                <Link
                                    href={route('register')}
                                    className="rounded-md border border-white/10 px-5 py-1.5 text-sm text-white/80 transition hover:border-white/25 hover:text-white"
                                >
                                    Register
                                </Link>
                            </>
                        )}
                    </nav>
                </header>

                <main className="relative z-10 flex flex-col items-center text-center">
                    {/* Logo */}
                    <div className="mb-8 animate-[fadeIn_0.8s_ease-out]">
                        <img
                            src="/logo.jpg"
                            alt="Pulso"
                            className="h-20 w-20 rounded-2xl shadow-[0_0_60px_rgba(45,212,168,0.2)]"
                        />
                    </div>

                    {/* Title */}
                    <h1
                        className="mb-6 animate-[fadeIn_0.8s_ease-out_0.1s_both] text-6xl font-bold tracking-tight lg:text-8xl"
                        style={{ fontFamily: "'Instrument Sans', sans-serif" }}
                    >
                        Pulso
                    </h1>

                    {/* Headline */}
                    <p
                        className="mb-4 max-w-2xl animate-[fadeIn_0.8s_ease-out_0.2s_both] text-xl leading-snug font-medium text-white/80 lg:text-2xl"
                        style={{ fontFamily: "'Instrument Sans', sans-serif" }}
                    >
                        Your GA4 data, finally readable.
                    </p>

                    {/* Description */}
                    <p className="mb-12 max-w-lg animate-[fadeIn_0.8s_ease-out_0.25s_both] text-lg leading-relaxed text-white/40 lg:text-xl">
                        Connect your Google Analytics properties and turn complex reports into clear, actionable insights — all in one place.
                    </p>

                    {/* CTA */}
                    <div className="flex animate-[fadeIn_0.8s_ease-out_0.3s_both] gap-4">
                        {auth.user ? (
                            <Link
                                href={route('dashboard')}
                                className="rounded-lg bg-[#2dd4a8] px-7 py-2.5 text-sm font-semibold text-[#0a0a0a] shadow-[0_0_20px_rgba(45,212,168,0.3)] transition hover:bg-[#3be0b6] hover:shadow-[0_0_30px_rgba(45,212,168,0.4)]"
                            >
                                Go to Dashboard
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href={route('register')}
                                    className="rounded-lg bg-[#2dd4a8] px-7 py-2.5 text-sm font-semibold text-[#0a0a0a] shadow-[0_0_20px_rgba(45,212,168,0.3)] transition hover:bg-[#3be0b6] hover:shadow-[0_0_30px_rgba(45,212,168,0.4)]"
                                >
                                    Get Started
                                </Link>
                                <Link
                                    href={route('login')}
                                    className="rounded-lg border border-white/10 px-7 py-2.5 text-sm font-medium text-white/70 transition hover:border-white/25 hover:text-white"
                                >
                                    Log in
                                </Link>
                            </>
                        )}
                    </div>

                    {/* Features */}
                    <div className="mt-20 grid animate-[fadeIn_0.8s_ease-out_0.5s_both] grid-cols-1 gap-6 sm:grid-cols-3 sm:gap-8">
                        <div className="flex flex-col items-center gap-2">
                            <div
                                className="text-3xl font-bold text-[#2dd4a8]"
                                style={{ fontFamily: "'Space Mono', monospace" }}
                            >
                                Clear
                            </div>
                            <div className="max-w-[200px] text-sm leading-relaxed text-white/30">
                                No more digging through GA4's cluttered interface
                            </div>
                        </div>
                        <div className="flex flex-col items-center gap-2">
                            <div
                                className="text-3xl font-bold text-[#2dd4a8]"
                                style={{ fontFamily: "'Space Mono', monospace" }}
                            >
                                Real-time
                            </div>
                            <div className="max-w-[200px] text-sm leading-relaxed text-white/30">
                                Live metrics that update as your visitors browse
                            </div>
                        </div>
                        <div className="flex flex-col items-center gap-2">
                            <div
                                className="text-3xl font-bold text-[#2dd4a8]"
                                style={{ fontFamily: "'Space Mono', monospace" }}
                            >
                                Funnels
                            </div>
                            <div className="max-w-[200px] text-sm leading-relaxed text-white/30">
                                Custom conversion paths, visualized at a glance
                            </div>
                        </div>
                    </div>
                </main>

                <footer className="relative z-10 mt-auto pt-16 pb-6 text-center text-xs text-white/20">
                    Built by{' '}
                    <a
                        href="https://meltinbit.com"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-white/40 transition hover:text-white/60"
                    >
                        MeltinBit
                    </a>
                    {' '}&middot;{' '}
                    <a
                        href="https://www.gnu.org/licenses/agpl-3.0.html"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-white/40 transition hover:text-white/60"
                    >
                        AGPL-3.0
                    </a>
                </footer>
            </div>

            <style>{`
                @keyframes fadeIn {
                    from { opacity: 0; transform: translateY(12px); }
                    to { opacity: 1; transform: translateY(0); }
                }
            `}</style>
        </>
    );
}
