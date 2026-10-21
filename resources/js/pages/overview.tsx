import { Head, Link, router } from '@inertiajs/react';
import { BarList, LineChart, PairedColumns, StatTile, StateBar } from '@/components/charts';
import { NativeSelect } from '@/components/native-select';
import { PageHeader, Section } from '@/components/page-header';
import { formatDate, percent, plural } from '@/lib/format';
import { cn } from '@/lib/utils';

type Month = { month: string; label: string; opening: number; joiners: number; leavers: number; closing: number; turnover: number | null };
type DocItem = { key: string; label: string; yes: number; na: number; pending: number; no: number; blank: number };
type Props = {
    today: string;
    filters: { company: number | null };
    companies: { id: number; name: string }[];
    tiles: { headcount: number; yearAgo: number; joined30: number; left30: number; turnover12: number | null; left12: number };
    monthly: Month[];
    departments: { id: number | null; name: string; company: string | null; count: number }[];
    attendance: { statuses: Record<string, number>; late: number; working_days: number; people: number; linked: number };
    onboarding: {
        total: number;
        orientation: Record<'Not Started' | 'In Progress' | 'Complete', number>;
        status: Record<'Onboarding In Progress' | 'Onboarding Complete', number>;
        probation: Record<'Pending' | 'Pass' | 'Extend' | 'Fail' | 'Not tracked', number>;
    };
    documents: { population: number; compliant: number; items: DocItem[] };
};

const short = (label: string) => label.slice(0, 3);

export default function Overview(props: Props) {
    const { tiles: t, attendance: a, documents: d, onboarding: o } = props;
    const change = t.headcount - t.yearAgo;
    const s = a.statuses;
    const present = (s['Present'] ?? 0) + (s['At work'] ?? 0);
    const absent = s['Absent'] ?? 0;
    const shownDepartments = props.departments.slice(0, 12);
    const rest = props.departments.slice(12).reduce((n, x) => n + x.count, 0);
    // Report links keep the company filter.
    const report = (key: string) => `/reports/${key}${props.filters.company ? `?company=${props.filters.company}` : ''}`;

    return (
        <>
            <Head title="Overview" />
            <PageHeader
                title="Overview"
                description={formatDate(props.today)}
                help="How the company looks: people, movement, documents, onboarding and this month's attendance."
                actions={props.companies.length > 1 && (
                    <NativeSelect className="w-44" aria-label="Company" value={props.filters.company ?? ''} placeholder="All companies"
                        options={props.companies.map((c) => ({ value: c.id, label: c.name }))}
                        onChange={(e) => router.get('/overview', e.target.value ? { company: e.target.value } : {}, { preserveState: true, preserveScroll: true, replace: true })} />
                )}
            />

            <div className="grid gap-8 p-4 md:p-6">
                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <StatTile label="Current employees" value={t.headcount.toLocaleString('en-GB')}
                        detail={`${change >= 0 ? '+' : ''}${change} since a year ago`} href={report('headcount')} />
                    <StatTile label="Joined, last 30 days" value={t.joined30} href={report('joiners-leavers')} />
                    <StatTile label="Left, last 30 days" value={t.left30} href={report('joiners-leavers')} />
                    <StatTile label="Turnover, 12 months" value={t.turnover12 === null ? 'n/a' : `${t.turnover12}%`} detail={`${plural(t.left12, 'leaver')}`} href={report('turnover')} />
                    <StatTile label="Every document on file" value={percent(d.compliant, d.population)} detail={`${d.compliant} of ${d.population} people`} href={report('missing-documents')} />
                    <StatTile label="On time this month" value={present ? percent(present - a.late, present) : 'n/a'}
                        detail={a.linked ? `${plural(a.late, 'late arrival')}, ${plural(absent, 'absent day')}` : 'Nobody linked to attendance yet'} href={report('attendance-monthly')} />
                </div>

                <div className="grid gap-8 xl:grid-cols-2">
                    <Section title="Headcount" help="People on the books at the end of each month."
                        actions={<Link href={report('turnover')} className="text-sm font-semibold text-cascade hover:underline">See as table</Link>}>
                        <div className="rounded-lg border p-4">
                            <LineChart label="Headcount by month" data={props.monthly.map((m) => ({ label: short(m.label), value: m.closing }))} />
                        </div>
                    </Section>
                    <Section title="Joined and left" help="People who started or left in each month."
                        actions={<Link href={report('joiners-leavers')} className="text-sm font-semibold text-cascade hover:underline">See who</Link>}>
                        <div className="rounded-lg border p-4">
                            <PairedColumns label="Joiners and leavers by month" series={['Joined', 'Left']}
                                data={props.monthly.map((m) => ({ label: short(m.label), values: [m.joiners, m.leavers] }))} />
                        </div>
                    </Section>
                </div>

                <div className="grid gap-8 xl:grid-cols-2">
                    <Section title="Departments" description={rest ? 'The largest 12 shown.' : undefined}
                    help="Current employees per department.">
                        <div className="rounded-lg border p-4">
                            <BarList items={[
                                ...shownDepartments.map((x) => ({
                                    key: x.id ?? 0, value: x.count, title: x.company ?? undefined,
                                    label: x.id ? <Link href={`/employees?department=${x.id}`} className="hover:text-cascade hover:underline">{x.name}</Link> : x.name,
                                })),
                                ...(rest ? [{ key: 'rest', label: <span className="text-muted-foreground">Other departments</span>, value: rest }] : []),
                            ]} />
                        </div>
                    </Section>

                    <Section title="Attendance this month" description={a.linked ? `Days so far for the ${a.linked} of ${a.people} people linked to the machine or HR.my.` : 'Nobody is linked to the machine or HR.my yet.'}
                        actions={<Link href={report('attendance-monthly')} className="text-sm font-semibold text-cascade hover:underline">Monthly report</Link>}>
                        <div className="grid gap-5 rounded-lg border p-4">
                            {a.linked ? (
                                <>
                                    <StateBar label="Attendance this month" parts={[
                                        { label: 'On time', value: present - a.late, className: 'bg-done' },
                                        { label: 'Late', value: a.late, className: 'bg-[#D99A2B]' },
                                        { label: 'Half day', value: s['Half day'] ?? 0, className: 'bg-[#e9c46a]' },
                                        { label: 'Missing punch', value: s['Missing punch'] ?? 0, className: 'bg-[#c98a5a]' },
                                        { label: 'Absent', value: absent, className: 'bg-missing' },
                                        { label: 'On leave', value: s['On leave'] ?? 0, className: 'bg-muted-tone' },
                                    ]} />
                                    <p className="text-sm text-muted-foreground">
                                        {a.working_days ? `${percent(absent, a.working_days)} of working days were absences.` : 'No working days yet this month.'}{' '}
                                        <Link href={report('absences')} className="font-semibold text-cascade hover:underline">See absences</Link>
                                    </p>
                                </>
                            ) : (
                                <p className="text-sm text-muted-foreground">Link machine numbers and HR.my IDs on <Link href="/attendance/links?tab=numbers" className="font-semibold text-cascade hover:underline">Attendance links</Link> to see this.</p>
                            )}
                        </div>
                    </Section>
                </div>

                <div className="grid gap-8 xl:grid-cols-[3fr_2fr]">
                    <Section title="Documents on file" description={`${d.compliant} of ${d.population} current employees (${percent(d.compliant, d.population)}) have every document.`}
                        actions={<Link href={report('missing-documents')} className="text-sm font-semibold text-cascade hover:underline">Who owes what</Link>}>
                        <div className="grid gap-x-8 gap-y-1 rounded-lg border p-3 md:grid-cols-2">
                            {d.items.map((item) => {
                                const owed = item.no + item.pending + item.blank;
                                const onFile = item.yes + item.na;
                                return (
                                    <Link key={item.key} href={`/documents?missing=${item.key}`} className="group grid grid-cols-[9rem_1fr_4.5rem] items-center gap-3 rounded px-1.5 py-1.5 hover:bg-accent">
                                        <span className="truncate text-sm">{item.label}</span>
                                        <span className="flex h-2.5 overflow-hidden rounded-[3px] bg-muted" title={`${onFile} on file or not needed, ${owed} owed`}>
                                            <span className="bg-done" style={{ width: `${(onFile / Math.max(d.population, 1)) * 100}%` }} />
                                        </span>
                                        <span className={cn('text-right text-xs tabular-nums', owed ? 'text-foreground' : 'text-done')}>{owed ? `${owed} owed` : 'All on file'}</span>
                                    </Link>
                                );
                            })}
                        </div>
                    </Section>

                    <Section title="Onboarding" description={`${o.total} people in the onboarding process.`}
                        actions={<Link href={report('onboarding-incomplete')} className="text-sm font-semibold text-cascade hover:underline">Unfinished</Link>}>
                        <div className="grid gap-5 rounded-lg border p-4">
                            <StateBar label="Orientation" parts={[
                                { label: 'Complete', value: o.orientation.Complete, className: 'bg-done' },
                                { label: 'In progress', value: o.orientation['In Progress'], className: 'bg-[#D99A2B]' },
                                { label: 'Not started', value: o.orientation['Not Started'], className: 'bg-missing' },
                            ]} />
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
                            <p className="text-sm text-muted-foreground">{percent(o.status['Onboarding Complete'], o.total)} have finished onboarding.</p>
                        </div>
                    </Section>
                </div>
            </div>
        </>
    );
}
