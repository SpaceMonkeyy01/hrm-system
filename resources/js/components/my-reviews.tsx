import { useForm } from '@inertiajs/react';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { Section } from '@/components/page-header';
import { RatingRows, reviewTone, ScaleKey } from '@/components/reviews';
import type { Criterion, Cycle, ReviewData } from '@/components/reviews';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/format';

export type MyReview = ReviewData & { cycle: Cycle & { criteria: Criterion[] }; canSelfReview: boolean };

const label: Record<ReviewData['status'], string> = { self_review: 'Your turn', manager_review: 'With HR', completed: 'Done', cancelled: 'Taken out' };

function SelfReviewForm({ r, scale }: { r: MyReview; scale: Record<string, string> }) {
    const form = useForm({ self_ratings: { ...r.self_ratings }, self_comments: { ...r.self_comments }, self_summary: r.self_summary ?? '', submit: false });
    const errors = form.errors as Record<string, string>;
    const save = (submit: boolean) => {
        form.transform((d) => ({ ...d, submit }));
        form.put(`/me/reviews/${r.id}`, { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };
    return (
        <div className="grid gap-4">
            <p className="text-sm">
                Rate yourself on each line for {formatDate(r.cycle.period_start)} to {formatDate(r.cycle.period_end)}, with an example where you can.
                {r.cycle.self_due && <span className="font-semibold"> Due {formatDate(r.cycle.self_due)}.</span>}
                {' '}Only you and HR see this.
            </p>
            <ScaleKey scale={scale} />
            <RatingRows criteria={r.cycle.criteria} scale={scale} ratings={form.data.self_ratings} comments={form.data.self_comments}
                onRate={(k, v) => form.setData('self_ratings', { ...form.data.self_ratings, [k]: v })}
                onComment={(k, v) => form.setData('self_comments', { ...form.data.self_comments, [k]: v })} />
            {errors.self_ratings && <p className="text-sm text-missing">{errors.self_ratings}</p>}
            <Field label="Anything else" htmlFor={`s-${r.id}`} error={form.errors.self_summary}>
                <Textarea id={`s-${r.id}`} rows={3} placeholder="What you are proud of, what got in the way, what you want to learn next"
                    value={form.data.self_summary} onChange={(e) => form.setData('self_summary', e.target.value)} />
            </Field>
            <div className="flex flex-wrap gap-2">
                <Button variant="outline" disabled={form.processing} onClick={() => save(false)}>Save and finish later</Button>
                <Button disabled={form.processing} onClick={() => save(true)}>Send to HR</Button>
            </div>
        </div>
    );
}

function Result({ r, scale }: { r: MyReview; scale: Record<string, string> }) {
    const form = useForm({ employee_comment: '' });
    return (
        <div className="grid gap-4">
            <p className="text-sm">
                <span className="text-lg font-bold">{r.overall ?? 'No rating'}</span>{r.band && <span className="font-semibold">, {r.band}</span>}
                <span className="text-muted-foreground"> overall, finished {formatDateTime(r.manager_submitted_at)}.</span>
            </p>
            <RatingRows criteria={r.cycle.criteria} scale={scale} disabled ratings={r.manager_ratings} comments={r.manager_comments}
                other={{ ratings: r.self_ratings, comments: r.self_comments }} otherLabel="You said" />
            {r.manager_summary && <p className="rounded-md bg-muted px-3 py-2 text-sm whitespace-pre-line"><span className="font-semibold">HR's comments: </span>{r.manager_summary}</p>}
            {r.goals.length > 0 && (
                <div>
                    <p className="mb-1.5 text-sm font-semibold">Your goals for the next period</p>
                    <ul className="divide-y rounded-lg border text-sm">
                        {r.goals.map((g, i) => (
                            <li key={i} className="grid gap-0.5 px-3 py-2 sm:grid-cols-[1fr_1fr_auto]">
                                <span className="font-semibold">{g.title}</span>
                                <span className="text-muted-foreground">{g.measure}</span>
                                <span className="text-xs text-muted-foreground tabular-nums">{g.due && `by ${formatDate(g.due)}`}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {r.acknowledged_at ? (
                <p className="text-sm text-muted-foreground">You marked this as read {formatDateTime(r.acknowledged_at)}.{r.employee_comment && ` Your comment: ${r.employee_comment}`}</p>
            ) : (
                <form onSubmit={(e) => { e.preventDefault(); form.post(`/me/reviews/${r.id}/acknowledge`, { preserveScroll: true }); }} className="grid max-w-2xl gap-2 rounded-lg border p-3">
                    <Field label="Your comment (optional)" htmlFor={`ack-${r.id}`} error={form.errors.employee_comment}>
                        <Textarea id={`ack-${r.id}`} rows={2} placeholder="Anything you want HR to know about this review" value={form.data.employee_comment}
                            onChange={(e) => form.setData('employee_comment', e.target.value)} />
                    </Field>
                    <div><Button type="submit" disabled={form.processing}>I have read my review</Button></div>
                </form>
            )}
        </div>
    );
}

export function MyReviews({ reviews, scale }: { reviews: MyReview[]; scale: Record<string, string> }) {
    if (!reviews.length) return <EmptyState title="No performance reviews yet.">When HR opens a review cycle that includes you, it shows here.</EmptyState>;
    return (
        <div className="grid max-w-4xl gap-10">
            {reviews.map((r) => (
                <Section key={r.id} title={r.cycle.name}
                    description={<span className="flex items-center gap-2"><StatusPill value={label[r.status]} tone={reviewTone[r.status]} /> {formatDate(r.cycle.period_start)} to {formatDate(r.cycle.period_end)}</span>}>
                    {r.canSelfReview ? <SelfReviewForm r={r} scale={scale} />
                        : r.status === 'completed' ? <Result r={r} scale={scale} />
                            : r.status === 'manager_review' ? (
                                <p className="rounded-lg border px-3 py-3 text-sm text-muted-foreground">
                                    {r.self_submitted_at ? `You sent your self-review ${formatDateTime(r.self_submitted_at)}. ` : ''}HR is finishing the review. The result shows here when it's done.
                                </p>
                            ) : <p className="rounded-lg border px-3 py-3 text-sm text-muted-foreground">This cycle is closed.</p>}
                </Section>
            ))}
        </div>
    );
}
