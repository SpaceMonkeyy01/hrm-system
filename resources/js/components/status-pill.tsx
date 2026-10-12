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

/** Coloured status chip. Colour is backed by the word itself, never colour alone. */
export function StatusPill({ value, tone, className }: { value: string | null | undefined; tone: Tone | 'blank'; className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex h-6 min-w-12 items-center justify-center rounded-full px-2.5 text-xs font-semibold whitespace-nowrap',
                toneClass[tone],
                className,
            )}
        >
            {value || 'Not set'}
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
