import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowUpRight, Cake, CalendarDays, FileChartColumn, Palmtree, PartyPopper, Upload, UserPlus } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { Avatar } from '@/components/avatar';
import { NativeSelect } from '@/components/native-select';
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
    attendance: { date: string; statuses: Record<string, number>; late: number; working_days: number; people: number; linked: number } | null;
    away: { id: number; name: string; photo: string | null; type: string; from: string; to: string; part: 'full' | 'first_half' | 'second_half' | 'short' }[];
    newPeople: { id: number; name: string; photo: string | null; role: string | null; start: string }[];
    celebrations: { id: number; name: string; date: string; kind: 'anniversary' | 'birthday'; years: number | null }[];
    holidays: { date: string; name: string; company: string | null }[];
    activity: ActivityItem[];
};

function Attention({ n, href, children, tone = 'pending' }: { n: number; href: string; children: ReactNode; tone?: 'pending' | 'missing' | 'neutral' }) {
    const color = n === 0 ? 'text-muted-foreground' : tone === 'missing' ? 'text-missing' : tone === 'neutral' ? 'text-ink' : 'text-pending';
    return (
        <li>
            <Link href={href} className="group flex items-baseline gap-3 rounded-md px-2 py-2 hover:bg-accent">
                <span className={cn('w-12 shrink-0 text-right text-2xl font-bold tabular-nums', color)}>{n}</span>
                <span className="flex-1 text-sm">{children}</span>
                <ArrowUpRight className="size-4 shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100" />
            </Link>
        </li>
    );
}

function PeopleList({ people, empty, today }: { people: Person[]; empty: string; today: string }) {
    if (!people.length) return <p className="px-2 text-sm text-muted-foreground">{empty}</p>;
    return (
        <ul className="divide-y rounded-md border">
            {people.slice(0, 8).map((p) => {
                const d = daysBetween(today, p.end);   // server date, so the label matches the list
                return (
                    <li key={p.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                        <Link href={`/employees/${p.id}`} className="min-w-0 truncate font-semibold hover:text-cascade hover:underline">
                            {p.name}
                        </Link>
                        <span className={cn('shrink-0 tabular-nums', d < 0 ? 'text-missing' : 'text-pending')}>
                            {d < 0 ? `ended ${plural(-d, 'day')} ago` : d === 0 ? 'ends today' : `ends in ${plural(d, 'day')}`}
                        </span>
                    </li>
                );
            })}
            {people.length > 8 && <li className="px-3 py-2 text-sm text-muted-foreground">and {people.length - 8} more</li>}
        </ul>
    );
}

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

const REMINDERS_SHOWN = 10;

function RemindersCard({ reminders }: { reminders: Reminder[] }) {
    const [showAll, setShowAll] = useState(false);
    const overdue = reminders.filter((r) => r.severity === 'overdue').length;
    const shown = showAll ? reminders : reminders.slice(0, REMINDERS_SHOWN);

    return (
        <Section
            title="Needs attention"
            description={reminders.length ? `${plural(reminders.length, 'thing')} to act on, ${overdue} overdue. Overdue first, then by date.` : 'Probation, check-ins, documents and offboarding that are due or overdue.'}
        >
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
                                    <span className="block sm:inline"><span className="hidden sm:inline">: </span>{r.message}</span>
                                </span>
                                <span className="col-start-3 row-start-1 text-xs text-muted-foreground tabular-nums sm:col-start-4">{formatDate(r.due_date)}</span>
                                <ArrowUpRight className="hidden size-4 text-muted-foreground opacity-0 group-hover:opacity-100 sm:block" />
                            </Link>
                        </li>
                    ))}
                    {reminders.length > REMINDERS_SHOWN && (
                        <li className="px-3 py-2">
                            <button type="button" onClick={() => setShowAll((v) => !v)} className="text-sm font-semibold text-cascade hover:underline">
                                {showAll ? 'Show fewer' : `Show all (${reminders.length})`}
                            </button>
                        </li>
                    )}
                </ul>
            )}
        </Section>
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

function SmallList<T>({ items, empty, render }: { items: T[]; empty: string; render: (item: T) => ReactNode }) {
    if (!items.length) return <p className="rounded-lg border px-3 py-3 text-sm text-muted-foreground">{empty}</p>;
    return <ul className="divide-y rounded-lg border">{items.map(render)}</ul>;
}

const relative = (today: string, date: string) => {
    const d = daysBetween(today, date);
    return d === 0 ? 'today' : d === 1 ? 'tomorrow' : d === -1 ? 'yesterday' : d > 0 ? `in ${plural(d, 'day')}` : `${plural(-d, 'day')} ago`;
};

/** Counts on the Home attendance strip; each opens the Attendance page filtered to that status. */
const attendanceTiles = [
    { key: 'Present', label: 'Present', tone: 'text-done' },
    { key: 'Late', label: 'Late', tone: 'text-pending' },
    { key: 'Absent', label: 'Absent', tone: 'text-missing' },
    { key: 'Missing punch', label: 'Missing punch', tone: 'text-pending' },
    { key: 'On leave', label: 'On leave', tone: 'text-ink' },
];

type Upcoming = { key: string; id: number | null; name: string; date: string; kind: 'anniversary' | 'birthday' | 'holiday'; years: number | null };

export default function Dashboard(props: Props) {
    const { auth } = usePage<SharedProps>().props;
    const { totals, onboarding: o, documents: d, attendance: att } = props;
    const legal = o.legalPending;
    const firstName = auth.user?.name.split(' ')[0];
    const upcoming: Upcoming[] = [
        ...props.celebrations.map((c) => ({ ...c, key: `${c.kind}-${c.id}-${c.date}` })),
        ...props.holidays.map((h) => ({ key: `holiday-${h.date}-${h.name}`, id: null, name: h.company ? `${h.name} (${h.company})` : h.name, date: h.date, kind: 'holiday' as const, years: null })),
    ].sort((x, y) => x.date.localeCompare(y.date)).slice(0, 10);
    const companyName = props.companies.find((c) => c.id === props.company)?.name;
    const inCompany = props.company ? `&company=${props.company}` : '';
    const count = (key: string) => !att ? 0 : key === 'Late' ? att.late : key === 'Present' ? (att.statuses.Present ?? 0) + (att.statuses['At work'] ?? 0) : att.statuses[key] ?? 0;

    return (
        <>
            <Head title="Home" />
            <PageHeader
                title={firstName ? `Hello, ${firstName}` : 'Home'}
                description={`${formatDate(props.today)}${companyName ? `, ${companyName}` : ''}`}
                help="What needs doing today. Company-wide numbers are on Overview, and the full lists are on Reports."
                actions={auth.can.edit && (
                    <>
                        <Button variant="outline" asChild><Link href="/employees/create"><UserPlus /> Add employee</Link></Button>
                        <Button variant="outline" asChild><Link href="/attendance/imports"><Upload /> Upload attendance</Link></Button>
                        <Button variant="outline" asChild><Link href="/leave"><Palmtree /> Record leave</Link></Button>
                        <Button variant="outline" asChild><Link href="/reports"><FileChartColumn /> Reports</Link></Button>
                    </>
                )}
            >
                {props.companies.length > 1 && (
                    <NativeSelect className="w-48" value={props.company ?? ''} placeholder="All companies" aria-label="Company"
                        options={props.companies.map((c) => ({ value: c.id, label: c.name }))}
                        onChange={(e) => router.get('/dashboard', e.target.value ? { company: e.target.value } : {}, { preserveScroll: true, replace: true })} />
                )}
            </PageHeader>

            <div className="grid gap-8 p-4 md:p-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <div className="grid content-start gap-8">
                    <RemindersCard reminders={props.reminders} />
                    <Section title="Recent changes" description="Every change is kept in the activity log.">
                        {props.activity.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No changes yet.</p>
                        ) : (
                            <ol className="divide-y rounded-lg border">
                                {props.activity.map((a) => (
                                    <li key={a.id} className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 px-3 py-2 text-sm">
                                        <span className="w-28 shrink-0 text-xs text-muted-foreground tabular-nums">{formatDateTime(a.when)}</span>
                                        <span className="font-semibold">{a.user}</span>
                                        <span className="min-w-0 flex-1">
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
                    </Section>
                </div>

                <div className="grid content-start gap-8">
                    <Section
                        title="Attendance"
                        description={att
                            ? `${att.date === props.today ? 'Today' : formatDate(att.date)}, the last day with punches loaded. ${att.linked} of ${att.people} people are linked.`
                            : 'No attendance files uploaded yet.'}
                        actions={att && <Link href={`/attendance?date=${att.date}${inCompany}`} className="text-sm font-semibold text-cascade hover:underline">Open the day</Link>}
                    >
                        {att && att.linked === 0 ? (
                            <p className="rounded-lg border px-3 py-3 text-sm text-muted-foreground">
                                Punches are loaded, but nobody is linked to a machine number or HR.my ID yet.{' '}
                                {auth.can.edit && <Link href="/attendance/links?tab=numbers" className="font-semibold text-cascade hover:underline">Link people</Link>}
                            </p>
                        ) : att ? (
                            <dl className="grid grid-cols-3 gap-2 text-center sm:grid-cols-6">
                                <Link href={`/employees${props.company ? `?company=${props.company}` : ''}`} className="rounded-md border bg-muted/40 px-1 py-2 hover:bg-accent">
                                    <dd className="text-lg font-bold">{totals.current}</dd>
                                    <dt className="text-xs text-muted-foreground">Total staff</dt>
                                </Link>
                                {attendanceTiles.map((t) => (
                                    <Link key={t.key} href={`/attendance?date=${att.date}&status=${encodeURIComponent(t.key)}${inCompany}`} className="rounded-md border px-1 py-2 hover:bg-accent">
                                        <dd className={cn('text-lg font-bold', count(t.key) ? t.tone : 'text-muted-foreground')}>{count(t.key)}</dd>
                                        <dt className="text-xs text-muted-foreground">{t.label}</dt>
                                    </Link>
                                ))}
                            </dl>
                        ) : auth.can.edit ? (
                            <p className="rounded-lg border px-3 py-3 text-sm">
                                <Link href="/attendance/imports" className="font-semibold text-cascade hover:underline">Upload a machine or HR.my file</Link> to see who came in.
                            </p>
                        ) : null}
                    </Section>

                    <Section title="Lists to check" description="Each line opens the list behind it.">
                        <ul className="grid gap-0.5 rounded-lg border p-2">
                            {auth.can.edit && (
                                <Attention n={totals.requests} href="/attendance/requests" tone="missing">
                                    attendance fixes asked for on My page are waiting for HR
                                </Attention>
                            )}
                            {auth.can.edit && totals.reviews > 0 && (
                                <Attention n={totals.reviews} href="/reviews">
                                    performance reviews are waiting for HR's part
                                </Attention>
                            )}
                            <Attention n={o.overdue.length} href="/onboarding?probation=overdue" tone="missing">
                                people are past their probation end date with the result still Pending
                            </Attention>
                            <Attention n={o.endingSoon.length} href="/onboarding?probation=ending">
                                people finish probation in the next 7 days
                            </Attention>
                            <Attention n={o.legalAnyPending} href="/onboarding?legal=pending">
                                people in onboarding are missing a signed NDA, NCA or policy acknowledgment
                                <span className="block text-xs text-muted-foreground">
                                    NDA {legal.nda_signed}, NCA {legal.nca_signed}, policy {legal.policy_acknowledgment}
                                </span>
                            </Attention>
                            <Attention n={d.population - d.compliant} href="/documents?status=Pending">
                                current employees have at least one document missing or pending
                            </Attention>
                            <Attention n={totals.needsReview} href="/employees?review=1" tone="neutral">
                                records were flagged during the import and need a quick check
                            </Attention>
                            <Attention n={totals.unassigned} href="/employees?unassigned=1" tone="neutral">
                                people aren't on the BCC or Emerge department lists yet
                            </Attention>
                            <Attention n={totals.offboarding} href="/offboarding" tone="neutral">
                                offboardings are open
                            </Attention>
                        </ul>
                    </Section>
                </div>

                <div className="grid gap-8 md:grid-cols-2 xl:col-span-2 xl:grid-cols-4">
                    <Section title="Probation ending" description="Result still Pending.">
                        <div className="grid gap-4">
                            <div>
                                <h3 className="mb-1.5 text-xs font-semibold text-missing">Past the end date</h3>
                                <PeopleList people={o.overdue} empty="Nobody is past their end date." today={props.today} />
                            </div>
                            <div>
                                <h3 className="mb-1.5 text-xs font-semibold text-pending">Next 7 days</h3>
                                <PeopleList people={o.endingSoon} empty="Nobody finishes probation this week." today={props.today} />
                            </div>
                        </div>
                    </Section>

                    <Section title="Away this week" description="Leave recorded from today to next week.">
                        <SmallList items={props.away} empty="Nobody has leave recorded this week." render={(l) => (
                            <li key={`${l.id}-${l.from}`} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                <span className="flex min-w-0 items-center gap-2.5">
                                    <Avatar name={l.name} photo={l.photo} className="size-7 text-[10px]" />
                                    <Link href={`/employees/${l.id}`} className="min-w-0 truncate font-semibold hover:text-cascade hover:underline">{l.name}</Link>
                                </span>
                                <span className="shrink-0 text-right text-xs text-muted-foreground">
                                    {l.type}{l.part !== 'full' && (l.part === 'short' ? ', short leave' : ', half day')}
                                    <span className="block tabular-nums">{l.from === l.to ? formatDate(l.from) : `${formatDate(l.from)} to ${formatDate(l.to)}`}</span>
                                </span>
                            </li>
                        )} />
                    </Section>

                    <Section title="New people" description="Started in the last 30 days or starting soon.">
                        <SmallList items={props.newPeople} empty="Nobody new in the last 30 days." render={(p) => (
                            <li key={p.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                <span className="flex min-w-0 items-center gap-2.5">
                                    <Avatar name={p.name} photo={p.photo} className="size-7 text-[10px]" />
                                    <span className="min-w-0">
                                        <Link href={`/employees/${p.id}`} className="block truncate font-semibold hover:text-cascade hover:underline">{p.name}</Link>
                                        {p.role && <span className="block truncate text-xs text-muted-foreground">{p.role}</span>}
                                    </span>
                                </span>
                                <span className={cn('shrink-0 text-xs tabular-nums', p.start > props.today ? 'text-info' : 'text-muted-foreground')}>
                                    {p.start > props.today ? 'starts ' : 'started '}{relative(props.today, p.start)}
                                </span>
                            </li>
                        )} />
                    </Section>

                    <Section title="Coming up" description={auth.can.edit ? 'Birthdays, work anniversaries and holidays.' : 'Work anniversaries and holidays.'}>
                        <SmallList items={upcoming} empty="Nothing in the next week." render={(c) => (
                            <li key={c.key} className="flex items-center gap-2.5 px-3 py-2 text-sm">
                                {c.kind === 'birthday' ? <Cake className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                                    : c.kind === 'anniversary' ? <PartyPopper className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                                        : <CalendarDays className="size-4 shrink-0 text-muted-foreground" aria-hidden />}
                                <span className="min-w-0 flex-1">
                                    {c.id ? <Link href={`/employees/${c.id}`} className="font-semibold hover:text-cascade hover:underline">{c.name}</Link>
                                        : <span className="font-semibold">{c.name}</span>}
                                    <span className="block text-xs text-muted-foreground">
                                        {c.kind === 'birthday' ? 'Birthday' : c.kind === 'anniversary' ? `${plural(c.years ?? 0, 'year')} with us` : 'Holiday'}
                                    </span>
                                </span>
                                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">{relative(props.today, c.date)}</span>
                            </li>
                        )} />
                    </Section>
                </div>

            </div>
        </>
    );
}
