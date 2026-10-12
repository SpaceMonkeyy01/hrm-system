import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { PageHeader, Section } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import { daysBetween, formatDate, formatDateTime, percent, plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ActivityItem, Reminder, ReminderType } from '@/types';

type Person = { id: number; code: string; name: string; department: string | null; end: string };
type DocItem = { key: string; label: string; yes: number; na: number; pending: number; no: number; blank: number };

type Props = {
    today: string;
    reminders: Reminder[];
    totals: { current: number; unassigned: number; needsReview: number; inOnboarding: number; offboarding: number };
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
    headcount: { company: string; departments: { id: number; name: string; count: number }[] }[];
    activity: ActivityItem[];
};

const segment = {
    yes: 'bg-done',
    na: 'bg-[#9AA7B6]',
    pending: 'bg-[#D99A2B]',
    no: 'bg-missing',
    blank: 'bg-transparent',
} as const;

function Bar({ parts, total, label }: { parts: { key: keyof typeof segment; n: number }[]; total: number; label: string }) {
    return (
        <div className="flex h-3 w-full overflow-hidden rounded-sm border border-rule bg-[repeating-linear-gradient(135deg,#f3f5f8_0_4px,#e6eaf0_4px_6px)]" role="img" aria-label={label}>
            {parts.map((p) => (p.n > 0 ? <div key={p.key} className={segment[p.key]} style={{ width: `${(p.n / Math.max(total, 1)) * 100}%` }} /> : null))}
        </div>
    );
}

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
};

const REMINDERS_SHOWN = 10;

function RemindersCard({ reminders }: { reminders: Reminder[] }) {
    const [showAll, setShowAll] = useState(false);
    const overdue = reminders.filter((r) => r.severity === 'overdue').length;
    const shown = showAll ? reminders : reminders.slice(0, REMINDERS_SHOWN);

    return (
        <Section
            className="xl:col-span-2"
            title="Needs attention"
            description={reminders.length ? `${plural(reminders.length, 'thing')} to act on, ${overdue} overdue. Overdue first, then by date.` : 'Probation, check-ins, documents and offboarding that are due or overdue.'}
        >
            {reminders.length === 0 ? (
                <p className="rounded-lg border px-3 py-4 text-sm text-muted-foreground">Nothing needs attention today.</p>
            ) : (
                <ul className="divide-y rounded-lg border">
                    {shown.map((r) => (
                        <li key={`${r.type}-${r.employee_id}-${r.due_date}-${r.link}`}>
                            <Link href={r.link} className="group flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm hover:bg-accent">
                                <StatusPill value={r.severity === 'overdue' ? 'Overdue' : 'Due soon'} tone={r.severity === 'overdue' ? 'missing' : 'pending'} className="w-20" />
                                <span className="w-24 shrink-0 text-xs font-semibold text-muted-foreground">{reminderLabel[r.type]}</span>
                                <span className="min-w-0 flex-1">
                                    <span className="font-semibold group-hover:text-cascade group-hover:underline">{r.employee_name}</span>
                                    <span className="text-muted-foreground"> {r.employee_code}</span>
                                    <span className="block sm:inline"><span className="hidden sm:inline">: </span>{r.message}</span>
                                </span>
                                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">{formatDate(r.due_date)}</span>
                                <ArrowUpRight className="size-4 shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100" />
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

export default function Dashboard(props: Props) {
    const { totals, onboarding: o, documents: d } = props;
    const legal = o.legalPending;

    return (
        <>
            <Head title="Dashboard" />
            <PageHeader title="Dashboard" description={`Where onboarding, documents and probation stand on ${formatDate(props.today)}.`} />

            <div className="grid gap-8 p-4 md:p-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <RemindersCard reminders={props.reminders} />

                <Section title="Lists to check" description="Each line opens the list behind it.">
                    <ul className="grid gap-0.5 rounded-lg border p-2">
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

                <div className="grid content-start gap-6">
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
                </div>

                <Section
                    className="xl:col-span-2"
                    title="Documents on file"
                    description={`${d.compliant} of ${d.population} current employees (${percent(d.compliant, d.population)}) have every document. Each bar is one document across all ${d.population} people.`}
                    actions={
                        <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            <span className="flex items-center gap-1.5"><i className="size-2.5 rounded-sm bg-done" /> Yes</span>
                            <span className="flex items-center gap-1.5"><i className="size-2.5 rounded-sm bg-[#9AA7B6]" /> Not required / old employee / not available</span>
                            <span className="flex items-center gap-1.5"><i className="size-2.5 rounded-sm bg-[#D99A2B]" /> Pending</span>
                            <span className="flex items-center gap-1.5"><i className="size-2.5 rounded-sm bg-missing" /> No</span>
                            <span className="flex items-center gap-1.5"><i className="size-2.5 rounded-sm border border-rule bg-[#e6eaf0]" /> Not recorded</span>
                        </div>
                    }
                >
                    <div className="grid gap-x-10 gap-y-1 rounded-lg border p-3 lg:grid-cols-2">
                        {d.items.map((item) => {
                            const owed = item.no + item.pending + item.blank;
                            return (
                                <Link key={item.key} href={`/documents?missing=${item.key}`} className="group grid grid-cols-[10rem_1fr_5.5rem] items-center gap-3 rounded px-1.5 py-1.5 hover:bg-accent">
                                    <span className="truncate text-sm font-semibold">{item.label}</span>
                                    <Bar
                                        total={d.population}
                                        label={`${item.label}: ${item.yes} yes, ${item.na} not required, ${item.pending} pending, ${item.no} no, ${item.blank} not recorded`}
                                        parts={[{ key: 'yes', n: item.yes }, { key: 'na', n: item.na }, { key: 'pending', n: item.pending }, { key: 'no', n: item.no }]}
                                    />
                                    <span className={cn('text-right text-sm tabular-nums', owed ? 'text-ink' : 'text-done')}>
                                        {owed ? `${owed} owed` : 'All on file'}
                                    </span>
                                </Link>
                            );
                        })}
                    </div>
                </Section>

                <Section title="Onboarding" description={`${o.total} people in the onboarding process.`}>
                    <div className="grid gap-5 rounded-lg border p-4">
                        <div>
                            <div className="mb-1.5 flex justify-between text-sm">
                                <span className="font-semibold">Orientation (3 sessions)</span>
                                <span className="text-muted-foreground">{o.orientation.Complete} complete</span>
                            </div>
                            <Bar total={o.total} label="Orientation" parts={[{ key: 'yes', n: o.orientation.Complete }, { key: 'pending', n: o.orientation['In Progress'] }, { key: 'no', n: o.orientation['Not Started'] }]} />
                            <div className="mt-1.5 flex gap-4 text-xs text-muted-foreground">
                                <Link className="hover:underline" href="/onboarding?orientation=Complete">Complete {o.orientation.Complete}</Link>
                                <Link className="hover:underline" href="/onboarding?orientation=In+Progress">In progress {o.orientation['In Progress']}</Link>
                                <Link className="hover:underline" href="/onboarding?orientation=Not+Started">Not started {o.orientation['Not Started']}</Link>
                            </div>
                        </div>
                        <div>
                            <div className="mb-1.5 flex justify-between text-sm">
                                <span className="font-semibold">Onboarding status</span>
                                <span className="text-muted-foreground">{percent(o.status['Onboarding Complete'], o.total)} complete</span>
                            </div>
                            <Bar total={o.total} label="Onboarding status" parts={[{ key: 'yes', n: o.status['Onboarding Complete'] }, { key: 'pending', n: o.status['Onboarding In Progress'] }]} />
                            <div className="mt-1.5 flex gap-4 text-xs text-muted-foreground">
                                <Link className="hover:underline" href="/onboarding?status=complete">Complete {o.status['Onboarding Complete']}</Link>
                                <Link className="hover:underline" href="/onboarding?status=in_progress">In progress {o.status['Onboarding In Progress']}</Link>
                            </div>
                        </div>
                        <div>
                            <p className="mb-1.5 text-sm font-semibold">Probation result</p>
                            <dl className="grid grid-cols-5 gap-2 text-center">
                                {(['Pending', 'Pass', 'Extend', 'Fail', 'Not tracked'] as const).map((k) => (
                                    <Link key={k} href={`/onboarding?probation=${k === 'Not tracked' ? 'none' : k}`} className="rounded-md border px-1 py-2 hover:bg-accent">
                                        <dd className="text-lg font-bold tabular-nums">{o.probation[k]}</dd>
                                        <dt className="text-xs text-muted-foreground">{k}</dt>
                                    </Link>
                                ))}
                            </dl>
                        </div>
                    </div>
                </Section>

                <Section title="Headcount" description={`${totals.current} current employees.`}>
                    <div className="grid gap-4 rounded-lg border p-4 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                        {props.headcount.map((c) => (
                            <div key={c.company}>
                                <p className="mb-1 text-sm font-semibold">{c.company}</p>
                                <ul className="text-sm">
                                    {c.departments.filter((dep) => dep.count > 0).map((dep) => (
                                        <li key={dep.id} className="flex justify-between gap-2 py-0.5">
                                            <Link href={`/employees?department=${dep.id}`} className="truncate hover:text-cascade hover:underline">{dep.name}</Link>
                                            <span className="tabular-nums text-muted-foreground">{dep.count}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                </Section>

                <Section className="xl:col-span-2" title="Recent changes" description="Every change is kept in the activity log.">
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
        </>
    );
}
