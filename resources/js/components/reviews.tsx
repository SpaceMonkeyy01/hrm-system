import { cn } from '@/lib/utils';
import type { Tone } from '@/types';
import { Textarea } from '@/components/ui/textarea';

export type Criterion = { key: string; label: string; description: string | null };
export type Cycle = {
    id: number; name: string; status: 'draft' | 'open' | 'closed'; notes: string | null;
    period_start: string; period_end: string; self_due: string | null; manager_due: string | null; criteria?: Criterion[];
};
/** app/Http/Controllers/ReviewController.php reviewProps() */
export type ReviewData = {
    id: number; status: 'self_review' | 'manager_review' | 'completed' | 'cancelled';
    self_ratings: Record<string, number>; self_comments: Record<string, string>; self_summary: string | null; self_submitted_at: string | null;
    manager_ratings: Record<string, number>; manager_comments: Record<string, string>; manager_summary: string | null;
    goals: { title: string; measure: string | null; due: string | null }[]; recommendation: string | null;
    overall: number | null; band: string | null; reviewed_by: string | null; manager_submitted_at: string | null;
    acknowledged_at: string | null; employee_comment: string | null;
};

export const reviewTone: Record<ReviewData['status'], Tone> = { self_review: 'pending', manager_review: 'info', completed: 'done', cancelled: 'neutral' };
export const cycleTone: Record<Cycle['status'], Tone> = { draft: 'neutral', open: 'info', closed: 'done' };

/** 1-5 picker: buttons with the number, the word for the rating shown on hover and to screen readers. */
export function RatingPicker({ value, onChange, scale, disabled, label }: {
    value: number | undefined; onChange?: (v: number) => void; scale: Record<string, string>; disabled?: boolean; label: string;
}) {
    return (
        <div role="radiogroup" aria-label={label} className="flex gap-1">
            {[1, 2, 3, 4, 5].map((n) => (
                <button key={n} type="button" role="radio" aria-checked={value === n} aria-label={`${n}, ${scale[n]}`} title={scale[n]} disabled={disabled}
                    onClick={() => onChange?.(n)}
                    className={cn('size-8 rounded-md border text-sm font-semibold tabular-nums transition-colors disabled:cursor-default',
                        value === n ? 'border-cascade bg-cascade text-white' : 'bg-background hover:bg-accent disabled:hover:bg-background',
                        disabled && value !== n && 'text-muted-foreground')}>
                    {n}
                </button>
            ))}
        </div>
    );
}

/** One line per criterion: name, rating, comment. Optional read-only column with the other side's answer. */
export function RatingRows({ criteria, ratings, comments, onRate, onComment, scale, disabled, other, otherLabel }: {
    criteria: Criterion[]; ratings: Record<string, number>; comments: Record<string, string>;
    onRate?: (key: string, v: number) => void; onComment?: (key: string, v: string) => void;
    scale: Record<string, string>; disabled?: boolean;
    other?: { ratings: Record<string, number>; comments: Record<string, string> }; otherLabel?: string;
}) {
    return (
        <ul className="divide-y rounded-lg border">
            {criteria.map((c) => (
                <li key={c.key} className="grid gap-3 px-4 py-3 lg:grid-cols-[minmax(0,1fr)_auto]">
                    <div className="min-w-0">
                        <p className="font-semibold">{c.label}</p>
                        {c.description && <p className="text-sm text-muted-foreground">{c.description}</p>}
                        {other && (other.ratings[c.key] || other.comments[c.key]) && (
                            <p className="mt-1.5 rounded-md bg-muted px-2.5 py-1.5 text-sm">
                                <span className="font-semibold">{otherLabel}: {other.ratings[c.key] ? `${other.ratings[c.key]}, ${scale[other.ratings[c.key]]}` : 'no rating'}.</span>
                                {other.comments[c.key] && <span className="block whitespace-pre-line text-muted-foreground">{other.comments[c.key]}</span>}
                            </p>
                        )}
                        {(onComment || comments[c.key]) && (
                            <Textarea className="mt-2" rows={2} disabled={disabled} aria-label={`Comment on ${c.label}`} placeholder="Examples help: what went well, what to work on"
                                value={comments[c.key] ?? ''} onChange={(e) => onComment?.(c.key, e.target.value)} />
                        )}
                    </div>
                    <div className="grid content-start justify-items-start gap-1 lg:justify-items-end">
                        <RatingPicker value={ratings[c.key]} onChange={(v) => onRate?.(c.key, v)} scale={scale} disabled={disabled || !onRate} label={`Rating for ${c.label}`} />
                        <span className="text-xs text-muted-foreground">{ratings[c.key] ? scale[ratings[c.key]] : 'Not rated'}</span>
                    </div>
                </li>
            ))}
        </ul>
    );
}

export function ScaleKey({ scale }: { scale: Record<string, string> }) {
    return (
        <p className="text-xs text-muted-foreground">
            {Object.entries(scale).map(([n, label]) => `${n} ${label}`).join(' · ')}
        </p>
    );
}
