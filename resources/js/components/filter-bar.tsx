import { SlidersHorizontal } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * A page's filters. Desktop: everything in one wrapping row. Phones: the search box and a "Filters" button
 * (with how many are set), which opens the rest as full-width controls, so the list starts on the first screen.
 */
export function FilterBar({ search, active, children }: { search: ReactNode; active: number; children: ReactNode }) {
    const [open, setOpen] = useState(false);
    return (
        <div className="grid gap-2 md:flex md:flex-wrap md:items-center">
            <div className="flex items-center gap-2 md:contents">
                <div className="min-w-0 flex-1 md:flex-none">{search}</div>
                <Button type="button" variant={active ? 'secondary' : 'outline'} className="shrink-0 md:hidden" aria-expanded={open} onClick={() => setOpen((o) => !o)}>
                    <SlidersHorizontal /> Filters{active ? ` (${active})` : ''}
                </Button>
            </div>
            {/* md:contents: on desktop the filters join the search box's row instead of forming a block of their own. */}
            <div className={cn('gap-2 md:contents', open ? 'grid' : 'hidden', 'max-md:[&>*]:!w-full')}>
                {children}
            </div>
        </div>
    );
}
