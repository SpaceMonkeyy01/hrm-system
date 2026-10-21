import { SlidersHorizontal, X } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

/**
 * A page's filters. Desktop: everything in one wrapping row; filters passed as `more` sit behind a "More filters"
 * button with how many of them are set. Phones: the search box and a "Filters" button (with how many are set), which
 * opens all of them as full-width controls, so the list starts on the first screen. `grow` lets the search box take
 * the row's free space.
 */
export function FilterBar({ search, active, children, more, moreActive = 0, grow = false }: {
    search: ReactNode; active: number; children: ReactNode; more?: ReactNode; moreActive?: number; grow?: boolean;
}) {
    const [open, setOpen] = useState(false);
    return (
        <div className="grid gap-2 md:flex md:flex-wrap md:items-center">
            <div className="flex items-center gap-2 md:contents">
                <div className={cn('min-w-0 flex-1', grow ? 'md:min-w-64' : 'md:flex-none')}>{search}</div>
                <Button type="button" variant={active ? 'secondary' : 'outline'} className="shrink-0 md:hidden" aria-expanded={open} onClick={() => setOpen((o) => !o)}>
                    <SlidersHorizontal /> Filters{active ? ` (${active})` : ''}
                </Button>
            </div>
            {/* md:contents: on desktop the filters join the search box's row instead of forming a block of their own. */}
            <div className={cn('gap-2 md:contents', open ? 'grid' : 'hidden', 'max-md:[&>*]:!w-full')}>
                {children}
                {more && <div className="grid gap-2 md:hidden [&>*]:!w-full">{more}</div>}
            </div>
            {more && (
                <Popover>
                    <PopoverTrigger asChild>
                        <Button type="button" variant="outline" className="hidden md:inline-flex">
                            <SlidersHorizontal /> More filters
                            {moreActive > 0 && <span className="ml-0.5 inline-flex size-5 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground">{moreActive}</span>}
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent align="end" className="grid w-80 gap-2 p-3 [&>*]:!w-full">{more}</PopoverContent>
                </Popover>
            )}
        </div>
    );
}

export type FilterChip = { key: string; label: string; clear: () => void };

/** The filters that are set, each removable, plus Clear all and a count on the right. */
export function ActiveFilters({ chips, onClearAll, summary }: { chips: FilterChip[]; onClearAll: () => void; summary?: ReactNode }) {
    if (!chips.length && !summary) return null;
    return (
        <div className="mt-3 flex flex-wrap items-center gap-2">
            {chips.map((c) => (
                <span key={c.key} className="inline-flex items-center gap-1 rounded-full bg-info-soft py-0.5 pr-1 pl-3 text-sm font-semibold text-ink">
                    {c.label}
                    <button type="button" onClick={c.clear} aria-label={`Remove filter: ${c.label}`} className="rounded-full p-0.5 hover:bg-black/10">
                        <X className="size-3.5" />
                    </button>
                </span>
            ))}
            {chips.length > 0 && <button type="button" onClick={onClearAll} className="text-sm font-semibold text-cascade underline hover:no-underline">Clear all</button>}
            {summary && <span className="ml-auto text-sm text-muted-foreground">{summary}</span>}
        </div>
    );
}
