import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

export function Pagination({ page }: { page: Paginated<unknown> }) {
    if (page.last_page <= 1) return null;

    return (
        <nav className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm" aria-label="Pages">
            <span className="text-muted-foreground">
                {page.from}-{page.to} of {page.total}
            </span>
            <div className="flex flex-wrap gap-1">
                {page.links.map((l, i) => (
                    <Link
                        key={i}
                        href={l.url ?? '#'}
                        preserveScroll
                        aria-current={l.active ? 'page' : undefined}
                        className={cn(
                            'min-w-9 rounded-md border px-2.5 py-1 text-center',
                            l.active ? 'border-primary bg-primary text-white' : 'bg-white hover:bg-accent',
                            !l.url && 'pointer-events-none opacity-40',
                        )}
                    >
                        <span dangerouslySetInnerHTML={{ __html: l.label }} />
                    </Link>
                ))}
            </div>
        </nav>
    );
}
