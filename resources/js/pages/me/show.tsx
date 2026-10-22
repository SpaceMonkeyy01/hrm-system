import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, CircleDashed, ExternalLink, FileText, House, Mail, MoreHorizontal, Pencil, Plus, Send } from 'lucide-react';
import { type FormEvent, type ReactNode, useState } from 'react';
import { DayStatus, duration, SessionsLine, TimeCell } from '@/components/attendance';
import { AttendanceHeatmap } from '@/components/attendance-heatmap';
import type { AttendanceYear } from '@/components/attendance-heatmap';
import type { AttendanceDay } from '@/components/attendance';
import { AssetList } from '@/components/assets';
import type { AssetRow } from '@/components/assets';
import { ClockCard } from '@/components/clock-card';
import type { ClockState } from '@/components/clock-card';
import { EmptyState } from '@/components/empty-state';
import { MyFeedback } from '@/components/feedback';
import type { ConductRow, MeetingRow } from '@/components/feedback';
import { Detail, Field } from '@/components/field';
import { deductionText, leaveDates } from '@/components/leave';
import type { LeaveBalance, LeaveCounted, LeaveDeductions, LeaveRecordRow } from '@/components/leave';
import { MyLoans } from '@/components/loans';
import type { LoanRow } from '@/components/loans';
import { ME_SECTIONS, openSection, sectionFromHash, useHash } from '@/components/me-sections';
import { Card, LeaveBars, TodayCards } from '@/components/me-today';
import type { FixRequest } from '@/components/me-today';
import { MyMedicalRequests } from '@/components/medical';
import type { MedicalRow } from '@/components/medical';
import { LeaveApplyDialog, MyLeaveRequests } from '@/components/my-leave-requests';
import type { LeaveRequestRow, LeaveTypeOption } from '@/components/my-leave-requests';
import { MyReviews } from '@/components/my-reviews';
import type { MyReview } from '@/components/my-reviews';
import { MyWfhRequests, WfhDialog } from '@/components/my-wfh-requests';
import type { WfhRequestRow } from '@/components/my-wfh-requests';
import { PageHeader } from '@/components/page-header';
import { PhotoEditor } from '@/components/photo-editor';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { SharedProps, Tone } from '@/types';

type Me = {
    photo: string | null;
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
type Props = {
    employee: Me | null;
    documents?: { label: string; status: string | null; done: boolean }[];
    clock?: ClockState;
    attendance?: AttendanceMonth;
    attendanceYear?: AttendanceYear;
    leave?: { year: number; records: LeaveRecordRow[]; totals: Record<string, number>; balances: LeaveBalance[]; deductions: LeaveDeductions; counted: LeaveCounted[] };
    requests?: FixRequest[];
    assets?: AssetRow[];
    reviews?: MyReview[];
    scale?: Record<string, string>;
    rules?: { daysBack: number; editable: string[] };
    leaveRequests?: LeaveRequestRow[];
    wfhRequests?: WfhRequestRow[];
    loans?: LoanRow[];
    medicalRequests?: MedicalRow[];
    medicalKinds?: Record<string, string>;
    medicalPatients?: Record<string, string>;
    conduct?: ConductRow[];
    meetings?: MeetingRow[];
    leaveTypes?: LeaveTypeOption[];
    leaveParts?: Record<string, string>;
    uploads?: { enabled: boolean; max_mb: number };
    letters?: { id: number; reference: string; subject: string | null; issued_on: string }[];
    policies?: { id: number; doc_number: string; name: string; description: string | null; link_url: string | null; issued_on: string | null }[];
    holidays?: { date: string; name: string }[];
    hrEmail?: string | null;
    lastSync?: string | null;
    today?: string;
};

/** Days worth asking HR about: something is missing or off. */
const askable = (d: AttendanceDay) => ['Absent', 'Missing punch', 'Half day', 'Not linked', 'At work'].includes(d.status) || d.late > 0 || d.early > 0;

const requestTone: Record<FixRequest['status'], Tone> = { pending: 'pending', approved: 'done', rejected: 'missing', cancelled: 'neutral' };

/** "30:00" (next morning on a night shift) as it would be typed: 06:00. */
const clock = (t: string | null) => (t ? `${String(Number(t.slice(0, 2)) % 24).padStart(2, '0')}${t.slice(2)}` : '');

const greeting = () => {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
};
const longDate = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

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

/** Details HR keeps: only the ones on file, the rest named in one line, and a way to tell HR what's wrong. */
function FactList({ title, facts, hrEmail, name }: { title: string; facts: [string, ReactNode][]; hrEmail?: string | null; name: string }) {
    const shown = facts.filter(([, v]) => v !== null && v !== undefined && v !== '');
    const missing = facts.filter(([, v]) => v === null || v === undefined || v === '').map(([k]) => k);
    const mail = hrEmail ? `mailto:${hrEmail}?subject=${encodeURIComponent(`Please correct my ${title.toLowerCase()} details (${name})`)}` : null;
    return (
        <section className="grid content-start gap-3 rounded-lg border p-4">
            <h2 className="font-bold">{title}</h2>
            {shown.length > 0 && <dl className="grid gap-4 sm:grid-cols-2">{shown.map(([k, v]) => <Detail key={k} label={k}>{v}</Detail>)}</dl>}
            {missing.length > 0 && <p className="text-sm text-muted-foreground">Not on file: {missing.join(', ')}.</p>}
            {mail && (
                <a href={mail} className="inline-flex w-fit items-center gap-1.5 text-sm font-semibold text-cascade hover:underline"><Mail className="size-4" /> Something wrong? Ask HR</a>
            )}
        </section>
    );
}

/** Phones, staff logins: the four main sections and More, fixed at the bottom. */
function BottomBar({ current, todo }: { current: string; todo: number }) {
    const main = ME_SECTIONS.slice(0, 4);
    const more = ME_SECTIONS.slice(4);
    const inMore = more.some((s) => s.key === current);
    return (
        <nav aria-label="My page" className="fixed inset-x-0 bottom-0 z-30 grid h-16 grid-cols-5 border-t bg-background pb-[env(safe-area-inset-bottom)] md:hidden">
            {main.map((s) => (
                <button key={s.key} type="button" onClick={() => openSection(s.key)} aria-current={s.key === current ? 'page' : undefined}
                    className={cn('flex flex-col items-center justify-center gap-0.5 text-[11px]', s.key === current ? 'font-bold text-cascade' : 'text-muted-foreground')}>
                    <s.icon className="size-5" /> {s.short ?? s.title}
                </button>
            ))}
            <DropdownMenu>
                <DropdownMenuTrigger className={cn('relative flex flex-col items-center justify-center gap-0.5 text-[11px]', inMore ? 'font-bold text-cascade' : 'text-muted-foreground')}>
                    <MoreHorizontal className="size-5" /> More
                    {todo > 0 && <span className="absolute top-1.5 right-[calc(50%-1.25rem)] rounded-full bg-missing px-1.5 text-[10px] font-bold text-white">{todo}</span>}
                </DropdownMenuTrigger>
                <DropdownMenuContent side="top" align="end" className="w-60">
                    {more.map((s) => (
                        <DropdownMenuItem key={s.key} onSelect={() => openSection(s.key)} className="py-2.5">
                            <s.icon /> {s.title}
                            {s.key === 'reviews' && todo > 0 && <span className="ml-auto rounded-full bg-pending-soft px-1.5 text-xs text-pending">{todo}</span>}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
        </nav>
    );
}

export default function MyPage(props: Props) {
    const me = props.employee;
    const { auth } = usePage<SharedProps>().props;
    const staff = !auth.can.view;
    const section = sectionFromHash(useHash());
    const [asking, setAsking] = useState<{ day: AttendanceDay | null; date: string } | null>(null);
    const [applying, setApplying] = useState(false);
    const [wfh, setWfh] = useState(false);

    if (!me || !props.attendance || !props.leave || !props.requests || !props.documents || !props.rules || !props.today) {
        return (
            <>
                <Head title="My page" />
                <PageHeader title="My page" help="Your own record, attendance and leave." />
                <div className="p-4 md:p-6">
                    <EmptyState title="Your login isn't linked to an employee record yet.">Ask HR or an admin to link it under Settings, Users.</EmptyState>
                </div>
            </>
        );
    }
    const a = props.attendance;
    const today = props.today;
    const uploads = props.uploads ?? { enabled: false, max_mb: 10 };
    const owed = props.documents.filter((d) => !d.done);
    const toAck = [...(props.conduct ?? []), ...(props.meetings ?? [])].filter((x) => !x.acknowledged_at).length;
    const yourTurn = (props.reviews ?? []).filter((r) => r.canSelfReview || (r.status === 'completed' && !r.acknowledged_at)).length;
    const order = ['Present', 'Late', 'Half day', 'Missing punch', 'Absent', 'On leave', 'Holiday', 'Worked day off'];
    const firstName = me.full_name.split(' ')[0];
    const title = ME_SECTIONS.find((s) => s.key === section)?.title ?? 'Today';
    const missingContact = [
        !me.emergency_contact_name || !me.emergency_contact_phone ? 'emergency contact' : null,
        !me.phone ? 'phone number' : null,
    ].filter((x): x is string => x !== null);

    const actions = (
        <>
            <Button variant="outline" onClick={() => setWfh(true)}><House /> Work from home</Button>
            <Button variant="outline" onClick={() => setAsking({ day: null, date: today })}><Pencil /> Fix a day</Button>
            <Button onClick={() => setApplying(true)}><Plus /> Apply for leave</Button>
        </>
    );

    return (
        <>
            <Head title={section === 'today' ? 'My page' : title} />
            {section === 'today' ? (
                <PageHeader eyebrow={longDate(today)}
                    title={<span className="flex items-center gap-3"><PhotoEditor name={me.full_name} photo={me.photo} uploadUrl="/me/photo" className="size-10 text-sm" />{greeting()}, {firstName}</span>}
                    description={[[me.role_title, me.department].filter(Boolean).join(', '), me.manager && `Manager: ${me.manager}`].filter(Boolean).join(' · ') || me.company}
                    actions={<div className="hidden flex-wrap gap-2 sm:flex">{actions}</div>} />
            ) : (
                <PageHeader title={title} description={`${me.full_name} · ${me.employee_code}`} actions={<div className="hidden flex-wrap gap-2 sm:flex">{actions}</div>} />
            )}

            <div className={cn('grid gap-6 px-4 py-6 md:px-6', staff && 'pb-24 md:pb-6')}>
                {/* HR and admin with a record of their own: the sections as tabs (staff have them in the sidebar and bottom bar). */}
                {!staff && (
                    <nav aria-label="My page sections" className="-mt-2 flex gap-1 overflow-x-auto border-b [scrollbar-width:none]">
                        {ME_SECTIONS.map((s) => (
                            <button key={s.key} type="button" onClick={() => openSection(s.key)} aria-current={s.key === section ? 'page' : undefined}
                                className={cn('-mb-px border-b-2 border-transparent px-3 py-2 text-sm whitespace-nowrap text-muted-foreground hover:text-foreground',
                                    s.key === section && 'border-primary font-semibold text-foreground')}>
                                {s.title}{s.key === 'reviews' && toAck + yourTurn ? ` (${toAck + yourTurn})` : ''}
                            </button>
                        ))}
                    </nav>
                )}

                {section === 'today' && (<>
                    {props.clock?.enabled && <ClockCard clock={props.clock} today={today} lastSync={props.lastSync} />}
                    <div className="grid grid-cols-2 gap-2 sm:hidden">
                        <Button size="lg" onClick={() => setApplying(true)}>Apply for leave</Button>
                        <Button size="lg" variant="outline" onClick={() => setAsking({ day: null, date: today })}>Fix a day</Button>
                    </div>
                    <TodayCards today={today} startDate={me.start_date} dateOfBirth={me.date_of_birth}
                        balances={props.leave.balances} leaveRequests={props.leaveRequests ?? []} wfhRequests={props.wfhRequests ?? []} requests={props.requests}
                        loans={props.loans ?? []} medicalRequests={props.medicalRequests ?? []}
                        month={a.month === today.slice(0, 7) ? a : null} year={props.attendanceYear}
                        reviews={props.reviews ?? []} conduct={props.conduct ?? []} meetings={props.meetings ?? []} missingContact={missingContact}
                        holidays={props.holidays ?? []} assets={props.assets ?? []} owed={owed.map((d) => d.label)} />
                </>)}

                {section === 'attendance' && (<>
                    {props.attendanceYear && (
                        <Card title="Your attendance"><AttendanceHeatmap year={props.attendanceYear} /></Card>
                    )}
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div className="text-sm">
                            <p><span className="text-muted-foreground">Work schedule:</span> {a.schedule ? <><span className="font-semibold">{a.schedule.name}</span> ({a.schedule.summary})</> : 'None'}</p>
                            {!a.linked && <p className="text-muted-foreground">Your machine number or HR.my ID isn't linked yet, so machine punches don't show. HR links it. Clocking in here works straight away.</p>}
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
                        {/* Phones: one card per day. */}
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
                                            In <TimeCell time={d.in} source={d.in_source} where={d.in_where} />, out <TimeCell time={d.out} source={d.out_source} where={d.out_where} />{d.worked ? `, ${duration(d.worked)}` : ''}<SessionsLine day={d} />
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
                                    {a.days.map((d) => (
                                        <tr key={d.date} className={d.status === 'Day off' ? 'text-muted-foreground' : undefined}>
                                            <td className="px-3 py-2 tabular-nums">{formatDate(d.date)}</td>
                                            <td className="px-3 py-2"><TimeCell time={d.in} source={d.in_source} where={d.in_where} /></td>
                                            <td className="px-3 py-2"><TimeCell time={d.out} source={d.out_source} where={d.out_where} /></td>
                                            <td className="px-3 py-2 tabular-nums">{duration(d.worked)}<SessionsLine day={d} /></td>
                                            <td className="px-3 py-2">
                                                <DayStatus day={d} statuses={a.statuses} />
                                                {d.note && <span className="mt-0.5 block text-xs text-muted-foreground">{d.note}</span>}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {askable(d) && <Button variant="ghost" size="sm" onClick={() => setAsking({ day: d, date: d.date })}>Ask to fix</Button>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>)}
                    <div className="grid gap-8 lg:grid-cols-2">
                        <MyWfhRequests requests={props.wfhRequests ?? []} today={today} />
                        <section className="grid content-start gap-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="font-bold">Attendance fixes</h2>
                                <Button variant="outline" onClick={() => setAsking({ day: null, date: today })}><Pencil /> Fix a day</Button>
                            </div>
                            {props.requests.length === 0 ? (
                                <p className="text-sm text-muted-foreground">None yet. Use "Ask to fix" next to a day above.</p>
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
                        </section>
                    </div>
                </>)}

                {section === 'leave' && (
                    <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                        <div className="grid content-start gap-8">
                            <Card title={`Leave left in ${props.leave.year}`}>
                                <LeaveBars balances={props.leave.balances} waitingDays={(props.leaveRequests ?? []).filter((r) => r.status === 'pending').reduce((n, r) => n + r.days, 0)} />
                                {props.leave.deductions.days > 0 && (
                                    <p className="text-sm text-muted-foreground">Deducted {props.leave.deductions.days} {props.leave.deductions.days === 1 ? 'day' : 'days'} (Casual first, then Annual): {deductionText(props.leave.deductions)}. Ask HR to fix a day if one is wrong.</p>
                                )}
                                {props.leave.counted.length > 0 && (
                                    <p className="text-sm text-muted-foreground">Counted in HR's sheet before the app: {props.leave.counted.map((c) => `${c.type} ${c.days}`).join(', ')}.</p>
                                )}
                            </Card>
                            <MyLeaveRequests requests={props.leaveRequests ?? []} types={props.leaveTypes ?? []} parts={props.leaveParts ?? {}} uploads={uploads} today={today} />
                            <section className="grid gap-3">
                                <h2 className="font-bold">Approved leave in {props.leave.year}</h2>
                                {Object.keys(props.leave.totals).length > 0 && (
                                    <div className="flex flex-wrap gap-2 text-sm">
                                        {Object.entries(props.leave.totals).map(([type, days]) => (
                                            <span key={type} className="rounded-md border px-2.5 py-1"><span className="text-muted-foreground">{type}</span> <span className="font-semibold tabular-nums">{days}</span></span>
                                        ))}
                                    </div>
                                )}
                                {props.leave.records.length === 0 ? <p className="text-sm text-muted-foreground">No leave taken in {props.leave.year} yet. Approved leave shows here and comes off your balance.</p> : (
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
                            </section>
                        </div>
                        <Card title={`Public holidays ${props.leave.year}`}>
                            {(props.holidays ?? []).length === 0 ? <p className="text-sm text-muted-foreground">HR hasn't added this year's holidays yet.</p> : (
                                <ul className="divide-y text-sm">
                                    {props.holidays!.map((h) => (
                                        <li key={h.date} className={cn('flex justify-between gap-3 py-2', h.date < today && 'text-muted-foreground')}>
                                            <span>{h.name}</span>
                                            <span className="shrink-0 tabular-nums">{new Date(`${h.date}T00:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </div>
                )}

                {section === 'care' && (
                    <div className="grid max-w-3xl gap-8">
                        <p className="text-sm text-muted-foreground">Ask for a loan or medical help, and see everything you asked for with HR's answer. Only you and HR see this.</p>
                        <MyLoans loans={props.loans ?? []} />
                        <MyMedicalRequests requests={props.medicalRequests ?? []} kinds={props.medicalKinds ?? {}} patients={props.medicalPatients ?? {}} uploads={uploads} />
                    </div>
                )}

                {section === 'reviews' && (
                    <div className="grid gap-10">
                        <section className="grid gap-3">
                            <h2 className="text-lg font-bold">Performance reviews</h2>
                            <MyReviews reviews={props.reviews ?? []} scale={props.scale ?? {}} />
                        </section>
                        <section className="grid gap-3">
                            <h2 className="text-lg font-bold">Feedback and meeting notes</h2>
                            <MyFeedback conduct={props.conduct ?? []} meetings={props.meetings ?? []} />
                        </section>
                    </div>
                )}

                {section === 'letters' && (
                    <div className="grid max-w-3xl gap-6">
                        <section className="grid gap-3">
                            <h2 className="font-bold">Letters HR issued to you</h2>
                            {(props.letters ?? []).length === 0 ? <EmptyState icon={FileText} title="No letters yet.">Experience, verification and other letters show here once HR issues them.</EmptyState> : (
                                <ul className="divide-y rounded-lg border">
                                    {props.letters!.map((l) => (
                                        <li key={l.id} className="flex flex-wrap items-center gap-3 px-3 py-2 text-sm">
                                            <span className="flex-1 font-semibold">{l.subject ?? 'Letter'}</span>
                                            <span className="text-muted-foreground tabular-nums">{l.reference}, {formatDate(l.issued_on)}</span>
                                            <Button variant="outline" size="sm" asChild><a href={`/me/letters/${l.id}`}>Open</a></Button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                        <section className="grid gap-3">
                            <h2 className="font-bold">Company policies</h2>
                            {(props.policies ?? []).length === 0 ? <EmptyState icon={FileText} title="No policies published yet." /> : (
                                <ul className="divide-y rounded-lg border">
                                    {props.policies!.map((p) => (
                                        <li key={p.id} className="flex flex-wrap items-start gap-3 px-3 py-2 text-sm">
                                            <span className="flex-1">
                                                <span className="font-semibold">{p.name}</span> <span className="text-muted-foreground">{p.doc_number}</span>
                                                {p.description && <span className="block text-muted-foreground">{p.description}</span>}
                                            </span>
                                            {p.link_url ? (
                                                <Button variant="outline" size="sm" asChild><a href={p.link_url} target="_blank" rel="noreferrer"><ExternalLink /> Read</a></Button>
                                            ) : <span className="text-xs text-muted-foreground">Ask HR for a copy</span>}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </div>
                )}

                {section === 'details' && (
                    <div className="grid max-w-5xl gap-6 lg:grid-cols-2">
                        <FactList title="Job" name={me.full_name} hrEmail={props.hrEmail} facts={[
                            ['Employee code', me.employee_code], ['System ID', me.system_id], ['Role', me.role_title],
                            ['Department and team', [me.department, me.team].filter(Boolean).join(', ')], ['Manager', me.manager],
                            ['Employment type', me.employment_type], ['Work mode', [me.work_mode, me.work_location].filter(Boolean).join(', ')],
                            ['Work email', me.email], ['Start date', me.start_date && formatDate(me.start_date)], ['Confirmation date', me.confirmation_date && formatDate(me.confirmation_date)],
                        ]} />
                        <FactList title="Personal" name={me.full_name} hrEmail={props.hrEmail} facts={[
                            ['CNIC', me.cnic], ['CNIC expiry', me.cnic_expiry && formatDate(me.cnic_expiry)], ['Date of birth', me.date_of_birth && formatDate(me.date_of_birth)],
                            ['Father\'s name', me.father_name], ['Blood group', me.blood_group],
                            ['Bank', [me.bank_name, me.bank_account_last4 && `account ending ${me.bank_account_last4}`].filter(Boolean).join(', ')],
                        ]} />
                        <section className="grid gap-3 lg:col-span-2">
                            <div>
                                <h2 className="font-bold">Contact details</h2>
                                <p className="text-sm text-muted-foreground">Keep these up to date. Changes save straight to your record.</p>
                            </div>
                            <ContactForm me={me} />
                        </section>
                        <section className="grid gap-3 lg:col-span-2">
                            <div>
                                <h2 className="font-bold">Company property with you</h2>
                                <p className="text-sm text-muted-foreground">Hand these back to HR when you leave. Tell HR if anything here is wrong.</p>
                            </div>
                            <AssetList rows={props.assets ?? []} empty="Nothing from the asset register is with you." />
                        </section>
                        <section className="grid gap-3 lg:col-span-2">
                            <div>
                                <h2 className="font-bold">Documents HR has on file</h2>
                                <p className="text-sm text-muted-foreground">{owed.length ? `HR still needs ${owed.length} from you. Hand them to HR.` : 'HR has everything it needs from you.'}</p>
                            </div>
                            <ul className="grid gap-x-6 gap-y-1.5 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-3">
                                {props.documents.map((d) => (
                                    <li key={d.label} className="flex items-center gap-2 text-sm">
                                        {d.done ? <CheckCircle2 className="size-4 shrink-0 text-done" aria-label="On file" /> : <CircleDashed className="size-4 shrink-0 text-pending" aria-label="Still needed" />}
                                        <span className={d.done ? undefined : 'font-semibold'}>{d.label}</span>
                                        {!d.done && <span className="text-xs text-muted-foreground">{d.status ?? 'not received'}</span>}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </div>
                )}
            </div>

            {staff && <BottomBar current={section} todo={toAck + yourTurn} />}
            {asking && <RequestDialog day={asking.day} date={asking.date} onClose={() => setAsking(null)} today={today} daysBack={props.rules.daysBack} />}
            {applying && <LeaveApplyDialog types={props.leaveTypes ?? []} parts={props.leaveParts ?? {}} uploads={uploads} today={today} onClose={() => setApplying(false)} />}
            {wfh && <WfhDialog today={today} onClose={() => setWfh(false)} />}
        </>
    );
}
