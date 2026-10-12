import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { NativeSelect } from '@/components/native-select';
import { PageHeader, Section } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { leaveDates } from '@/components/leave';
import type { LeaveRecordRow } from '@/components/leave';
import { formatDate } from '@/lib/format';
import type { SharedProps } from '@/types';


type LeaveType = { id: number; name: string; is_paid: boolean; is_active: boolean; sort_order: number; used: number };
type Holiday = { id: number; date: string; name: string; company: string | null; company_id: number | null };
type Company = { id: number; name: string; departments: { id: number; name: string }[] };
type Props = {
    month: string; today: string; filters: { type?: number; department?: number; q?: string };
    records: LeaveRecordRow[]; onLeaveToday: { id: number; name: string; type: string; part: string }[];
    types: LeaveType[]; holidays: Holiday[]; year: number; people: { id: number; label: string }[]; companies: Company[];
    parts: Record<string, string>;
};

const opts = { preserveScroll: true, onError: (e: Record<string, string>) => toast.error(Object.values(e)[0] ?? 'Not saved.') };


function AddLeave({ people, types, parts, onClose }: { people: Props['people']; types: LeaveType[]; parts: Props['parts']; onClose: () => void }) {
    const [d, setD] = useState({ employee_id: '', leave_type_id: '', start_date: '', end_date: '', part: 'full', note: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const set = (k: keyof typeof d, v: string) => setD((s) => ({ ...s, [k]: v }));
    const save = (e: FormEvent) => {
        e.preventDefault();
        router.post('/leave', { ...d, end_date: d.end_date || d.start_date, note: d.note || null }, { preserveScroll: true, onSuccess: onClose, onError: setErrors });
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={save} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Record leave</DialogTitle>
                        <DialogDescription>Leave already approved (in HR.my or by email). Attendance shows these days as On leave.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Employee" htmlFor="l-emp" error={errors.employee_id} className="sm:col-span-2">
                            <NativeSelect id="l-emp" required value={d.employee_id} placeholder="Choose" options={people.map((p) => ({ value: p.id, label: p.label }))} onChange={(e) => set('employee_id', e.target.value)} />
                        </Field>
                        <Field label="Type" htmlFor="l-type" error={errors.leave_type_id}>
                            <NativeSelect id="l-type" required value={d.leave_type_id} placeholder="Choose" options={types.filter((t) => t.is_active).map((t) => ({ value: t.id, label: t.name }))} onChange={(e) => set('leave_type_id', e.target.value)} />
                        </Field>
                        <Field label="Day" htmlFor="l-part" error={errors.part}>
                            <NativeSelect id="l-part" value={d.part} options={Object.entries(parts).map(([value, label]) => ({ value, label }))} onChange={(e) => set('part', e.target.value)} />
                        </Field>
                        <Field label="First day" htmlFor="l-start" error={errors.start_date}>
                            <Input id="l-start" type="date" required value={d.start_date} onChange={(e) => set('start_date', e.target.value)} />
                        </Field>
                        {d.part === 'full' && (
                            <Field label="Last day" htmlFor="l-end" error={errors.end_date} hint="Empty for one day.">
                                <Input id="l-end" type="date" min={d.start_date} value={d.end_date} onChange={(e) => set('end_date', e.target.value)} />
                            </Field>
                        )}
                        <Field label="Note" htmlFor="l-note" error={errors.note} className="sm:col-span-2">
                            <Textarea id="l-note" rows={2} value={d.note} onChange={(e) => set('note', e.target.value)} />
                        </Field>
                    </div>
                    <p className="text-xs text-muted-foreground">Days are counted on the person's working days, skipping weekends and holidays.</p>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit">Record leave</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Holidays({ holidays, companies, year, month, can }: { holidays: Holiday[]; companies: Company[]; year: number; month: string; can: boolean }) {
    const [d, setD] = useState({ date: '', end_date: '', name: '', company_id: '' });
    const add = (e: FormEvent) => {
        e.preventDefault();
        router.post('/leave/holidays', { ...d, end_date: d.end_date || null, company_id: d.company_id || null }, { ...opts, onSuccess: () => setD({ date: '', end_date: '', name: '', company_id: '' }) });
    };
    return (
        <div className="grid max-w-3xl gap-4">
            {can && (
                <form onSubmit={add} className="grid gap-3 rounded-lg border p-4 sm:grid-cols-[1fr_1fr_1.5fr] sm:items-end">
                    <Field label="Date" htmlFor="h-date"><Input id="h-date" type="date" required value={d.date} onChange={(e) => setD({ ...d, date: e.target.value })} /></Field>
                    <Field label="Until (optional)" htmlFor="h-end"><Input id="h-end" type="date" min={d.date} value={d.end_date} onChange={(e) => setD({ ...d, end_date: e.target.value })} /></Field>
                    <Field label="Name" htmlFor="h-name"><Input id="h-name" required value={d.name} placeholder="Eid ul Fitr" onChange={(e) => setD({ ...d, name: e.target.value })} /></Field>
                    {companies.length > 1 && (
                        <Field label="Company" htmlFor="h-company">
                            <NativeSelect id="h-company" value={d.company_id} placeholder="Every company" options={companies.map((c) => ({ value: c.id, label: c.name }))} onChange={(e) => setD({ ...d, company_id: e.target.value })} />
                        </Field>
                    )}
                    <Button type="submit" className="justify-self-start"><Plus /> Add holiday</Button>
                </form>
            )}
            <div className="flex items-center justify-between">
                <h2 className="font-bold">Holidays in {year}</h2>
                <span className="flex gap-1">
                    <Button variant="ghost" size="sm" asChild><Link href={`/leave?month=${year - 1}-${month.slice(5)}#holidays`}>{year - 1}</Link></Button>
                    <Button variant="ghost" size="sm" asChild><Link href={`/leave?month=${year + 1}-${month.slice(5)}#holidays`}>{year + 1}</Link></Button>
                </span>
            </div>
            {holidays.length === 0 ? <EmptyState title={`No holidays added for ${year}.`} /> : (
                <ul className="divide-y rounded-lg border">
                    {holidays.map((h) => (
                        <li key={h.id} className="flex items-center gap-3 px-3 py-2 text-sm">
                            <span className="w-32 shrink-0 tabular-nums">{formatDate(h.date)}</span>
                            <span className="flex-1 font-semibold">{h.name}</span>
                            <span className="text-muted-foreground">{h.company ?? 'Every company'}</span>
                            {can && <Button variant="ghost" size="sm" aria-label={`Remove ${h.name} on ${formatDate(h.date)}`} onClick={() => router.delete(`/leave/holidays/${h.id}`, opts)}><Trash2 /></Button>}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function Types({ types, can }: { types: LeaveType[]; can: boolean }) {
    const [name, setName] = useState('');
    return (
        <div className="grid max-w-2xl gap-4">
            <ul className="divide-y rounded-lg border">
                {types.map((t) => (
                    <li key={t.id} className="flex flex-wrap items-center gap-3 px-3 py-2 text-sm">
                        <span className={`flex-1 font-semibold ${t.is_active ? '' : 'text-muted-foreground line-through'}`}>{t.name}</span>
                        <span className="text-muted-foreground">{t.used} recorded</span>
                        {can ? (
                            <>
                                <label className="flex items-center gap-1.5"><input type="checkbox" className="size-4 accent-cascade" checked={t.is_paid}
                                    onChange={(e) => router.put(`/leave/types/${t.id}`, { name: t.name, is_paid: e.target.checked, is_active: t.is_active }, opts)} /> Paid</label>
                                <label className="flex items-center gap-1.5"><input type="checkbox" className="size-4 accent-cascade" checked={t.is_active}
                                    onChange={(e) => router.put(`/leave/types/${t.id}`, { name: t.name, is_paid: t.is_paid, is_active: e.target.checked }, opts)} /> In use</label>
                            </>
                        ) : <StatusPill value={t.is_paid ? 'Paid' : 'Unpaid'} tone="neutral" />}
                    </li>
                ))}
            </ul>
            {can && (
                <form onSubmit={(e) => { e.preventDefault(); router.post('/leave/types', { name, is_paid: true }, { ...opts, onSuccess: () => setName('') }); }} className="flex gap-2">
                    <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="New leave type" className="w-64" aria-label="New leave type" />
                    <Button type="submit" variant="outline" disabled={!name.trim()}><Check /> Add type</Button>
                </form>
            )}
        </div>
    );
}

export default function LeaveIndex(props: Props) {
    const { auth } = usePage<SharedProps>().props;
    const can = auth.can.edit;
    const [adding, setAdding] = useState(false);
    const [q, setQ] = useState(props.filters.q ?? '');
    const go = (changes: Record<string, unknown>) => router.get('/leave', { ...props.filters, month: props.month, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
    const departments = props.companies.flatMap((c) => c.departments.map((d) => ({ value: d.id, label: props.companies.length > 1 ? `${d.name} (${c.name})` : d.name })));
    const hash = typeof window !== 'undefined' ? window.location.hash.slice(1) : '';

    return (
        <>
            <Head title="Leave" />
            <PageHeader title="Leave" description="Approved leave and public holidays. Approvals stay in HR.my; record the outcome here so attendance is right."
                actions={can && <Button onClick={() => setAdding(true)}><Plus /> Record leave</Button>} />
            <Tabs defaultValue={['holidays', 'types'].includes(hash) ? hash : 'records'} className="px-4 pt-4 pb-10 md:px-6"
                onValueChange={(v) => history.replaceState(history.state, '', `#${v}`)}>
                <TabsList>
                    <TabsTrigger value="records">Leave</TabsTrigger>
                    <TabsTrigger value="holidays">Public holidays</TabsTrigger>
                    <TabsTrigger value="types">Leave types</TabsTrigger>
                </TabsList>

                <TabsContent value="records" className="grid gap-4">
                    {props.onLeaveToday.length > 0 && (
                        <p className="rounded-lg border bg-muted-tone-soft px-4 py-3 text-sm">
                            <span className="font-semibold">On leave today: </span>
                            {props.onLeaveToday.map((p, i) => (
                                <span key={`${p.id}-${i}`}>{i > 0 && ', '}<Link className="text-cascade hover:underline" href={`/employees/${p.id}#leave`}>{p.name}</Link> ({p.type}{p.part !== 'full' ? ', half day' : ''})</span>
                            ))}
                        </p>
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                        <Input type="month" className="w-44" value={props.month} aria-label="Month" onChange={(e) => e.target.value && go({ month: e.target.value })} />
                        <NativeSelect className="w-44" value={props.filters.type ?? ''} placeholder="All types" options={props.types.map((t) => ({ value: t.id, label: t.name }))} aria-label="Leave type" onChange={(e) => go({ type: e.target.value || undefined })} />
                        <NativeSelect className="w-56" value={props.filters.department ?? ''} placeholder="All departments" options={departments} aria-label="Department" onChange={(e) => go({ department: e.target.value || undefined })} />
                        <form onSubmit={(e) => { e.preventDefault(); go({ q: q || undefined }); }}>
                            <Input className="w-56" placeholder="Search name or code" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search" />
                        </form>
                    </div>
                    {props.records.length === 0 ? <EmptyState title="No leave recorded this month." /> : (
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full min-w-[48rem] text-sm">
                                <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-3 py-2 font-semibold">Employee</th><th className="px-3 py-2 font-semibold">Type</th>
                                        <th className="px-3 py-2 font-semibold">Dates</th><th className="px-3 py-2 font-semibold">Days</th>
                                        <th className="px-3 py-2 font-semibold">Note</th>{can && <th className="px-3 py-2"><span className="sr-only">Remove</span></th>}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {props.records.map((r) => (
                                        <tr key={r.id}>
                                            <td className="px-3 py-2">
                                                {r.employee && <Link href={`/employees/${r.employee.id}#leave`} className="font-semibold hover:text-cascade hover:underline">{r.employee.name}</Link>}
                                                <span className="block text-xs text-muted-foreground">{[r.employee?.code, r.employee?.department].filter(Boolean).join(', ')}</span>
                                            </td>
                                            <td className="px-3 py-2">{r.type}</td>
                                            <td className="px-3 py-2 tabular-nums">{leaveDates(r)}</td>
                                            <td className="px-3 py-2 tabular-nums">{r.days}</td>
                                            <td className="px-3 py-2 text-muted-foreground">{r.note}{r.by && <span className="block text-xs">Recorded by {r.by}{r.source === 'hrmy' ? ' (from HR.my)' : ''}</span>}</td>
                                            {can && <td className="px-3 py-2 text-right">
                                                <Button variant="ghost" size="sm" aria-label={`Remove leave for ${r.employee?.name}`} onClick={() => confirm('Remove this leave? Attendance will show those days as before.') && router.delete(`/leave/${r.id}`, opts)}><Trash2 /></Button>
                                            </td>}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </TabsContent>

                <TabsContent value="holidays">
                    <Holidays holidays={props.holidays} companies={props.companies} year={props.year} month={props.month} can={can} />
                </TabsContent>

                <TabsContent value="types">
                    <Section title="Leave types" description="Switch a type off to stop new leave of that type; past records keep it.">
                        <Types types={props.types} can={can} />
                    </Section>
                </TabsContent>
            </Tabs>
            {adding && <AddLeave people={props.people} types={props.types} parts={props.parts} onClose={() => setAdding(false)} />}
        </>
    );
}
