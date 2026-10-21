import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ExternalLink, Pencil, Plus, SearchX, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { NativeSelect, toOptions } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format';
import type { SharedProps } from '@/types';

type Doc = { id: number; doc_number: string; doc_type: string; name: string; description: string | null; status: string;
    link_url: string | null; issued_on: string | null; obsolete_on: string | null };

const tone = { Active: 'done', Draft: 'pending', Obsolete: 'neutral' } as const;

export default function ControlledDocuments({ documents, filters, types, statuses, nextNumber }: {
    documents: Doc[]; filters: { type?: string; status?: string; q?: string }; types: string[]; statuses: string[]; nextNumber: string;
}) {
    const { auth } = usePage<SharedProps>().props;
    const [editing, setEditing] = useState<Doc | 'new' | null>(null);
    const blank = { doc_type: types[0], name: '', description: '', status: 'Active', issued_on: '', obsolete_on: '', link_url: '' };
    const form = useForm(blank);

    const openFor = (doc: Doc | 'new') => {
        form.clearErrors();
        form.setData(doc === 'new' ? blank : {
            doc_type: doc.doc_type, name: doc.name, description: doc.description ?? '', status: doc.status,
            issued_on: doc.issued_on ?? '', obsolete_on: doc.obsolete_on ?? '', link_url: doc.link_url ?? '',
        });
        setEditing(doc);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v])));
        const opts = { preserveScroll: true, onSuccess: () => setEditing(null) };
        if (editing === 'new') form.post('/controlled-documents', opts);
        else if (editing) form.put(`/controlled-documents/${editing.id}`, opts);
    };

    const filter = (patch: Record<string, string | undefined>) =>
        router.get('/controlled-documents', Object.fromEntries(Object.entries({ ...filters, ...patch }).filter(([, v]) => v)), { preserveState: true, replace: true });

    return (
        <>
            <Head title="Controlled documents" />
            <PageHeader
                title="Controlled documents"
                help="The register of HR policies, templates, forms and letters, each with a document number."
                actions={auth.can.edit && <Button onClick={() => openFor('new')}><Plus /> Add document</Button>}
            >
                <div className="flex flex-wrap gap-2">
                    <NativeSelect aria-label="Type" className="w-40" value={filters.type ?? ''} placeholder="All types" onChange={(e) => filter({ type: e.target.value || undefined })} options={toOptions(types)} />
                    <NativeSelect aria-label="Status" className="w-40" value={filters.status ?? ''} placeholder="Any status" onChange={(e) => filter({ status: e.target.value || undefined })} options={toOptions(statuses)} />
                </div>
            </PageHeader>

            <div className="px-4 py-4 md:px-6">
                {documents.length === 0 ? <EmptyState icon={SearchX} title="No documents match.">Change the filters or add a document.</EmptyState> : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[820px] text-sm">
                            <thead>
                                <tr className="border-b text-left text-xs text-muted-foreground">
                                    <th className="py-2.5 pr-3 font-semibold">Number</th>
                                    <th className="py-2.5 pr-3 font-semibold">Name</th>
                                    <th className="py-2.5 pr-3 font-semibold">Type</th>
                                    <th className="py-2.5 pr-3 font-semibold">Issued</th>
                                    <th className="py-2.5 pr-3 font-semibold">Obsolete</th>
                                    <th className="py-2.5 pr-3 font-semibold">Status</th>
                                    <th className="py-2.5 font-semibold"><span className="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {documents.map((d) => (
                                    <tr key={d.id} className="hover:bg-[#f7f9fc]">
                                        <td className="py-2.5 pr-3 font-semibold tabular-nums">{d.doc_number}</td>
                                        <td className="py-2.5 pr-3">
                                            {d.link_url ? <a href={d.link_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 font-semibold text-cascade hover:underline">{d.name} <ExternalLink className="size-3.5" /></a> : <span className="font-semibold">{d.name}</span>}
                                            {d.description && <span className="block max-w-md truncate text-xs text-muted-foreground">{d.description}</span>}
                                        </td>
                                        <td className="py-2.5 pr-3">{d.doc_type}</td>
                                        <td className="py-2.5 pr-3 tabular-nums">{formatDate(d.issued_on) || '-'}</td>
                                        <td className="py-2.5 pr-3 tabular-nums">{formatDate(d.obsolete_on) || '-'}</td>
                                        <td className="py-2.5 pr-3"><StatusPill value={d.status} tone={tone[d.status as keyof typeof tone] ?? 'neutral'} /></td>
                                        <td className="py-2.5 text-right whitespace-nowrap">
                                            {auth.can.edit && <Button variant="ghost" size="sm" onClick={() => openFor(d)} aria-label={`Edit ${d.doc_number}`}><Pencil /></Button>}
                                            {auth.can.admin && <Button variant="ghost" size="sm" aria-label={`Delete ${d.doc_number}`}
                                                onClick={() => confirm(`Delete ${d.doc_number} ${d.name}?`) && router.delete(`/controlled-documents/${d.id}`, { preserveScroll: true })}><Trash2 /></Button>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editing === 'new' ? 'Add document' : `Edit ${editing ? editing.doc_number : ''}`}</DialogTitle>
                        <DialogDescription>{editing === 'new' ? `It will be numbered ${nextNumber}.` : 'The number stays the same.'}</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="grid gap-4">
                        <Field label="Name" htmlFor="doc-name" error={form.errors.name}>
                            <Input id="doc-name" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Type" htmlFor="doc-type" error={form.errors.doc_type}>
                                <NativeSelect id="doc-type" value={form.data.doc_type} onChange={(e) => form.setData('doc_type', e.target.value)} options={toOptions(types)} />
                            </Field>
                            <Field label="Status" htmlFor="doc-status" error={form.errors.status}>
                                <NativeSelect id="doc-status" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)} options={toOptions(statuses)} />
                            </Field>
                            <Field label="Issued on" htmlFor="doc-issued" error={form.errors.issued_on}>
                                <Input id="doc-issued" type="date" value={form.data.issued_on} onChange={(e) => form.setData('issued_on', e.target.value)} />
                            </Field>
                            <Field label="Obsolete on" htmlFor="doc-obsolete" error={form.errors.obsolete_on}>
                                <Input id="doc-obsolete" type="date" value={form.data.obsolete_on} onChange={(e) => form.setData('obsolete_on', e.target.value)} />
                            </Field>
                        </div>
                        <Field label="Link to the file" htmlFor="doc-link" error={form.errors.link_url} hint="Google Drive or any other link.">
                            <Input id="doc-link" type="url" value={form.data.link_url} onChange={(e) => form.setData('link_url', e.target.value)} placeholder="https://" />
                        </Field>
                        <Field label="Description" htmlFor="doc-desc" error={form.errors.description}>
                            <Textarea id="doc-desc" rows={3} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        </Field>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setEditing(null)}>Cancel</Button>
                            <Button type="submit" disabled={form.processing}>{editing === 'new' ? 'Add document' : 'Save changes'}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
