import { Head, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Field } from '@/components/field';
import { NativeSelect } from '@/components/native-select';
import { PageHeader, Section } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

type Template = { id: number; name: string; subject: string | null; body: string; details_label: string | null; checklist_key: string | null; is_active: boolean };
type Option = { value: string; label: string };
type Props = { templates: Template[]; fields: Record<string, string>; checklistItems: Option[] };

function TemplateForm({ t, checklistItems, onDone }: { t: Template | null; checklistItems: Option[]; onDone?: () => void }) {
    const form = useForm({
        name: t?.name ?? '', subject: t?.subject ?? '', body: t?.body ?? '', details_label: t?.details_label ?? '',
        checklist_key: t?.checklist_key ?? '', is_active: t?.is_active ?? true,
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, subject: d.subject || null, details_label: d.details_label || null, checklist_key: d.checklist_key || null }));
        const opts = { preserveScroll: true, onSuccess: () => { form.setDefaults(); onDone?.(); } };
        if (t) form.put(`/letters/templates/${t.id}`, opts);
        else form.post('/letters/templates', opts);
    };
    return (
        <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Name" htmlFor={`n-${t?.id ?? 'new'}`} error={form.errors.name}>
                    <Input id={`n-${t?.id ?? 'new'}`} required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                </Field>
                <Field label="Subject line on the letter" htmlFor={`s-${t?.id ?? 'new'}`} error={form.errors.subject}>
                    <Input id={`s-${t?.id ?? 'new'}`} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />
                </Field>
            </div>
            <Field label="Text" htmlFor={`b-${t?.id ?? 'new'}`} error={form.errors.body} hint="Fields in double curly brackets are filled from the profile, e.g. {{name}}. Leave an empty line between paragraphs.">
                <Textarea id={`b-${t?.id ?? 'new'}`} rows={10} required className="font-mono text-[13px]" value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Ask for details when issuing" htmlFor={`d-${t?.id ?? 'new'}`} error={form.errors.details_label} hint="A question for {{details}}, e.g. What happened. Empty = not asked.">
                    <Input id={`d-${t?.id ?? 'new'}`} value={form.data.details_label} onChange={(e) => form.setData('details_label', e.target.value)} />
                </Field>
                <Field label="Issuing it ticks this offboarding item" htmlFor={`c-${t?.id ?? 'new'}`} error={form.errors.checklist_key}>
                    <NativeSelect id={`c-${t?.id ?? 'new'}`} value={form.data.checklist_key} placeholder="Nothing" options={checklistItems}
                        onChange={(e) => form.setData('checklist_key', e.target.value)} />
                </Field>
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" className="size-4 accent-cascade" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                    Offer this letter when issuing
                </label>
                <Button type="submit" disabled={form.processing || (!!t && !form.isDirty)}>{t ? 'Save template' : 'Add template'}</Button>
            </div>
        </form>
    );
}

export default function LetterTemplates({ templates, fields, checklistItems }: Props) {
    const [adding, setAdding] = useState(false);
    return (
        <>
            <Head title="Letter templates" />
            <PageHeader title="Letter templates" description="The letters HR can issue from a profile: experience, verification, appointment, warning and any you add."
                actions={!adding && <Button onClick={() => setAdding(true)}><Plus /> Add template</Button>} />
            <div className="grid gap-8 px-4 py-6 md:px-6 xl:grid-cols-[minmax(0,1fr)_18rem]">
                <div className="grid content-start gap-6">
                    {adding && (
                        <Section title="New template"><TemplateForm t={null} checklistItems={checklistItems} onDone={() => setAdding(false)} /></Section>
                    )}
                    {templates.map((t) => (
                        <Section key={t.id} title={<span className="flex items-center gap-2">{t.name} {!t.is_active && <StatusPill value="Switched off" tone="neutral" />}</span>}>
                            <TemplateForm t={t} checklistItems={checklistItems} />
                        </Section>
                    ))}
                </div>
                <aside className="self-start rounded-lg border p-4 xl:sticky xl:top-4">
                    <h2 className="mb-2 font-bold">Fields you can use</h2>
                    <ul className="grid gap-1.5 text-sm">
                        {Object.entries(fields).map(([key, label]) => (
                            <li key={key} className="flex flex-wrap items-baseline justify-between gap-x-2">
                                <code className="rounded bg-muted px-1 text-[12px]">{`{{${key}}}`}</code>
                                <span className="text-xs text-muted-foreground">{label}</span>
                            </li>
                        ))}
                    </ul>
                    <p className="mt-3 text-xs text-muted-foreground">A field with nothing on file shows in [brackets] in the draft, so HR can fill it in before issuing.</p>
                </aside>
            </div>
        </>
    );
}
