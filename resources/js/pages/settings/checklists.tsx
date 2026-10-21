import { Head, useForm } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import type { FormEvent } from 'react';
import { Field } from '@/components/field';
import { NativeSelect } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { SettingsTabs } from '@/components/section-tabs';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { OptionSets } from '@/types';

type Item = { id: number; key: string; label: string; option_set: string; description: string | null; sort_order: number; is_active: boolean; used: number; required: boolean };
type ListName = 'onboarding' | 'document' | 'offboarding';
type Probation = { days: number; checkins: { key: string; label: string; days: number }[] };

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

/** Probation length and check-in days for everyone (ChecklistItemController::updateProbation). */
function ProbationTimeline({ probation }: { probation: Probation }) {
    const form = useForm({
        days: probation.days,
        checkins: Object.fromEntries(probation.checkins.map((c) => [c.key, c.days])) as Record<string, number>,
        update_open: true,
    });
    const submit = (e: FormEvent) => { e.preventDefault(); form.put('/settings/probation', { preserveScroll: true, onSuccess: () => form.setDefaults() }); };
    const errors = form.errors as Record<string, string>;
    return (
        <form onSubmit={submit} className="mb-6 grid gap-4 rounded-lg border p-4">
            <div>
                <h2 className="font-bold">Probation and check-ins</h2>
                <p className="text-sm text-muted-foreground">
                    For everyone, counted from the start date. A person's own end date can still be changed or extended on the onboarding tracker or their profile.
                    If you change a check-in day, rename its label below to match (e.g. "45-day check-in").
                </p>
            </div>
            <div className="flex flex-wrap items-start gap-4">
                <Field label="Probation length (days)" htmlFor="prob-days" error={form.errors.days}>
                    <Input id="prob-days" type="number" min={7} max={730} className="w-36" value={form.data.days} onChange={(e) => form.setData('days', Number(e.target.value))} />
                </Field>
                {probation.checkins.map((c) => (
                    <Field key={c.key} label={`${c.label} on day`} htmlFor={`ci-${c.key}`} error={errors[`checkins.${c.key}`]}>
                        <Input id={`ci-${c.key}`} type="number" min={1} max={form.data.days} className="w-36" value={form.data.checkins[c.key]}
                            onChange={(e) => form.setData('checkins', { ...form.data.checkins, [c.key]: Number(e.target.value) })} />
                    </Field>
                ))}
            </div>
            {errors.checkins && <p className="text-sm text-missing">{errors.checkins}</p>}
            {form.data.days !== probation.days && (
                <label className="flex items-start gap-2 text-sm">
                    <input type="checkbox" className="mt-0.5 size-4 accent-cascade" checked={form.data.update_open} onChange={(e) => form.setData('update_open', e.target.checked)} />
                    <span>Also move the end date for open probations still on {probation.days} days. <span className="text-muted-foreground">People who were extended, passed, or had their end date set by hand keep theirs.</span></span>
                </label>
            )}
            <Button type="submit" className="justify-self-start" disabled={!form.isDirty || form.processing}>Save timeline</Button>
        </form>
    );
}

export default function Checklists({ lists, setsPerList, probation }: { lists: Record<ListName, Item[]>; optionSets: OptionSets; setsPerList: Record<ListName, string[]>; probation: Probation }) {
    return (
        <>
            <Head title="Checklists" />
            <PageHeader title="Checklists" tabs={<SettingsTabs />} />
            <Tabs defaultValue="document" className="px-4 py-4 md:px-6">
                <TabsList>
                    {(Object.keys(listLabel) as ListName[]).map((l) => <TabsTrigger key={l} value={l}>{listLabel[l]} ({lists[l].length})</TabsTrigger>)}
                </TabsList>
                {(Object.keys(listLabel) as ListName[]).map((l) => (
                    <TabsContent key={l} value={l}>
                        {l === 'onboarding' && <ProbationTimeline probation={probation} />}
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
