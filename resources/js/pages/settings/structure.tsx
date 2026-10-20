import { Head, Link, router } from '@inertiajs/react';
import { Check, Combine, FolderInput, MoreHorizontal, Pencil, Plus, Search, Trash2, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { NativeSelect } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { plural } from '@/lib/format';
import { useSessionState } from '@/lib/use-session-state';

/** app/Http/Controllers/Settings/StructureController.php. employees = everyone pointing here; current = active or leaving. */
type Team = { id: number; name: string; employees: number; current: number };
type Department = { id: number; name: string; employees: number; current: number; head_id: number | null; teams: Team[] };
type Option = { value: number; label: string };
type Company = { id: number; name: string; code: string; prefix: string; hr_email: string | null; employees: number; current: number; departments: Department[] };
type Category = { id: number; name: string; employees: number };
type Props = { companies: Company[]; categories: Category[]; people: Record<number, Option[]> };

const opts = { preserveScroll: true, preserveState: true, onError: (e: Record<string, string>) => toast.error(Object.values(e)[0] ?? 'Not saved.') };

/** Click the name to rename it in place. field / empty: edit another text field (blank allowed, shown as `empty`). */
function Rename({ value, url, label, className, field = 'name', empty }: { value: string | null; url: string; label: string; className?: string; field?: string; empty?: string }) {
    const [editing, setEditing] = useState(false);
    const [name, setName] = useState(value ?? '');
    if (!editing) {
        return (
            <button type="button" className={`group inline-flex items-center gap-1.5 text-left hover:text-cascade ${className ?? 'font-semibold'}`}
                onClick={() => { setName(value ?? ''); setEditing(true); }} aria-label={field === 'name' ? `Rename ${label}` : `Change ${label}`}>
                {value || empty} <Pencil className="size-3 text-muted-foreground opacity-0 group-hover:opacity-100 group-focus-visible:opacity-100" />
            </button>
        );
    }
    const save = (e: FormEvent) => { e.preventDefault(); router.put(url, { [field]: name.trim() || null }, { ...opts, onSuccess: () => setEditing(false) }); };
    return (
        <form onSubmit={save} className="flex items-center gap-1">
            <Input value={name} onChange={(e) => setName(e.target.value)} className="h-8 w-64" autoFocus aria-label={field === 'name' ? `New name for ${label}` : label}
                onKeyDown={(e) => e.key === 'Escape' && setEditing(false)} />
            <Button size="sm" type="submit" aria-label="Save name"><Check /></Button>
            <Button size="sm" type="button" variant="ghost" onClick={() => setEditing(false)} aria-label="Cancel"><X /></Button>
        </form>
    );
}

/** A "+ Add" button that turns into a name box when clicked, so empty boxes don't fill every row. */
function AddInline({ label, placeholder, onAdd, compact }: { label: string; placeholder: string; onAdd: (name: string, done: () => void) => void; compact?: boolean }) {
    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    if (!open) {
        return (
            <Button type="button" size="sm" variant={compact ? 'ghost' : 'outline'} className={compact ? 'h-6 px-2 text-xs text-muted-foreground' : undefined} onClick={() => setOpen(true)}>
                <Plus /> {label}
            </Button>
        );
    }
    return (
        <form onSubmit={(e) => { e.preventDefault(); if (name.trim()) onAdd(name.trim(), () => { setName(''); setOpen(false); }); }} className="flex items-center gap-1">
            <Input value={name} onChange={(e) => setName(e.target.value)} placeholder={placeholder} className="h-8 w-48" aria-label={placeholder} autoFocus
                onKeyDown={(e) => e.key === 'Escape' && setOpen(false)} />
            <Button size="sm" type="submit" disabled={!name.trim()}>Add</Button>
            <Button size="sm" type="button" variant="ghost" onClick={() => setOpen(false)} aria-label="Cancel"><X /></Button>
        </form>
    );
}

/** Pick another department of the same company: merge a department into it, or move a team to it. */
function PickDepartment({ title, description, action, choices, onPick, onClose }: {
    title: string; description: string; action: string; choices: Department[]; onPick: (id: number) => void; onClose: () => void;
}) {
    const [id, setId] = useState('');
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={(e) => { e.preventDefault(); if (id) onPick(Number(id)); }} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                    </DialogHeader>
                    <NativeSelect aria-label="Department" value={id} placeholder="Choose a department" onChange={(e) => setId(e.target.value)}
                        options={choices.map((d) => ({ value: d.id, label: `${d.name} (${d.current})` }))} />
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={!id}>{action}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function TeamChip({ team, onMove }: { team: Team; onMove: () => void }) {
    const [renaming, setRenaming] = useState(false);
    if (renaming) {
        return (
            <span className="inline-flex"><RenameNow value={team.name} url={`/settings/teams/${team.id}`} label={team.name} onDone={() => setRenaming(false)} /></span>
        );
    }
    const former = team.employees - team.current;
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button type="button" className="inline-flex items-center gap-1 rounded-full border bg-muted/60 px-2.5 py-0.5 text-xs hover:border-cascade/50 hover:bg-accent"
                    aria-label={`Team ${team.name}, ${team.current} people. Options`}>
                    {team.name} <span className="text-muted-foreground tabular-nums">{team.current}</span>
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
                <DropdownMenuItem onSelect={() => setRenaming(true)}><Pencil /> Rename</DropdownMenuItem>
                <DropdownMenuItem onSelect={onMove}><FolderInput /> Move to another department</DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem disabled={team.employees > 0} className="text-missing"
                    onSelect={() => confirm(`Delete team ${team.name}?`) && router.delete(`/settings/teams/${team.id}`, opts)}>
                    <Trash2 /> {team.employees > 0 ? `Delete (move its ${team.current ? 'people' : `${former} former staff`} first)` : 'Delete'}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** Rename that starts open (from a menu). */
function RenameNow({ value, url, label, onDone }: { value: string; url: string; label: string; onDone: () => void }) {
    const [name, setName] = useState(value);
    return (
        <form onSubmit={(e) => { e.preventDefault(); router.put(url, { name }, { ...opts, onSuccess: onDone }); }} className="flex items-center gap-1">
            <Input value={name} onChange={(e) => setName(e.target.value)} className="h-7 w-40 text-xs" autoFocus aria-label={`New name for ${label}`}
                onKeyDown={(e) => e.key === 'Escape' && onDone()} />
            <Button size="sm" type="submit" className="h-7" aria-label="Save name"><Check /></Button>
            <Button size="sm" type="button" variant="ghost" className="h-7" onClick={onDone} aria-label="Cancel"><X /></Button>
        </form>
    );
}

function CompanyTab({ company, people }: { company: Company; people: Option[] }) {
    const [q, setQ] = useState('');
    const [sort, setSort] = useSessionState<'name' | 'size'>('structure:sort', 'name');
    const [onlyEmpty, setOnlyEmpty] = useState(false);
    const [merging, setMerging] = useState<Department | null>(null);
    const [moving, setMoving] = useState<{ team: Team; from: Department } | null>(null);
    const needle = q.trim().toLowerCase();
    const empty = company.departments.filter((d) => d.current === 0);
    const shown = useMemo(() => company.departments
        .filter((d) => !needle || d.name.toLowerCase().includes(needle) || d.teams.some((t) => t.name.toLowerCase().includes(needle)))
        .filter((d) => !onlyEmpty || d.current === 0)
        .sort((a, b) => (sort === 'size' ? b.current - a.current : 0) || a.name.localeCompare(b.name)), [company.departments, needle, sort, onlyEmpty]);

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative w-full sm:w-64">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input className="pl-8" placeholder="Find a department or team" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Find a department or team" />
                </div>
                <NativeSelect className="w-40" aria-label="Sort" value={sort} onChange={(e) => setSort(e.target.value as 'name' | 'size')}
                    options={[{ value: 'name', label: 'A to Z' }, { value: 'size', label: 'Biggest first' }]} />
                {empty.length > 0 && (
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" className="size-4 accent-cascade" checked={onlyEmpty} onChange={(e) => setOnlyEmpty(e.target.checked)} />
                        Only the {plural(empty.length, 'department')} with no current staff
                    </label>
                )}
                <div className="ml-auto">
                    <AddInline label="Add department" placeholder="New department name"
                        onAdd={(name, done) => router.post('/settings/departments', { company_id: company.id, name }, { ...opts, onSuccess: done })} />
                </div>
            </div>
            <p className="text-sm text-muted-foreground">
                {plural(company.current, 'current employee')} in {plural(company.departments.length, 'department')}. New employee codes look like {company.prefix}001.
                Click a name to rename it, and a team for its options.
            </p>
            <div className="flex flex-wrap items-center gap-x-2 text-sm">
                <span className="text-muted-foreground">Emails to {company.name} staff (login links) come from</span>
                <Rename value={company.hr_email} url={`/settings/companies/${company.id}/email`} label={`HR email for ${company.name}`}
                    field="hr_email" empty="the default address" className="font-medium" />
            </div>

            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full min-w-[56rem] text-sm">
                    <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                        <tr>
                            <th className="w-64 px-3 py-2 font-semibold">Department</th>
                            <th className="w-60 px-3 py-2 font-semibold">Head</th>
                            <th className="w-24 px-3 py-2 font-semibold">People</th>
                            <th className="px-3 py-2 font-semibold">Teams</th>
                            <th className="w-12 px-3 py-2"><span className="sr-only">Options</span></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {shown.length === 0 && <tr><td colSpan={5} className="px-3 py-6 text-center text-muted-foreground">No department matches.</td></tr>}
                        {shown.map((d) => {
                            const former = d.employees - d.current;
                            return (
                                <tr key={d.id} className="align-top hover:bg-accent/30">
                                    <td className="px-3 py-2.5">
                                        <Rename value={d.name} url={`/settings/departments/${d.id}`} label={d.name} />
                                        {d.current === 0 && <StatusPill className="mt-1" value="No current staff" tone="pending" />}
                                    </td>
                                    <td className="px-3 py-2">
                                        <NativeSelect className="h-8 w-full text-xs" aria-label={`Head of ${d.name}`} value={d.head_id ?? ''} placeholder="No head"
                                            options={people} onChange={(e) => router.put(`/settings/departments/${d.id}/head`, { head_employee_id: e.target.value || null }, opts)} />
                                    </td>
                                    <td className="px-3 py-2.5 tabular-nums">
                                        {d.current > 0 ? <Link href={`/employees?department=${d.id}`} className="font-semibold text-cascade hover:underline">{d.current}</Link> : <span className="text-muted-foreground">0</span>}
                                        {former > 0 && <span className="block text-xs text-muted-foreground" title="People who left or are under review still point at this department">+{former} former</span>}
                                    </td>
                                    <td className="px-3 py-2">
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            {d.teams.map((t) => <TeamChip key={t.id} team={t} onMove={() => setMoving({ team: t, from: d })} />)}
                                            <AddInline compact label="Team" placeholder={`New team in ${d.name}`}
                                                onAdd={(name, done) => router.post('/settings/teams', { department_id: d.id, name }, { ...opts, onSuccess: done })} />
                                        </div>
                                    </td>
                                    <td className="px-2 py-2 text-right">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button variant="ghost" size="icon" className="size-8" aria-label={`Options for ${d.name}`}><MoreHorizontal /></Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem onSelect={() => setMerging(d)} disabled={company.departments.length < 2}><Combine /> Merge into another department</DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem disabled={d.employees > 0} className="text-missing"
                                                    onSelect={() => confirm(`Delete department ${d.name} and its teams?`) && router.delete(`/settings/departments/${d.id}`, opts)}>
                                                    <Trash2 /> {d.employees > 0 ? 'Delete (merge it instead: it still has people)' : 'Delete'}
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {merging && (
                <PickDepartment title={`Merge ${merging.name} into…`} action="Merge"
                    description={`Everyone in ${merging.name} (${merging.current} current${merging.employees > merging.current ? `, ${merging.employees - merging.current} former` : ''}) and its teams move to the department you pick, then ${merging.name} is deleted. A team with the same name joins the existing one. Current staff get a line in their job history.`}
                    choices={company.departments.filter((x) => x.id !== merging.id)} onClose={() => setMerging(null)}
                    onPick={(id) => router.post(`/settings/departments/${merging.id}/merge`, { into_id: id }, { ...opts, onSuccess: () => setMerging(null) })} />
            )}
            {moving && (
                <PickDepartment title={`Move ${moving.team.name} to…`} action="Move team"
                    description={`The team and its ${plural(moving.team.current, 'person', 'people')} move from ${moving.from.name} to the department you pick. If it already has a team called ${moving.team.name}, the two become one.`}
                    choices={company.departments.filter((x) => x.id !== moving.from.id)} onClose={() => setMoving(null)}
                    onPick={(id) => router.put(`/settings/teams/${moving.team.id}/move`, { department_id: id }, { ...opts, onSuccess: () => setMoving(null) })} />
            )}
        </div>
    );
}

function CategoriesTab({ categories }: { categories: Category[] }) {
    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm text-muted-foreground">Tags on employees, across both companies (for example Google Ads Neon). A person can have several. Set them on each profile.</p>
                <AddInline label="Add category" placeholder="New category name" onAdd={(name, done) => router.post('/settings/categories', { name }, { ...opts, onSuccess: done })} />
            </div>
            {categories.length === 0 ? <p className="rounded-lg border px-3 py-4 text-sm text-muted-foreground">No categories yet.</p> : (
                <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    {categories.map((c) => (
                        <li key={c.id} className="flex items-center justify-between gap-2 rounded-lg border px-3 py-2">
                            <div className="min-w-0">
                                <Rename value={c.name} url={`/settings/categories/${c.id}`} label={c.name} />
                                <p className="text-xs text-muted-foreground">{plural(c.employees, 'employee')}</p>
                            </div>
                            <Button variant="ghost" size="icon" className="size-8 shrink-0" disabled={c.employees > 0} title={c.employees ? 'Take it off its employees first' : 'Delete category'}
                                aria-label={`Delete category ${c.name}`} onClick={() => confirm(`Delete category ${c.name}?`) && router.delete(`/settings/categories/${c.id}`, opts)}>
                                <Trash2 />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export default function Structure({ companies, categories, people }: Props) {
    const [tab, setTab] = useSessionState('structure:tab', companies[0] ? String(companies[0].id) : 'categories');
    const current = companies.some((c) => String(c.id) === tab) || tab === 'categories' ? tab : String(companies[0]?.id ?? 'categories');
    return (
        <>
            <Head title="Departments and teams" />
            <PageHeader title="Departments and teams"
                description="Each company's departments, their heads (shown on the org chart and suggested as manager for new joiners) and teams, plus categories." />
            <Tabs value={current} onValueChange={setTab} className="gap-4 px-4 py-6 md:px-6">
                <TabsList className="w-auto">
                    {companies.map((c) => (
                        <TabsTrigger key={c.id} value={String(c.id)}>
                            {c.name} <span className="ml-1.5 text-xs text-muted-foreground tabular-nums">{c.departments.length}</span>
                        </TabsTrigger>
                    ))}
                    <TabsTrigger value="categories">Categories <span className="ml-1.5 text-xs text-muted-foreground tabular-nums">{categories.length}</span></TabsTrigger>
                </TabsList>
                {companies.map((c) => (
                    <TabsContent key={c.id} value={String(c.id)}>
                        <CompanyTab company={c} people={people[c.id] ?? []} />
                    </TabsContent>
                ))}
                <TabsContent value="categories"><CategoriesTab categories={categories} /></TabsContent>
            </Tabs>
        </>
    );
}
