import { cn } from '@/lib/utils';

/**
 * Initials in a soft circle. The tint is picked from the name, so the same person always gets the same one;
 * it only helps the eye find a row, the name next to it is what identifies the person.
 */
const TINTS = [
    'bg-[#dcebf2] text-[#00435c]',
    'bg-done-soft text-done',
    'bg-pending-soft text-pending',
    'bg-info-soft text-info',
    'bg-muted-tone-soft text-muted-tone',
    'bg-[#fde8e1] text-[#9a3b1c]',
];

export function initials(name: string): string {
    const words = name.trim().split(/\s+/).filter((w) => !/^(muhammad|m\.?|syed|hafiz|hafiza)$/i.test(w));
    const pick = words.length ? words : name.trim().split(/\s+/);
    return ((pick[0]?.[0] ?? '') + (pick.length > 1 ? pick[pick.length - 1][0] : '')).toUpperCase() || '?';
}

function tint(name: string): string {
    let h = 0;
    for (const ch of name) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
    return TINTS[h % TINTS.length];
}

export function Avatar({ name, className }: { name: string; className?: string }) {
    return (
        <span aria-hidden className={cn('inline-flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-bold select-none', tint(name), className)}>
            {initials(name)}
        </span>
    );
}
