import { Head, router } from '@inertiajs/react';
import { Check, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { plural } from '@/lib/format';

type Team = { id: number; name: string; employees: number };
type Department = { id: number; name: string; employees: number; teams: Team[] };
type Company = { id: number; name: string; code: string; prefix: string; employees: number; departments: Department[] };

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

export default function Structure({ companies }: { companies: Company[] }) {
    return (
        <>
            <Head title="Departments and teams" />
            <PageHeader title="Departments and teams" description="Companies, their departments, and the teams inside each department. Delete works only when nobody is assigned." />
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
            </div>
        </>
    );
}
