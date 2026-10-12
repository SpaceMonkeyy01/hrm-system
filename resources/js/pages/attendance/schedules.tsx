import { Head, router, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2, Users } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { NativeSelect } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { plural } from '@/lib/format';
import type { SharedProps } from '@/types';

type Schedule = {
    id: number; name: string; type: 'fixed' | 'flexible'; start_time: string | null; end_time: string | null;
    late_grace_minutes: number; early_grace_minutes: number; full_day_minutes: number; half_day_minutes: number; overtime_after_minutes: number | null;
    working_days: number[]; missing_checkout: 'flag' | 'auto'; is_default: boolean; notes: string | null; summary: string; people: number;
};
type Company = { id: number; name: string; departments: { id: number; name: string }[] };
type Props = { schedules: Schedule[]; unassigned: number; companies: Company[]; today: string };

const DAYS = [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun']] as const;
const blank = {
    name: '', type: 'fixed' as 'fixed' | 'flexible', start_time: '09:00', end_time: '17:00', late_grace_minutes: 10, early_grace_minutes: 0,
    full_day_hours: 8, half_day_hours: 4, overtime_after: '' as number | '', working_days: [1, 2, 3, 4, 5], missing_checkout: 'flag' as 'flag' | 'auto', is_default: false, notes: '',
};

function ScheduleDialog({ schedule, onClose }: { schedule: Schedule | null; onClose: () => void }) {
    const [d, setD] = useState(() => schedule ? {
        ...schedule, start_time: schedule.start_time ?? '09:00', end_time: schedule.end_time ?? '17:00', notes: schedule.notes ?? '',
        full_day_hours: schedule.full_day_minutes / 60, half_day_hours: schedule.half_day_minutes / 60,
        overtime_after: (schedule.overtime_after_minutes ?? '') as number | '',
    } : blank);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const set = <K extends keyof typeof d>(k: K, v: (typeof d)[K]) => setD((s) => ({ ...s, [k]: v }));
    const fixed = d.type === 'fixed';

    const save = (e: FormEvent) => {
        e.preventDefault();
        const payload = {
            name: d.name, type: d.type, start_time: fixed ? d.start_time : null, end_time: fixed ? d.end_time : null,
            late_grace_minutes: Number(d.late_grace_minutes) || 0, early_grace_minutes: Number(d.early_grace_minutes) || 0,
            full_day_minutes: Math.round(Number(d.full_day_hours) * 60), half_day_minutes: Math.round(Number(d.half_day_hours) * 60),
            overtime_after_minutes: d.overtime_after === '' ? null : Number(d.overtime_after),
            working_days: d.working_days, missing_checkout: d.missing_checkout, is_default: d.is_default, notes: d.notes || null,
        };
        const opts = { preserveScroll: true, onSuccess: onClose, onError: setErrors };
        if (schedule) router.put(`/attendance/schedules/${schedule.id}`, payload, opts);
        else router.post('/attendance/schedules', payload, opts);
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-xl">
                <form onSubmit={save} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{schedule ? `Edit ${schedule.name}` : 'Add a work schedule'}</DialogTitle>
                        <DialogDescription>{schedule ? 'Changes apply to past days too. To keep old days as they were, add a new schedule and move people to it from a date.' : 'Office timings and the rules for late, early and half days.'}</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Name" htmlFor="s-name" error={errors.name}>
                            <Input id="s-name" required value={d.name} placeholder="9 to 5 strict" onChange={(e) => set('name', e.target.value)} />
                        </Field>
                        <Field label="Type" htmlFor="s-type" error={errors.type}>
                            <NativeSelect id="s-type" value={d.type} onChange={(e) => set('type', e.target.value as 'fixed' | 'flexible')}
                                options={[{ value: 'fixed', label: 'Fixed hours' }, { value: 'flexible', label: 'Flexible (come and go)' }]} />
                        </Field>
                        {fixed && (
                            <>
                                <Field label="Starts at" htmlFor="s-start" error={errors.start_time}>
                                    <Input id="s-start" type="time" required value={d.start_time} onChange={(e) => set('start_time', e.target.value)} />
                                </Field>
                                <Field label="Ends at" htmlFor="s-end" error={errors.end_time}>
                                    <Input id="s-end" type="time" required value={d.end_time} onChange={(e) => set('end_time', e.target.value)} />
                                </Field>
                                <Field label="Late after (minutes of grace)" htmlFor="s-late" error={errors.late_grace_minutes} hint="0 = late from the first minute.">
                                    <Input id="s-late" type="number" min={0} max={240} value={d.late_grace_minutes} onChange={(e) => set('late_grace_minutes', Number(e.target.value))} />
                                </Field>
                                <Field label="Early leave allowed (minutes)" htmlFor="s-early" error={errors.early_grace_minutes}>
                                    <Input id="s-early" type="number" min={0} max={240} value={d.early_grace_minutes} onChange={(e) => set('early_grace_minutes', Number(e.target.value))} />
                                </Field>
                            </>
                        )}
                        <Field label={fixed ? 'Full day (hours)' : 'Hours needed a day'} htmlFor="s-full" error={errors.full_day_minutes} hint={fixed ? undefined : 'Less than this shows as Short.'}>
                            <Input id="s-full" type="number" min={0.5} max={24} step={0.25} value={d.full_day_hours} onChange={(e) => set('full_day_hours', Number(e.target.value))} />
                        </Field>
                        <Field label="Half day if under (hours)" htmlFor="s-half" error={errors.half_day_minutes}>
                            <Input id="s-half" type="number" min={0} max={24} step={0.25} value={d.half_day_hours} onChange={(e) => set('half_day_hours', Number(e.target.value))} />
                        </Field>
                        <Field label="Count overtime after (minutes)" htmlFor="s-ot" error={errors.overtime_after_minutes}
                            hint={fixed ? 'Minutes past the end time before overtime counts. Empty = no overtime.' : 'Minutes past the day\'s hours before overtime counts. Empty = no overtime.'}>
                            <Input id="s-ot" type="number" min={0} max={600} value={d.overtime_after} placeholder="Not counted"
                                onChange={(e) => set('overtime_after', e.target.value === '' ? '' : Number(e.target.value))} />
                        </Field>
                        <Field label="Working days" error={errors.working_days} className="sm:col-span-2">
                            <div className="flex flex-wrap gap-1.5">
                                {DAYS.map(([n, label]) => {
                                    const on = d.working_days.includes(n);
                                    return (
                                        <Button key={n} type="button" size="sm" variant={on ? 'default' : 'outline'} aria-pressed={on}
                                            onClick={() => set('working_days', on ? d.working_days.filter((x) => x !== n) : [...d.working_days, n].sort())}>
                                            {label}
                                        </Button>
                                    );
                                })}
                            </div>
                        </Field>
                        {fixed && (
                            <Field label="If someone forgets to punch out" htmlFor="s-missing" error={errors.missing_checkout} className="sm:col-span-2">
                                <NativeSelect id="s-missing" value={d.missing_checkout} onChange={(e) => set('missing_checkout', e.target.value as 'flag' | 'auto')}
                                    options={[{ value: 'flag', label: 'Show as Missing punch, HR enters the time' }, { value: 'auto', label: 'Check them out automatically at the end time' }]} />
                            </Field>
                        )}
                        <Field label="Notes" htmlFor="s-notes" error={errors.notes} className="sm:col-span-2">
                            <Textarea id="s-notes" rows={2} value={d.notes} onChange={(e) => set('notes', e.target.value)} />
                        </Field>
                        <label className="flex items-center gap-2 text-sm sm:col-span-2">
                            <input type="checkbox" className="size-4 accent-cascade" checked={d.is_default} onChange={(e) => set('is_default', e.target.checked)} />
                            Default: use for anyone who hasn't been given a schedule
                        </label>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit">{schedule ? 'Save schedule' : 'Add schedule'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function AssignDialog({ schedule, companies, today, onClose }: { schedule: Schedule; companies: Company[]; today: string; onClose: () => void }) {
    const [companyId, setCompanyId] = useState(companies.length === 1 ? String(companies[0].id) : '');
    const [departmentId, setDepartmentId] = useState('');
    const [startsOn, setStartsOn] = useState(today);
    const [onlyUnassigned, setOnlyUnassigned] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const company = companies.find((c) => String(c.id) === companyId);

    const save = (e: FormEvent) => {
        e.preventDefault();
        router.post(`/attendance/schedules/${schedule.id}/assign`, {
            company_id: companyId || null, department_id: departmentId || null, starts_on: startsOn, only_unassigned: onlyUnassigned,
        }, { preserveScroll: true, onSuccess: onClose, onError: setErrors });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={save} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Move people to {schedule.name}</DialogTitle>
                        <DialogDescription>Everyone in the company or department works to this schedule from the date you pick. For one person, use Edit on their profile.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Company" htmlFor="a-company" error={errors.company_id}>
                            <NativeSelect id="a-company" value={companyId} placeholder="Choose" options={companies.map((c) => ({ value: c.id, label: c.name }))}
                                onChange={(e) => { setCompanyId(e.target.value); setDepartmentId(''); }} />
                        </Field>
                        <Field label="Department" htmlFor="a-dept" error={errors.department_id}>
                            <NativeSelect id="a-dept" value={departmentId} disabled={!company} placeholder="Whole company"
                                options={(company?.departments ?? []).map((x) => ({ value: x.id, label: x.name }))} onChange={(e) => setDepartmentId(e.target.value)} />
                        </Field>
                        <Field label="From" htmlFor="a-from" error={errors.starts_on}>
                            <Input id="a-from" type="date" required value={startsOn} onChange={(e) => setStartsOn(e.target.value)} />
                        </Field>
                        <label className="flex items-center gap-2 self-end pb-2 text-sm">
                            <input type="checkbox" className="size-4 accent-cascade" checked={onlyUnassigned} onChange={(e) => setOnlyUnassigned(e.target.checked)} />
                            Only people with no schedule yet
                        </label>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit">Move people</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Schedules({ schedules, unassigned, companies, today }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const can = auth.can.edit;
    const [editing, setEditing] = useState<Schedule | 'new' | null>(null);
    const [assigning, setAssigning] = useState<Schedule | null>(null);
    const hasDefault = schedules.some((s) => s.is_default);

    return (
        <>
            <Head title="Work schedules" />
            <PageHeader
                title="Work schedules"
                description="Office timings people are held to. Each employee has one; the default covers anyone without."
                actions={can && <Button onClick={() => setEditing('new')}><Plus /> Add schedule</Button>}
            />
            <div className="grid gap-4 px-4 py-6 md:px-6">
                {unassigned > 0 && (
                    <p className="rounded-lg border bg-info-soft px-4 py-3 text-sm">
                        {plural(unassigned, 'current employee')} {unassigned === 1 ? 'has' : 'have'} no schedule of their own
                        {hasDefault ? ' and use the default.' : '. Without a default they are never marked late or absent.'}
                    </p>
                )}
                {schedules.length === 0 ? (
                    <EmptyState title="No work schedules yet.">
                        <p className="mt-1 text-sm text-muted-foreground">Add one for each timing, e.g. "10 to 6", "9 to 5 strict", "12 to 6" and "Flexible".</p>
                    </EmptyState>
                ) : (
                    <ul className="grid gap-3 lg:grid-cols-2">
                        {schedules.map((s) => (
                            <li key={s.id} className="grid gap-2 rounded-lg border p-4">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <h2 className="flex items-center gap-2 font-bold">{s.name} {s.is_default && <StatusPill value="Default" tone="info" />}</h2>
                                        <p className="text-sm text-muted-foreground">{s.summary}</p>
                                    </div>
                                    {can && (
                                        <div className="flex gap-1">
                                            <Button variant="outline" size="sm" onClick={() => setAssigning(s)}><Users /> Move people</Button>
                                            <Button variant="ghost" size="sm" aria-label={`Edit ${s.name}`} onClick={() => setEditing(s)}><Pencil /></Button>
                                            <Button variant="ghost" size="sm" aria-label={`Delete ${s.name}`} onClick={() => {
                                                if (confirm(`Delete the ${s.name} schedule?`)) router.delete(`/attendance/schedules/${s.id}`, { preserveScroll: true, onError: (e) => toast.error(Object.values(e)[0] ?? 'Not deleted.') });
                                            }}><Trash2 /></Button>
                                        </div>
                                    )}
                                </div>
                                <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-4">
                                    <div><dt className="text-xs text-muted-foreground">People</dt><dd className="tabular-nums">{s.people}{s.is_default && unassigned ? ` + ${unassigned}` : ''}</dd></div>
                                    <div><dt className="text-xs text-muted-foreground">Half day under</dt><dd>{s.half_day_minutes / 60} h</dd></div>
                                    {s.type === 'fixed' && <div><dt className="text-xs text-muted-foreground">Early leave allowed</dt><dd>{s.early_grace_minutes} min</dd></div>}
                                    <div><dt className="text-xs text-muted-foreground">Overtime</dt><dd>{s.overtime_after_minutes === null ? 'Not counted' : `After ${s.overtime_after_minutes} min`}</dd></div>
                                    <div><dt className="text-xs text-muted-foreground">No punch out</dt><dd>{s.type === 'fixed' && s.missing_checkout === 'auto' ? 'Auto at end time' : 'HR enters it'}</dd></div>
                                </dl>
                                {s.notes && <p className="text-sm whitespace-pre-line text-muted-foreground">{s.notes}</p>}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            {editing && <ScheduleDialog schedule={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
            {assigning && <AssignDialog schedule={assigning} companies={companies} today={today} onClose={() => setAssigning(null)} />}
        </>
    );
}
