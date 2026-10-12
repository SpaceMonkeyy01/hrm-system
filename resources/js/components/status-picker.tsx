import { Check } from 'lucide-react';
import { useState } from 'react';
import type { KeyboardEvent } from 'react';
import { StatusPill, toneOf } from '@/components/status-pill';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import type { OptionSets } from '@/types';

/**
 * A status cell. Click to pick from a list, or with the cell focused type the first letter
 * (Y = Yes, N = No, P = Pending, D = Done ...); typing it again cycles through values with that
 * letter. Delete clears the value.
 */
export function StatusPicker({ value, set, optionSets, onChange, disabled, label, compact }: {
    value: string | null;
    set: string;
    optionSets: OptionSets;
    onChange: (value: string | null) => void;
    disabled?: boolean;
    label: string;
    compact?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const options = optionSets[set] ?? [];
    const tone = toneOf(value, set, optionSets);

    if (disabled) {
        return <StatusPill value={value} tone={tone} compact={compact} />;
    }

    const choose = (v: string | null) => {
        setOpen(false);
        if (v !== value) onChange(v);
    };

    const onKeyDown = (e: KeyboardEvent) => {
        if (e.metaKey || e.ctrlKey || e.altKey) return;
        if (e.key === 'Backspace' || e.key === 'Delete') {
            e.preventDefault();
            choose(null);
            return;
        }
        if (e.key.length === 1 && /[a-z]/i.test(e.key)) {
            const matches = options.filter((o) => o.value.toLowerCase().startsWith(e.key.toLowerCase()));
            if (matches.length) {
                e.preventDefault();
                const at = matches.findIndex((o) => o.value === value);
                choose(matches[(at + 1) % matches.length].value);
            }
        }
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    onKeyDown={onKeyDown}
                    aria-label={`${label}: ${value ?? 'not set'}. Press Enter to change.`}
                    className="rounded-full focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                >
                    <StatusPill value={value} tone={tone} compact={compact} className="cursor-pointer hover:brightness-95" />
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-48">
                <p className="px-2 pt-1 pb-1.5 text-xs text-muted-foreground">{label}</p>
                <div role="listbox" aria-label={label}>
                    {options.map((o) => (
                        <button
                            key={o.value}
                            type="button"
                            role="option"
                            aria-selected={o.value === value}
                            onClick={() => choose(o.value)}
                            className="flex w-full items-center justify-between rounded px-2 py-1 hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                        >
                            <StatusPill value={o.value} tone={o.tone} />
                            {o.value === value && <Check className="size-4 text-primary" />}
                        </button>
                    ))}
                    <button
                        type="button"
                        onClick={() => choose(null)}
                        className="mt-1 w-full rounded border-t px-2 py-1.5 text-left text-xs text-muted-foreground hover:bg-accent"
                    >
                        Clear value
                    </button>
                </div>
            </PopoverContent>
        </Popover>
    );
}
