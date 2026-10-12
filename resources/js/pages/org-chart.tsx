import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { NativeSelect } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { plural } from '@/lib/format';
import { cn } from '@/lib/utils';

type Person = { id: number; name: string; code: string; role: string | null; department: string | null; leaving: boolean };
type Node = Person & { reports: Node[]; total: number };
type Department = { id: number | null; name: string; count: number; teams: { name: string | null; people: Person[] }[] };
type Props = {
    filters: { company: number | null };
    companies: { id: number; name: string }[];
    total: number;
    structure: { name: string; count: number; departments: Department[] }[];
    managers: { roots: Node[]; inTree: number; withoutManager: number };
};

function Chip({ p, hit }: { p: Person; hit: boolean }) {
    return (
        <li>
            <Link href={`/employees/${p.id}`} title={[p.code, p.role].filter(Boolean).join(', ')}
                className={cn('inline-flex rounded-md border px-2 py-1 text-xs hover:border-cascade/50 hover:bg-accent', hit && 'border-cascade bg-accent font-semibold')}>
                {p.name}{p.leaving && <span className="ml-1 text-pending">leaving</span>}
            </Link>
        </li>
    );
}

function DepartmentCard({ d, needle, forceOpen }: { d: Department; needle: string; forceOpen: boolean }) {
    const [open, setOpen] = useState(false);
    const shown = open || forceOpen;
    const matches = (p: Person) => !!needle && p.name.toLowerCase().includes(needle);
    return (
        <li className="rounded-lg border">
            <button type="button" aria-expanded={shown} onClick={() => setOpen(!open)} className="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left hover:bg-accent/50">
                <span className="flex items-center gap-2 font-semibold">
                    {shown ? <ChevronDown className="size-4 text-muted-foreground" /> : <ChevronRight className="size-4 text-muted-foreground" />}
                    {d.name}
                </span>
                <span className="text-xs text-muted-foreground tabular-nums">
                    {plural(d.count, 'person', 'people')}{d.teams.filter((t) => t.name).length ? `, ${plural(d.teams.filter((t) => t.name).length, 'team')}` : ''}
                </span>
            </button>
            {shown && (
                <div className="grid gap-3 border-t px-3 py-3">
                    {d.teams.map((t) => (
                        <div key={t.name ?? '-'}>
                            <p className="mb-1.5 text-xs font-semibold text-muted-foreground">{t.name ?? 'No team'} <span className="font-normal">({t.people.length})</span></p>
                            <ul className="flex flex-wrap gap-1.5">{t.people.map((p) => <Chip key={p.id} p={p} hit={matches(p)} />)}</ul>
                        </div>
                    ))}
                    {d.id && <Link href={`/employees?department=${d.id}`} className="text-xs font-semibold text-cascade hover:underline">Open as a list</Link>}
                </div>
            )}
        </li>
    );
}

function Tree({ node, depth = 0 }: { node: Node; depth?: number }) {
    const [open, setOpen] = useState(depth < 1);
    return (
        <li>
            <div className="flex items-center gap-2 py-1" style={{ paddingLeft: depth * 20 }}>
                {node.reports.length ? (
                    <button type="button" aria-label={open ? `Hide ${node.name}'s team` : `Show ${node.name}'s team`} aria-expanded={open} onClick={() => setOpen(!open)} className="text-muted-foreground hover:text-foreground">
                        {open ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                    </button>
                ) : <span className="w-4" />}
                <Link href={`/employees/${node.id}`} className="font-semibold hover:text-cascade hover:underline">{node.name}</Link>
                <span className="text-xs text-muted-foreground">{[node.role, node.department].filter(Boolean).join(', ')}</span>
                {node.total > 0 && <span className="text-xs text-muted-foreground tabular-nums">{plural(node.total, 'report')}</span>}
            </div>
            {open && node.reports.length > 0 && (
                <ul className="border-l border-rule" style={{ marginLeft: depth * 20 + 7 }}>
                    {node.reports.map((r) => <Tree key={r.id} node={r} depth={depth + 1} />)}
                </ul>
            )}
        </li>
    );
}

export default function OrgChart(props: Props) {
    const [q, setQ] = useState('');
    const needle = q.trim().toLowerCase();
    // Searching keeps only the departments with a match, opened, with the person highlighted.
    const structure = useMemo(() => !needle ? props.structure : props.structure
        .map((c) => ({ ...c, departments: c.departments.filter((d) => d.teams.some((t) => t.people.some((p) => p.name.toLowerCase().includes(needle)))) }))
        .filter((c) => c.departments.length), [props.structure, needle]);

    return (
        <>
            <Head title="Org chart" />
            <PageHeader
                title="Org chart"
                description={`${plural(props.total, 'current employee')}, by department and team, or by who reports to whom.`}
                actions={props.companies.length > 1 && (
                    <NativeSelect className="w-44" aria-label="Company" value={props.filters.company ?? ''} placeholder="All companies"
                        options={props.companies.map((c) => ({ value: c.id, label: c.name }))}
                        onChange={(e) => router.get('/org-chart', e.target.value ? { company: e.target.value } : {}, { preserveState: true, replace: true })} />
                )}
            />
            <Tabs defaultValue="structure" className="px-4 py-6 md:px-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <TabsList className="w-auto">
                        <TabsTrigger value="structure">By department</TabsTrigger>
                        <TabsTrigger value="managers">By manager</TabsTrigger>
                    </TabsList>
                    <Input className="w-64" placeholder="Find a person" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Find a person" />
                </div>

                <TabsContent value="structure">
                    {structure.length === 0 ? <EmptyState title={needle ? 'Nobody by that name.' : 'No current employees.'} /> : (
                        <div className="grid gap-8">
                            {structure.map((c) => (
                                <section key={c.name}>
                                    <h2 className="mb-2 font-bold">{c.name} <span className="text-sm font-normal text-muted-foreground">{plural(c.count, 'person', 'people')}</span></h2>
                                    <ul className="grid gap-2 lg:grid-cols-2">
                                        {c.departments.map((d) => <DepartmentCard key={`${c.name}-${d.name}`} d={d} needle={needle} forceOpen={!!needle} />)}
                                    </ul>
                                </section>
                            ))}
                        </div>
                    )}
                </TabsContent>

                <TabsContent value="managers">
                    {props.managers.roots.length === 0 ? (
                        <EmptyState title="Nobody has a manager set yet.">
                            Set Manager on each person's profile (Edit) and the reporting lines show here.
                        </EmptyState>
                    ) : (
                        <div className="grid gap-3">
                            <p className="text-sm text-muted-foreground">
                                {props.managers.inTree} people are in a reporting line. {props.managers.withoutManager > 0 && `${plural(props.managers.withoutManager, 'person', 'people')} have no manager set and nobody reporting to them.`}
                            </p>
                            <ul className="rounded-lg border p-3">
                                {props.managers.roots.filter((r) => !needle || JSON.stringify(r).toLowerCase().includes(needle)).map((r) => <Tree key={r.id} node={r} />)}
                            </ul>
                        </div>
                    )}
                </TabsContent>
            </Tabs>
        </>
    );
}
