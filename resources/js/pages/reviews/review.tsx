import { Head, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { Detail, Field } from '@/components/field';
import { NativeSelect, toOptions } from '@/components/native-select';
import { PageHeader, Section } from '@/components/page-header';
import { RatingRows, reviewTone, ScaleKey } from '@/components/reviews';
import type { Criterion, Cycle, ReviewData } from '@/components/reviews';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/format';

type Props = {
    cycle: Cycle & { criteria: Criterion[] };
    review: ReviewData;
    employee: { id: number; name: string; code: string; department: string | null; role: string | null; start_date: string | null; manager: string | null };
    canEdit: boolean;
    scale: Record<string, string>;
    recommendations: string[];
    statuses: Record<string, string>;
};

export default function ReviewPage({ cycle, review, employee, canEdit, scale, recommendations, statuses }: Props) {
    const form = useForm({
        manager_ratings: { ...review.manager_ratings } as Record<string, number>,
        manager_comments: { ...review.manager_comments } as Record<string, string>,
        manager_summary: review.manager_summary ?? '',
        goals: review.goals.map((g) => ({ title: g.title, measure: g.measure ?? '', due: g.due ?? '' })),
        recommendation: review.recommendation ?? '',
        submit: false,
    });
    const errors = form.errors as Record<string, string>;
    const save = (submit: boolean) => {
        form.transform((d) => ({ ...d, submit, recommendation: d.recommendation || null, goals: d.goals.map((g) => ({ ...g, measure: g.measure || null, due: g.due || null })) }));
        form.put(`/reviews/${cycle.id}/people/${review.id}`, { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };
    const selfDone = !!review.self_submitted_at;
    const ratedCount = cycle.criteria.filter((c) => form.data.manager_ratings[c.key]).length;

    return (
        <>
            <Head title={`${employee.name}: ${cycle.name}`} />
            <PageHeader
                back={{ href: `/reviews/${cycle.id}`, label: cycle.name }}
                title={employee.name}
                description={
                    <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <StatusPill value={statuses[review.status]} tone={reviewTone[review.status]} />
                        <span>{cycle.name}, {formatDate(cycle.period_start)} to {formatDate(cycle.period_end)}</span>
                        {review.overall !== null && <span className="font-semibold text-ink">Overall {review.overall}, {review.band}</span>}
                    </span>
                }
            >
                <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <Detail label="Role">{employee.role}</Detail>
                    <Detail label="Department">{employee.department}</Detail>
                    <Detail label="Manager">{employee.manager}</Detail>
                    <Detail label="Started">{formatDate(employee.start_date)}</Detail>
                </dl>
            </PageHeader>

            <div className="grid max-w-5xl gap-8 px-4 py-6 md:px-6">
                <p className="text-sm text-muted-foreground">
                    {selfDone ? `Self-review sent ${formatDateTime(review.self_submitted_at)}. Their answers show in grey under each line.`
                        : review.status === 'self_review' ? 'The self-review hasn\'t been sent yet. You can start on your part, or skip the self-review from the cycle page.'
                            : 'No self-review for this person.'}
                </p>

                <Section title="Ratings" help="Talk to their manager, then rate each line." actions={<ScaleKey scale={scale} />}>
                    <RatingRows criteria={cycle.criteria} scale={scale} disabled={!canEdit}
                        ratings={form.data.manager_ratings} comments={form.data.manager_comments}
                        onRate={canEdit ? (k, v) => form.setData('manager_ratings', { ...form.data.manager_ratings, [k]: v }) : undefined}
                        onComment={canEdit ? (k, v) => form.setData('manager_comments', { ...form.data.manager_comments, [k]: v }) : undefined}
                        other={selfDone ? { ratings: review.self_ratings, comments: review.self_comments } : undefined}
                        otherLabel="They said" />
                    {errors.manager_ratings && <p className="mt-2 text-sm text-missing">{errors.manager_ratings}</p>}
                    {review.self_summary && (
                        <p className="mt-3 rounded-md bg-muted px-3 py-2 text-sm"><span className="font-semibold">Their summary: </span><span className="whitespace-pre-line">{review.self_summary}</span></p>
                    )}
                </Section>

                <Section title="Summary and next steps">
                    <div className="grid gap-4">
                        <Field label="Overall comments" htmlFor="m-summary" error={form.errors.manager_summary}>
                            <Textarea id="m-summary" rows={4} disabled={!canEdit} value={form.data.manager_summary} onChange={(e) => form.setData('manager_summary', e.target.value)}
                                placeholder="Strengths, what to work on, anything agreed with them" />
                        </Field>
                        <div>
                            <p className="mb-1.5 text-sm font-semibold">Goals for the next period</p>
                            <ul className="grid gap-2">
                                {form.data.goals.map((g, i) => (
                                    <li key={i} className="grid gap-2 sm:grid-cols-[1fr_1fr_10rem_auto]">
                                        <Input aria-label="Goal" placeholder="Goal" disabled={!canEdit} value={g.title}
                                            onChange={(e) => form.setData('goals', form.data.goals.map((x, n) => (n === i ? { ...x, title: e.target.value } : x)))} />
                                        <Input aria-label="How it is measured" placeholder="How it is measured" disabled={!canEdit} value={g.measure}
                                            onChange={(e) => form.setData('goals', form.data.goals.map((x, n) => (n === i ? { ...x, measure: e.target.value } : x)))} />
                                        <Input aria-label="Due" type="date" disabled={!canEdit} value={g.due}
                                            onChange={(e) => form.setData('goals', form.data.goals.map((x, n) => (n === i ? { ...x, due: e.target.value } : x)))} />
                                        {canEdit && <Button type="button" variant="ghost" size="icon" aria-label="Remove goal" onClick={() => form.setData('goals', form.data.goals.filter((_, n) => n !== i))}><Trash2 /></Button>}
                                        {errors[`goals.${i}.title`] && <p className="text-xs text-missing sm:col-span-4">{errors[`goals.${i}.title`]}</p>}
                                    </li>
                                ))}
                            </ul>
                            {canEdit && form.data.goals.length < 10 && (
                                <Button type="button" variant="outline" size="sm" className="mt-2" onClick={() => form.setData('goals', [...form.data.goals, { title: '', measure: '', due: '' }])}><Plus /> Add goal</Button>
                            )}
                            {!canEdit && form.data.goals.length === 0 && <p className="text-sm text-muted-foreground">No goals set.</p>}
                        </div>
                        <Field label="Recommendation" htmlFor="m-rec" error={form.errors.recommendation} className="max-w-xs">
                            <NativeSelect id="m-rec" disabled={!canEdit} value={form.data.recommendation} placeholder="None" options={toOptions(recommendations)}
                                onChange={(e) => form.setData('recommendation', e.target.value)} />
                        </Field>
                    </div>
                </Section>

                {canEdit ? (
                    <div className="flex flex-wrap items-center gap-3">
                        <Button variant="outline" disabled={form.processing} onClick={() => save(false)}>Save draft</Button>
                        <Button disabled={form.processing} onClick={() => save(true)}>Finish review</Button>
                        <span className="text-sm text-muted-foreground">{ratedCount} of {cycle.criteria.length} rated. Finishing shows the result to {employee.name} on My page.</span>
                        {errors.review && <p className="text-sm text-missing">{errors.review}</p>}
                    </div>
                ) : review.status === 'completed' && (
                    <p className="rounded-lg border px-4 py-3 text-sm">
                        Finished {formatDateTime(review.manager_submitted_at)}{review.reviewed_by ? ` by ${review.reviewed_by}` : ''}.{' '}
                        {review.acknowledged_at ? `${employee.name} read it ${formatDateTime(review.acknowledged_at)}.` : `${employee.name} hasn't marked it as read yet.`}
                        {review.employee_comment && <span className="mt-1 block"><span className="font-semibold">Their comment: </span>{review.employee_comment}</span>}
                    </p>
                )}
            </div>
        </>
    );
}
