import { cn } from '@/lib/utils';

/** A thin bar showing value out of max (leave left, loan repaid). The numbers are always written next to it. */
export function Meter({ value, max, label, className, tone = 'primary' }: {
    value: number; max: number; label: string; className?: string; tone?: 'primary' | 'done' | 'missing';
}) {
    const share = max > 0 ? Math.min(1, Math.max(0, value / max)) : 0;
    return (
        <div className={cn('h-2 overflow-hidden rounded-full bg-muted', className)} role="meter" aria-label={label}
            aria-valuemin={0} aria-valuemax={max} aria-valuenow={value}>
            <div className={cn('h-full rounded-full', tone === 'done' ? 'bg-done' : tone === 'missing' ? 'bg-missing' : 'bg-primary')} style={{ width: `${share * 100}%` }} />
        </div>
    );
}
