import { CircleHelp } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

/** A small "?" that shows help text: opens on hover with a mouse, on tap or click otherwise (phones have no hover). */
export function HelpTip({ label, children }: { label: string; children: ReactNode }) {
    const [open, setOpen] = useState(false);
    const hover = (next: boolean) => (e: React.PointerEvent) => e.pointerType === 'mouse' && setOpen(next);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger type="button" aria-label={label} onPointerEnter={hover(true)} onPointerLeave={hover(false)}
                className="inline-flex size-5 items-center justify-center rounded-full align-middle text-muted-foreground hover:text-foreground focus-visible:outline-2">
                <CircleHelp className="size-3.5" />
            </PopoverTrigger>
            <PopoverContent className="w-72 p-3 text-sm font-normal" onPointerEnter={hover(true)} onPointerLeave={hover(false)}
                onOpenAutoFocus={(e) => e.preventDefault()}>
                {children}
            </PopoverContent>
        </Popover>
    );
}
