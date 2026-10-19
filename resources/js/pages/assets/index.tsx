import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Check, ChevronDown, ChevronRight, Laptop, Pencil, Plus, SearchX, Tags, Undo2, Upload, UserPlus } from 'lucide-react';
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
import { toast } from 'sonner';
import { formatDate } from '@/lib/format';
import type { SharedProps, Tone } from '@/types';

type History = { id: number; employee: string | null; employee_id: number; assigned_on: string; returned_on: string | null; condition_out: string | null; condition_in: string | null; note: string | null };
type Asset = {
    id: number; asset_tag: string; type: string; name: string; serial_number: string | null; status: string; notes: string | null; purchase_date: string | null;
    company_id: number | null; company: string | null; cost: number | null; vendor: string | null; warranty_until: string | null;
    holder: { id: number; name: string; code: string; since: string; leaving: boolean } | null;
    history: History[];
};
type Option = { value: number; label: string };
type AssetTypeRow = { id: number; name: string; prefix: string; is_active: boolean; count: number };
type Company = { id: number; name: string; code: string };
type Props = {
    filters: { q?: string; type?: string; status?: string; company?: number; warranty?: 'ending' | 'expired' };
    assetTypes: AssetTypeRow[]; companies: Company[];
    totals: { company_id: number | null; count: number; cost: number; no_cost: number }[];
    warrantyEnding: number; warrantyDays: number;
    assets: Asset[];
    counts: Record<string, number>;
    types: string[];
    statuses: Record<string, { label: string; tone: Tone }>;
    people: Option[];
    today: string;
};

const money = (n: number) => `Rs ${n.toLocaleString('en-PK', { maximumFractionDigits: 0 })}`;

/** Add or rename asset types and their tag prefixes; switch off types no longer bought. */
function TypesDialog({ types, onClose }: { types: AssetTypeRow[]; onClose: () => void }) {
    const [rows, setRows] = useState(types.map((t) => ({ ...t })));
    const [name, setName] = useState('');
    const [prefix, setPrefix] = useState('');
    const opts = { preserveScroll: true, preserveState: true, onError: (e: Record<string, string>) => toast.error(Object.values(e)[0] ?? 'Not saved.') };
    const save = (t: AssetTypeRow) => router.put(`/assets/types/${t.id}`, { name: t.name, prefix: t.prefix, is_active: t.is_active }, opts);
    const edit = (id: number, patch: Partial<AssetTypeRow>) => setRows((r) => r.map((x) => (x.id === id ? { ...x, ...patch } : x)));
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Asset types</DialogTitle>
                    <DialogDescription>The prefix goes in automatic tags: BCC-LAP-001. Renaming a type renames it on its assets; changing a prefix leaves old tags as they are.</DialogDescription>
                </DialogHeader>
                <ul className="grid gap-2">
                    {rows.map((t) => (
                        <li key={t.id} className="flex flex-wrap items-center gap-2 text-sm">
                            <Input aria-label="Type name" className="w-44" value={t.name} onChange={(e) => edit(t.id, { name: e.target.value })} />
                            <Input aria-label={`${t.name} prefix`} className="w-20 font-mono uppercase" value={t.prefix} onChange={(e) => edit(t.id, { prefix: e.target.value })} />
                            <label className="flex items-center gap-1.5"><input type="checkbox" className="size-4 accent-cascade" checked={t.is_active} onChange={(e) => edit(t.id, { is_active: e.target.checked })} /> In use</label>
                            <span className="text-xs text-muted-foreground">{t.count} assets</span>
                            <Button size="sm" variant="ghost" className="ml-auto" onClick={() => save(t)}><Check /> Save</Button>
                        </li>
                    ))}
                </ul>
                <form className="flex flex-wrap gap-2 border-t pt-3" onSubmit={(e) => {
                    e.preventDefault();
                    router.post('/assets/types', { name, prefix }, { ...opts, onSuccess: () => { setName(''); setPrefix(''); onClose(); } });
                }}>
                    <Input aria-label="New type" className="w-44" placeholder="New type, e.g. UPS" value={name} onChange={(e) => setName(e.target.value)} />
                    <Input aria-label="New prefix" className="w-20 font-mono uppercase" placeholder="UPS" value={prefix} onChange={(e) => setPrefix(e.target.value)} />
                    <Button type="submit" variant="outline" disabled={!name.trim() || !prefix.trim()}><Plus /> Add type</Button>
                </form>
                <DialogFooter><Button variant="ghost" onClick={onClose}>Done</Button></DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function AssetForm({ asset, types, companies, statuses, onClose }: { asset: Asset | null; types: string[]; companies: Company[]; statuses: Props['statuses']; onClose: () => void }) {
    const form = useForm({
        asset_tag: asset?.asset_tag ?? '', type: asset?.type ?? types[0], name: asset?.name ?? '', serial_number: asset?.serial_number ?? '',
        status: asset?.status ?? 'in_stock', purchase_date: asset?.purchase_date ?? '', notes: asset?.notes ?? '',
        company_id: asset?.company_id ? String(asset.company_id) : (companies.length === 1 ? String(companies[0].id) : ''),
        cost: asset?.cost != null ? String(asset.cost) : '', vendor: asset?.vendor ?? '', warranty_until: asset?.warranty_until ?? '',
    });
    const held = asset?.status === 'assigned';
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, asset_tag: d.asset_tag || null, serial_number: d.serial_number || null, purchase_date: d.purchase_date || null, notes: d.notes || null,
            company_id: d.company_id ? Number(d.company_id) : null, cost: d.cost === '' ? null : Number(d.cost), vendor: d.vendor || null, warranty_until: d.warranty_until || null }));
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
                        <DialogDescription>{asset ? 'The tag is what\'s written on the sticker.' : 'Leave the tag empty for the next automatic one (company, type, number: BCC-LAP-001), or type the one on the sticker.'}</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Company" htmlFor="a-company" error={form.errors.company_id}>
                            <NativeSelect id="a-company" value={form.data.company_id} placeholder="Not set" options={companies.map((c) => ({ value: c.id, label: c.name }))}
                                onChange={(e) => form.setData('company_id', e.target.value)} />
                        </Field>
                        <Field label="Tag" htmlFor="a-tag" error={form.errors.asset_tag}>
                            <Input id="a-tag" required={!!asset} placeholder={asset ? undefined : 'Automatic'} value={form.data.asset_tag} onChange={(e) => form.setData('asset_tag', e.target.value)} />
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
                        <Field label="Cost (Rs)" htmlFor="a-cost" error={form.errors.cost}>
                            <Input id="a-cost" type="number" min={0} step="any" value={form.data.cost} onChange={(e) => form.setData('cost', e.target.value)} />
                        </Field>
                        <Field label="Vendor" htmlFor="a-vendor" error={form.errors.vendor}>
                            <Input id="a-vendor" placeholder="Where it was bought" value={form.data.vendor} onChange={(e) => form.setData('vendor', e.target.value)} />
                        </Field>
                        <Field label="Warranty until" htmlFor="a-warranty" error={form.errors.warranty_until}>
                            <Input id="a-warranty" type="date" value={form.data.warranty_until} onChange={(e) => form.setData('warranty_until', e.target.value)} />
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
    const [dialog, setDialog] = useState<{ kind: 'form' | 'give' | 'return' | 'types'; asset: Asset | null } | null>(null);
    const companyName = (id: number | null) => props.companies.find((c) => c.id === id)?.name ?? 'No company set';
    const go = (changes: Record<string, unknown>) => router.get('/assets', { ...props.filters, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
    const total = Object.values(props.counts).reduce((n, v) => n + v, 0);

    return (
        <>
            <Head title="Assets" />
            <PageHeader
                title="Assets"
                description="Company property and who has it. Offboarding can't be completed while someone still has something on this list."
                actions={can && (
                    <>
                        <Button variant="outline" onClick={() => setDialog({ kind: 'types', asset: null })}><Tags /> Types</Button>
                        <Button variant="outline" asChild><Link href="/imports/assets"><Upload /> Upload a list</Link></Button>
                        <Button onClick={() => setDialog({ kind: 'form', asset: null })}><Plus /> Add asset</Button>
                    </>
                )}
            >
                <div className="flex flex-wrap items-center gap-2">
                    <Button size="sm" variant={!props.filters.status ? 'default' : 'outline'} onClick={() => go({ status: undefined })}>All {total}</Button>
                    {Object.entries(props.statuses).map(([k, v]) => (
                        <Button key={k} size="sm" variant={props.filters.status === k ? 'default' : 'outline'} onClick={() => go({ status: k })}>{v.label} {props.counts[k] ?? 0}</Button>
                    ))}
                    <NativeSelect className="w-44" aria-label="Type" value={props.filters.type ?? ''} placeholder="All types" options={toOptions(props.types)} onChange={(e) => go({ type: e.target.value || undefined })} />
                    {props.companies.length > 1 && (
                        <NativeSelect className="w-40" aria-label="Company" value={props.filters.company ?? ''} placeholder="All companies" options={props.companies.map((c) => ({ value: c.id, label: c.name }))} onChange={(e) => go({ company: e.target.value || undefined })} />
                    )}
                    <NativeSelect className="w-52" aria-label="Warranty" value={props.filters.warranty ?? ''} placeholder="Any warranty"
                        options={[{ value: 'ending', label: `Warranty ending (${props.warrantyDays} days)` }, { value: 'expired', label: 'Warranty expired' }]} onChange={(e) => go({ warranty: e.target.value || undefined })} />
                    <form onSubmit={(e) => { e.preventDefault(); go({ q: q || undefined }); }}>
                        <Input className="w-64" placeholder="Tag, model, serial or person" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search assets" />
                    </form>
                </div>
            </PageHeader>

            <div className="grid gap-4 px-4 py-6 md:px-6">
                {props.warrantyEnding > 0 && props.filters.warranty !== 'ending' && (
                    <p className="rounded-lg border border-[#ecd29c] bg-pending-soft px-4 py-3 text-sm">
                        {props.warrantyEnding} {props.warrantyEnding === 1 ? 'warranty ends' : 'warranties end'} in the next {props.warrantyDays} days. <button type="button" className="font-semibold text-cascade underline" onClick={() => go({ warranty: 'ending' })}>See which</button>.
                    </p>
                )}
                {props.totals.some((t) => t.cost > 0) && (
                    <dl className="flex flex-wrap gap-3 text-sm" aria-label="Value of assets in use">
                        {props.totals.map((t) => (
                            <div key={t.company_id ?? 0} className="rounded-lg border px-3 py-2">
                                <dt className="text-xs text-muted-foreground">{companyName(t.company_id)}, {t.count} in use</dt>
                                <dd className="font-semibold tabular-nums">{money(t.cost)}{t.no_cost > 0 && <span className="ml-1 text-xs font-normal text-muted-foreground">({t.no_cost} without a cost)</span>}</dd>
                            </div>
                        ))}
                    </dl>
                )}
                {props.assets.length === 0 ? (
                    <EmptyState icon={total === 0 ? Laptop : SearchX} title={total === 0 ? 'No assets yet.' : 'Nothing matches these filters.'}
                        action={total === 0 && can && <Button variant="outline" asChild><Link href="/imports/assets"><Upload /> Upload a list</Link></Button>}>
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
                                            <td className="px-3 py-2 font-semibold whitespace-nowrap tabular-nums">{a.asset_tag}{a.company && <span className="block text-xs font-normal text-muted-foreground">{a.company}</span>}</td>
                                            <td className="px-3 py-2">
                                                {a.name} <span className="text-muted-foreground">{a.type}</span>
                                                {(a.serial_number || a.notes || a.cost != null || a.vendor) && <span className="block text-xs text-muted-foreground">{[a.serial_number && `Serial ${a.serial_number}`, a.cost != null && money(a.cost), a.vendor && `from ${a.vendor}`, a.notes].filter(Boolean).join('. ')}</span>}
                                                {a.warranty_until && <span className={`block text-xs ${a.warranty_until < props.today ? 'text-muted-foreground' : 'text-foreground'}`}>Warranty {a.warranty_until < props.today ? 'ended' : 'until'} {formatDate(a.warranty_until)}</span>}
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

            {dialog?.kind === 'form' && <AssetForm asset={dialog.asset} types={dialog.asset && !props.types.includes(dialog.asset.type) ? [...props.types, dialog.asset.type] : props.types} companies={props.companies} statuses={props.statuses} onClose={() => setDialog(null)} />}
            {dialog?.kind === 'types' && <TypesDialog types={props.assetTypes} onClose={() => setDialog(null)} />}
            {dialog?.kind === 'give' && dialog.asset && <GiveDialog asset={dialog.asset} people={props.people} today={props.today} onClose={() => setDialog(null)} />}
            {dialog?.kind === 'return' && dialog.asset && <ReturnDialog asset={dialog.asset} statuses={props.statuses} today={props.today} onClose={() => setDialog(null)} />}
        </>
    );
}
