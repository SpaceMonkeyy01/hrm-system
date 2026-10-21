import { Head, Link, router } from '@inertiajs/react';
import { Check, Download, Link2, Pencil, Search, Upload, UserPlus, Wand2, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Avatar } from '@/components/avatar';
import { EmptyState } from '@/components/empty-state';
import { FilterBar } from '@/components/filter-bar';
import { NativeSelect } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { AttendanceTabs } from '@/components/section-tabs';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatDate, plural } from '@/lib/format';

type Source = 'machine' | 'hrmy';
type Issue = 'no_machine' | 'no_hrmy' | 'nothing' | 'stale';
type Row = {
    id: number; code: string; name: string; company: string | null; company_id: number | null; work_mode: string | null; photo: string | null;
    machine: string | null; hrmy: string | null; device_id: number | null; device: string | null;
    last: { machine: string | null; hrmy: string | null; app: string | null }; latest: string | null; issues: Issue[];
};
type Unlinked = {
    source: Source; device_user_id: string; device_name: string | null; punches: number; last_seen: string; device_id: number | null;
    suggestion: number | null; candidates: number[]; confidence: 'match' | 'check' | 'several' | 'none';
};
type Person = { id: number; label: string; machine: string | null; hrmy: string | null };
type Device = { id: number; code: string; name: string };
type Props = { rows: Row[]; unlinked: Unlinked[]; people: Person[]; devices: Device[]; companies: { id: number; name: string }[]; staleDays: number };

const SOURCE_LABEL: Record<Source, string> = { machine: 'Attendance machine', hrmy: 'HR.my' };
const NOUN: Record<Source, string> = { machine: 'machine number', hrmy: 'HR.my ID' };
const confidence = {
    match: { label: 'Name matches', tone: 'done' },
    check: { label: 'Check this', tone: 'pending' },
    several: { label: 'Several people', tone: 'missing' },
    none: { label: 'No match', tone: 'neutral' },
} as const;
const opts = { preserveScroll: true, preserveState: true } as const;
const query = () => new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search);

function EditRow({ row, devices, onDone }: { row: Row; devices: Device[]; onDone: () => void }) {
    const [d, setD] = useState({ attendance_machine_id: row.machine ?? '', attendance_device_id: row.device_id ? String(row.device_id) : '', hr_portal_id: row.hrmy ?? '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const save = () => router.put(`/attendance/links/${row.id}`, {
        attendance_machine_id: d.attendance_machine_id || null, hr_portal_id: d.hr_portal_id || null,
        attendance_device_id: d.attendance_device_id ? Number(d.attendance_device_id) : null,
    }, { ...opts, onSuccess: onDone, onError: (e) => { setErrors(e); toast.error(Object.values(e)[0] ?? 'Not saved.'); } });
    const err = (f: string) => errors[f] && <p className="mt-1 text-xs text-missing">{errors[f]}</p>;
    return (
        <>
            <td className="px-3 py-2">
                <Input className="h-8 w-24 tabular-nums" value={d.attendance_machine_id} aria-label={`Machine number for ${row.name}`} autoFocus
                    onChange={(e) => setD({ ...d, attendance_machine_id: e.target.value })} onKeyDown={(e) => e.key === 'Enter' && save()} />
                {err('attendance_machine_id')}
            </td>
            <td className="px-3 py-2">
                <NativeSelect className="h-8 w-32" value={d.attendance_device_id} placeholder="From punches" aria-label={`Device for ${row.name}`}
                    options={devices.map((x) => ({ value: x.id, label: `${x.code} ${x.name}` }))} onChange={(e) => setD({ ...d, attendance_device_id: e.target.value })} />
            </td>
            <td className="px-3 py-2">
                <Input className="h-8 w-28" value={d.hr_portal_id} aria-label={`HR.my ID for ${row.name}`}
                    onChange={(e) => setD({ ...d, hr_portal_id: e.target.value })} onKeyDown={(e) => e.key === 'Enter' && save()} />
                {err('hr_portal_id')}
            </td>
            <td className="px-3 py-2 text-xs text-muted-foreground" colSpan={2}>Empty removes the link.</td>
            <td className="px-3 py-2 text-right whitespace-nowrap">
                <Button size="sm" onClick={save} aria-label="Save links"><Check /></Button>
                <Button size="sm" variant="ghost" onClick={onDone} aria-label="Cancel"><X /></Button>
            </td>
        </>
    );
}

function issuePills(r: Row, staleDays: number) {
    if (r.issues.includes('nothing')) return <StatusPill value="Nothing linked" tone="missing" />;
    return (
        <span className="flex flex-wrap gap-1">
            {r.issues.includes('stale') && <StatusPill value={r.latest ? `No punch in ${staleDays}+ days` : 'Linked, no punches'} tone="pending" />}
            {r.issues.includes('no_machine') && <StatusPill value="No machine no." tone="neutral" />}
            {r.issues.includes('no_hrmy') && <StatusPill value="No HR.my ID" tone="neutral" />}
            {r.issues.length === 0 && <StatusPill value="Linked" tone="done" />}
        </span>
    );
}

function PeopleTable({ rows, devices, companies, staleDays }: Pick<Props, 'rows' | 'devices' | 'companies' | 'staleDays'>) {
    const q0 = query();
    const [q, setQ] = useState('');
    const [company, setCompany] = useState('');
    const [device, setDevice] = useState(q0.get('device') ?? '');
    const [issue, setIssue] = useState(q0.get('issue') ?? '');
    const [editing, setEditing] = useState<number | null>(null);
    const shown = useMemo(() => {
        const needle = q.trim().toLowerCase();
        return rows.filter((r) => (!needle || [r.name, r.code, r.machine, r.hrmy].some((v) => v?.toLowerCase().includes(needle)))
            && (!company || String(r.company_id) === company)
            && (!device || (device === 'none' ? r.machine && !r.device_id : String(r.device_id) === device))
            && (!issue || (issue === 'ok' ? r.issues.length === 0 : r.issues.includes(issue as Issue))));
    }, [rows, q, company, device, issue]);

    return (
        <div className="grid gap-3">
            <FilterBar active={[company, device, issue].filter(Boolean).length} search={
                <div className="relative">
                    <Search className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                    <Input className="w-full pl-8 md:w-64" placeholder="Name, code, machine no. or HR.my ID" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search" />
                </div>
            }>
                <NativeSelect aria-label="Company" className="w-40" value={company} placeholder="All companies" onChange={(e) => setCompany(e.target.value)}
                    options={companies.map((c) => ({ value: c.id, label: c.name }))} />
                <NativeSelect aria-label="Device" className="w-48" value={device} placeholder="All devices" onChange={(e) => setDevice(e.target.value)}
                    options={[...devices.map((d) => ({ value: d.id, label: `${d.code} ${d.name}` })), { value: 'none', label: 'Machine no. but no device' }]} />
                <NativeSelect aria-label="Show" className="w-56" value={issue} placeholder="Everyone" onChange={(e) => setIssue(e.target.value)}
                    options={[
                        { value: 'ok', label: 'Machine and HR.my linked' }, { value: 'no_machine', label: 'No machine number' }, { value: 'no_hrmy', label: 'No HR.my ID' },
                        { value: 'nothing', label: 'Nothing linked' }, { value: 'stale', label: `No punches in ${staleDays} days` },
                    ]} />
            </FilterBar>
            <p className="text-sm text-muted-foreground">{plural(shown.length, 'person', 'people')}{shown.length !== rows.length ? ` of ${rows.length}` : ''}</p>
            {shown.length === 0 ? <EmptyState title="Nobody matches these filters." /> : (
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-[56rem] text-sm">
                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr>
                                <th className="px-3 py-2 font-semibold">Employee</th>
                                <th className="px-3 py-2 font-semibold">Machine no.</th>
                                <th className="px-3 py-2 font-semibold">Device</th>
                                <th className="px-3 py-2 font-semibold">HR.my ID</th>
                                <th className="px-3 py-2 font-semibold">Last punch</th>
                                <th className="px-3 py-2 font-semibold">Status</th>
                                <th className="px-3 py-2"><span className="sr-only">Edit</span></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {shown.map((r) => (
                                <tr key={r.id} className={editing === r.id ? 'bg-muted/30' : undefined}>
                                    <td className="px-3 py-2">
                                        <Link href={`/employees/${r.id}#time`} className="flex items-center gap-2 hover:text-cascade">
                                            <Avatar name={r.name} photo={r.photo} className="size-7 text-[0.65rem]" />
                                            <span><span className="font-semibold">{r.name}</span> <span className="block text-xs text-muted-foreground tabular-nums">{r.code}{r.company ? ` · ${r.company}` : ''}</span></span>
                                        </Link>
                                    </td>
                                    {editing === r.id ? <EditRow row={r} devices={devices} onDone={() => setEditing(null)} /> : (
                                        <>
                                            <td className="px-3 py-2 font-semibold tabular-nums">{r.machine ?? <span className="font-normal text-muted-foreground">-</span>}</td>
                                            <td className="px-3 py-2 tabular-nums">{r.device ?? <span className="text-muted-foreground">-</span>}</td>
                                            <td className="px-3 py-2 tabular-nums">{r.hrmy ?? <span className="text-muted-foreground">-</span>}</td>
                                            <td className="px-3 py-2 text-xs text-muted-foreground">
                                                {r.latest ? formatDate(r.latest) : '-'}
                                                {r.last.app && <span className="block">Clocks in on the app</span>}
                                            </td>
                                            <td className="px-3 py-2">{issuePills(r, staleDays)}</td>
                                            <td className="px-3 py-2 text-right">
                                                <Button size="sm" variant="ghost" onClick={() => setEditing(r.id)} aria-label={`Edit links for ${r.name}`}><Pencil /></Button>
                                            </td>
                                        </>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function NumbersTable({ source, unlinked, people }: { source: Source; unlinked: Unlinked[]; people: Person[] }) {
    const free = useMemo(() => people.filter((p) => !p[source]), [people, source]);
    const byId = useMemo(() => new Map(people.map((p) => [p.id, p.label])), [people]);
    const noun = NOUN[source];
    const [pick, setPick] = useState<Record<string, string>>(() =>
        Object.fromEntries(unlinked.map((u) => [u.device_user_id, u.confidence === 'match' && u.suggestion ? String(u.suggestion) : ''])));
    const chosen = Object.entries(pick).filter(([, v]) => v);
    const pickedTwice = new Set(chosen.map(([, v]) => v).filter((v, i, all) => all.indexOf(v) !== i));

    const save = () => router.post('/attendance/link', { source, links: chosen.map(([device_user_id, employee_id]) => ({ device_user_id, employee_id: Number(employee_id) })) },
        { preserveScroll: true, onError: (e) => toast.error(Object.values(e)[0] ?? 'Not saved.') });

    return (
        <div className="grid gap-3">
            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full min-w-[48rem] text-sm">
                    <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                        <tr>
                            <th className="px-3 py-2 font-semibold">{source === 'machine' ? 'Machine no.' : 'HR.my ID'}</th>
                            <th className="px-3 py-2 font-semibold">{source === 'machine' ? 'Name on the machine' : 'Name in HR.my'}</th>
                            <th className="px-3 py-2 font-semibold">Punches</th>
                            <th className="px-3 py-2 font-semibold">Suggestion</th>
                            <th className="px-3 py-2 font-semibold">Employee</th>
                            <th className="px-3 py-2"><span className="sr-only">New employee</span></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {unlinked.map((u) => (
                            <tr key={u.device_user_id}>
                                <td className="px-3 py-2 font-semibold tabular-nums">{u.device_user_id}</td>
                                <td className="px-3 py-2">{u.device_name ?? '-'}</td>
                                <td className="px-3 py-2 text-muted-foreground tabular-nums">{u.punches}, last {formatDate(u.last_seen)}</td>
                                <td className="px-3 py-2">
                                    <StatusPill value={confidence[u.confidence].label} tone={confidence[u.confidence].tone} />
                                    {u.candidates.length > 0 && <span className="mt-1 block text-xs text-muted-foreground">{u.candidates.map((c) => byId.get(c)).filter(Boolean).join(' or ')}</span>}
                                </td>
                                <td className="px-3 py-2">
                                    <NativeSelect className="w-72" value={pick[u.device_user_id] ?? ''} placeholder="Not linked" aria-label={`Employee for ${noun} ${u.device_user_id}`}
                                        aria-invalid={pickedTwice.has(pick[u.device_user_id])}
                                        options={free.map((p) => ({ value: p.id, label: p.id === u.suggestion ? `${p.label} (suggested)` : p.label }))}
                                        onChange={(e) => setPick((s) => ({ ...s, [u.device_user_id]: e.target.value }))} />
                                    {pickedTwice.has(pick[u.device_user_id]) && <p className="mt-1 text-xs text-missing">Picked for another {noun} too.</p>}
                                    {u.confidence === 'check' && u.suggestion && !pick[u.device_user_id] && (
                                        <button type="button" className="mt-1 text-xs text-cascade hover:underline" onClick={() => setPick((s) => ({ ...s, [u.device_user_id]: String(u.suggestion) }))}>
                                            Use {byId.get(u.suggestion) ?? 'the suggestion'}
                                        </button>
                                    )}
                                </td>
                                <td className="px-3 py-2 text-right">
                                    <Button variant="ghost" size="sm" asChild title="No employee record for this person? Add one and link this number.">
                                        <Link href={`/employees/create?${new URLSearchParams({ from: 'links', [source]: u.device_user_id, ...(u.device_name ? { name: u.device_name } : {}) })}`}>
                                            <UserPlus /> New employee
                                        </Link>
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="flex flex-wrap items-center gap-3">
                <Button onClick={save} disabled={chosen.length === 0 || pickedTwice.size > 0}><Link2 /> Link {plural(chosen.length, noun)}</Button>
                <span className="text-sm text-muted-foreground">Exact name matches are picked for you. Check the rest before linking.</span>
            </div>
        </div>
    );
}

export default function AttendanceLinks({ rows, unlinked, people, devices, companies, staleDays }: Props) {
    const q0 = query();
    const [tab, setTab] = useState(q0.get('tab') === 'numbers' ? 'numbers' : 'people');
    const deviceFilter = q0.get('device');
    const waiting = unlinked.filter((u) => !deviceFilter || u.source !== 'machine' || String(u.device_id) === deviceFilter);
    const exact = unlinked.filter((u) => u.confidence === 'match').length;
    const count = (i: Issue) => rows.filter((r) => r.issues.includes(i)).length;
    const tiles = [
        { label: 'Current staff', value: rows.length },
        { label: 'Machine and HR.my', value: rows.filter((r) => r.issues.length === 0).length },
        { label: 'No machine number', value: count('no_machine') },
        { label: 'No HR.my ID', value: count('no_hrmy') },
        { label: `No punches in ${staleDays} days`, value: count('stale') },
        { label: 'Numbers with no person', value: unlinked.length },
    ];
    const changeTab = (v: string) => { setTab(v); const u = new URL(window.location.href); u.searchParams.set('tab', v); history.replaceState(history.state, '', u); };

    return (
        <>
            <Head title="Attendance links" />
            <PageHeader title="Attendance links" tabs={<AttendanceTabs />}
                actions={<>
                    <Button variant="outline" asChild><a href="/imports/links/template"><Download /> Download Excel</a></Button>
                    <Button variant="outline" asChild><Link href="/imports/links"><Upload /> Upload Excel</Link></Button>
                </>}>
                <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {tiles.map((t) => (
                        <div key={t.label} className="rounded-lg border bg-card px-3 py-2">
                            <dt className="text-xs text-muted-foreground">{t.label}</dt>
                            <dd className="text-lg font-semibold tabular-nums">{t.value}</dd>
                        </div>
                    ))}
                </dl>
            </PageHeader>
            <Tabs value={tab} onValueChange={changeTab} className="px-4 pt-4 pb-10 md:px-6">
                <TabsList>
                    <TabsTrigger value="people">People</TabsTrigger>
                    <TabsTrigger value="numbers">Numbers with no person {unlinked.length > 0 && <StatusPill value={String(unlinked.length)} tone="pending" />}</TabsTrigger>
                </TabsList>
                <TabsContent value="people" className="pt-4">
                    <PeopleTable rows={rows} devices={devices} companies={companies} staleDays={staleDays} />
                </TabsContent>
                <TabsContent value="numbers" className="grid gap-8 pt-4">
                    {waiting.length === 0 ? <EmptyState title="Every number that punches belongs to someone." /> : (
                        <>
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/30 px-4 py-3 text-sm">
                                <p>These numbers punch but belong to nobody, so their punches show on nobody's attendance.{deviceFilter ? ' Showing one device; HR.my IDs are shown too.' : ''}</p>
                                {exact > 0 && (
                                    <Button size="sm" onClick={() => confirm(`Link ${plural(exact, 'exact name match', 'exact name matches')}?`) && router.post('/attendance/links/accept', {}, { preserveScroll: true })}>
                                        <Wand2 /> Link all {exact} exact matches
                                    </Button>
                                )}
                            </div>
                            {(['machine', 'hrmy'] as const).map((s) => {
                                const list = waiting.filter((u) => u.source === s);
                                return list.length > 0 && (
                                    <section key={s}>
                                        <h3 className="mb-2 font-semibold">{SOURCE_LABEL[s]} ({list.length})</h3>
                                        <NumbersTable key={list.map((u) => u.device_user_id).join()} source={s} unlinked={list} people={people} />
                                    </section>
                                );
                            })}
                        </>
                    )}
                </TabsContent>
            </Tabs>
        </>
    );
}
