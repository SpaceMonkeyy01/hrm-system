import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Detail } from '@/components/field';
import { NativeSelect, toOptions } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/format';
import type { SharedProps } from '@/types';

type Item = {
    id: number; section: 'goal' | 'behaviour' | 'attendance' | 'deliverable'; title: string | null; detail: string | null;
    due_date: string | null; status: string | null; evaluation: string | null; manager_notes: string | null; employee_notes: string | null;
};
type Checkin = {
    id: number; kind: string; label: string; held_on: string | null; manager_summary: string | null; employee_summary: string | null;
    action_items: string | null; next_follow_up: string | null; initials: string | null;
};
type Plan = Record<string, string | null> & { items: Item[]; checkins: Checkin[]; updated_at: string | null };
type Props = {
    employee: { id: number; code: string; name: string; department: string | null; role_title: string | null; manager: string | null;
        employment_type: string | null; start_date: string | null; cohort: string | null };
    probation: { end_date: string | null; result: string | null };
    plan: Plan | null;
    options: { itemStatuses: string[]; evaluations: string[]; finalEvaluations: string[]; overallFit: string[]; probationResults: string[] };
};

const cell = 'border-b px-2 py-1.5 align-top';
const head = 'border-b px-2 py-2 text-left text-xs font-semibold text-muted-foreground';

function Block({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
    return (
        <section className="grid gap-2">
            <div>
                <h2 className="font-bold">{title}</h2>
                {description && <p className="text-sm text-muted-foreground">{description}</p>}
            </div>
            {children}
        </section>
    );
}

export default function PlanShow({ employee: e, probation, plan, options }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const can = auth.can.edit;

    if (!plan) {
        return (
            <>
                <Head title={`90-day plan: ${e.name}`} />
                <PageHeader back={{ href: `/employees/${e.id}`, label: e.name }} title="90-day plan" />
                <div className="p-4 md:p-6">
                    <EmptyState title={`${e.name} has no 90-day plan yet.`}>
                        <p>The plan is created from the same template as the sheet: goals, behaviour, attendance, deliverables, check-ins and the final evaluation.</p>
                        {can && <Button className="mt-4" onClick={() => router.post(`/employees/${e.id}/plan`)}>Create plan from template</Button>}
                    </EmptyState>
                </div>
            </>
        );
    }

    // key: remount when a check-in row is added so the form picks it up
    return <PlanForm key={plan.checkins.length} e={e} probation={probation} plan={plan} options={options} can={can} />;
}

function PlanForm({ e, probation, plan, options, can }: Omit<Props, 'plan' | 'employee'> & { e: Props['employee']; plan: Plan; can: boolean }) {
    const pick = (k: string) => plan[k] ?? '';
    const form = useForm({
        plan_date: pick('plan_date'),
        final_performance: pick('final_performance'), final_performance_notes: pick('final_performance_notes'),
        final_behaviour: pick('final_behaviour'), final_behaviour_notes: pick('final_behaviour_notes'),
        final_reliability: pick('final_reliability'), final_reliability_notes: pick('final_reliability_notes'),
        overall_fit: pick('overall_fit'), overall_fit_notes: pick('overall_fit_notes'),
        manager_recommendation: pick('manager_recommendation'), hr_comments: pick('hr_comments'),
        employee_signed_name: pick('employee_signed_name'), employee_signed_on: pick('employee_signed_on'),
        manager_signed_name: pick('manager_signed_name'), manager_signed_on: pick('manager_signed_on'),
        hr_signed_name: pick('hr_signed_name'), hr_signed_on: pick('hr_signed_on'),
        probation_result: probation.result ?? '',
        probation_end_date: probation.result === 'Extend' ? (probation.end_date ?? '') : '',
        items: plan.items.map((i) => ({ ...i, title: i.title ?? '', detail: i.detail ?? '', due_date: i.due_date ?? '', status: i.status ?? '',
            evaluation: i.evaluation ?? '', manager_notes: i.manager_notes ?? '', employee_notes: i.employee_notes ?? '' })),
        checkins: plan.checkins.map((c) => ({ ...c, held_on: c.held_on ?? '', manager_summary: c.manager_summary ?? '', employee_summary: c.employee_summary ?? '',
            action_items: c.action_items ?? '', next_follow_up: c.next_follow_up ?? '', initials: c.initials ?? '' })),
    });
    const d = form.data;
    const err = form.errors as Record<string, string>;

    const setItem = (id: number, field: keyof Item, value: string) =>
        form.setData('items', d.items.map((i) => (i.id === id ? { ...i, [field]: value } : i)));
    const setCheckin = (id: number, field: keyof Checkin, value: string) =>
        form.setData('checkins', d.checkins.map((c) => (c.id === id ? { ...c, [field]: value } : c)));
    const rows = (section: Item['section']) => d.items.filter((i) => i.section === section);

    const submit = (ev: FormEvent) => {
        ev.preventDefault();
        const nul = (v: unknown) => (v === '' ? null : v);
        form.transform((data) => ({
            // The end date is only sent with Extend: that is the one case where the plan moves it.
            ...Object.fromEntries(Object.entries(data)
                .filter(([k]) => k !== 'items' && k !== 'checkins' && (k !== 'probation_end_date' || data.probation_result === 'Extend'))
                .map(([k, v]) => [k, nul(v)])),
            items: data.items.map((i) => Object.fromEntries(Object.entries(i).map(([k, v]) => [k, nul(v)]))),
            checkins: data.checkins.map((c) => Object.fromEntries(Object.entries(c).map(([k, v]) => [k, nul(v)]))),
        }));
        form.put(`/employees/${e.id}/plan`, { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    const ro = !can;
    const txt = (value: string, onChange: (v: string) => void, label: string, props: Record<string, unknown> = {}) => (
        <Input value={value} onChange={(ev) => onChange(ev.target.value)} aria-label={label} disabled={ro} className="h-8" {...props} />
    );
    const area = (value: string, onChange: (v: string) => void, label: string) => (
        <Textarea value={value} onChange={(ev) => onChange(ev.target.value)} aria-label={label} disabled={ro} rows={2} className="min-h-14 text-sm" />
    );
    const sel = (value: string, onChange: (v: string) => void, opts: string[], label: string) => (
        <NativeSelect value={value} onChange={(ev) => onChange(ev.target.value)} aria-label={label} disabled={ro} placeholder="-" options={toOptions(opts)} />
    );

    return (
        <>
            <Head title={`90-day plan: ${e.name}`} />
            <PageHeader
                back={{ href: `/employees/${e.id}#onboarding`, label: e.name }}
                title={`90-day plan: ${e.name}`}
                description={plan.updated_at ? `Last saved ${formatDateTime(plan.updated_at)}.` : undefined}
            >
                <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-7">
                    <Detail label="Code">{e.code}</Detail>
                    <Detail label="Department">{e.department}</Detail>
                    <Detail label="Role">{e.role_title}</Detail>
                    <Detail label="Manager">{e.manager}</Detail>
                    <Detail label="Group">{e.cohort}</Detail>
                    <Detail label="Start date">{formatDate(e.start_date)}</Detail>
                    <Detail label="Probation ends">{formatDate(probation.end_date)}</Detail>
                </dl>
            </PageHeader>

            <form onSubmit={submit} className="grid max-w-6xl gap-10 px-4 py-6 pb-28 md:px-6">
                <Block title="Performance goals" description="Goal 4 is optional.">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[900px] text-sm">
                            <thead><tr><th className={head}>Goal</th><th className={head}>Success criteria</th><th className={`${head} w-36`}>Target date</th><th className={`${head} w-36`}>Status</th><th className={head}>Manager notes</th><th className={head}>Employee notes</th></tr></thead>
                            <tbody>{rows('goal').map((i, n) => (
                                <tr key={i.id}>
                                    <td className={cell}>{area(i.title, (v) => setItem(i.id, 'title', v), `Goal ${n + 1}`)}</td>
                                    <td className={cell}>{area(i.detail, (v) => setItem(i.id, 'detail', v), `Success criteria ${n + 1}`)}</td>
                                    <td className={cell}>{txt(i.due_date, (v) => setItem(i.id, 'due_date', v), `Target date ${n + 1}`, { type: 'date' })}</td>
                                    <td className={cell}>{sel(i.status, (v) => setItem(i.id, 'status', v), options.itemStatuses, `Status ${n + 1}`)}</td>
                                    <td className={cell}>{area(i.manager_notes, (v) => setItem(i.id, 'manager_notes', v), `Manager notes ${n + 1}`)}</td>
                                    <td className={cell}>{area(i.employee_notes, (v) => setItem(i.id, 'employee_notes', v), `Employee notes ${n + 1}`)}</td>
                                </tr>
                            ))}</tbody>
                        </table>
                    </div>
                </Block>

                <div className="grid gap-10 xl:grid-cols-2">
                    <Block title="Behaviour and culture">
                        <table className="w-full text-sm">
                            <thead><tr><th className={head}>Expectation</th><th className={`${head} w-44`}>Evaluation</th><th className={head}>Manager notes</th></tr></thead>
                            <tbody>{rows('behaviour').map((i) => (
                                <tr key={i.id}>
                                    <td className={`${cell} pt-3 font-semibold`}>{i.title}</td>
                                    <td className={cell}>{sel(i.evaluation, (v) => setItem(i.id, 'evaluation', v), options.evaluations, `Evaluation: ${i.title}`)}</td>
                                    <td className={cell}>{area(i.manager_notes, (v) => setItem(i.id, 'manager_notes', v), `Notes: ${i.title}`)}</td>
                                </tr>
                            ))}</tbody>
                        </table>
                    </Block>
                    <Block title="Attendance and reliability">
                        <table className="w-full text-sm">
                            <thead><tr><th className={head}>Area</th><th className={`${head} w-44`}>Evaluation</th><th className={head}>Notes</th></tr></thead>
                            <tbody>{rows('attendance').map((i) => (
                                <tr key={i.id}>
                                    <td className={`${cell} pt-3`}><span className="font-semibold">{i.title}</span><span className="block text-xs text-muted-foreground">{i.detail}</span></td>
                                    <td className={cell}>{sel(i.evaluation, (v) => setItem(i.id, 'evaluation', v), options.evaluations, `Evaluation: ${i.title}`)}</td>
                                    <td className={cell}>{area(i.manager_notes, (v) => setItem(i.id, 'manager_notes', v), `Notes: ${i.title}`)}</td>
                                </tr>
                            ))}</tbody>
                        </table>
                    </Block>
                </div>

                <Block title="Deliverables and milestones">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[720px] text-sm">
                            <thead><tr><th className={head}>Expected output</th><th className={`${head} w-36`}>Due date</th><th className={`${head} w-36`}>Status</th><th className={head}>Notes</th></tr></thead>
                            <tbody>{rows('deliverable').map((i, n) => (
                                <tr key={i.id}>
                                    <td className={cell}>{area(i.title, (v) => setItem(i.id, 'title', v), `Deliverable ${n + 1}`)}</td>
                                    <td className={cell}>{txt(i.due_date, (v) => setItem(i.id, 'due_date', v), `Due date ${n + 1}`, { type: 'date' })}</td>
                                    <td className={cell}>{sel(i.status, (v) => setItem(i.id, 'status', v), options.itemStatuses, `Status ${n + 1}`)}</td>
                                    <td className={cell}>{area(i.manager_notes, (v) => setItem(i.id, 'manager_notes', v), `Notes ${n + 1}`)}</td>
                                </tr>
                            ))}</tbody>
                        </table>
                    </div>
                </Block>

                <Block title="Check-in log" description="Saving a date on the 30, 60 or 90-day check-in ticks it on the onboarding checklist.">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1000px] text-sm">
                            <thead><tr><th className={`${head} w-36`}>Check-in</th><th className={`${head} w-36`}>Date</th><th className={head}>Manager summary</th><th className={head}>Employee summary</th><th className={head}>Action items</th><th className={`${head} w-36`}>Next follow-up</th><th className={`${head} w-20`}>Initials</th></tr></thead>
                            <tbody>{d.checkins.map((c) => (
                                <tr key={c.id}>
                                    <td className={`${cell} pt-3 font-semibold`}>{c.label}</td>
                                    <td className={cell}>{txt(c.held_on, (v) => setCheckin(c.id, 'held_on', v), `${c.label} date`, { type: 'date' })}</td>
                                    <td className={cell}>{area(c.manager_summary, (v) => setCheckin(c.id, 'manager_summary', v), `${c.label} manager summary`)}</td>
                                    <td className={cell}>{area(c.employee_summary, (v) => setCheckin(c.id, 'employee_summary', v), `${c.label} employee summary`)}</td>
                                    <td className={cell}>{area(c.action_items, (v) => setCheckin(c.id, 'action_items', v), `${c.label} action items`)}</td>
                                    <td className={cell}>{txt(c.next_follow_up, (v) => setCheckin(c.id, 'next_follow_up', v), `${c.label} next follow-up`, { type: 'date' })}</td>
                                    <td className={cell}>{txt(c.initials, (v) => setCheckin(c.id, 'initials', v), `${c.label} initials`)}</td>
                                </tr>
                            ))}</tbody>
                        </table>
                    </div>
                    {can && (
                        <Button type="button" variant="outline" size="sm" className="justify-self-start" disabled={form.isDirty}
                            title={form.isDirty ? 'Save your changes first' : undefined}
                            onClick={() => router.post(`/employees/${e.id}/plan/checkins`, {}, { preserveScroll: true })}>
                            <Plus /> Add a check-in
                        </Button>
                    )}
                </Block>

                <div className="grid gap-10 xl:grid-cols-2">
                    <Block title="Final evaluation">
                        <table className="w-full text-sm">
                            <thead><tr><th className={head}>Criteria</th><th className={`${head} w-40`}>Evaluation</th><th className={head}>Notes</th></tr></thead>
                            <tbody>
                                {([['Performance', 'final_performance'], ['Behaviour and culture', 'final_behaviour'], ['Reliability', 'final_reliability']] as const).map(([label, key]) => (
                                    <tr key={key}>
                                        <td className={`${cell} pt-3 font-semibold`}>{label}</td>
                                        <td className={cell}>{sel(d[key], (v) => form.setData(key, v), options.finalEvaluations, label)}</td>
                                        <td className={cell}>{area(d[`${key}_notes`], (v) => form.setData(`${key}_notes`, v), `${label} notes`)}</td>
                                    </tr>
                                ))}
                                <tr>
                                    <td className={`${cell} pt-3 font-semibold`}>Overall fit</td>
                                    <td className={cell}>{sel(d.overall_fit, (v) => form.setData('overall_fit', v), options.overallFit, 'Overall fit')}</td>
                                    <td className={cell}>{area(d.overall_fit_notes, (v) => form.setData('overall_fit_notes', v), 'Overall fit notes')}</td>
                                </tr>
                            </tbody>
                        </table>
                    </Block>
                    <Block title="Probation result" description="Saved to the onboarding tracker too.">
                        <div className="grid gap-3">
                            <div className="grid max-w-56 gap-1.5">
                                <label className="text-sm font-medium" htmlFor="probation_result">Result</label>
                                <NativeSelect id="probation_result" value={d.probation_result} disabled={ro} placeholder="Not tracked"
                                    onChange={(ev) => form.setData('probation_result', ev.target.value)} options={toOptions(options.probationResults)} />
                            </div>
                            {d.probation_result === 'Extend' && (
                                <div className="grid max-w-56 gap-1.5">
                                    <label className="text-sm font-medium" htmlFor="probation_end_date">New probation end date</label>
                                    <Input id="probation_end_date" type="date" className="h-8" value={d.probation_end_date} disabled={ro}
                                        onChange={(ev) => form.setData('probation_end_date', ev.target.value)} />
                                    {probation.result !== 'Extend' && <p className="text-xs text-muted-foreground">Must be later than the current end date ({formatDate(probation.end_date)}).</p>}
                                    {err.probation_end_date && <p className="text-xs text-missing">{err.probation_end_date}</p>}
                                </div>
                            )}
                            <div className="grid gap-1.5">
                                <label className="text-sm font-medium" htmlFor="rec">Manager recommendation</label>
                                <Textarea id="rec" value={d.manager_recommendation} disabled={ro} rows={3} onChange={(ev) => form.setData('manager_recommendation', ev.target.value)} />
                            </div>
                            <div className="grid gap-1.5">
                                <label className="text-sm font-medium" htmlFor="hrc">HR comments</label>
                                <Textarea id="hrc" value={d.hr_comments} disabled={ro} rows={3} onChange={(ev) => form.setData('hr_comments', ev.target.value)} />
                            </div>
                        </div>
                    </Block>
                </div>

                <Block title="Sign-off" description="Type the name and date for each person who has signed.">
                    <div className="grid gap-4 sm:grid-cols-3">
                        {(['employee', 'manager', 'hr'] as const).map((who) => (
                            <div key={who} className="grid gap-2 rounded-lg border p-3">
                                <p className="text-sm font-semibold">{who === 'hr' ? 'HR' : who === 'employee' ? 'Employee' : 'Manager'}</p>
                                {txt(d[`${who}_signed_name`], (v) => form.setData(`${who}_signed_name`, v), `${who} name`, { placeholder: 'Name' })}
                                {txt(d[`${who}_signed_on`], (v) => form.setData(`${who}_signed_on`, v), `${who} date`, { type: 'date' })}
                            </div>
                        ))}
                    </div>
                </Block>

                {Object.keys(err).length > 0 && <p className="text-sm text-missing">Some fields need fixing: {Object.values(err).slice(0, 3).join(' ')}</p>}

                {can && (
                    <div className="fixed right-0 bottom-0 left-0 z-20 border-t bg-white/95 px-4 py-3 backdrop-blur md:left-[var(--sidebar-width)]">
                        <div className="flex max-w-6xl items-center gap-3 md:px-2">
                            <Button type="submit" disabled={form.processing || !form.isDirty}>Save plan</Button>
                            <span className="text-sm text-muted-foreground">{form.isDirty ? 'You have unsaved changes.' : 'All changes saved.'}</span>
                            <Link href={`/employees/${e.id}`} className="ml-auto text-sm text-muted-foreground hover:text-foreground">Back to profile</Link>
                        </div>
                    </div>
                )}
            </form>
        </>
    );
}
