import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, Plus, Search, SearchX, TableProperties, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Avatar } from '@/components/avatar';
import { EmptyState } from '@/components/empty-state';
import { FilterBar } from '@/components/filter-bar';
import { NativeSelect } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';
import { query } from '@/lib/utils';
import type { CompanyOption, Paginated, SharedProps } from '@/types';

type Row = {
    id: number; code: string; name: string; photo: string | null; company: string | null; department: string | null; team: string | null;
    role_title: string | null; employment_type: string | null; start_date: string | null; status: string; needs_review: boolean;
};
type Filters = {
    q?: string; company?: number; department?: number; status: string; review?: boolean; unassigned?: boolean; sort?: string;
    type?: string; mode?: string; manager?: number; started_from?: string; started_to?: string; login?: 'yes' | 'no'; linked?: 'yes' | 'no';
};
type Option = { value: number; label: string };
type Props = {
    employees: Paginated<Row>; filters: Filters; companies: CompanyOption[]; employmentTypes: string[]; workModes: string[];
    managers: Option[]; managerIds: number[]; schedules: Option[];
};

const bulkFields = [
    { value: 'department', label: 'Department' }, { value: 'manager', label: 'Manager' }, { value: 'employment_type', label: 'Employment type' },
    { value: 'work_mode', label: 'Work mode' }, { value: 'schedule', label: 'Work schedule' },
] as const;
type BulkField = (typeof bulkFields)[number]['value'];

/** Change one thing for everyone ticked (EmployeeBulkController). Each person gets a job history row. */
function BulkBar({ ids, props, onDone }: { ids: number[]; props: Props; onDone: () => void }) {
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
    const submit = (e: FormEvent) => {
        e.preventDefault();
        const label = bulkFields.find((f) => f.value === field)!.label.toLowerCase();
        if (!confirm(`Change the ${label} for ${ids.length === 1 ? '1 person' : `${ids.length} people`}?`)) return;
        setBusy(true);
        router.post('/employees/bulk', { ids, field, value: value === '' ? null : value, effective_date: date || null }, {
            preserveScroll: true, onSuccess: onDone, onFinish: () => setBusy(false),
            onError: (errs) => alert(Object.values(errs)[0] ?? 'Not saved.'),
        });
    };
    return (
        <form onSubmit={submit} className="sticky top-0 z-10 mb-3 flex flex-wrap items-end gap-2 rounded-lg border bg-info-soft px-3 py-2.5 text-sm">
            <span className="self-center font-semibold">{ids.length === 1 ? '1 person' : `${ids.length} people`} ticked. Change their</span>
            <NativeSelect aria-label="What to change" className="w-44" value={field} options={bulkFields.map((f) => ({ value: f.value, label: f.label }))}
                onChange={(e) => { setField(e.target.value as BulkField); setValue(''); }} />
            <span className="self-center">to</span>
            <NativeSelect aria-label="New value" className="w-60" value={value} placeholder={clearable ? undefined : 'Choose'} options={options}
                onChange={(e) => setValue(e.target.value)} />
            <label className="grid gap-0.5 text-xs text-muted-foreground">
                {field === 'schedule' ? 'From' : 'Effective'}
                <Input type="date" className="h-9 w-40" value={date} onChange={(e) => setDate(e.target.value)} aria-label={field === 'schedule' ? 'From' : 'Effective date'} />
            </label>
            <Button type="submit" disabled={busy || (!clearable && value === '')}>Apply</Button>
            <Button type="button" variant="ghost" onClick={onDone}><X /> Clear</Button>
        </form>
    );
}

const statusTone = { active: 'done', review: 'pending', offboarding: 'pending', exited: 'neutral' } as const;
const statusLabel: Record<string, string> = { active: 'Active', review: 'Review', offboarding: 'Offboarding', exited: 'Exited' };

export default function EmployeesIndex(props: Props) {
    const { employees, filters, companies } = props;
    const { auth } = usePage<SharedProps>().props;
    const [q, setQ] = useState(filters.q ?? '');
    const [picked, setPicked] = useState<number[]>([]);
    const pageIds = employees.data.map((e) => e.id);
    const allPicked = pageIds.length > 0 && pageIds.every((id) => picked.includes(id));
    const toggle = (id: number) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]));
    const exportUrl = `/export/employees${(() => { const { sort: _sort, ...rest } = filters; const qs = new URLSearchParams(query(rest)).toString(); return qs ? `?${qs}` : ''; })()}`;
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

    // A column of dashes is noise: Role shows once anyone on this page has one.
    const showRole = employees.data.some((x) => x.role_title);

    return (
        <>
            <Head title="Employees" />
            <PageHeader
                title="Employees"
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
                <FilterBar active={[filters.company, filters.department, filters.status !== 'current' ? filters.status : null, filters.review, filters.unassigned,
                    filters.type, filters.mode, filters.manager, filters.started_from || filters.started_to, filters.login, filters.linked].filter(Boolean).length} search={
                        <div className="relative w-full md:w-72">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name, code, ID or CNIC" className="pl-8" aria-label="Search employees" />
                        </div>
                }>
                    <NativeSelect aria-label="Company" className="w-40" value={filters.company ?? ''} placeholder="All companies"
                        onChange={(e) => apply({ company: e.target.value ? Number(e.target.value) : undefined, department: undefined })}
                        options={companies.map((c) => ({ value: c.id, label: c.name }))} />
                    <NativeSelect aria-label="Department" className="w-60" value={filters.department ?? ''} placeholder="All departments"
                        onChange={(e) => apply({ department: e.target.value ? Number(e.target.value) : undefined })}
                        options={departments.map((d) => ({ value: d.id, label: d.name }))} />
                    <NativeSelect aria-label="Status" className="w-60" value={filters.status}
                        onChange={(e) => apply({ status: e.target.value })}
                        options={[
                            { value: 'current', label: 'Current (active + leaving)' }, { value: 'active', label: 'Active' },
                            { value: 'review', label: 'Review (not confirmed yet)' },
                            { value: 'offboarding', label: 'Offboarding' }, { value: 'exited', label: 'Exited' }, { value: 'all', label: 'Everyone' },
                        ]} />
                    <NativeSelect aria-label="Employment type" className="w-36" value={filters.type ?? ''} placeholder="Any type"
                        onChange={(e) => apply({ type: e.target.value || undefined })} options={props.employmentTypes.map((t) => ({ value: t, label: t }))} />
                    <NativeSelect aria-label="Work mode" className="w-36" value={filters.mode ?? ''} placeholder="Any work mode"
                        onChange={(e) => apply({ mode: e.target.value || undefined })} options={props.workModes.map((t) => ({ value: t, label: t }))} />
                    {managerOptions.length > 0 && (
                        <NativeSelect aria-label="Manager" className="w-56" value={filters.manager ?? ''} placeholder="Any manager"
                            onChange={(e) => apply({ manager: e.target.value ? Number(e.target.value) : undefined })} options={managerOptions} />
                    )}
                    <NativeSelect aria-label="Login" className="w-36" value={filters.login ?? ''} placeholder="Login: any"
                        onChange={(e) => apply({ login: (e.target.value || undefined) as Filters['login'] })}
                        options={[{ value: 'yes', label: 'Has a login' }, { value: 'no', label: 'No login' }]} />
                    <NativeSelect aria-label="Attendance" className="w-44" value={filters.linked ?? ''} placeholder="Attendance: any"
                        onChange={(e) => apply({ linked: (e.target.value || undefined) as Filters['linked'] })}
                        options={[{ value: 'yes', label: 'Linked to attendance' }, { value: 'no', label: 'Not linked' }]} />
                    <span className="flex items-center gap-1.5 text-sm">
                        <span className="text-muted-foreground">Started</span>
                        <Input type="date" className="h-9 w-38" aria-label="Started from" value={filters.started_from ?? ''} onChange={(e) => apply({ started_from: e.target.value || undefined })} />
                        <span className="text-muted-foreground">to</span>
                        <Input type="date" className="h-9 w-38" aria-label="Started to" value={filters.started_to ?? ''} onChange={(e) => apply({ started_to: e.target.value || undefined })} />
                    </span>
                    <label className="flex items-center gap-2 px-1 text-sm">
                        <input type="checkbox" className="size-4 accent-cascade" checked={!!filters.review} onChange={(e) => apply({ review: e.target.checked || undefined })} />
                        Needs a check
                    </label>
                    <label className="flex items-center gap-2 px-1 text-sm">
                        <input type="checkbox" className="size-4 accent-cascade" checked={!!filters.unassigned} onChange={(e) => apply({ unassigned: e.target.checked || undefined })} />
                        No company yet
                    </label>
                </FilterBar>
            </PageHeader>

            <div className="px-4 md:px-6">
                {employees.data.length === 0 ? (
                    <div className="py-6"><EmptyState icon={SearchX} title="No employees match these filters.">Clear a filter or search for a different name.</EmptyState></div>
                ) : (
                    <>
                        {/* Phones: one card per person. */}
                        <ul className="-mx-4 divide-y border-y md:hidden">
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
                        {auth.can.edit && picked.length > 0 && <div className="hidden md:block"><BulkBar ids={picked} props={props} onDone={() => setPicked([])} /></div>}
                        <div className="hidden overflow-x-auto md:block">
                            <table className="w-full min-w-[860px] text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-muted-foreground">
                                        {auth.can.edit && (
                                            <th className="w-8 py-2.5 pr-2">
                                                <input type="checkbox" className="size-4 accent-cascade" aria-label="Tick everyone on this page" checked={allPicked}
                                                    onChange={() => setPicked((p) => (allPicked ? p.filter((id) => !pageIds.includes(id)) : [...new Set([...p, ...pageIds])]))} />
                                            </th>
                                        )}
                                        <th className="py-2.5 pr-3 font-semibold">
                                            <button className="hover:text-foreground" onClick={() => apply({ sort: 'name' })}>Name</button>
                                        </th>
                                        <th className="py-2.5 pr-3 font-semibold">
                                            <button className="hover:text-foreground" onClick={() => apply({ sort: 'code' })}>Code</button>
                                        </th>
                                        <th className="py-2.5 pr-3 font-semibold">
                                            <button className="hover:text-foreground" onClick={() => apply({ sort: 'department' })}>Department and team</button>
                                        </th>
                                        {showRole && <th className="py-2.5 pr-3 font-semibold">Role</th>}
                                        <th className="py-2.5 pr-3 font-semibold">
                                            <button className="hover:text-foreground" onClick={() => apply({ sort: 'start_date' })}>Start date</button>
                                        </th>
                                        <th className="py-2.5 font-semibold">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {employees.data.map((e) => (
                                        <tr key={e.id} className={picked.includes(e.id) ? 'bg-info-soft/60' : 'hover:bg-[#f7f9fc]'}>
                                            {auth.can.edit && (
                                                <td className="py-2 pr-2">
                                                    <input type="checkbox" className="size-4 accent-cascade" aria-label={`Tick ${e.name}`} checked={picked.includes(e.id)} onChange={() => toggle(e.id)} />
                                                </td>
                                            )}
                                            <td className="py-2 pr-3">
                                                <span className="flex items-center gap-2.5">
                                                    <Avatar name={e.name} photo={e.photo} />
                                                    <span>
                                                        <Link href={`/employees/${e.id}`} className="font-semibold hover:text-cascade hover:underline">{e.name}</Link>
                                                        {e.needs_review && <span className="ml-2 text-xs font-semibold text-pending">Check record</span>}
                                                    </span>
                                                </span>
                                            </td>
                                            <td className="py-2.5 pr-3 tabular-nums text-muted-foreground">{e.code}</td>
                                            <td className="py-2.5 pr-3">
                                                {e.department ?? <span className="text-muted-foreground">No department</span>}
                                                {e.team && <span className="block text-xs text-muted-foreground">{e.team}{e.company ? `, ${e.company}` : ''}</span>}
                                            </td>
                                            {showRole && <td className="py-2.5 pr-3">{e.role_title ?? <span className="text-muted-foreground">-</span>}</td>}
                                            <td className="py-2.5 pr-3 tabular-nums">{e.start_date ? formatDate(e.start_date) : <span className="text-muted-foreground">-</span>}</td>
                                            <td className="py-2.5"><StatusPill value={statusLabel[e.status] ?? e.status} tone={statusTone[e.status as keyof typeof statusTone] ?? 'neutral'} /></td>
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
