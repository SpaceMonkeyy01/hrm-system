import { Link, usePage } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';
import { getTrail, markRestore } from '@/lib/trail';
import type { Step } from '@/lib/trail';

/** Fires after AppLayout records a page, so crumbs on screen pick up the new trail. */
export const TRAIL_EVENT = 'hrm:trail';

/**
 * "Org chart / Ali Asghar / 90-day plan": the pages you came through, each one clickable and restoring where
 * you were (lib/trail.ts). Without a trail (opened from a bookmark) it shows the page's plain back link.
 */
export function Breadcrumbs({ fallback }: { fallback?: { href: string; label: string } }) {
    const { url } = usePage();
    const path = new URL(url, 'http://x').pathname;
    const [trail, setTrail] = useState<Step[]>(getTrail);

    useEffect(() => {
        const update = () => setTrail(getTrail());
        window.addEventListener(TRAIL_EVENT, update);
        return () => window.removeEventListener(TRAIL_EVENT, update);
    }, []);

    const here = trail.length ? trail[trail.length - 1] : null;
    if (trail.length > 1 && here?.path === path) {
        return (
            <nav aria-label="Breadcrumb" className="mb-2 flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5 text-sm">
                {trail.slice(0, -1).map((s) => (
                    <Fragment key={s.path}>
                        <Link href={s.url} onClick={() => markRestore(s.path)} className="max-w-56 truncate text-cascade hover:underline">{s.title}</Link>
                        <span className="text-muted-foreground" aria-hidden>/</span>
                    </Fragment>
                ))}
                <span className="max-w-64 truncate text-muted-foreground" aria-current="page">{here.title}</span>
            </nav>
        );
    }
    if (!fallback) return null;
    return (
        <Link href={fallback.href} className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
            <ChevronLeft className="size-4" /> {fallback.label}
        </Link>
    );
}
