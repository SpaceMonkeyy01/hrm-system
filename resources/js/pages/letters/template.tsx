import { Head, router, useForm } from '@inertiajs/react';
import { TriangleAlert, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Field } from '@/components/field';
import { NativeSelect } from '@/components/native-select';
import { PageHeader, Section } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { plural } from '@/lib/format';

type Template = { id: number; name: string; subject: string | null; body: string; details_label: string | null; checklist_key: string | null; conduct_type: string | null; is_active: boolean };
type Option = { value: string; label: string };
type Props = { template: Template | null; issued: number; fields: Record<string, string>; checklistItems: Option[]; conductTypes: Record<string, string> };
type Person = { id: number; name: string; code: string | null; department: string | null; status: string };
type Preview = { body: string; missing: string[]; reference: string };

/** Laravel checks this header on posts made outside Inertia (the preview). */
const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

/** One person to preview the letter for: type a name or code, pick a match. */
function PersonPicker({ person, onPick }: { person: Person | null; onPick: (p: Person | null) => void }) {
    const [q, setQ] = useState('');
    const [hits, setHits] = useState<Person[]>([]);
    const seq = useRef(0);

    useEffect(() => {
        const term = q.trim();
        const mine = ++seq.current;
        if (term.length < 2) {
            setHits([]);
            return;
        }
        const t = setTimeout(() => {
            fetch(`/search/employees?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((r) => (r.ok ? r.json() : []))
                .then((data: Person[]) => mine === seq.current && setHits(data));
        }, 150);
        return () => clearTimeout(t);
    }, [q]);

    if (person) {
        return (
            <div className="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm">
                <span><span className="font-semibold">{person.name}</span> <span className="text-xs text-muted-foreground">{[person.code, person.department].filter(Boolean).join(', ')}</span></span>
                <Button type="button" variant="ghost" size="icon" className="size-7" aria-label="Pick someone else" onClick={() => onPick(null)}><X /></Button>
            </div>
        );
    }
    return (
        <div className="grid gap-2">
            <Input id="preview-person" value={q} placeholder="Type a name or code" autoComplete="off" onChange={(e) => setQ(e.target.value)} />
            {hits.length > 0 && (
                <ul className="max-h-48 overflow-y-auto rounded-md border p-1" aria-label="Matches">
                    {hits.map((h) => (
                        <li key={h.id}>
                            <button type="button" onClick={() => { onPick(h); setQ(''); setHits([]); }}
                                className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-accent">
                                <span className="font-semibold">{h.name}</span>
                                <span className="text-xs text-muted-foreground">{[h.code, h.department, h.status === 'exited' ? 'left' : null].filter(Boolean).join(', ')}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export default function LetterTemplateEditor({ template: t, issued, fields, checklistItems, conductTypes }: Props) {
    const form = useForm({
        name: t?.name ?? '', subject: t?.subject ?? '', body: t?.body ?? '', details_label: t?.details_label ?? '',
        checklist_key: t?.checklist_key ?? '', conduct_type: t?.conduct_type ?? '', is_active: t?.is_active ?? true,
    });
    const bodyRef = useRef<HTMLTextAreaElement>(null);
    const [person, setPerson] = useState<Person | null>(null);
    const [details, setDetails] = useState('');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);

    // Leaving with unsaved changes asks first (links inside the app, and closing or reloading the tab).
    useEffect(() => {
        if (!form.isDirty || form.processing) return;
        const off = router.on('before', (e) => {
            if (e.detail.visit.method === 'get' && !window.confirm('Leave without saving your changes to this template?')) e.preventDefault();
        });
        const unload = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', unload);
        return () => { off(); window.removeEventListener('beforeunload', unload); };
    }, [form.isDirty, form.processing]);

    // The preview follows the text as it is typed (half a second after the last change).
    useEffect(() => {
        if (!person || !form.data.body.trim()) {
            setPreview(null);
            return;
        }
        let live = true;
        const timer = setTimeout(() => {
            fetch('/letters/templates/preview', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
                body: JSON.stringify({ employee_id: person.id, body: form.data.body, details: details || null }),
            })
                .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
                .then((p: Preview) => { if (live) { setPreview(p); setPreviewError(null); } })
                .catch(() => live && setPreviewError("The preview couldn't load. Try again in a moment."));
        }, 500);
        return () => { live = false; clearTimeout(timer); };
    }, [person, form.data.body, details]);

    /** Puts {{field}} where the cursor is in the letter text. */
    const insertField = (key: string) => {
        const el = bodyRef.current;
        const token = `{{${key}}}`;
        const body = form.data.body;
        const start = el?.selectionStart ?? body.length;
        const end = el?.selectionEnd ?? body.length;
        form.setData('body', body.slice(0, start) + token + body.slice(end));
        requestAnimationFrame(() => {
            el?.focus();
            el?.setSelectionRange(start + token.length, start + token.length);
        });
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, subject: d.subject || null, details_label: d.details_label || null, checklist_key: d.checklist_key || null, conduct_type: d.conduct_type || null }));
        if (t) form.put(`/letters/templates/${t.id}`);
        else form.post('/letters/templates');
    };

    const title = t ? t.name : 'New template';
    return (
        <>
            <Head title={title} />
            <PageHeader back={{ href: '/letters/templates', label: 'Letter templates' }} title={title}
                description={t ? (issued ? `Issued ${plural(issued, 'time')}. Changes apply to letters issued from now on.` : 'Not issued yet.') : undefined}
                help="Write the letter once; fields in double curly brackets are filled from the person's profile when HR issues it. Pick someone under Preview to see it filled in." />

            <form onSubmit={submit} className="grid gap-8 px-4 py-6 md:px-6 xl:grid-cols-[minmax(0,1fr)_18rem]">
                <div className="grid min-w-0 content-start gap-8">
                    <Section title="The letter">
                        <div className="grid gap-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Name" htmlFor="name" error={form.errors.name} hint="What HR picks when issuing.">
                                    <Input id="name" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                </Field>
                                <Field label="Subject line on the letter" htmlFor="subject" error={form.errors.subject}>
                                    <Input id="subject" value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />
                                </Field>
                            </div>
                            <Field label="Text" htmlFor="body" error={form.errors.body} hint="Leave an empty line between paragraphs. Click a field on the right to put it where the cursor is.">
                                <Textarea id="body" ref={bodyRef} rows={16} required className="font-mono text-[13px] leading-relaxed" value={form.data.body}
                                    onChange={(e) => form.setData('body', e.target.value)} />
                            </Field>
                        </div>
                    </Section>

                    <Section title="When it is issued">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Ask for details" htmlFor="details_label" error={form.errors.details_label} hint="A question for {{details}}, e.g. What happened. Empty = not asked.">
                                <Input id="details_label" value={form.data.details_label} onChange={(e) => form.setData('details_label', e.target.value)} />
                            </Field>
                            <Field label="Tick this offboarding item" htmlFor="checklist_key" error={form.errors.checklist_key}>
                                <NativeSelect id="checklist_key" value={form.data.checklist_key} placeholder="Nothing" options={checklistItems}
                                    onChange={(e) => form.setData('checklist_key', e.target.value)} />
                            </Field>
                            <Field label="Add to the conduct record" htmlFor="conduct_type" error={form.errors.conduct_type}
                                hint="For warning and appreciation letters: the person sees it on My page and acknowledges it.">
                                <NativeSelect id="conduct_type" value={form.data.conduct_type} placeholder="Nothing"
                                    options={Object.entries(conductTypes).map(([value, label]) => ({ value, label }))} onChange={(e) => form.setData('conduct_type', e.target.value)} />
                            </Field>
                            <label className="flex items-center gap-2 self-center text-sm">
                                <input type="checkbox" className="size-4 accent-cascade" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                                Offer this letter when issuing
                            </label>
                        </div>
                    </Section>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit" disabled={form.processing || (!!t && !form.isDirty)}>{t ? 'Save template' : 'Add template'}</Button>
                        <Button type="button" variant="ghost" onClick={() => router.visit('/letters/templates')}>Cancel</Button>
                        {form.isDirty && <span className="text-sm text-muted-foreground">Unsaved changes</span>}
                    </div>

                    <Section title="Preview" help="The text above filled in for one person, as the draft would be. Nothing is saved or issued.">
                        <div className="grid gap-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Preview for" htmlFor="preview-person">
                                    <PersonPicker person={person} onPick={setPerson} />
                                </Field>
                                {form.data.details_label && (
                                    <Field label={form.data.details_label} htmlFor="preview-details" hint="Only for the preview.">
                                        <Input id="preview-details" value={details} onChange={(e) => setDetails(e.target.value)} />
                                    </Field>
                                )}
                            </div>
                            {previewError && <p className="text-sm text-missing">{previewError}</p>}
                            {person && preview && (
                                <>
                                    {preview.missing.length > 0 && (
                                        <div className="flex gap-2 rounded-lg border border-pending/40 bg-pending-soft px-3 py-2 text-sm">
                                            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-pending" />
                                            <p>Not on file for {person.name}: {preview.missing.join(', ')}. They show in [brackets].</p>
                                        </div>
                                    )}
                                    <div className="w-full max-w-[52rem] rounded-sm border bg-white p-8 shadow-sm md:p-12" aria-label="Letter preview">
                                        <p className="mb-6 text-xs text-muted-foreground">Ref. {preview.reference} (the next free number)</p>
                                        {form.data.subject && <p className="mb-4 text-base font-bold">{form.data.subject}</p>}
                                        <div className="text-[15px] leading-relaxed whitespace-pre-wrap">{preview.body}</div>
                                    </div>
                                </>
                            )}
                        </div>
                    </Section>
                </div>

                <aside className="self-start rounded-lg border p-4 xl:sticky xl:top-4">
                    <h2 className="mb-2 font-bold">Fields you can use</h2>
                    <ul className="grid gap-1">
                        {Object.entries(fields).map(([key, label]) => (
                            <li key={key}>
                                <button type="button" onClick={() => insertField(key)} title={`Put {{${key}}} in the text`}
                                    className="flex w-full flex-wrap items-baseline justify-between gap-x-2 rounded px-1 py-0.5 text-left hover:bg-accent">
                                    <code className="rounded bg-muted px-1 text-[12px]">{`{{${key}}}`}</code>
                                    <span className="text-xs text-muted-foreground">{label}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                    <p className="mt-3 text-xs text-muted-foreground">A field with nothing on file shows in [brackets] in the draft, so HR can fill it in before issuing.</p>
                </aside>
            </form>
        </>
    );
}
