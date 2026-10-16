import { Head, router } from '@inertiajs/react';
import { Check, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { NativeSelect } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { plural } from '@/lib/format';

type Team = { id: number; name: string; employees: number };
type Department = { id: number; name: string; employees: number; head_id: number | null; teams: Team[] };
type Option = { value: number; label: string };
type Company = { id: number; name: string; code: string; prefix: string; employees: number; departments: Department[] };
type Category = { id: number; name: string; employees: number };

const opts = { preserveScroll: true, preserveState: true, onError: (e: Record<string, string>) => toast.error(Object.values(e)[0] ?? 'Not saved.') };

function Rename({ value, url, label }: { value: string; url: string; label: string }) {
    const [editing, setEditing] = useState(false);
    const [name, setName] = useState(value);
    if (!editing) {
        return (
            <button type="button" className="inline-flex items-center gap-1.5 font-semibold hover:text-cascade" onClick={() => { setName(value); setEditing(true); }} aria-label={`Rename ${label}`}>
                {value} <Pencil className="size-3.5 text-muted-foreground" />
            </button>
        );
    }
    const save = (e: FormEvent) => { e.preventDefault(); router.put(url, { name }, { ...opts, onSuccess: () => setEditing(false) }); };
    return (
        <form onSubmit={save} className="flex items-center gap-1">
            <Input value={name} onChange={(e) => setName(e.target.value)} className="h-8 w-56" autoFocus aria-label={`New name for ${label}`} />
            <Button size="sm" type="submit" aria-label="Save name"><Check /></Button>
            <Button size="sm" type="button" variant="ghost" onClick={() => setEditing(false)} aria-label="Cancel"><X /></Button>
        </form>
    );
}

function AddInline({ placeholder, onAdd }: { placeholder: string; onAdd: (name: string, done: () => void) => void }) {
    const [name, setName] = useState('');
    return (
        <form onSubmit={(e) => { e.preventDefault(); if (name.trim()) onAdd(name.trim(), () => setName('')); }} className="flex items-center gap-1">
            <Input value={name} onChange={(e) => setName(e.target.value)} placeholder={placeholder} className="h-8 w-56" aria-label={placeholder} />
            <Button size="sm" variant="outline" type="submit" disabled={!name.trim()}><Plus /> Add</Button>
        </form>
    );
}

export default function Structure({ companies, categories, people }: { companies: Company[]; categories: Category[]; people: Record<number, Option[]> }) {
    return (
        <>
            <Head title="Departments and teams" />
            <PageHeader title="Departments and teams" description="Companies, their departments (with an optional head, shown on the org chart and suggested as manager for new joiners), the teams inside each department, and categories. Delete works only when nobody is assigned." />
            <div className="grid gap-10 px-4 py-6 md:px-6">
                {companies.map((c) => (
                    <section key={c.id}>
                        <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-bold">{c.name}</h2>
                                <p className="text-sm text-muted-foreground">{plural(c.employees, 'employee')}. New employee codes look like {c.prefix}001.</p>
                            </div>
                            <AddInline placeholder="New department name" onAdd={(name, done) => router.post('/settings/departments', { company_id: c.id, name }, { ...opts, onSuccess: done })} />
                        </div>
                        <ul className="divide-y rounded-lg border">
                            {c.departments.map((d) => (
                                <li key={d.id} className="grid gap-2 px-3 py-3 lg:grid-cols-[18rem_1fr_auto]">
                                    <div>
                                        <Rename value={d.name} url={`/settings/departments/${d.id}`} label={d.name} />
                                        <p className="text-xs text-muted-foreground">{plural(d.employees, 'employee')}</p>
                                        <NativeSelect className="mt-1.5 h-8 w-full text-xs" aria-label={`Head of ${d.name}`} value={d.head_id ?? ''} placeholder="No head"
                                            options={people[c.id] ?? []}
                                            onChange={(e) => router.put(`/settings/departments/${d.id}/head`, { head_employee_id: e.target.value || null }, opts)} />
                                    </div>
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        {d.teams.map((t) => (
                                            <span key={t.id} className="inline-flex items-center gap-1 rounded-full border bg-muted py-0.5 pr-1 pl-2.5 text-xs">
                                                {t.name} <span className="text-muted-foreground">{t.employees}</span>
                                                <button type="button" aria-label={`Delete team ${t.name}`} title={t.employees ? 'Move its employees first' : 'Delete team'}
                                                    disabled={t.employees > 0} className="rounded-full p-0.5 hover:bg-white disabled:opacity-30"
                                                    onClick={() => confirm(`Delete team ${t.name}?`) && router.delete(`/settings/teams/${t.id}`, opts)}>
                                                    <X className="size-3" />
                                                </button>
                                            </span>
                                        ))}
                                        <AddInline placeholder={`New team in ${d.name}`} onAdd={(name, done) => router.post('/settings/teams', { department_id: d.id, name }, { ...opts, onSuccess: done })} />
                                    </div>
                                    <Button variant="ghost" size="sm" disabled={d.employees > 0} title={d.employees ? 'Move its employees first' : 'Delete department'}
                                        aria-label={`Delete department ${d.name}`} onClick={() => confirm(`Delete department ${d.name} and its teams?`) && router.delete(`/settings/departments/${d.id}`, opts)}>
                                        <Trash2 />
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
                <section>
                    <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-bold">Categories</h2>
                            <p className="text-sm text-muted-foreground">Tags on employees, across both companies (for example Google Ads Neon). A person can have several.</p>
                        </div>
                        <AddInline placeholder="New category name" onAdd={(name, done) => router.post('/settings/categories', { name }, { ...opts, onSuccess: done })} />
                    </div>
                    <ul className="divide-y rounded-lg border">
                        {categories.length === 0 && <li className="px-3 py-3 text-sm text-muted-foreground">No categories yet.</li>}
                        {categories.map((c) => (
                            <li key={c.id} className="flex items-center justify-between gap-2 px-3 py-2">
                                <div>
                                    <Rename value={c.name} url={`/settings/categories/${c.id}`} label={c.name} />
                                    <p className="text-xs text-muted-foreground">{plural(c.employees, 'employee')}</p>
                                </div>
                                <Button variant="ghost" size="sm" disabled={c.employees > 0} title={c.employees ? 'Take it off its employees first' : 'Delete category'}
                                    aria-label={`Delete category ${c.name}`} onClick={() => confirm(`Delete category ${c.name}?`) && router.delete(`/settings/categories/${c.id}`, opts)}>
                                    <Trash2 />
                                </Button>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}
