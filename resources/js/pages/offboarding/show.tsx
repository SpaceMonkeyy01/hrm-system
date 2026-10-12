import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { Detail, Field } from '@/components/field';
import { NativeSelect, toOptions } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { StatusPicker } from '@/components/status-picker';
import { computedTone, StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/format';
import type { ChecklistColumn, OptionSets, SharedProps } from '@/types';

type Props = {
    case: { id: number; type: string; reason: string | null; status: string; exit_interview_notes: string | null; notes: string | null;
        notice_date: string | null; last_working_day: string | null; completed_at: string | null };
    employee: { id: number; code: string; name: string; department: string | null; company: string | null; start_date: string | null; status: string };
    checklist: { columns: ChecklistColumn[]; values: Record<string, string | null> };
    optionSets: OptionSets;
    types: string[];
};

const statusLabel: Record<string, string> = { in_progress: 'In progress', completed: 'Completed', cancelled: 'Cancelled' };

export default function OffboardingShow({ case: c, employee: e, checklist, optionSets, types }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const open = c.status === 'in_progress';
    const can = auth.can.edit && open;
    const [optimistic, setOptimistic] = useState<Record<string, string | null>>({});
    const form = useForm({
        type: c.type, notice_date: c.notice_date ?? '', last_working_day: c.last_working_day ?? '', reason: c.reason ?? '',
        exit_interview_notes: c.exit_interview_notes ?? '', notes: c.notes ?? '',
    });

    const value = (key: string) => (key in optimistic ? optimistic[key] : checklist.values[key] ?? null);
    const cleared = checklist.columns.filter((col) => optionSets.clearance?.find((o) => o.value === value(col.key))?.done).length;

    const save = (col: ChecklistColumn, v: string | null) => {
        setOptimistic((s) => ({ ...s, [col.key]: v }));
        router.put(`/checklist/${e.id}/${col.id}`, { status: v }, {
            preserveScroll: true, preserveState: true, only: ['checklist'],
            onError: (err) => toast.error(Object.values(err)[0] ?? 'Not saved.'),
            onFinish: () => setOptimistic((s) => { const n = { ...s }; delete n[col.key]; return n; }),
        });
    };

    const submit = (ev: FormEvent) => {
        ev.preventDefault();
        form.transform((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v])));
        form.put(`/offboarding/${c.id}`, { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    return (
        <>
            <Head title={`Offboarding: ${e.name}`} />
            <PageHeader
                back={{ href: '/offboarding', label: 'Offboarding' }}
                title={`Offboarding: ${e.name}`}
                description={
                    <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <StatusPill value={statusLabel[c.status]} tone={computedTone(c.status)} />
                        <span>{c.type}{c.last_working_day ? `, last working day ${formatDate(c.last_working_day)}` : ''}</span>
                        {c.completed_at && <span>Completed {formatDateTime(c.completed_at)}</span>}
                    </span>
                }
                actions={can && (
                    <>
                        <Button variant="ghost" onClick={() => confirm('Cancel this offboarding? The employee becomes active again.') && router.post(`/offboarding/${c.id}/cancel`, {}, { preserveScroll: true })}>
                            Cancel offboarding
                        </Button>
                        <Button onClick={() => router.post(`/offboarding/${c.id}/complete`, {}, { preserveScroll: true })} disabled={checklist.columns.length === 0 || cleared < checklist.columns.length}
                            title={checklist.columns.length === 0 ? 'No clearance items are switched on in Settings' : cleared < checklist.columns.length ? 'Finish the clearance checklist first' : undefined}>
                            Mark as exited
                        </Button>
                    </>
                )}
            >
                <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <Detail label="Employee"><Link href={`/employees/${e.id}`} className="text-cascade hover:underline">{e.name} ({e.code})</Link></Detail>
                    <Detail label="Department">{[e.department, e.company].filter(Boolean).join(', ')}</Detail>
                    <Detail label="Started">{formatDate(e.start_date)}</Detail>
                    <Detail label="Employee status">{e.status}</Detail>
                </dl>
            </PageHeader>

            <div className="grid gap-10 px-4 py-6 md:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                <section>
                    <h2 className="font-bold">Clearance</h2>
                    <p className="mb-3 text-sm text-muted-foreground">{cleared} of {checklist.columns.length} done. All must be Done or Not Required before marking as exited.</p>
                    <ul className="divide-y rounded-lg border">
                        {checklist.columns.map((col) => (
                            <li key={col.id} className="flex items-center justify-between gap-4 px-3 py-2">
                                <span className="text-sm font-semibold">{col.label}</span>
                                <StatusPicker value={value(col.key)} set={col.option_set} optionSets={optionSets} disabled={!can}
                                    label={`${col.label} for ${e.name}`} onChange={(v) => save(col, v)} />
                            </li>
                        ))}
                    </ul>
                </section>

                <form onSubmit={submit} className="grid content-start gap-4">
                    <h2 className="font-bold">Details and exit interview</h2>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Type" htmlFor="type" error={form.errors.type}>
                            <NativeSelect id="type" disabled={!can} value={form.data.type} onChange={(ev) => form.setData('type', ev.target.value)} options={toOptions(types)} />
                        </Field>
                        <Field label="Notice given on" htmlFor="notice" error={form.errors.notice_date}>
                            <Input id="notice" type="date" disabled={!can} value={form.data.notice_date} onChange={(ev) => form.setData('notice_date', ev.target.value)} />
                        </Field>
                        <Field label="Last working day" htmlFor="last" error={form.errors.last_working_day}>
                            <Input id="last" type="date" disabled={!can} value={form.data.last_working_day} onChange={(ev) => form.setData('last_working_day', ev.target.value)} />
                        </Field>
                    </div>
                    <Field label="Reason for leaving" htmlFor="reason" error={form.errors.reason}>
                        <Textarea id="reason" rows={3} disabled={!can} value={form.data.reason} onChange={(ev) => form.setData('reason', ev.target.value)} />
                    </Field>
                    <Field label="Exit interview notes" htmlFor="exit" error={form.errors.exit_interview_notes}
                        hint="Why they're leaving, what worked, what should change, would they come back or recommend us.">
                        <Textarea id="exit" rows={7} disabled={!can} value={form.data.exit_interview_notes} onChange={(ev) => form.setData('exit_interview_notes', ev.target.value)} />
                    </Field>
                    <Field label="Other notes" htmlFor="notes" error={form.errors.notes}>
                        <Textarea id="notes" rows={3} disabled={!can} value={form.data.notes} onChange={(ev) => form.setData('notes', ev.target.value)} />
                    </Field>
                    {can && <Button type="submit" className="justify-self-start" disabled={form.processing || !form.isDirty}>Save details</Button>}
                </form>
            </div>
        </>
    );
}
