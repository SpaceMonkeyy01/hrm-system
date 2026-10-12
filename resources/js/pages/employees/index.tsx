import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, Plus, Search, TableProperties } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
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
    id: number; code: string; name: string; company: string | null; department: string | null; team: string | null;
    role_title: string | null; employment_type: string | null; start_date: string | null; status: string; needs_review: boolean;
};
type Filters = { q?: string; company?: number; department?: number; status: string; review?: boolean; unassigned?: boolean; sort?: string };

const statusTone = { active: 'done', offboarding: 'pending', exited: 'neutral' } as const;
const statusLabel: Record<string, string> = { active: 'Active', offboarding: 'Offboarding', exited: 'Exited' };

export default function EmployeesIndex({ employees, filters, companies }: { employees: Paginated<Row>; filters: Filters; companies: CompanyOption[] }) {
    const { auth } = usePage<SharedProps>().props;
    const [q, setQ] = useState(filters.q ?? '');
    const apply = (patch: Partial<Filters>) =>
        router.get('/employees', query({ ...filters, ...patch }), { preserveState: true, preserveScroll: true, replace: true });

    useEffect(() => {
        if ((filters.q ?? '') === q) return;
        const t = setTimeout(() => apply({ q }), 300);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q]);

    const company = companies.find((c) => c.id === Number(filters.company));
    const departments = company ? company.departments : companies.flatMap((c) => c.departments.map((d) => ({ ...d, name: `${d.name} (${c.name})` })));

    return (
        <>
            <Head title="Employees" />
            <PageHeader
                title="Employees"
                description="Everyone in BCC and Emerge, with one unique code each and a permanent system ID that never changes."
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <a href="/export/employees"><Download /> Export CSV</a>
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
                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative w-full sm:w-72">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name, code, ID or CNIC" className="pl-8" aria-label="Search employees" />
                    </div>
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
                            { value: 'offboarding', label: 'Offboarding' }, { value: 'exited', label: 'Exited' }, { value: 'all', label: 'Everyone' },
                        ]} />
                    <label className="flex items-center gap-2 px-1 text-sm">
                        <input type="checkbox" className="size-4 accent-cascade" checked={!!filters.review} onChange={(e) => apply({ review: e.target.checked || undefined })} />
                        Needs a check
                    </label>
                    <label className="flex items-center gap-2 px-1 text-sm">
                        <input type="checkbox" className="size-4 accent-cascade" checked={!!filters.unassigned} onChange={(e) => apply({ unassigned: e.target.checked || undefined })} />
                        No company yet
                    </label>
                </div>
            </PageHeader>

            <div className="px-4 md:px-6">
                {employees.data.length === 0 ? (
                    <div className="py-6"><EmptyState title="No employees match these filters.">Clear a filter or search for a different name.</EmptyState></div>
                ) : (
                    <>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[860px] text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-muted-foreground">
                                        <th className="py-2.5 pr-3 font-semibold">
                                            <button className="hover:text-foreground" onClick={() => apply({ sort: 'name' })}>Name</button>
                                        </th>
                                        <th className="py-2.5 pr-3 font-semibold">
                                            <button className="hover:text-foreground" onClick={() => apply({ sort: 'code' })}>Code</button>
                                        </th>
                                        <th className="py-2.5 pr-3 font-semibold">
                                            <button className="hover:text-foreground" onClick={() => apply({ sort: 'department' })}>Department and team</button>
                                        </th>
                                        <th className="py-2.5 pr-3 font-semibold">Role</th>
                                        <th className="py-2.5 pr-3 font-semibold">
                                            <button className="hover:text-foreground" onClick={() => apply({ sort: 'start_date' })}>Start date</button>
                                        </th>
                                        <th className="py-2.5 font-semibold">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {employees.data.map((e) => (
                                        <tr key={e.id} className="hover:bg-[#f7f9fc]">
                                            <td className="py-2.5 pr-3">
                                                <Link href={`/employees/${e.id}`} className="font-semibold hover:text-cascade hover:underline">{e.name}</Link>
                                                {e.needs_review && <span className="ml-2 text-xs font-semibold text-pending">Check record</span>}
                                            </td>
                                            <td className="py-2.5 pr-3 tabular-nums text-muted-foreground">{e.code}</td>
                                            <td className="py-2.5 pr-3">
                                                {e.department ?? <span className="text-muted-foreground">No department</span>}
                                                {e.team && <span className="block text-xs text-muted-foreground">{e.team}{e.company ? `, ${e.company}` : ''}</span>}
                                            </td>
                                            <td className="py-2.5 pr-3">{e.role_title ?? <span className="text-muted-foreground">-</span>}</td>
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
