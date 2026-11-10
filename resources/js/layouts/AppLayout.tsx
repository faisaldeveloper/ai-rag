import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { SharedProps } from '../types';

interface AppLayoutProps {
    title: string;
    children: ReactNode;
}

function NavLink({ href, children }: { href: string; children: ReactNode }) {
    const { url } = usePage();
    const isCurrent = url === href || url.startsWith(`${href}?`) || url.startsWith(`${href}/`);

    return (
        <Link
            href={href}
            aria-current={isCurrent ? 'page' : undefined}
            className={`rounded-md px-3 py-2 text-sm font-medium ${isCurrent ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-200 hover:text-slate-900'}`}
        >
            {children}
        </Link>
    );
}

export default function AppLayout({ title, children }: AppLayoutProps) {
    const { auth } = usePage<SharedProps>().props;

    return (
        <>
            <Head title={title} />
            <a href="#main" className="sr-only rounded-md bg-white px-4 py-2 font-medium focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50">
                Skip to content
            </a>
            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-3">
                    <div className="flex items-center gap-6">
                        <span className="font-semibold text-slate-900">Knowledge Assistant</span>
                        <nav aria-label="Main" className="flex gap-1">
                            <NavLink href="/chat">Chat</NavLink>
                            {auth.user?.is_admin && <NavLink href="/knowledge-base">Knowledge Base</NavLink>}
                        </nav>
                    </div>
                    <div className="flex items-center gap-3 text-sm">
                        <span className="text-slate-700">{auth.user?.name}</span>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            className="rounded-md border border-slate-300 px-3 py-1.5 font-medium text-slate-900 hover:bg-slate-100"
                        >
                            Sign out
                        </Link>
                    </div>
                </div>
            </header>
            <main id="main" tabIndex={-1} className="mx-auto max-w-7xl px-4 py-6">
                {children}
            </main>
        </>
    );
}
