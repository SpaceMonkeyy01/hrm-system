import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, Plus, Search, SearchX, TableProperties } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Avatar } from '@/components/avatar';
import { EmptyState } from '@/components/empty-state';
import { ActiveFilters, FilterBar, type FilterChip } from '@/components/filter-bar';
import { NativeSelect } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';
import { query } from '@/lib/utils';
import type { CompanyOption, Paginated, SharedProps } from '@/types';

type Row = {
    id: number; code: string; name: string; photo: string | null; company: string | null; department: string | null; team: string | null;
    role_title: string | null; employment_type: string | null; start_date: string | null; status: string; needs_review: boolean;
    manager: { id: number; name: string } | null;
};
type Filters = {
    q?: string; company?: number; department?: number; status: string; review?: boolean; unassigned?: boolean; sort?: string;
    type?: string; mode?: string; manager?: number; started_from?: string; started_to?: string; login?: 'yes' | 'no'; linked?: 'yes' | 'no';
};
type Option = { value: number; label: string };
type Props = {
    employees: Paginated<Row>; filters: Filters; companies: CompanyOption[]; employmentTypes: string[]; workModes: string[];
    managers: Option[]; managerIds: number[]; schedules: Option[]; counts: { current: number; left: number }; statusTotal: number;
};

const bulkFields = [
    { value: 'department', label: 'Department' }, { value: 'manager', label: 'Manager' }, { value: 'employment_type', label: 'Employment type' },
    { value: 'work_mode', label: 'Work mode' }, { value: 'schedule', label: 'Work schedule' },
] as const;
type BulkField = (typeof bulkFields)[number]['value'];

/** Change one thing for everyone ticked (EmployeeBulkController). Each person gets a job history row. */
function BulkDialog({ ids, props, open, onOpenChange, onDone }: { ids: number[]; props: Props; open: boolean; onOpenChange: (o: boolean) => void; onDone: () => void }) {
    const [field, setField] = useState<BulkField>('department');
    const [value, setValue] = useState('');
    const [date, setDate] = useState('');
    const [busy, setBusy] = useState(false);
    const options: { value: string | number; label: string }[] = {
        department: props.companies.flatMap((c) => c.departments.map((d) => ({ value: d.id, label: props.companies.length > 1 ? `${d.name} (${c.name})` : d.name }))),
        manager: [{ value: '', label: 'No manager' }, ...props.managers],
        employment_type: props.employmentTypes.map((t) => ({ value: t, label: t })),
        work_mode: [{ value: '', label: 'Not set' }, ...props.workModes.map((t) => ({ value: t, label: t }))],
        schedule: props.schedules,
    }[field];
    const clearable = field === 'manager' || field === 'work_mode';
    const people = ids.length === 1 ? '1 person' : `${ids.length} people`;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        router.post('/employees/bulk', { ids, field, value: value === '' ? null : value, effective_date: date || null }, {
            preserveScroll: true, onSuccess: () => { onOpenChange(false); onDone(); }, onFinish: () => setBusy(false),
            onError: (errs) => alert(Object.values(errs)[0] ?? 'Not saved.'),
        });
    };
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Change details for {people}</DialogTitle>
                        <DialogDescription>Each person's job history gets a line for the change.</DialogDescription>
                    </DialogHeader>
                    <label className="grid gap-1.5 text-sm font-semibold">
                        What to change
                        <NativeSelect value={field} options={bulkFields.map((f) => ({ value: f.value, label: f.label }))}
                            onChange={(e) => { setField(e.target.value as BulkField); setValue(''); }} />
                    </label>
                    <label className="grid gap-1.5 text-sm font-semibold">
                        Change it to
                        <NativeSelect value={value} placeholder={clearable ? undefined : 'Choose'} options={options} onChange={(e) => setValue(e.target.value)} />
                    </label>
                    <label className="grid gap-1.5 text-sm font-semibold">
                        {field === 'schedule' ? 'From' : 'Effective date'}
                        <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                    </label>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" disabled={busy || (!clearable && value === '')}>Change for {people}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

const statusTone = { active: 'done', review: 'pending', offboarding: 'pending', exited: 'neutral' } as const;
const statusLabel: Record<string, string> = { active: 'Active', review: 'Review', offboarding: 'Offboarding', exited: 'Exited' };

export default function EmployeesIndex(props: Props) {
    const { employees, filters, companies } = props;
    const { auth } = usePage<SharedProps>().props;
    const [q, setQ] = useState(filters.q ?? '');
    const [picked, setPicked] = useState<number[]>([]);
    const [changing, setChanging] = useState(false);
    const pageIds = employees.data.map((e) => e.id);
    const allPicked = pageIds.length > 0 && pageIds.every((id) => picked.includes(id));
    const toggle = (id: number) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]));
    const exportUrl = `/export/employees${(() => { const { sort: _sort, ...rest } = filters; const qs = new URLSearchParams(query(rest)).toString(); return qs ? `?${qs}` : ''; })()}`;
    const exportPicked = `/export/employees?${picked.map((id) => `ids[]=${id}`).join('&')}`;
    const managerOptions = props.managers.filter((m) => props.managerIds.includes(m.value));
    const apply = (patch: Partial<Filters>) => {
        setPicked([]);   // ticks belong to the list as filtered
        router.get('/employees', query({ ...filters, ...patch }), { preserveState: true, preserveScroll: true, replace: true });
    };

    useEffect(() => {
        if ((filters.q ?? '') === q) return;
        const t = setTimeout(() => apply({ q }), 300);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q]);

    const company = companies.find((c) => c.id === Number(filters.company));
    const departments = company ? company.departments : companies.flatMap((c) => c.departments.map((d) => ({ ...d, name: `${d.name} (${c.name})` })));

    // A column of dashes is noise: Role and Manager show once anyone on this page has one.
    const showRole = employees.data.some((x) => x.role_title);
    const showManager = employees.data.some((x) => x.manager);

    // The filters behind "More filters", shown as removable chips when set.
    const chips: FilterChip[] = [
        filters.type && { key: 'type', label: `Type: ${filters.type}`, clear: () => apply({ type: undefined }) },
        filters.mode && { key: 'mode', label: `Work mode: ${filters.mode}`, clear: () => apply({ mode: undefined }) },
        filters.manager && { key: 'manager', label: `Manager: ${props.managers.find((m) => m.value === Number(filters.manager))?.label ?? 'someone'}`, clear: () => apply({ manager: undefined }) },
        filters.login && { key: 'login', label: filters.login === 'yes' ? 'Has a login' : 'No login', clear: () => apply({ login: undefined }) },
        filters.linked && { key: 'linked', label: filters.linked === 'yes' ? 'Linked to attendance' : 'Not linked to attendance', clear: () => apply({ linked: undefined }) },
        filters.started_from && { key: 'from', label: `Started from ${formatDate(filters.started_from)}`, clear: () => apply({ started_from: undefined }) },
        filters.started_to && { key: 'to', label: `Started by ${formatDate(filters.started_to)}`, clear: () => apply({ started_to: undefined }) },
        filters.review && { key: 'review', label: 'Needs a check', clear: () => apply({ review: undefined }) },
        filters.unassigned && { key: 'unassigned', label: 'No company yet', clear: () => apply({ unassigned: undefined }) },
    ].filter(Boolean) as FilterChip[];
    const visibleSet = [filters.company, filters.department, filters.status !== 'current' ? filters.status : null, filters.q].filter(Boolean).length;
    const clearAll = () => { setQ(''); apply({ q: undefined, company: undefined, department: undefined, status: 'current', type: undefined, mode: undefined, manager: undefined,
        login: undefined, linked: undefined, started_from: undefined, started_to: undefined, review: undefined, unassigned: undefined }); };
    const narrowed = employees.total !== props.statusTotal;

    return (
        <>
            <Head title="Employees" />
            <PageHeader
                title="Employees"
                description={`${props.counts.current} current, ${props.counts.left} left.`}
                help="Everyone in BCC and Emerge, with one unique code each and a permanent system ID that never changes."
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <a href={exportUrl}><Download /> Export CSV</a>
                        </Button>
                        {auth.can.edit && (
                            <>
                                <Button variant="outline" asChild>
                                    <Link href="/imports/details"><TableProperties /> Fill in details</Link>
                                </Button>
                                <Button asChild>
                                    <Link href="/employees/create"><Plus /> Add employee</Link>
                                </Button>
                            </>
                        )}
                    </>
                }
            >
                <FilterBar grow active={visibleSet + chips.length} moreActive={chips.length} search={
                    <div className="relative w-full">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name, code, HR.my ID or CNIC" className="pl-8" aria-label="Search employees" />
                    </div>
                } more={
                    <>
                        <NativeSelect aria-label="Employment type" value={filters.type ?? ''} placeholder="Any employment type"
                            onChange={(e) => apply({ type: e.target.value || undefined })} options={props.employmentTypes.map((t) => ({ value: t, label: t }))} />
                        <NativeSelect aria-label="Work mode" value={filters.mode ?? ''} placeholder="Any work mode"
                            onChange={(e) => apply({ mode: e.target.value || undefined })} options={props.workModes.map((t) => ({ value: t, label: t }))} />
                        {managerOptions.length > 0 && (
                            <NativeSelect aria-label="Manager" value={filters.manager ?? ''} placeholder="Any manager"
                                onChange={(e) => apply({ manager: e.target.value ? Number(e.target.value) : undefined })} options={managerOptions} />
                        )}
                        <NativeSelect aria-label="Login" value={filters.login ?? ''} placeholder="Login: any"
                            onChange={(e) => apply({ login: (e.target.value || undefined) as Filters['login'] })}
                            options={[{ value: 'yes', label: 'Has a login' }, { value: 'no', label: 'No login' }]} />
                        <NativeSelect aria-label="Attendance" value={filters.linked ?? ''} placeholder="Attendance: any"
                            onChange={(e) => apply({ linked: (e.target.value || undefined) as Filters['linked'] })}
                            options={[{ value: 'yes', label: 'Linked to attendance' }, { value: 'no', label: 'Not linked' }]} />
                        <span className="grid grid-cols-2 gap-2 text-sm">
                            <label className="grid gap-1 text-xs text-muted-foreground">Started from
                                <Input type="date" className="h-9" value={filters.started_from ?? ''} onChange={(e) => apply({ started_from: e.target.value || undefined })} />
                            </label>
                            <label className="grid gap-1 text-xs text-muted-foreground">to
                                <Input type="date" className="h-9" value={filters.started_to ?? ''} onChange={(e) => apply({ started_to: e.target.value || undefined })} />
                            </label>
                        </span>
                        <label className="flex items-center gap-2 px-1 text-sm">
                            <input type="checkbox" className="size-4 accent-cascade" checked={!!filters.review} onChange={(e) => apply({ review: e.target.checked || undefined })} />
                            Needs a check
                        </label>
                        <label className="flex items-center gap-2 px-1 text-sm">
                            <input type="checkbox" className="size-4 accent-cascade" checked={!!filters.unassigned} onChange={(e) => apply({ unassigned: e.target.checked || undefined })} />
                            No company yet
                        </label>
                    </>
                }>
                    {companies.length > 1 && (
                        <NativeSelect aria-label="Company" className="w-40" value={filters.company ?? ''} placeholder="All companies"
                            onChange={(e) => apply({ company: e.target.value ? Number(e.target.value) : undefined, department: undefined })}
                            options={companies.map((c) => ({ value: c.id, label: c.name }))} />
                    )}
                    <NativeSelect aria-label="Department" className="w-52" value={filters.department ?? ''} placeholder="All departments"
                        onChange={(e) => apply({ department: e.target.value ? Number(e.target.value) : undefined })}
                        options={departments.map((d) => ({ value: d.id, label: d.name }))} />
                    <NativeSelect aria-label="Status" className="w-36" value={filters.status}
                        onChange={(e) => apply({ status: e.target.value })}
                        options={[
                            { value: 'current', label: 'Current' }, { value: 'active', label: 'Active' }, { value: 'review', label: 'Review' },
                            { value: 'offboarding', label: 'Offboarding' }, { value: 'exited', label: 'Exited' }, { value: 'all', label: 'Everyone' },
                        ]} />
                </FilterBar>
                <ActiveFilters chips={chips} onClearAll={clearAll}
                    summary={narrowed ? `Showing ${employees.total} of ${props.statusTotal}` : undefined} />
            </PageHeader>

            <div className="px-4 py-4 md:px-6">
                {employees.data.length === 0 ? (
                    <div className="py-2"><EmptyState icon={SearchX} title="No employees match these filters.">Clear a filter or search for a different name.</EmptyState></div>
                ) : (
                    <>
                        {/* Phones: one card per person. */}
                        <ul className="-mx-4 -mt-4 divide-y border-b md:hidden">
                            {employees.data.map((e) => (
                                <li key={e.id}>
                                    <Link href={`/employees/${e.id}`} className="flex items-center gap-3 px-4 py-3 hover:bg-accent/50">
                                        <Avatar name={e.name} photo={e.photo} className="size-9" />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-semibold">{e.name}</span>
                                            <span className="block truncate text-xs text-muted-foreground">{[e.code, e.department].filter(Boolean).join(', ')}</span>
                                            {e.needs_review && <span className="text-xs font-semibold text-pending">Check record</span>}
                                        </span>
                                        {e.status !== 'active' && <StatusPill value={statusLabel[e.status] ?? e.status} tone={statusTone[e.status as keyof typeof statusTone] ?? 'neutral'} />}
                                    </Link>
                                </li>
                            ))}
                        </ul>

                        {auth.can.edit && picked.length > 0 && (
                            <div className="sticky top-0 z-10 mb-3 hidden flex-wrap items-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm text-primary-foreground md:flex">
                                <span className="mr-2 font-bold">{picked.length} selected</span>
                                <Button size="sm" variant="outline" className="border-white/50 bg-transparent text-white hover:bg-white/10 hover:text-white" onClick={() => setChanging(true)}>Change details</Button>
                                <Button size="sm" variant="outline" className="border-white/50 bg-transparent text-white hover:bg-white/10 hover:text-white" asChild>
                                    <a href={exportPicked}>Export selected</a>
                                </Button>
                                <button type="button" className="ml-auto font-semibold hover:underline" onClick={() => setPicked([])}>Clear</button>
                            </div>
                        )}
                        {auth.can.edit && <BulkDialog ids={picked} props={props} open={changing} onOpenChange={setChanging} onDone={() => setPicked([])} />}

                        <div className="hidden overflow-x-auto rounded-lg border md:block">
                            <table className="w-full min-w-[860px] text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                                        {auth.can.edit && (
                                            <th className="w-10 py-2.5 pl-3">
                                                <input type="checkbox" className="size-4 accent-cascade" aria-label="Tick everyone on this page" checked={allPicked}
                                                    onChange={() => setPicked((p) => (allPicked ? p.filter((id) => !pageIds.includes(id)) : [...new Set([...p, ...pageIds])]))} />
                                            </th>
                                        )}
                                        <th className="px-3 py-2.5 font-semibold">
                                            <button className="hover:text-foreground" onClick={() => apply({ sort: 'name' })}>Person</button>
                                        </th>
                                        <th className="px-3 py-2.5 font-semibold">
                                            <button className="hover:text-foreground" onClick={() => apply({ sort: 'department' })}>Department</button>
                                        </th>
                                        {showRole && <th className="px-3 py-2.5 font-semibold">Role</th>}
                                        {showManager && <th className="px-3 py-2.5 font-semibold">Manager</th>}
                                        <th className="px-3 py-2.5 font-semibold">
                                            <button className="hover:text-foreground" onClick={() => apply({ sort: 'start_date' })}>Started</button>
                                        </th>
                                        <th className="px-3 py-2.5 font-semibold">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {employees.data.map((e) => (
                                        <tr key={e.id} className={picked.includes(e.id) ? 'bg-info-soft/60' : 'hover:bg-[#f7f9fc]'}>
                                            {auth.can.edit && (
                                                <td className="py-2 pl-3">
                                                    <input type="checkbox" className="size-4 accent-cascade" aria-label={`Tick ${e.name}`} checked={picked.includes(e.id)} onChange={() => toggle(e.id)} />
                                                </td>
                                            )}
                                            <td className="px-3 py-2">
                                                <span className="flex items-center gap-2.5">
                                                    <Avatar name={e.name} photo={e.photo} />
                                                    <span className="min-w-0">
                                                        <Link href={`/employees/${e.id}`} className="block font-semibold hover:text-cascade hover:underline">{e.name}</Link>
                                                        <span className="text-xs text-muted-foreground tabular-nums">
                                                            {e.code}
                                                            {e.needs_review && <> · <span className="font-semibold text-pending">Check record</span></>}
                                                        </span>
                                                    </span>
                                                </span>
                                            </td>
                                            <td className="px-3 py-2">
                                                {e.department ?? <span className="text-muted-foreground">No department</span>}
                                                {e.team && <span className="block text-xs text-muted-foreground">{e.team}</span>}
                                            </td>
                                            {showRole && <td className="px-3 py-2">{e.role_title ?? <span className="text-muted-foreground">-</span>}</td>}
                                            {showManager && (
                                                <td className="px-3 py-2">
                                                    {e.manager ? <Link href={`/employees/${e.manager.id}`} className="hover:text-cascade hover:underline">{e.manager.name}</Link> : <span className="text-muted-foreground">-</span>}
                                                </td>
                                            )}
                                            <td className="px-3 py-2 tabular-nums">{e.start_date ? formatDate(e.start_date) : <span className="text-muted-foreground">-</span>}</td>
                                            <td className="px-3 py-2"><StatusPill value={statusLabel[e.status] ?? e.status} tone={statusTone[e.status as keyof typeof statusTone] ?? 'neutral'} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Pagination page={employees} />
                    </>
                )}
            </div>
        </>
    );
}
