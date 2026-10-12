import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { SidebarTrigger } from '@/components/ui/sidebar';

/** Title, one line saying what the page is for, and the page's main actions on the right. */
export function PageHeader({
    title,
    description,
    actions,
    back,
    children,
}: {
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    back?: { href: string; label: string };
    children?: ReactNode;
}) {
    return (
        <header className="relative isolate border-b px-4 pt-4 pb-4 md:px-6 md:pt-5">
            <div aria-hidden className="bg-grid-faint pointer-events-none absolute inset-0 -z-10" />
            {back && (
                <Link href={back.href} className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ChevronLeft className="size-4" /> {back.label}
                </Link>
            )}
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-2">
                    <SidebarTrigger className="mt-0.5 -ml-1 hidden md:inline-flex" />
                    <div className="min-w-0">
                        <h1 className="font-display text-xl font-semibold tracking-tight text-balance md:text-2xl">{title}</h1>
                        {description && <p className="mt-1 max-w-3xl text-sm text-muted-foreground">{description}</p>}
                    </div>
                </div>
                {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
            </div>
            {children && <div className="mt-4">{children}</div>}
        </header>
    );
}

export function Section({ title, description, actions, children, className }: {
    title: ReactNode; description?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string;
}) {
    return (
        <section className={className}>
            <div className="mb-3 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 className="text-base font-bold">{title}</h2>
                    {description && <p className="text-sm text-muted-foreground">{description}</p>}
                </div>
                {actions}
            </div>
            {children}
        </section>
    );
}
