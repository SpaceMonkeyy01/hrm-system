import { Head, Link, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { NativeSelect } from '@/components/native-select';
import { PageHeader, Section } from '@/components/page-header';
import { CycleForm } from '@/components/review-cycle-form';
import { cycleTone, reviewTone } from '@/components/reviews';
import type { Criterion, Cycle, ReviewData } from '@/components/reviews';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate, plural } from '@/lib/format';

type Row = { id: number; status: ReviewData['status']; overall: number | null; band: string | null; recommendation: string | null; acknowledged: boolean;
    employee: { id: number; name: string; code: string; department: string | null } };
type Company = { id: number; name: string; departments: { id: number; name: string }[] };
type Props = {
    cycle: Cycle & { criteria: Criterion[] };
    reviews: Row[];
    statuses: Record<string, string>;
    cycleStatuses: Record<string, string>;
    companies: Company[];
    eligible: number;
};

function CriteriaEditor({ cycle }: { cycle: Props['cycle'] }) {
    const form = useForm({ ...cycle, criteria: cycle.criteria.map((c) => ({ ...c, description: c.description ?? '' })) });
    const set = (i: number, field: 'label' | 'description', v: string) => form.setData('criteria', form.data.criteria.map((c, n) => (n === i ? { ...c, [field]: v } : c)));
    const errors = form.errors as Record<string, string>;
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.put(`/reviews/${cycle.id}`, { preserveScroll: true, onSuccess: () => form.setDefaults() }); }} className="grid gap-3">
            <ul className="grid gap-2">
                {form.data.criteria.map((c, i) => (
                    <li key={i} className="grid gap-2 rounded-lg border p-3 sm:grid-cols-[14rem_1fr_auto]">
                        <Input aria-label="What is rated" value={c.label} onChange={(e) => set(i, 'label', e.target.value)} />
                        <Input aria-label="What it means" placeholder="What good looks like" value={c.description} onChange={(e) => set(i, 'description', e.target.value)} />
                        <Button type="button" variant="ghost" size="icon" aria-label={`Remove ${c.label}`} onClick={() => form.setData('criteria', form.data.criteria.filter((_, n) => n !== i))}><Trash2 /></Button>
                        {errors[`criteria.${i}.label`] && <p className="text-xs text-missing sm:col-span-3">{errors[`criteria.${i}.label`]}</p>}
                    </li>
                ))}
            </ul>
            {errors.criteria && <p className="text-sm text-missing">{errors.criteria}</p>}
            <div className="flex gap-2">
                <Button type="button" variant="outline" onClick={() => form.setData('criteria', [...form.data.criteria, { key: '', label: '', description: '' }])}><Plus /> Add a line</Button>
                <Button type="submit" disabled={!form.isDirty || form.processing}>Save what is rated</Button>
            </div>
        </form>
    );
}

export default function ReviewCycleShow({ cycle, reviews, statuses, cycleStatuses, companies, eligible }: Props) {
    const [editing, setEditing] = useState(false);
    const [scope, setScope] = useState('');
    const closed = cycle.status === 'closed';
    const counts = reviews.reduce<Record<string, number>>((m, r) => ({ ...m, [r.status]: (m[r.status] ?? 0) + 1 }), {});
    const scopes = [
        { value: '', label: `Everyone not in yet (${eligible})` },
        ...companies.map((c) => ({ value: `c${c.id}`, label: `All of ${c.name}` })),
        ...companies.flatMap((c) => c.departments.map((d) => ({ value: `d${d.id}`, label: companies.length > 1 ? `${d.name} (${c.name})` : d.name }))),
    ];
    const add = () => router.post(`/reviews/${cycle.id}/people`,
        scope.startsWith('c') ? { company_id: Number(scope.slice(1)) } : scope.startsWith('d') ? { department_id: Number(scope.slice(1)) } : {}, { preserveScroll: true });

    return (
        <>
            <Head title={cycle.name} />
            <PageHeader
                back={{ href: '/reviews', label: 'Performance reviews' }}
                title={cycle.name}
                description={
                    <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <StatusPill value={cycleStatuses[cycle.status]} tone={cycleTone[cycle.status]} />
                        <span>{formatDate(cycle.period_start)} to {formatDate(cycle.period_end)}</span>
                        {cycle.self_due && <span>Self-reviews due {formatDate(cycle.self_due)}</span>}
                        {cycle.manager_due && <span>HR reviews due {formatDate(cycle.manager_due)}</span>}
                    </span>
                }
                actions={!closed && (
                    <>
                        <Button variant="outline" onClick={() => setEditing(true)}><Pencil /> Edit</Button>
                        {cycle.status === 'draft' && <Button onClick={() => router.post(`/reviews/${cycle.id}/open`, {}, { preserveScroll: true })} disabled={!reviews.length}>Open cycle</Button>}
                        {cycle.status === 'open' && (
                            <Button variant="outline" onClick={() => confirm('Close this cycle? Nothing in it can be changed afterwards.') && router.post(`/reviews/${cycle.id}/close`, {}, { preserveScroll: true })}>Close cycle</Button>
                        )}
                    </>
                )}
            />

            <div className="grid gap-10 px-4 py-6 md:px-6">
                {cycle.status === 'draft' && (
                    <p className="max-w-3xl rounded-lg border bg-info-soft px-4 py-3 text-sm">
                        Being set up: nobody sees this cycle yet. Check what is rated, add people, then open it. People with a My page login fill in a self-review first; the rest go straight to HR.
                    </p>
                )}

                <Section title="What is rated" description={cycle.status === 'draft' ? 'Rated 1 to 5. Change the lines before opening the cycle.' : 'Rated 1 to 5. Fixed once the cycle is open.'}>
                    {cycle.status === 'draft' ? <CriteriaEditor cycle={cycle} /> : (
                        <ul className="grid gap-1 text-sm sm:grid-cols-2">
                            {cycle.criteria.map((c) => <li key={c.key}><span className="font-semibold">{c.label}</span>{c.description && <span className="text-muted-foreground">: {c.description}</span>}</li>)}
                        </ul>
                    )}
                </Section>

                <Section title="People" description={reviews.length ? `${plural(reviews.length, 'person', 'people')}. ${Object.entries(counts).map(([k, n]) => `${statuses[k]}: ${n}`).join(', ')}.` : 'Nobody added yet.'}
                    actions={!closed && (
                        <div className="flex flex-wrap items-center gap-2">
                            <NativeSelect className="w-72" aria-label="Who to add" value={scope} options={scopes} onChange={(e) => setScope(e.target.value)} />
                            <Button variant="outline" onClick={add}><Plus /> Add</Button>
                        </div>
                    )}>
                    {reviews.length === 0 ? <EmptyState title="Add the people this cycle reviews." /> : (
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full min-w-[48rem] text-sm">
                                <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-3 py-2 font-semibold">Employee</th>
                                        <th className="px-3 py-2 font-semibold">Status</th>
                                        <th className="px-3 py-2 font-semibold">Overall</th>
                                        <th className="px-3 py-2 font-semibold">Recommendation</th>
                                        <th className="px-3 py-2"><span className="sr-only">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {reviews.map((r) => (
                                        <tr key={r.id} className={r.status === 'cancelled' ? 'text-muted-foreground' : undefined}>
                                            <td className="px-3 py-2">
                                                <Link href={`/reviews/${cycle.id}/people/${r.id}`} className="font-semibold hover:text-cascade hover:underline">{r.employee.name}</Link>
                                                <span className="block text-xs text-muted-foreground">{[r.employee.code, r.employee.department].filter(Boolean).join(', ')}</span>
                                            </td>
                                            <td className="px-3 py-2">
                                                <StatusPill value={statuses[r.status]} tone={reviewTone[r.status]} />
                                                {r.status === 'completed' && <span className="mt-0.5 block text-xs text-muted-foreground">{r.acknowledged ? 'Read by them' : 'Not read yet'}</span>}
                                            </td>
                                            <td className="px-3 py-2 tabular-nums">{r.overall !== null && r.status === 'completed' ? <>{r.overall} <span className="text-xs text-muted-foreground">{r.band}</span></> : ''}</td>
                                            <td className="px-3 py-2">{r.status === 'completed' ? r.recommendation : ''}</td>
                                            <td className="px-3 py-2">
                                                <div className="flex justify-end gap-1">
                                                    {cycle.status === 'open' && r.status === 'self_review' && (
                                                        <Button size="sm" variant="ghost" onClick={() => router.post(`/reviews/${cycle.id}/people/${r.id}/skip-self`, {}, { preserveScroll: true })}>Skip self-review</Button>
                                                    )}
                                                    <Button size="sm" variant="outline" asChild><Link href={`/reviews/${cycle.id}/people/${r.id}`}>{cycle.status === 'open' && r.status !== 'completed' && r.status !== 'cancelled' ? 'Review' : 'View'}</Link></Button>
                                                    {!closed && r.status !== 'completed' && r.status !== 'cancelled' && (
                                                        <Button size="sm" variant="ghost" aria-label={`Take ${r.employee.name} out`}
                                                            onClick={() => confirm(`Take ${r.employee.name} out of this cycle?`) && router.delete(`/reviews/${cycle.id}/people/${r.id}`, { preserveScroll: true })}><Trash2 /></Button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Section>
            </div>
            {editing && <CycleForm cycle={cycle} onClose={() => setEditing(false)} />}
        </>
    );
}
