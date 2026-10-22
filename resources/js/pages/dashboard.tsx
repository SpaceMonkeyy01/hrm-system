import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowUpRight, ChevronRight, Palmtree, Plus, Upload } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { CompanySwitch } from '@/components/company-switch';
import { PageHeader, Section } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { daysBetween, formatDate, formatDateTime, plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ActivityItem, Reminder, ReminderType, SharedProps } from '@/types';

type Person = { id: number; code: string; name: string; department: string | null; end: string };
type DocItem = { key: string; label: string; yes: number; na: number; pending: number; no: number; blank: number };

type Props = {
    today: string;
    company: number | null;
    companies: { id: number; name: string }[];
    reminders: Reminder[];
    totals: { current: number; unassigned: number; needsReview: number; inOnboarding: number; offboarding: number; requests: number; reviews: number };
    onboarding: {
        total: number;
        orientation: Record<'Not Started' | 'In Progress' | 'Complete', number>;
        status: Record<'Onboarding In Progress' | 'Onboarding Complete', number>;
        legalPending: Record<'policy_acknowledgment' | 'nda_signed' | 'nca_signed', number>;
        legalAnyPending: number;
        probation: Record<'Pending' | 'Pass' | 'Extend' | 'Fail' | 'Not tracked', number>;
        endingSoon: Person[];
        overdue: Person[];
    };
    documents: { population: number; compliant: number; items: DocItem[] };
    attendance: { date: string; came_in: number; statuses: Record<string, number>; late: number; working_days: number; people: number; linked: number } | null;
    away: { id: number; name: string; photo: string | null; type: string; from: string; to: string; part: 'full' | 'first_half' | 'second_half' | 'short' }[];
    newPeople: { id: number; name: string; photo: string | null; role: string | null; start: string }[];
    celebrations: { id: number; name: string; date: string; kind: 'anniversary' | 'birthday'; years: number | null }[];
    holidays: { date: string; name: string; company: string | null }[];
    activity: ActivityItem[];
    activityToday: number;
    requestsWaiting: { attendance: number; leave: number; wfh: number; loans: number; medical: number } | null;
};

/** Days either side of today that "This week" covers. */
const WEEK = 7;

const reminderLabel: Record<ReminderType, string> = {
    probation_overdue: 'Probation',
    probation_ending: 'Probation',
    checkin_missed: 'Check-in',
    checkin_due: 'Check-in',
    documents_pending: 'Documents',
    offboarding_due: 'Offboarding',
    cnic_expired: 'CNIC',
    cnic_expiring: 'CNIC',
};

/** Plural chip labels for the Needs attention filter. */
const chipLabel: Record<string, string> = { Probation: 'Probation', 'Check-in': 'Check-ins', Documents: 'Documents', Offboarding: 'Offboarding', CNIC: 'CNIC' };

const REMINDERS_SHOWN = 7;

const greeting = () => {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
};

const longDate = (iso: string) => {
    const [y, m, d] = iso.split('-').map(Number);
    return new Intl.DateTimeFormat('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(y, m - 1, d));
};

const relative = (today: string, date: string) => {
    const d = daysBetween(today, date);
    return d === 0 ? 'today' : d === 1 ? 'tomorrow' : d === -1 ? 'yesterday' : d > 0 ? `in ${plural(d, 'day')}` : `${plural(-d, 'day')} ago`;
};

/** One number card in the row at the top; the whole card opens the list behind it. */
function StatCard({ label, value, tone, href, children }: { label: string; value: ReactNode; tone?: 'missing' | 'pending'; href: string; children?: ReactNode }) {
    return (
        <Link href={href} className="group grid min-w-40 shrink-0 snap-start content-start gap-1 rounded-lg border bg-card p-4 hover:bg-accent sm:min-w-0">
            <span className="text-sm text-muted-foreground">{label}</span>
            <span className={cn('text-3xl font-bold tabular-nums', tone === 'missing' ? 'text-missing' : tone === 'pending' ? 'text-pending' : 'text-ink')}>{value}</span>
            {children && <span className="text-xs text-muted-foreground">{children}</span>}
        </Link>
    );
}

function RemindersCard({ reminders }: { reminders: Reminder[] }) {
    const [showAll, setShowAll] = useState(false);
    const [kind, setKind] = useState<string | null>(null);
    const kinds = Object.entries(reminders.reduce<Record<string, number>>((acc, r) => {
        const k = reminderLabel[r.type];
        acc[k] = (acc[k] ?? 0) + 1;
        return acc;
    }, {})).sort((a, b) => b[1] - a[1]);
    const filtered = kind ? reminders.filter((r) => reminderLabel[r.type] === kind) : reminders;
    const shown = showAll ? filtered : filtered.slice(0, REMINDERS_SHOWN);
    const chip = (label: string, n: number, value: string | null) => (
        <button key={label} type="button" aria-pressed={kind === value} onClick={() => { setKind(value); setShowAll(false); }}
            className={cn('shrink-0 rounded-full border px-3 py-1 text-sm font-semibold whitespace-nowrap',
                kind === value ? 'border-primary bg-primary text-primary-foreground' : 'bg-background hover:bg-accent')}>
            {label} {n}
        </button>
    );

    return (
        <section id="needs-attention" className="scroll-mt-4">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-base font-bold">Needs attention</h2>
                {kinds.length > 1 && (
                    <div className="flex gap-1.5 overflow-x-auto [scrollbar-width:none]" role="group" aria-label="Show one kind">
                        {chip('All', reminders.length, null)}
                        {kinds.map(([k, n]) => chip(chipLabel[k] ?? k, n, k))}
                    </div>
                )}
            </div>
            {reminders.length === 0 ? (
                <p className="rounded-lg border px-3 py-4 text-sm text-muted-foreground">Nothing needs attention today.</p>
            ) : (
                <ul className="divide-y rounded-lg border">
                    {shown.map((r) => (
                        <li key={`${r.type}-${r.employee_id}-${r.due_date}-${r.link}`}>
                            <Link href={r.link} className="group grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 px-3 py-2 text-sm hover:bg-accent sm:grid-cols-[5.5rem_5rem_minmax(0,1fr)_auto_1rem]">
                                <StatusPill value={r.severity === 'overdue' ? 'Overdue' : 'Due soon'} tone={r.severity === 'overdue' ? 'missing' : 'pending'} className="w-20" />
                                <span className="truncate text-xs font-semibold text-muted-foreground">{reminderLabel[r.type]}</span>
                                <span className="col-span-full min-w-0 sm:col-span-1">
                                    <span className="font-semibold group-hover:text-cascade group-hover:underline">{r.employee_name}</span>
                                    <span className="text-muted-foreground"> {r.employee_code}</span>
                                    <span className="block">{r.message}</span>
                                </span>
                                <span className="col-start-3 row-start-1 text-xs text-muted-foreground tabular-nums sm:col-start-4">{formatDate(r.due_date)}</span>
                                <ArrowUpRight className="hidden size-4 text-muted-foreground opacity-0 group-hover:opacity-100 sm:block" />
                            </Link>
                        </li>
                    ))}
                    {filtered.length > REMINDERS_SHOWN && (
                        <li className="px-3 py-2">
                            <button type="button" onClick={() => setShowAll((v) => !v)} className="text-sm font-semibold text-cascade hover:underline">
                                {showAll ? 'Show fewer' : `Show all ${filtered.length}`}
                            </button>
                        </li>
                    )}
                </ul>
            )}
        </section>
    );
}

function describe(a: ActivityItem): string {
    if (a.event === 'imported') return a.description ?? 'Imported data';
    const changes = a.changes && !Array.isArray(a.changes) ? Object.entries(a.changes) : [];
    const status = changes.find(([k]) => k === 'status');
    if (a.description && status && Array.isArray(status[1])) {
        const [from, to] = status[1] as [unknown, unknown];
        return `${a.description}: ${from ?? 'blank'} to ${to ?? 'blank'}`;
    }
    return `${a.description ?? 'Record'} ${a.event}`;
}

type WeekItem = { key: string; date: string; id: number | null; name: string; what: string; when: string };

export default function Dashboard(props: Props) {
    const { auth } = usePage<SharedProps>().props;
    const { totals, onboarding: o, documents: d, attendance: att, today } = props;
    const firstName = auth.user?.name.split(' ')[0];
    const overdue = props.reminders.filter((r) => r.severity === 'overdue').length;
    const owed = d.population - d.compliant;
    const inCompany = props.company ? `&company=${props.company}` : '';
    const waiting = props.requestsWaiting;
    const waitingTotal = waiting ? waiting.attendance + waiting.leave + waiting.wfh + waiting.loans + waiting.medical : 0;
    const waitingParts = waiting ? [
        waiting.leave && `${waiting.leave} leave`, waiting.wfh && `${waiting.wfh} WFH`,
        waiting.attendance && plural(waiting.attendance, 'attendance fix', 'attendance fixes'), waiting.loans && plural(waiting.loans, 'loan'),
        waiting.medical && `${waiting.medical} medical`,
    ].filter(Boolean).join(', ') : '';

    const within = (date: string) => Math.abs(daysBetween(today, date)) <= WEEK;
    const week: WeekItem[] = [
        ...props.newPeople.filter((p) => within(p.start)).map((p) => ({
            key: `start-${p.id}`, date: p.start, id: p.id, name: p.name, what: p.start > today ? 'starts work' : 'started work', when: relative(today, p.start),
        })),
        ...props.celebrations.map((c) => ({
            key: `${c.kind}-${c.id}-${c.date}`, date: c.date, id: c.id, name: c.name,
            what: c.kind === 'birthday' ? 'birthday' : `${plural(c.years ?? 0, 'year')} with us`, when: relative(today, c.date),
        })),
        ...props.holidays.filter((h) => within(h.date)).map((h) => ({
            key: `holiday-${h.date}-${h.name}`, date: h.date, id: null, name: h.company ? `${h.name} (${h.company})` : h.name, what: 'holiday', when: relative(today, h.date),
        })),
        ...props.away.map((l) => ({
            key: `away-${l.id}-${l.from}`, date: l.from < today ? today : l.from, id: l.id, name: l.name,
            what: `on ${l.type.toLowerCase()}${l.part === 'short' ? ', short leave' : l.part !== 'full' ? ', half day' : ''}`,
            when: l.from === l.to ? formatDate(l.from) : `${l.from <= today ? 'until' : formatDate(l.from) + ' to'} ${formatDate(l.to)}`,
        })),
    ].sort((x, y) => x.date.localeCompare(y.date));

    /** Each line opens the list behind it; text is [one, many]. Lines with 0 are hidden. */
    const lists = [
        { n: owed, href: '/documents?status=Pending', tone: 'pending', text: ['person has a document missing or pending', 'people have a document missing or pending'], show: true },
        { n: o.legalAnyPending, href: '/onboarding?legal=pending', tone: 'pending', text: ['in onboarding without a signed NDA, NCA or policy'], show: true },
        { n: o.overdue.length, href: '/onboarding?probation=overdue', tone: 'missing', text: ['past probation end with no decision'], show: true },
        { n: o.endingSoon.length, href: '/onboarding?probation=ending', tone: 'pending', text: ['finishes probation in the next 7 days', 'finish probation in the next 7 days'], show: true },
        { n: totals.reviews, href: '/reviews', tone: 'pending', text: ["performance review waiting for HR's part", "performance reviews waiting for HR's part"], show: auth.can.edit },
        { n: totals.needsReview, href: '/employees?review=1', tone: 'ink', text: ['record flagged at import', 'records flagged at import'], show: true },
        { n: totals.unassigned, href: '/employees?unassigned=1', tone: 'ink', text: ['person not on the BCC or Emerge department lists', 'people not on the BCC or Emerge department lists'], show: true },
        { n: totals.offboarding, href: '/offboarding', tone: 'ink', text: ['offboarding open', 'offboardings open'], show: true },
    ].filter((l) => l.show && l.n > 0);

    const switchCompany = (id: number | null) => router.get('/dashboard', { company: id ?? 'all' }, { preserveScroll: true, replace: true });

    return (
        <>
            <Head title="Home" />
            <PageHeader
                eyebrow={longDate(today)}
                title={firstName ? `${greeting()}, ${firstName}` : 'Home'}
                help="What needs doing today. Company-wide numbers are on Overview, and the full lists are on Reports."
                actions={
                    <>
                        <CompanySwitch companies={props.companies} value={props.company} onChange={switchCompany} />
                        {auth.can.edit && (
                            <>
                                <Button variant="outline" asChild><Link href="/attendance/imports"><Upload /> Upload attendance</Link></Button>
                                <Button variant="outline" asChild><Link href="/leave"><Palmtree /> Record leave</Link></Button>
                                <Button asChild><Link href="/employees/create"><Plus /> Add employee</Link></Button>
                            </>
                        )}
                    </>
                }
            />

            <div className="grid gap-8 p-4 md:p-6">
                {/* Phones: the cards scroll sideways; wider screens: one row. */}
                <div className={cn('-mx-4 flex snap-x scroll-px-4 gap-3 overflow-x-auto px-4 pb-1 [scrollbar-width:none] sm:mx-0 sm:grid sm:grid-cols-2 sm:overflow-visible sm:px-0 md:grid-cols-3',
                    waiting ? 'xl:grid-cols-5' : 'xl:grid-cols-4')}>
                    <StatCard label="Needs attention" value={props.reminders.length} tone={overdue ? 'missing' : props.reminders.length ? 'pending' : undefined} href="#needs-attention">
                        {props.reminders.length ? `${overdue} overdue` : 'Nothing due'}
                    </StatCard>
                    {waiting && (
                        <StatCard label="Requests waiting" value={waitingTotal} tone={waitingTotal ? 'pending' : undefined} href="/requests">
                            {waitingParts || 'Nothing waiting'}
                        </StatCard>
                    )}
                    <StatCard label={att && att.date !== today ? `Came in on ${formatDate(att.date)}` : 'At work today'}
                        value={att ? <>{att.came_in} <span className="text-base font-normal text-muted-foreground">of {att.people}</span></> : '-'}
                        href={att ? `/attendance?date=${att.date}${inCompany}` : '/attendance'}>
                        {att ? (
                            <span className="grid gap-1">
                                <span className="block h-1.5 overflow-hidden rounded-full bg-muted" aria-hidden>
                                    <span className="block h-full rounded-full bg-done" style={{ width: `${att.people ? Math.round((att.came_in / att.people) * 100) : 0}%` }} />
                                </span>
                                {att.people > att.linked && <span>{att.people - att.linked} not linked</span>}
                            </span>
                        ) : 'No punches loaded yet'}
                    </StatCard>
                    <StatCard label="Probation decisions" value={o.overdue.length} tone={o.overdue.length ? 'missing' : undefined} href="/onboarding?probation=overdue">
                        past end date, still Pending
                    </StatCard>
                    <StatCard label="Documents owed" value={owed} tone={owed ? 'pending' : undefined} href="/documents?status=Pending">
                        people with one or more
                    </StatCard>
                </div>

                <div className="grid gap-8 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                    <RemindersCard reminders={props.reminders} />

                    <div className="grid content-start gap-8">
                        <Section title="Lists to check">
                            {lists.length === 0 ? (
                                <p className="rounded-lg border px-3 py-3 text-sm text-muted-foreground">Nothing to check.</p>
                            ) : (
                                <ul className="divide-y rounded-lg border">
                                    {lists.map((l) => (
                                        <li key={l.href}>
                                            <Link href={l.href} className="group flex items-baseline gap-3 px-3 py-2.5 hover:bg-accent">
                                                <span className={cn('w-10 shrink-0 text-right text-xl font-bold tabular-nums', l.tone === 'missing' ? 'text-missing' : l.tone === 'pending' ? 'text-pending' : 'text-ink')}>{l.n}</span>
                                                <span className="flex-1 text-sm">{l.n === 1 ? l.text[0] : l.text[l.text.length - 1]}</span>
                                                <ArrowUpRight className="size-4 shrink-0 self-center text-muted-foreground opacity-0 group-hover:opacity-100" />
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <p className="mt-2 text-xs text-muted-foreground">Lists with nothing in them are hidden.</p>
                        </Section>

                        <Section title="This week">
                            <ul className="divide-y rounded-lg border">
                                {week.map((w) => (
                                    <li key={w.key} className="flex items-baseline justify-between gap-3 px-3 py-2 text-sm">
                                        <span className="min-w-0">
                                            {w.id ? <Link href={`/employees/${w.id}`} className="font-semibold hover:text-cascade hover:underline">{w.name}</Link>
                                                : <span className="font-semibold">{w.name}</span>}
                                            <span className="text-muted-foreground"> {w.what}</span>
                                        </span>
                                        <span className="shrink-0 text-xs text-muted-foreground tabular-nums">{w.when}</span>
                                    </li>
                                ))}
                                {props.away.length === 0 && <li className="px-3 py-2 text-sm text-muted-foreground">Nobody is on leave this week</li>}
                            </ul>
                        </Section>

                        <details className="group rounded-lg border">
                            <summary className="flex cursor-pointer list-none items-center gap-2 px-3 py-2.5 text-sm font-bold [&::-webkit-details-marker]:hidden">
                                <ChevronRight className="size-4 transition-transform group-open:rotate-90" aria-hidden />
                                Recent changes by people {props.activityToday > 0 && <span className="font-normal text-muted-foreground">({props.activityToday} today)</span>}
                            </summary>
                            {props.activity.length === 0 ? (
                                <p className="border-t px-3 py-3 text-sm text-muted-foreground">No changes yet.</p>
                            ) : (
                                <ol className="divide-y border-t">
                                    {props.activity.map((a) => (
                                        <li key={a.id} className="grid gap-0.5 px-3 py-2 text-sm">
                                            <span className="text-xs text-muted-foreground tabular-nums">{formatDateTime(a.when)}, {a.user}</span>
                                            <span>
                                                {describe(a)}
                                                {a.employee && (
                                                    <>
                                                        {' for '}
                                                        <Link href={`/employees/${a.employee.id}`} className="text-cascade hover:underline">{a.employee.name}</Link>
                                                    </>
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </details>
                    </div>
                </div>
            </div>
        </>
    );
}
