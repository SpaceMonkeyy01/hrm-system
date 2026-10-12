import { Head, router, useForm } from '@inertiajs/react';
import { CheckCircle2, CircleDashed, Send } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { DayStatus, duration, TimeCell } from '@/components/attendance';
import type { AttendanceDay } from '@/components/attendance';
import { EmptyState } from '@/components/empty-state';
import { Detail, Field } from '@/components/field';
import { AssetList } from '@/components/assets';
import type { AssetRow } from '@/components/assets';
import { leaveDates } from '@/components/leave';
import type { LeaveRecordRow } from '@/components/leave';
import { PageHeader, Section } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/format';
import type { Tone } from '@/types';

type Me = {
    system_id: string; employee_code: string; full_name: string; email: string | null; role_title: string | null; employment_type: string | null;
    work_mode: string | null; work_location: string | null; cnic: string | null; father_name: string | null; gender: string | null;
    marital_status: string | null; blood_group: string | null; bank_name: string | null; bank_account_title: string | null; bank_account_last4: string | null;
    permanent_address: string | null; start_date: string | null; confirmation_date: string | null; date_of_birth: string | null; cnic_expiry: string | null;
    company: string | null; department: string | null; team: string | null; manager: string | null;
    phone: string | null; alternate_phone: string | null; personal_email: string | null; current_address: string | null; city: string | null;
    emergency_contact_name: string | null; emergency_contact_relation: string | null; emergency_contact_phone: string | null;
};
type AttendanceMonth = {
    month: string; days: AttendanceDay[]; counts: Record<string, number>; worked: number;
    schedule: { name: string; summary: string } | null; linked: boolean; statuses: Record<string, Tone>;
};
/** app/Models/AttendanceRequest.php props() */
type FixRequest = {
    id: number; date: string; check_in: string | null; check_out: string | null; reason: string; status: 'pending' | 'approved' | 'rejected' | 'cancelled';
    status_label: string; decision_note: string | null; decided_by: string | null; decided_at: string | null; created_at: string | null;
};
type Props = {
    employee: Me | null;
    documents?: { label: string; status: string | null; done: boolean }[];
    attendance?: AttendanceMonth;
    leave?: { year: number; records: LeaveRecordRow[]; totals: Record<string, number> };
    requests?: FixRequest[];
    assets?: AssetRow[];
    rules?: { daysBack: number; editable: string[] };
    today?: string;
};

/** Days worth asking HR about: something is missing or off. */
const askable = (d: AttendanceDay) => ['Absent', 'Missing punch', 'Half day', 'Not linked', 'In office'].includes(d.status) || d.late > 0 || d.early > 0;

const requestTone: Record<FixRequest['status'], Tone> = { pending: 'pending', approved: 'done', rejected: 'missing', cancelled: 'neutral' };

/** "30:00" (next morning on a night shift) as it would be typed: 06:00. */
const clock = (t: string | null) => (t ? `${String(Number(t.slice(0, 2)) % 24).padStart(2, '0')}${t.slice(2)}` : '');

function RequestDialog({ day, date, onClose, today, daysBack }: { day: AttendanceDay | null; date: string; onClose: () => void; today: string; daysBack: number }) {
    const form = useForm({ date, check_in: day?.in ? clock(day.in) : '', check_out: day?.out && day.out_source === 'punch' ? clock(day.out) : '', reason: '' });
    const earliest = new Date(Date.parse(today) - daysBack * 86_400_000).toISOString().slice(0, 10);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/me/requests', { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Ask HR to fix a day</DialogTitle>
                        <DialogDescription>
                            {day ? `Punches on ${formatDate(day.date)}: ${day.punchTimes.length ? day.punchTimes.join(', ') : 'none'}. ` : ''}
                            Enter the times you actually came in and left. HR checks and approves it.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Day" htmlFor="rq-date" error={form.errors.date} className="sm:col-span-2">
                            <Input id="rq-date" type="date" required min={earliest} max={today} value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} />
                        </Field>
                        <Field label="Came in at" htmlFor="rq-in" error={form.errors.check_in}>
                            <Input id="rq-in" type="time" value={form.data.check_in} onChange={(e) => form.setData('check_in', e.target.value)} />
                        </Field>
                        <Field label="Left at" htmlFor="rq-out" error={form.errors.check_out}>
                            <Input id="rq-out" type="time" value={form.data.check_out} onChange={(e) => form.setData('check_out', e.target.value)} />
                        </Field>
                        <Field label="What happened" htmlFor="rq-reason" error={form.errors.reason} className="sm:col-span-2">
                            <Textarea id="rq-reason" rows={2} required placeholder="Forgot to punch out" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}><Send /> Send to HR</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ContactForm({ me }: { me: Me }) {
    const form = useForm({
        phone: me.phone ?? '', alternate_phone: me.alternate_phone ?? '', personal_email: me.personal_email ?? '',
        current_address: me.current_address ?? '', city: me.city ?? '',
        emergency_contact_name: me.emergency_contact_name ?? '', emergency_contact_relation: me.emergency_contact_relation ?? '',
        emergency_contact_phone: me.emergency_contact_phone ?? '',
    });
    const text = (key: keyof typeof form.data, label: string, type = 'text') => (
        <Field label={label} htmlFor={`c-${key}`} error={form.errors[key]}>
            <Input id={`c-${key}`} type={type} value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} />
        </Field>
    );
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.put('/me/contact', { preserveScroll: true, onSuccess: () => form.setDefaults() }); }} className="grid gap-4 rounded-lg border p-4">
            <div className="grid gap-4 sm:grid-cols-2">
                {text('phone', 'Phone', 'tel')}
                {text('alternate_phone', 'Other phone', 'tel')}
                {text('personal_email', 'Personal email', 'email')}
                {text('city', 'City')}
                <Field label="Current address" htmlFor="c-current_address" error={form.errors.current_address} className="sm:col-span-2">
                    <Textarea id="c-current_address" rows={2} value={form.data.current_address} onChange={(e) => form.setData('current_address', e.target.value)} />
                </Field>
            </div>
            <p className="text-sm font-semibold">Emergency contact</p>
            <div className="grid gap-4 sm:grid-cols-3">
                {text('emergency_contact_name', 'Name')}
                {text('emergency_contact_relation', 'Relation')}
                {text('emergency_contact_phone', 'Phone', 'tel')}
            </div>
            <div><Button type="submit" disabled={!form.isDirty || form.processing}>Save my details</Button></div>
        </form>
    );
}

export default function MyPage(props: Props) {
    const me = props.employee;
    const [asking, setAsking] = useState<{ day: AttendanceDay | null; date: string } | null>(null);

    if (!me || !props.attendance || !props.leave || !props.requests || !props.documents || !props.rules || !props.today) {
        return (
            <>
                <Head title="My page" />
                <PageHeader title="My page" description="Your own record, attendance and leave." />
                <div className="p-4 md:p-6">
                    <EmptyState title="Your login isn't linked to an employee record yet.">Ask HR or an admin to link it under Settings, Users.</EmptyState>
                </div>
            </>
        );
    }
    const a = props.attendance;
    const today = props.today;
    const pending = props.requests.filter((r) => r.status === 'pending').length;
    const owed = props.documents.filter((d) => !d.done);
    const order = ['Present', 'Late', 'Half day', 'Missing punch', 'Absent', 'On leave', 'Holiday', 'Worked day off'];

    return (
        <>
            <Head title="My page" />
            <PageHeader
                title={me.full_name}
                description={[me.role_title, me.department, me.company].filter(Boolean).join(', ') || 'Your own record, attendance and leave.'}
                actions={<Button onClick={() => setAsking({ day: null, date: today })}><Send /> Ask HR to fix a day</Button>}
            />
            <Tabs defaultValue="attendance" className="px-4 py-6 md:px-6">
                <TabsList className="mb-4">
                    <TabsTrigger value="attendance">Attendance</TabsTrigger>
                    <TabsTrigger value="leave">Leave</TabsTrigger>
                    <TabsTrigger value="requests">Requests{pending ? ` (${pending})` : ''}</TabsTrigger>
                    <TabsTrigger value="details">My details</TabsTrigger>
                </TabsList>

                <TabsContent value="attendance">
                    <div className="grid max-w-4xl gap-4">
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <div className="text-sm">
                                <p><span className="text-muted-foreground">Work schedule:</span> {a.schedule ? <><span className="font-semibold">{a.schedule.name}</span> ({a.schedule.summary})</> : 'None'}</p>
                                {!a.linked && <p className="text-muted-foreground">Your machine number or HR.my ID isn't linked yet, so no punches show. HR links it.</p>}
                            </div>
                            <Input type="month" className="w-44" value={a.month} max={today.slice(0, 7)} aria-label="Month"
                                onChange={(ev) => ev.target.value && router.get('/me', { month: ev.target.value }, { only: ['attendance'], preserveState: true, preserveScroll: true, replace: true })} />
                        </div>
                        <div className="flex flex-wrap gap-2 text-sm">
                            {order.filter((s) => a.counts[s]).map((s) => (
                                <span key={s} className="rounded-md border px-2.5 py-1"><span className="text-muted-foreground">{s}</span> <span className="font-semibold tabular-nums">{a.counts[s]}</span></span>
                            ))}
                            {a.worked > 0 && <span className="rounded-md border px-2.5 py-1"><span className="text-muted-foreground">Worked</span> <span className="font-semibold tabular-nums">{duration(a.worked)}</span></span>}
                        </div>
                        {a.days.length === 0 ? <EmptyState title="No attendance this month." /> : (<>
                            {/* Phones: one line per day. */}
                            <ul className="divide-y rounded-lg border sm:hidden">
                                {a.days.map((d) => (
                                    <li key={d.date} className="grid gap-1 px-3 py-2.5 text-sm">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className={d.status === 'Day off' ? 'text-muted-foreground' : 'font-semibold'}>{formatDate(d.date)}</span>
                                            {askable(d) && <Button variant="ghost" size="sm" onClick={() => setAsking({ day: d, date: d.date })}>Ask to fix</Button>}
                                        </div>
                                        <DayStatus day={d} statuses={a.statuses} />
                                        {(d.in || d.out) && (
                                            <span className="text-xs text-muted-foreground">
                                                In <TimeCell time={d.in} source={d.in_source} />, out <TimeCell time={d.out} source={d.out_source} />{d.worked ? `, ${duration(d.worked)}` : ''}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            <div className="hidden overflow-x-auto rounded-lg border sm:block">
                                <table className="w-full min-w-[36rem] text-sm">
                                    <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                        <tr>
                                            <th className="px-3 py-2 font-semibold">Day</th><th className="px-3 py-2 font-semibold">In</th><th className="px-3 py-2 font-semibold">Out</th>
                                            <th className="px-3 py-2 font-semibold">Worked</th><th className="px-3 py-2 font-semibold">Status</th>
                                            <th className="px-3 py-2"><span className="sr-only">Ask HR</span></th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {a.days.map((d) => {
                                            return (
                                                <tr key={d.date} className={d.status === 'Day off' ? 'text-muted-foreground' : undefined}>
                                                    <td className="px-3 py-2 tabular-nums">{formatDate(d.date)}</td>
                                                    <td className="px-3 py-2"><TimeCell time={d.in} source={d.in_source} /></td>
                                                    <td className="px-3 py-2"><TimeCell time={d.out} source={d.out_source} /></td>
                                                    <td className="px-3 py-2 tabular-nums">{duration(d.worked)}</td>
                                                    <td className="px-3 py-2">
                                                        <DayStatus day={d} statuses={a.statuses} />
                                                        {d.note && <span className="mt-0.5 block text-xs text-muted-foreground">{d.note}</span>}
                                                    </td>
                                                    <td className="px-3 py-2 text-right">
                                                        {askable(d) && <Button variant="ghost" size="sm" onClick={() => setAsking({ day: d, date: d.date })}>Ask to fix</Button>}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </>)}
                    </div>
                </TabsContent>

                <TabsContent value="leave">
                    <div className="grid max-w-3xl gap-4">
                        <p className="text-sm text-muted-foreground">Approved leave in {props.leave.year}. Apply for leave and see your balance in HR.my; it shows here once HR records it.</p>
                        {Object.keys(props.leave.totals).length > 0 && (
                            <div className="flex flex-wrap gap-2 text-sm">
                                {Object.entries(props.leave.totals).map(([type, days]) => (
                                    <span key={type} className="rounded-md border px-2.5 py-1"><span className="text-muted-foreground">{type}</span> <span className="font-semibold tabular-nums">{days}</span></span>
                                ))}
                            </div>
                        )}
                        {props.leave.records.length === 0 ? <EmptyState title={`No leave recorded in ${props.leave.year}.`} /> : (
                            <ul className="divide-y rounded-lg border">
                                {props.leave.records.map((r) => (
                                    <li key={r.id} className="grid gap-0.5 px-3 py-2 text-sm sm:grid-cols-[10rem_1fr_auto]">
                                        <span className="font-semibold">{r.type}</span>
                                        <span className="tabular-nums">{leaveDates(r)}</span>
                                        <span className="text-muted-foreground tabular-nums">{r.days} {r.days === 1 ? 'day' : 'days'}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </TabsContent>

                <TabsContent value="requests">
                    <div className="grid max-w-3xl gap-4">
                        <p className="text-sm text-muted-foreground">Fixes you asked HR for. Once approved, the new times count in your attendance.</p>
                        {props.requests.length === 0 ? (
                            <EmptyState title="No requests yet.">Use "Ask to fix" next to a day on the Attendance tab.</EmptyState>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {props.requests.map((r) => (
                                    <li key={r.id} className="grid gap-2 px-3 py-3 text-sm sm:grid-cols-[1fr_auto] sm:items-start">
                                        <div>
                                            <p className="font-semibold">
                                                {formatDate(r.date)}
                                                <span className="font-normal text-muted-foreground">: in {r.check_in ?? 'as punched'}, out {r.check_out ?? 'as punched'}</span>
                                            </p>
                                            <p className="text-muted-foreground">{r.reason}</p>
                                            {r.decision_note && <p className="mt-1">HR: {r.decision_note}</p>}
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Sent {formatDateTime(r.created_at)}{r.decided_at && `, answered ${formatDateTime(r.decided_at)}${r.decided_by ? ` by ${r.decided_by}` : ''}`}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2 sm:justify-end">
                                            <StatusPill value={r.status_label} tone={requestTone[r.status]} />
                                            {r.status === 'pending' && (
                                                <Button variant="ghost" size="sm" onClick={() => router.delete(`/me/requests/${r.id}`, { preserveScroll: true })}>Cancel</Button>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </TabsContent>

                <TabsContent value="details">
                    <div className="grid max-w-5xl gap-8 lg:grid-cols-2">
                        <Section title="Job">
                            <dl className="grid gap-4 rounded-lg border p-4 sm:grid-cols-2">
                                <Detail label="Employee code">{me.employee_code}</Detail>
                                <Detail label="System ID">{me.system_id}</Detail>
                                <Detail label="Role">{me.role_title}</Detail>
                                <Detail label="Department and team">{[me.department, me.team].filter(Boolean).join(', ')}</Detail>
                                <Detail label="Manager">{me.manager}</Detail>
                                <Detail label="Employment type">{me.employment_type}</Detail>
                                <Detail label="Work mode">{[me.work_mode, me.work_location].filter(Boolean).join(', ')}</Detail>
                                <Detail label="Work email">{me.email}</Detail>
                                <Detail label="Start date">{formatDate(me.start_date)}</Detail>
                                <Detail label="Confirmation date">{formatDate(me.confirmation_date)}</Detail>
                            </dl>
                        </Section>
                        <Section title="Personal" description="Only you and HR see these. Ask HR to correct anything wrong.">
                            <dl className="grid gap-4 rounded-lg border p-4 sm:grid-cols-2">
                                <Detail label="CNIC">{me.cnic}</Detail>
                                <Detail label="CNIC expiry">{formatDate(me.cnic_expiry)}</Detail>
                                <Detail label="Date of birth">{formatDate(me.date_of_birth)}</Detail>
                                <Detail label="Father's name">{me.father_name}</Detail>
                                <Detail label="Blood group">{me.blood_group}</Detail>
                                <Detail label="Bank">{[me.bank_name, me.bank_account_last4 && `account ending ${me.bank_account_last4}`].filter(Boolean).join(', ')}</Detail>
                            </dl>
                        </Section>
                        <Section title="Contact details" description="Keep these up to date. Changes save straight to your record." className="lg:col-span-2">
                            <ContactForm me={me} />
                        </Section>
                        <Section title="Company property with you" className="lg:col-span-2" description="Hand these back to HR when you leave. Tell HR if anything here is wrong.">
                            <AssetList rows={props.assets ?? []} empty="Nothing from the asset register is with you." />
                        </Section>
                        <Section title="Documents HR has on file" className="lg:col-span-2"
                            description={owed.length ? `HR still needs ${owed.length} from you. Hand them to HR.` : 'HR has everything it needs from you.'}>
                            <ul className="grid gap-x-6 gap-y-1.5 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-3">
                                {props.documents.map((d) => (
                                    <li key={d.label} className="flex items-center gap-2 text-sm">
                                        {d.done ? <CheckCircle2 className="size-4 shrink-0 text-done" aria-label="On file" /> : <CircleDashed className="size-4 shrink-0 text-pending" aria-label="Still needed" />}
                                        <span className={d.done ? undefined : 'font-semibold'}>{d.label}</span>
                                        {!d.done && <span className="text-xs text-muted-foreground">{d.status ?? 'not received'}</span>}
                                    </li>
                                ))}
                            </ul>
                        </Section>
                    </div>
                </TabsContent>
            </Tabs>

            {asking && <RequestDialog day={asking.day} date={asking.date} onClose={() => setAsking(null)} today={today} daysBack={props.rules.daysBack} />}
        </>
    );
}
