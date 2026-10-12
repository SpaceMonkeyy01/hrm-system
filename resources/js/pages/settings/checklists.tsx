import { Head, useForm } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import type { FormEvent } from 'react';
import { Field } from '@/components/field';
import { NativeSelect } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { OptionSets } from '@/types';

type Item = { id: number; key: string; label: string; option_set: string; description: string | null; sort_order: number; is_active: boolean; used: number; required: boolean };
type ListName = 'onboarding' | 'document' | 'offboarding';

const listLabel: Record<ListName, string> = { onboarding: 'Onboarding', document: 'Documents', offboarding: 'Offboarding clearance' };
const setLabel: Record<string, string> = { yes_no: 'Yes / No', session: 'Done / Not Started', document: 'Yes / No / Pending / Not Required / Old employee / not available', clearance: 'Done / Pending / Not Required' };

function ItemRow({ item }: { item: Item }) {
    const form = useForm({ label: item.label, description: item.description ?? '', is_active: item.is_active, sort_order: item.sort_order });
    const submit = (e: FormEvent) => { e.preventDefault(); form.put(`/settings/checklists/${item.id}`, { preserveScroll: true, onSuccess: () => form.setDefaults() }); };
    return (
        <tr className={item.is_active ? '' : 'text-muted-foreground'}>
            <td className="py-2 pr-2"><Input aria-label="Order" type="number" className="h-8 w-20" value={form.data.sort_order} onChange={(e) => form.setData('sort_order', Number(e.target.value))} /></td>
            <td className="py-2 pr-2">
                <Input aria-label="Label" className="h-8" value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} />
                {form.errors.label && <p className="text-xs text-missing">{form.errors.label}</p>}
            </td>
            <td className="py-2 pr-2"><Input aria-label="Description" className="h-8" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /></td>
            <td className="py-2 pr-2 text-xs">{setLabel[item.option_set] ?? item.option_set}</td>
            <td className="py-2 pr-2 text-right tabular-nums">{item.used}</td>
            <td className="py-2 pr-2">
                {item.required ? (
                    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground" title="The status rules depend on this item"><Lock className="size-3.5" /> Always on</span>
                ) : (
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" className="size-4 accent-cascade" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} /> In use
                    </label>
                )}
                {form.errors.is_active && <p className="text-xs text-missing">{form.errors.is_active}</p>}
            </td>
            <td className="py-2 text-right"><form onSubmit={submit}><Button size="sm" type="submit" disabled={!form.isDirty || form.processing}>Save</Button></form></td>
        </tr>
    );
}

function AddItem({ list, sets }: { list: ListName; sets: string[] }) {
    const form = useForm({ list, label: '', option_set: sets[0], description: '' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/settings/checklists', { preserveScroll: true, onSuccess: () => form.reset('label', 'description') }); };
    return (
        <form onSubmit={submit} className="mt-4 grid items-end gap-3 rounded-lg border border-dashed p-3 sm:grid-cols-[1fr_14rem_1fr_auto]">
            <Field label={`New ${list === 'document' ? 'document' : 'item'}`} htmlFor={`new-${list}`} error={form.errors.label}>
                <Input id={`new-${list}`} value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} placeholder={list === 'document' ? 'e.g. Police character certificate' : 'e.g. Laptop set up'} />
            </Field>
            <Field label="Values" htmlFor={`set-${list}`} error={form.errors.option_set}>
                <NativeSelect id={`set-${list}`} value={form.data.option_set} onChange={(e) => form.setData('option_set', e.target.value)} options={sets.map((s) => ({ value: s, label: setLabel[s] ?? s }))} />
            </Field>
            <Field label="Description (optional)" htmlFor={`desc-${list}`}>
                <Input id={`desc-${list}`} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
            </Field>
            <Button type="submit" disabled={form.processing || !form.data.label.trim()}>Add</Button>
        </form>
    );
}

export default function Checklists({ lists, setsPerList }: { lists: Record<ListName, Item[]>; optionSets: OptionSets; setsPerList: Record<ListName, string[]> }) {
    return (
        <>
            <Head title="Checklists" />
            <PageHeader title="Checklists" description="The columns of the onboarding, document and clearance grids. Add a new document type here and it appears for everyone. Items switched off are hidden but their history is kept." />
            <Tabs defaultValue="document" className="px-4 py-4 md:px-6">
                <TabsList>
                    {(Object.keys(listLabel) as ListName[]).map((l) => <TabsTrigger key={l} value={l}>{listLabel[l]} ({lists[l].length})</TabsTrigger>)}
                </TabsList>
                {(Object.keys(listLabel) as ListName[]).map((l) => (
                    <TabsContent key={l} value={l}>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[900px] text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-muted-foreground">
                                        <th className="w-24 py-2 font-semibold">Order</th>
                                        <th className="py-2 font-semibold">Label</th>
                                        <th className="py-2 font-semibold">Description</th>
                                        <th className="w-56 py-2 font-semibold">Values</th>
                                        <th className="w-20 py-2 text-right font-semibold">Filled in</th>
                                        <th className="w-32 py-2 font-semibold">Status</th>
                                        <th className="w-20 py-2" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y">{lists[l].map((i) => <ItemRow key={i.id} item={i} />)}</tbody>
                            </table>
                        </div>
                        <AddItem list={l} sets={setsPerList[l]} />
                    </TabsContent>
                ))}
            </Tabs>
        </>
    );
}
