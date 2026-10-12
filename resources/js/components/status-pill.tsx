import { cn } from '@/lib/utils';
import type { OptionSets, Tone } from '@/types';

export const toneClass: Record<Tone | 'blank', string> = {
    done: 'bg-done-soft text-done',
    missing: 'bg-missing-soft text-missing',
    pending: 'bg-pending-soft text-pending',
    neutral: 'bg-neutral-soft text-neutral',
    info: 'bg-info-soft text-info',
    muted: 'bg-muted-tone-soft text-muted-tone',
    blank: 'border border-dashed border-input text-muted-foreground',
};

export function toneOf(value: string | null | undefined, set: string, optionSets: OptionSets): Tone | 'blank' {
    if (!value) return 'blank';
    return optionSets[set]?.find((o) => o.value === value)?.tone ?? 'neutral';
}

/**
 * Short code for compact grids: "Yes" stays "Yes", "No" stays "No", one long word gives its first letter
 * ("Pending" P, "Done" D), several words their initials ("Not Required" NR, "Old employee" OE). Blank is a dash.
 */
export function shortStatus(value: string | null | undefined): string {
    if (!value) return '-';
    const words = value.trim().split(/\s+/);
    if (words.length === 1) return value.length <= 3 ? value : value[0].toUpperCase();
    return words.map((w) => w[0].toUpperCase()).join('').slice(0, 3);
}

/** Coloured status chip. Colour is backed by the word itself, never colour alone (compact: a short code, the full word on hover). */
export function StatusPill({ value, tone, className, compact }: { value: string | null | undefined; tone: Tone | 'blank'; className?: string; compact?: boolean }) {
    return (
        <span
            title={compact ? value || 'Not set' : undefined}
            className={cn(
                'inline-flex items-center justify-center rounded-full font-semibold whitespace-nowrap',
                compact ? 'h-5 min-w-8 px-1.5 text-[11px]' : 'h-6 min-w-12 px-2.5 text-xs',
                toneClass[tone],
                className,
            )}
        >
            {compact ? shortStatus(value) : value || 'Not set'}
        </span>
    );
}

/** Computed statuses (Complete / In Progress / Pending ...) use the same palette. */
export function computedTone(value: string): Tone {
    if (['Complete', 'Onboarding Complete', 'Pass', 'completed'].includes(value)) return 'done';
    if (['Fail', 'cancelled'].includes(value)) return 'missing';
    if (['Not Started'].includes(value)) return 'neutral';
    return 'pending';
}
