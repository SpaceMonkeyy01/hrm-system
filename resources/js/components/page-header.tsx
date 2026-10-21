import type { ReactNode } from 'react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { HelpTip } from '@/components/help-tip';
import { SidebarTrigger } from '@/components/ui/sidebar';

/**
 * Title, the page's main actions on the right, and a line of live facts about what's shown (description).
 * What the page is for and how to use it goes in help: a ? next to the title, so it doesn't crowd the page.
 */
export function PageHeader({
    title,
    description,
    help,
    actions,
    back,
    children,
    tabs,
}: {
    title: ReactNode;
    description?: ReactNode;
    help?: ReactNode;
    actions?: ReactNode;
    back?: { href: string; label: string };
    children?: ReactNode;
    /** Links to the other pages of this section (AttendanceTabs, SettingsTabs), on the bottom border. */
    tabs?: ReactNode;
}) {
    return (
        <header className="relative isolate border-b px-4 pt-4 pb-4 md:px-6 md:pt-5">
            <div aria-hidden className="bg-grid-faint pointer-events-none absolute inset-0 -z-10" />
            {/* The trail you came through (Org chart / Ali Asghar), else the page's own back link. */}
            <Breadcrumbs fallback={back} />
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-2">
                    <SidebarTrigger className="mt-0.5 -ml-1 hidden md:inline-flex" />
                    <div className="min-w-0">
                        <div className="flex items-center gap-1.5">
                            <h1 className="font-display text-xl font-semibold tracking-tight text-balance md:text-2xl">{title}</h1>
                            {help && <HelpTip label="About this page">{help}</HelpTip>}
                        </div>
                        {description && <p className="mt-1 max-w-3xl text-sm text-muted-foreground">{description}</p>}
                    </div>
                </div>
                {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
            </div>
            {children && <div className="mt-4">{children}</div>}
            {tabs && <div className="mt-3">{tabs}</div>}
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
