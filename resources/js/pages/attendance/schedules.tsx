import { Head, router, usePage } from '@inertiajs/react';
import { LayoutGrid, MoreHorizontal, Pencil, Plus, Rows3, Search, Trash2, Users, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { NativeSelect } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { AttendanceTabs } from '@/components/section-tabs';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { plural } from '@/lib/format';
import { useSessionState } from '@/lib/use-session-state';
import type { SharedProps } from '@/types';

type Schedule = {
    id: number; name: string; type: ScheduleType; start_time: string | null; end_time: string | null;
    late_grace_minutes: number; early_grace_minutes: number; absent_after: string | null; absent_after_label: string | null; late_half_day_minutes: number | null; full_day_minutes: number; half_day_minutes: number; overtime_after_minutes: number | null;
    working_days: number[]; missing_checkout: 'flag' | 'auto'; is_default: boolean; notes: string | null; summary: string; people: number;
};
type Company = { id: number; name: string; departments: { id: number; name: string }[] };
type Props = { schedules: Schedule[]; unassigned: number; companies: Company[]; today: string };

type ScheduleType = 'fixed' | 'flexible' | 'none';

const DAYS = [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun']] as const;
const blank = {
    name: '', type: 'fixed' as ScheduleType, start_time: '09:00', end_time: '17:00', late_grace_minutes: 10, early_grace_minutes: 0, absent_after: '', late_half_day_hours: '' as number | '',
    full_day_hours: 8, half_day_hours: 4, overtime_after: '' as number | '', working_days: [1, 2, 3, 4, 5], missing_checkout: 'flag' as 'flag' | 'auto', is_default: false, notes: '',
};

/** "Mon to Fri", "Mon to Sat", or "Mon, Wed, Fri" when the days aren't in a row. */
function dayRange(days: number[]): string {
    const d = [...days].sort((a, b) => a - b);
    if (d.length === 0) return 'None';
    const name = (n: number) => DAYS.find(([k]) => k === n)?.[1] ?? '';
    const inRow = d.every((n, i) => i === 0 || n === d[i - 1] + 1);
    return inRow && d.length > 2 ? `${name(d[0])} to ${name(d[d.length - 1])}` : d.map(name).join(', ');
}

/** Halfway between start and end, "13:00" for 09:00-17:00 (a night shift ends the next day). Mirrors WorkSchedule::absentAfterMinutes. */
function midday(start: string, end: string): string | null {
    const toMin = (t: string) => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
    if (!/^\d{2}:\d{2}$/.test(start) || !/^\d{2}:\d{2}$/.test(end)) return null;
    const a = toMin(start);
    let b = toMin(end);
    if (b <= a) b += 1440;
    const mid = a + Math.floor((b - a) / 2);
    return `${String(Math.floor(mid / 60) % 24).padStart(2, '0')}:${String(mid % 60).padStart(2, '0')}`;
}

function ScheduleDialog({ schedule, onClose }: { schedule: Schedule | null; onClose: () => void }) {
    const [d, setD] = useState(() => schedule ? {
        ...schedule, start_time: schedule.start_time ?? '09:00', end_time: schedule.end_time ?? '17:00', absent_after: schedule.absent_after ?? '', notes: schedule.notes ?? '',
        full_day_hours: schedule.full_day_minutes / 60, half_day_hours: schedule.half_day_minutes / 60,
        overtime_after: (schedule.overtime_after_minutes ?? '') as number | '',
        late_half_day_hours: (schedule.late_half_day_minutes === null ? '' : schedule.late_half_day_minutes / 60) as number | '',
    } : blank);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const set = <K extends keyof typeof d>(k: K, v: (typeof d)[K]) => setD((s) => ({ ...s, [k]: v }));
    const fixed = d.type === 'fixed';
    const none = d.type === 'none';

    const save = (e: FormEvent) => {
        e.preventDefault();
        const payload = {
            name: d.name, type: d.type, start_time: fixed ? d.start_time : null, end_time: fixed ? d.end_time : null,
            late_grace_minutes: Number(d.late_grace_minutes) || 0, early_grace_minutes: Number(d.early_grace_minutes) || 0,
            absent_after: fixed && d.absent_after ? d.absent_after : null,
            late_half_day_minutes: fixed && d.late_half_day_hours !== '' ? Math.round(Number(d.late_half_day_hours) * 60) : null,
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
                            <NativeSelect id="s-type" value={d.type} onChange={(e) => set('type', e.target.value as ScheduleType)}
                                options={[{ value: 'fixed', label: 'Fixed hours' }, { value: 'flexible', label: 'Flexible (come and go)' }, { value: 'none', label: 'No clock-in (not tracked)' }]} />
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
                                <Field label="Absent if not in by" htmlFor="s-absent" error={errors.absent_after} className="sm:col-span-2"
                                    hint={`Not checked in by this time = Absent for the day. Empty = the middle of the day${midday(d.start_time, d.end_time) ? ` (${midday(d.start_time, d.end_time)})` : ''}.`}>
                                    <div className="flex gap-2">
                                        <Input id="s-absent" type="time" className="w-40" value={d.absent_after} onChange={(e) => set('absent_after', e.target.value)} />
                                        {d.absent_after && <Button type="button" variant="ghost" size="sm" onClick={() => set('absent_after', '')}>Use the middle of the day</Button>}
                                    </div>
                                </Field>
                                <Field label="...unless they still worked (hours)" htmlFor="s-latehalf" error={errors.late_half_day_minutes} className="sm:col-span-2"
                                    hint={d.late_half_day_hours === ''
                                        ? 'Empty = in after the cut-off is always Absent.'
                                        : `In after the cut-off but worked at least ${d.late_half_day_hours} h = Half day; less = Absent.`}>
                                    <Input id="s-latehalf" type="number" className="w-40" min={0.5} max={24} step={0.25} value={d.late_half_day_hours} placeholder="Always Absent"
                                        onChange={(e) => set('late_half_day_hours', e.target.value === '' ? '' : Number(e.target.value))} />
                                </Field>
                            </>
                        )}
                        {none && (
                            <p className="rounded-md border bg-info-soft px-3 py-2 text-sm sm:col-span-2">
                                For people who aren't expected to punch (management, field staff). Their working days show as Not tracked instead of Absent
                                or Not linked, and any punches they do make show as Present. Never late, short or absent.
                            </p>
                        )}
                        {!none && <>
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
                        </>}
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
                        {!none && (
                            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                <input type="checkbox" className="size-4 accent-cascade" checked={d.is_default} onChange={(e) => set('is_default', e.target.checked)} />
                                Default: use for anyone who hasn't been given a schedule
                            </label>
                        )}
                        {errors.is_default && <p className="text-sm text-destructive sm:col-span-2">{errors.is_default}</p>}
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

type Person = { id: number; name: string; code: string; department: string | null; status: string };

/** Search by name or code and pick people one by one (app/Http/Controllers/SearchController.php). */
function PeoplePicker({ picked, onChange }: { picked: Person[]; onChange: (p: Person[]) => void }) {
    const [q, setQ] = useState('');
    const [hits, setHits] = useState<Person[]>([]);
    const seq = useRef(0);

    useEffect(() => {
        const term = q.trim();
        const mine = ++seq.current;
        if (!term) {
            setHits([]);
            return;
        }
        const t = setTimeout(() => {
            fetch(`/search/employees?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((r) => (r.ok ? r.json() : []))
                .then((data: Person[]) => mine === seq.current && setHits(data.filter((h) => h.status !== 'exited')));
        }, 150);
        return () => clearTimeout(t);
    }, [q]);

    const ids = new Set(picked.map((p) => p.id));
    return (
        <div className="grid gap-2">
            <Input id="a-people" value={q} placeholder="Type a name or code" autoComplete="off" onChange={(e) => setQ(e.target.value)} />
            {hits.length > 0 && (
                <ul className="max-h-48 overflow-y-auto rounded-md border p-1" aria-label="Matches">
                    {hits.map((h) => (
                        <li key={h.id}>
                            <button type="button" disabled={ids.has(h.id)} onClick={() => { onChange([...picked, h]); setQ(''); }}
                                className="flex w-full items-center justify-between gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-accent disabled:opacity-50">
                                <span><span className="font-semibold">{h.name}</span> <span className="text-xs text-muted-foreground">{[h.code, h.department].filter(Boolean).join(', ')}</span></span>
                                <span className="text-xs text-muted-foreground">{ids.has(h.id) ? 'Added' : 'Add'}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {picked.length > 0 && (
                <ul className="flex flex-wrap gap-1.5" aria-label="Chosen people">
                    {picked.map((p) => (
                        <li key={p.id} className="flex items-center gap-1 rounded-full border bg-muted/50 py-0.5 pr-1 pl-2.5 text-xs">
                            {p.name} {p.code && <span className="text-muted-foreground">{p.code}</span>}
                            <button type="button" className="rounded-full p-0.5 hover:bg-accent" aria-label={`Remove ${p.name}`}
                                onClick={() => onChange(picked.filter((x) => x.id !== p.id))}><X className="size-3" /></button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function AssignDialog({ schedule, companies, today, onClose }: { schedule: Schedule; companies: Company[]; today: string; onClose: () => void }) {
    const [mode, setMode] = useState<'group' | 'people'>('group');
    const [companyId, setCompanyId] = useState(companies.length === 1 ? String(companies[0].id) : '');
    const [departmentId, setDepartmentId] = useState('');
    const [people, setPeople] = useState<Person[]>([]);
    const [startsOn, setStartsOn] = useState(today);
    const [onlyUnassigned, setOnlyUnassigned] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const company = companies.find((c) => String(c.id) === companyId);

    const save = (e: FormEvent) => {
        e.preventDefault();
        const who = mode === 'people'
            ? { employee_ids: people.map((p) => p.id) }
            : { company_id: companyId || null, department_id: departmentId || null, only_unassigned: onlyUnassigned };
        router.post(`/attendance/schedules/${schedule.id}/assign`, { ...who, starts_on: startsOn }, { preserveScroll: true, onSuccess: onClose, onError: setErrors });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={save} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Move people to {schedule.name}</DialogTitle>
                        <DialogDescription>They work to this schedule from the date you pick. Days before it keep their old schedule.</DialogDescription>
                    </DialogHeader>
                    <div className="flex gap-1.5" role="group" aria-label="Who to move">
                        <Button type="button" size="sm" variant={mode === 'group' ? 'default' : 'outline'} aria-pressed={mode === 'group'} onClick={() => setMode('group')}>A department or company</Button>
                        <Button type="button" size="sm" variant={mode === 'people' ? 'default' : 'outline'} aria-pressed={mode === 'people'} onClick={() => setMode('people')}>Pick people</Button>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {mode === 'group' ? (
                            <>
                                <Field label="Company" htmlFor="a-company" error={errors.company_id}>
                                    <NativeSelect id="a-company" value={companyId} placeholder="Choose" options={companies.map((c) => ({ value: c.id, label: c.name }))}
                                        onChange={(e) => { setCompanyId(e.target.value); setDepartmentId(''); }} />
                                </Field>
                                <Field label="Department" htmlFor="a-dept" error={errors.department_id}>
                                    <NativeSelect id="a-dept" value={departmentId} disabled={!company} placeholder="Whole company"
                                        options={(company?.departments ?? []).map((x) => ({ value: x.id, label: x.name }))} onChange={(e) => setDepartmentId(e.target.value)} />
                                </Field>
                            </>
                        ) : (
                            <Field label={`People${people.length ? ` (${people.length})` : ''}`} htmlFor="a-people" error={errors.employee_ids} className="sm:col-span-2">
                                <PeoplePicker picked={people} onChange={setPeople} />
                            </Field>
                        )}
                        <Field label="From" htmlFor="a-from" error={errors.starts_on}>
                            <Input id="a-from" type="date" required value={startsOn} onChange={(e) => setStartsOn(e.target.value)} />
                        </Field>
                        {mode === 'group' && (
                            <label className="flex items-center gap-2 self-end pb-2 text-sm">
                                <input type="checkbox" className="size-4 accent-cascade" checked={onlyUnassigned} onChange={(e) => setOnlyUnassigned(e.target.checked)} />
                                Only people with no schedule yet
                            </label>
                        )}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={mode === 'people' && people.length === 0}>
                            {mode === 'people' && people.length ? (people.length === 1 ? 'Move 1 person' : `Move ${people.length} people`) : 'Move people'}
                        </Button>
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
    const [view, setView] = useSessionState<'table' | 'cards'>('schedules:view', 'table');
    const [q, setQ] = useSessionState('schedules:q', '');
    const hasDefault = schedules.some((s) => s.is_default);
    const needle = q.trim().toLowerCase();
    const shown = needle ? schedules.filter((s) => `${s.name} ${s.summary} ${s.notes ?? ''}`.toLowerCase().includes(needle)) : schedules;
    // The default also covers everyone without a schedule of their own.
    const people = (s: Schedule) => s.is_default && unassigned ? s.people + unassigned : s.people;
    const peopleNote = (s: Schedule) => s.is_default && unassigned ? (s.people ? `${unassigned} by default` : 'all by default') : null;
    const remove = (s: Schedule) => {
        if (confirm(`Delete the ${s.name} schedule?`)) router.delete(`/attendance/schedules/${s.id}`, { preserveScroll: true, onError: (e) => toast.error(Object.values(e)[0] ?? 'Not deleted.') });
    };
    const actions = (s: Schedule, compact = false) => can && (compact ? (
        <DropdownMenu>
            <DropdownMenuTrigger asChild><Button variant="ghost" size="sm" aria-label={`Options for ${s.name}`}><MoreHorizontal /></Button></DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem onSelect={() => setAssigning(s)}><Users /> Move people</DropdownMenuItem>
                <DropdownMenuItem onSelect={() => setEditing(s)}><Pencil /> Edit</DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive" onSelect={() => remove(s)}><Trash2 /> Delete</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    ) : (
        <div className="flex justify-end gap-1">
            <Button variant="outline" size="sm" onClick={() => setAssigning(s)}><Users /> Move people</Button>
            <Button variant="ghost" size="sm" aria-label={`Edit ${s.name}`} onClick={() => setEditing(s)}><Pencil /></Button>
            <Button variant="ghost" size="sm" aria-label={`Delete ${s.name}`} onClick={() => remove(s)}><Trash2 /></Button>
        </div>
    ));

    return (
        <>
            <Head title="Work schedules" />
            <PageHeader
                title="Work schedules"
                tabs={<AttendanceTabs />}
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
                ) : (<>
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="relative w-full sm:w-64">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input className="pl-8" placeholder="Find a schedule" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Find a schedule" />
                        </div>
                        <span className="text-sm text-muted-foreground">{shown.length === schedules.length ? plural(schedules.length, 'schedule') : `${shown.length} of ${schedules.length}`}</span>
                        <div className="ml-auto inline-flex rounded-md border p-0.5" role="group" aria-label="View">
                            <Button size="sm" variant={view === 'table' ? 'default' : 'ghost'} onClick={() => setView('table')}><Rows3 /> Table</Button>
                            <Button size="sm" variant={view === 'cards' ? 'default' : 'ghost'} onClick={() => setView('cards')}><LayoutGrid /> Cards</Button>
                        </div>
                    </div>
                    {shown.length === 0 ? <EmptyState title="No schedule matches." /> : view === 'table' ? (
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full min-w-[48rem] text-sm">
                                <thead className="bg-muted/50 text-left text-xs whitespace-nowrap text-muted-foreground">
                                    <tr>
                                        <th className="px-3 py-2 font-semibold">Schedule</th>
                                        <th className="px-3 py-2 font-semibold">Hours</th>
                                        <th className="px-3 py-2 font-semibold">Days</th>
                                        <th className="px-3 py-2 font-semibold" title="Absent if not in by">Absent after</th>
                                        <th className="px-3 py-2 font-semibold" title="Half day if worked less than">Half day</th>
                                        <th className="px-3 py-2 font-semibold">Overtime</th>
                                        <th className="px-3 py-2 font-semibold">No punch out</th>
                                        <th className="px-3 py-2 text-right font-semibold">People</th>
                                        {can && <th className="px-3 py-2"><span className="sr-only">Actions</span></th>}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {shown.map((s) => (
                                        <tr key={s.id} className="align-top hover:bg-accent/30">
                                            <td className="px-3 py-2.5">
                                                <span className="flex items-center gap-2 font-semibold">{s.name} {s.is_default && <StatusPill value="Default" tone="info" />}</span>
                                                {s.notes && <span className="mt-0.5 block max-w-48 truncate text-xs text-muted-foreground" title={s.notes}>{s.notes}</span>}
                                            </td>
                                            <td className="px-3 py-2.5 whitespace-nowrap tabular-nums">
                                                {s.type === 'fixed' ? `${s.start_time?.slice(0, 5)} to ${s.end_time?.slice(0, 5)}` : s.type === 'flexible' ? `Flexible, ${s.full_day_minutes / 60} h` : 'No clock-in'}
                                                {s.type === 'fixed' && <span className="block text-xs text-muted-foreground">Late after {s.late_grace_minutes} min</span>}
                                            </td>
                                            <td className="px-3 py-2.5 whitespace-nowrap">{dayRange(s.working_days)}</td>
                                            <td className="px-3 py-2.5">
                                                {s.type === 'fixed' ? <><span className="whitespace-nowrap">{s.absent_after_label}{s.absent_after ? '' : <span className="text-muted-foreground"> (mid-day)</span>}</span>
                                                    {s.late_half_day_minutes !== null && <span className="block text-xs text-muted-foreground">Half day if worked {s.late_half_day_minutes / 60} h+</span>}</> : '-'}
                                            </td>
                                            <td className="px-3 py-2.5 whitespace-nowrap">{s.type !== 'none' ? `${s.half_day_minutes / 60} h` : '-'}</td>
                                            <td className="px-3 py-2.5 whitespace-nowrap">{s.type === 'none' ? '-' : s.overtime_after_minutes === null ? 'Not counted' : `After ${s.overtime_after_minutes} min`}</td>
                                            <td className="px-3 py-2.5 whitespace-nowrap">{s.type === 'none' ? '-' : s.type === 'fixed' && s.missing_checkout === 'auto' ? 'Auto at end time' : 'HR enters it'}</td>
                                            <td className="px-3 py-2.5 text-right tabular-nums">
                                                {people(s)}
                                                {peopleNote(s) && <span className="block text-xs whitespace-nowrap text-muted-foreground">{peopleNote(s)}</span>}
                                            </td>
                                            {can && <td className="px-2 py-1.5">{actions(s, true)}</td>}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                    <ul className="grid gap-3 lg:grid-cols-2">
                        {shown.map((s) => (
                            <li key={s.id} className="grid gap-2 rounded-lg border p-4">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <h2 className="flex items-center gap-2 font-bold">{s.name} {s.is_default && <StatusPill value="Default" tone="info" />}</h2>
                                        <p className="text-sm text-muted-foreground">{s.summary}</p>
                                    </div>
                                    {actions(s)}
                                </div>
                                <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-4">
                                    <div><dt className="text-xs text-muted-foreground">People</dt><dd className="tabular-nums">{people(s)}{peopleNote(s) && <span className="text-muted-foreground">, {peopleNote(s)}</span>}</dd></div>
                                    {s.type !== 'none' && <div><dt className="text-xs text-muted-foreground">Half day under</dt><dd>{s.half_day_minutes / 60} h</dd></div>}
                                    {s.type === 'fixed' && <div><dt className="text-xs text-muted-foreground">Absent if not in by</dt><dd>{s.absent_after_label}{s.absent_after ? '' : ' (mid-day)'}{s.late_half_day_minutes !== null ? `, Half day if worked ${s.late_half_day_minutes / 60} h+` : ''}</dd></div>}
                                    {s.type === 'fixed' && <div><dt className="text-xs text-muted-foreground">Early leave allowed</dt><dd>{s.early_grace_minutes} min</dd></div>}
                                    {s.type !== 'none' && <div><dt className="text-xs text-muted-foreground">Overtime</dt><dd>{s.overtime_after_minutes === null ? 'Not counted' : `After ${s.overtime_after_minutes} min`}</dd></div>}
                                    {s.type !== 'none' && <div><dt className="text-xs text-muted-foreground">No punch out</dt><dd>{s.type === 'fixed' && s.missing_checkout === 'auto' ? 'Auto at end time' : 'HR enters it'}</dd></div>}
                                </dl>
                                {s.notes && <p className="text-sm whitespace-pre-line text-muted-foreground">{s.notes}</p>}
                            </li>
                        ))}
                    </ul>
                    )}
                </>)}
            </div>
            {editing && <ScheduleDialog schedule={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
            {assigning && <AssignDialog schedule={assigning} companies={companies} today={today} onClose={() => setAssigning(null)} />}
        </>
    );
}
