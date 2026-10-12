import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Pencil, Plus, Undo2, UserPlus } from 'lucide-react';
import { type FormEvent, Fragment, useState } from 'react';
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
import type { SharedProps, Tone } from '@/types';

type History = { id: number; employee: string | null; employee_id: number; assigned_on: string; returned_on: string | null; condition_out: string | null; condition_in: string | null; note: string | null };
type Asset = {
    id: number; asset_tag: string; type: string; name: string; serial_number: string | null; status: string; notes: string | null; purchase_date: string | null;
    holder: { id: number; name: string; code: string; since: string; leaving: boolean } | null;
    history: History[];
};
type Option = { value: number; label: string };
type Props = {
    filters: { q?: string; type?: string; status?: string };
    assets: Asset[];
    counts: Record<string, number>;
    types: string[];
    statuses: Record<string, { label: string; tone: Tone }>;
    people: Option[];
    today: string;
};

function AssetForm({ asset, types, statuses, onClose }: { asset: Asset | null; types: string[]; statuses: Props['statuses']; onClose: () => void }) {
    const form = useForm({
        asset_tag: asset?.asset_tag ?? '', type: asset?.type ?? types[0], name: asset?.name ?? '', serial_number: asset?.serial_number ?? '',
        status: asset?.status ?? 'in_stock', purchase_date: asset?.purchase_date ?? '', notes: asset?.notes ?? '',
    });
    const held = asset?.status === 'assigned';
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, serial_number: d.serial_number || null, purchase_date: d.purchase_date || null, notes: d.notes || null }));
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (asset) form.put(`/assets/${asset.id}`, opts);
        else form.post('/assets', opts);
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{asset ? `Edit ${asset.asset_tag}` : 'Add asset'}</DialogTitle>
                        <DialogDescription>The tag is what's written on the sticker, e.g. LAP-014.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Tag" htmlFor="a-tag" error={form.errors.asset_tag}>
                            <Input id="a-tag" required value={form.data.asset_tag} onChange={(e) => form.setData('asset_tag', e.target.value)} />
                        </Field>
                        <Field label="Type" htmlFor="a-type" error={form.errors.type}>
                            <NativeSelect id="a-type" value={form.data.type} options={toOptions(types)} onChange={(e) => form.setData('type', e.target.value)} />
                        </Field>
                        <Field label="Make and model" htmlFor="a-name" error={form.errors.name} className="sm:col-span-2">
                            <Input id="a-name" required placeholder="Dell Latitude 5420" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        </Field>
                        <Field label="Serial number" htmlFor="a-serial" error={form.errors.serial_number}>
                            <Input id="a-serial" value={form.data.serial_number} onChange={(e) => form.setData('serial_number', e.target.value)} />
                        </Field>
                        <Field label="Bought on" htmlFor="a-bought" error={form.errors.purchase_date}>
                            <Input id="a-bought" type="date" value={form.data.purchase_date} onChange={(e) => form.setData('purchase_date', e.target.value)} />
                        </Field>
                        <Field label="Status" htmlFor="a-status" error={form.errors.status} hint={held ? 'Take it back to change the status.' : undefined}>
                            <NativeSelect id="a-status" value={form.data.status} disabled={held} onChange={(e) => form.setData('status', e.target.value)}
                                options={Object.entries(statuses).filter(([k]) => held || k !== 'assigned').map(([k, v]) => ({ value: k, label: v.label }))} />
                        </Field>
                        <Field label="Notes" htmlFor="a-notes" error={form.errors.notes} className="sm:col-span-2">
                            <Textarea id="a-notes" rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>{asset ? 'Save asset' : 'Add asset'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function GiveDialog({ asset, people, today, onClose }: { asset: Asset; people: Option[]; today: string; onClose: () => void }) {
    const form = useForm({ employee_id: '', assigned_on: today, condition_out: '', note: '' });
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={(e) => { e.preventDefault(); form.post(`/assets/${asset.id}/assign`, { preserveScroll: true, onSuccess: onClose }); }} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Give {asset.asset_tag} to someone</DialogTitle>
                        <DialogDescription>{asset.name}{asset.serial_number ? `, serial ${asset.serial_number}` : ''}</DialogDescription>
                    </DialogHeader>
                    <Field label="Employee" htmlFor="g-emp" error={form.errors.employee_id}>
                        <NativeSelect id="g-emp" required value={form.data.employee_id} placeholder="Pick a person" options={people} onChange={(e) => form.setData('employee_id', e.target.value)} />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Given on" htmlFor="g-date" error={form.errors.assigned_on}>
                            <Input id="g-date" type="date" required max={today} value={form.data.assigned_on} onChange={(e) => form.setData('assigned_on', e.target.value)} />
                        </Field>
                        <Field label="Condition" htmlFor="g-cond" error={form.errors.condition_out}>
                            <Input id="g-cond" placeholder="Good, small scratch on lid" value={form.data.condition_out} onChange={(e) => form.setData('condition_out', e.target.value)} />
                        </Field>
                    </div>
                    <Field label="Note" htmlFor="g-note" error={form.errors.note}>
                        <Textarea id="g-note" rows={2} placeholder="With charger and bag" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                    </Field>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>Give asset</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ReturnDialog({ asset, statuses, today, onClose }: { asset: Asset; statuses: Props['statuses']; today: string; onClose: () => void }) {
    const form = useForm({ returned_on: today, condition_in: '', status: 'in_stock', note: '' });
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={(e) => { e.preventDefault(); form.post(`/assets/${asset.id}/return`, { preserveScroll: true, onSuccess: onClose }); }} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Take back {asset.asset_tag}</DialogTitle>
                        <DialogDescription>From {asset.holder?.name}, who has had it since {formatDate(asset.holder?.since)}.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Returned on" htmlFor="r-date" error={form.errors.returned_on}>
                            <Input id="r-date" type="date" required min={asset.holder?.since} max={today} value={form.data.returned_on} onChange={(e) => form.setData('returned_on', e.target.value)} />
                        </Field>
                        <Field label="Now" htmlFor="r-status" error={form.errors.status}>
                            <NativeSelect id="r-status" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}
                                options={['in_stock', 'repair', 'lost', 'retired'].map((k) => ({ value: k, label: k === 'lost' ? 'Lost (not returned)' : statuses[k].label }))} />
                        </Field>
                        <Field label="Condition" htmlFor="r-cond" error={form.errors.condition_in} className="sm:col-span-2">
                            <Input id="r-cond" placeholder="Good, charger missing" value={form.data.condition_in} onChange={(e) => form.setData('condition_in', e.target.value)} />
                        </Field>
                    </div>
                    <Field label="Note" htmlFor="r-note" error={form.errors.note}>
                        <Textarea id="r-note" rows={2} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                    </Field>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>{form.data.status === 'lost' ? 'Mark as lost' : 'Take back'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Assets(props: Props) {
    const { auth } = usePage<SharedProps>().props;
    const can = auth.can.edit;
    const [q, setQ] = useState(props.filters.q ?? '');
    const [open, setOpen] = useState<number | null>(null);
    const [dialog, setDialog] = useState<{ kind: 'form' | 'give' | 'return'; asset: Asset | null } | null>(null);
    const go = (changes: Record<string, unknown>) => router.get('/assets', { ...props.filters, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
    const total = Object.values(props.counts).reduce((n, v) => n + v, 0);

    return (
        <>
            <Head title="Assets" />
            <PageHeader
                title="Assets"
                description="Company property and who has it. Offboarding can't be completed while someone still has something on this list."
                actions={can && <Button onClick={() => setDialog({ kind: 'form', asset: null })}><Plus /> Add asset</Button>}
            >
                <div className="flex flex-wrap items-center gap-2">
                    <Button size="sm" variant={!props.filters.status ? 'default' : 'outline'} onClick={() => go({ status: undefined })}>All {total}</Button>
                    {Object.entries(props.statuses).map(([k, v]) => (
                        <Button key={k} size="sm" variant={props.filters.status === k ? 'default' : 'outline'} onClick={() => go({ status: k })}>{v.label} {props.counts[k] ?? 0}</Button>
                    ))}
                    <NativeSelect className="w-44" aria-label="Type" value={props.filters.type ?? ''} placeholder="All types" options={toOptions(props.types)} onChange={(e) => go({ type: e.target.value || undefined })} />
                    <form onSubmit={(e) => { e.preventDefault(); go({ q: q || undefined }); }}>
                        <Input className="w-64" placeholder="Tag, model, serial or person" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search assets" />
                    </form>
                </div>
            </PageHeader>

            <div className="px-4 py-6 md:px-6">
                {props.assets.length === 0 ? (
                    <EmptyState title={total === 0 ? 'No assets yet.' : 'Nothing matches these filters.'}>
                        {total === 0 && can && 'Add laptops, phones, SIM cards and ID cards so you can see who has what.'}
                    </EmptyState>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[56rem] text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="w-8 px-2 py-2"><span className="sr-only">History</span></th>
                                    <th className="px-3 py-2 font-semibold">Tag</th>
                                    <th className="px-3 py-2 font-semibold">Asset</th>
                                    <th className="px-3 py-2 font-semibold">Status</th>
                                    <th className="px-3 py-2 font-semibold">With</th>
                                    {can && <th className="px-3 py-2"><span className="sr-only">Actions</span></th>}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {props.assets.map((a) => (
                                    <Fragment key={a.id}>
                                        <tr className="align-top hover:bg-accent/40">
                                            <td className="px-2 py-2">
                                                {a.history.length > 0 && (
                                                    <button type="button" aria-label={`History of ${a.asset_tag}`} aria-expanded={open === a.id} className="text-muted-foreground hover:text-foreground"
                                                        onClick={() => setOpen(open === a.id ? null : a.id)}>
                                                        {open === a.id ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                                                    </button>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 font-semibold whitespace-nowrap tabular-nums">{a.asset_tag}</td>
                                            <td className="px-3 py-2">
                                                {a.name} <span className="text-muted-foreground">{a.type}</span>
                                                {(a.serial_number || a.notes) && <span className="block text-xs text-muted-foreground">{[a.serial_number && `Serial ${a.serial_number}`, a.notes].filter(Boolean).join('. ')}</span>}
                                            </td>
                                            <td className="px-3 py-2"><StatusPill value={props.statuses[a.status]?.label ?? a.status} tone={props.statuses[a.status]?.tone ?? 'neutral'} /></td>
                                            <td className="px-3 py-2">
                                                {a.holder ? (
                                                    <>
                                                        <Link href={`/employees/${a.holder.id}`} className="font-semibold hover:text-cascade hover:underline">{a.holder.name}</Link>
                                                        <span className="block text-xs text-muted-foreground">since {formatDate(a.holder.since)}{a.holder.leaving && ', leaving'}</span>
                                                    </>
                                                ) : <span className="text-muted-foreground">Nobody</span>}
                                            </td>
                                            {can && (
                                                <td className="px-3 py-2">
                                                    <div className="flex justify-end gap-1">
                                                        {a.holder ? (
                                                            <Button size="sm" variant="outline" onClick={() => setDialog({ kind: 'return', asset: a })}><Undo2 /> Take back</Button>
                                                        ) : a.status === 'in_stock' ? (
                                                            <Button size="sm" variant="outline" onClick={() => setDialog({ kind: 'give', asset: a })}><UserPlus /> Give</Button>
                                                        ) : null}
                                                        <Button size="sm" variant="ghost" aria-label={`Edit ${a.asset_tag}`} onClick={() => setDialog({ kind: 'form', asset: a })}><Pencil /></Button>
                                                    </div>
                                                </td>
                                            )}
                                        </tr>
                                        {open === a.id && (
                                            <tr className="bg-muted/30">
                                                <td />
                                                <td colSpan={can ? 5 : 4} className="px-3 py-2">
                                                    <ul className="grid gap-1 text-xs">
                                                        {a.history.map((h) => (
                                                            <li key={h.id} className="flex flex-wrap gap-x-3">
                                                                <Link href={`/employees/${h.employee_id}`} className="font-semibold hover:underline">{h.employee ?? 'Removed employee'}</Link>
                                                                <span className="tabular-nums text-muted-foreground">{formatDate(h.assigned_on)} to {h.returned_on ? formatDate(h.returned_on) : 'now'}</span>
                                                                {(h.condition_out || h.condition_in || h.note) && (
                                                                    <span className="text-muted-foreground">{[h.condition_out && `Given: ${h.condition_out}`, h.condition_in && `Returned: ${h.condition_in}`, h.note].filter(Boolean).join('. ')}</span>
                                                                )}
                                                            </li>
                                                        ))}
                                                    </ul>
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {dialog?.kind === 'form' && <AssetForm asset={dialog.asset} types={props.types} statuses={props.statuses} onClose={() => setDialog(null)} />}
            {dialog?.kind === 'give' && dialog.asset && <GiveDialog asset={dialog.asset} people={props.people} today={props.today} onClose={() => setDialog(null)} />}
            {dialog?.kind === 'return' && dialog.asset && <ReturnDialog asset={dialog.asset} statuses={props.statuses} today={props.today} onClose={() => setDialog(null)} />}
        </>
    );
}
