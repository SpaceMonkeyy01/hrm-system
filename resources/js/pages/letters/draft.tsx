import { Head, router, useForm } from '@inertiajs/react';
import { FileText, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Avatar } from '@/components/avatar';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { NativeSelect } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

type Props = {
    employee: { id: number; name: string; code: string; photo: string | null };
    templates: { id: number; name: string; details_label: string | null }[];
    template: { id: number; name: string; subject: string | null; details_label: string | null } | null;
    details: string;
    draft: { body: string; missing: string[] } | null;
    reference: string;
    /** they have a login, so issuing can email them */
    canEmail: boolean;
};

export default function LetterDraft({ employee, templates, template, details, draft, reference, canEmail }: Props) {
    const [extra, setExtra] = useState(details);
    const form = useForm({ template_id: template?.id ?? null, subject: template?.subject ?? '', body: draft?.body ?? '', notify: canEmail });

    // A new template or new details give a fresh draft from the server (it fills the fields).
    useEffect(() => {
        form.setData((d) => ({ ...d, template_id: template?.id ?? null, subject: template?.subject ?? '', body: draft?.body ?? '' }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [template?.id, draft?.body]);

    const redraft = (changes: { template?: number; details?: string }) =>
        router.get(`/employees/${employee.id}/letters/new`, { template: changes.template ?? template?.id, details: changes.details ?? extra },
            { preserveState: true, preserveScroll: true, replace: true, only: ['template', 'details', 'draft'] });

    useEffect(() => {
        if (extra === details) return;
        const t = setTimeout(() => redraft({ details: extra }), 500);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [extra]);

    return (
        <>
            <Head title={`Letter for ${employee.name}`} />
            <PageHeader back={{ href: `/employees/${employee.id}`, label: employee.name }}
                title={<span className="flex items-center gap-3"><Avatar name={employee.name} photo={employee.photo} className="size-10 text-sm" />Issue a letter</span>}
                description={`For ${employee.name} (${employee.code}), reference ${reference}.`}
                help="Check the draft, change anything you need, then issue it." />

            {!template || !draft ? (
                <div className="p-4 md:p-6"><EmptyState icon={FileText} title="No letter templates are switched on.">Add one under Letter templates.</EmptyState></div>
            ) : (
                <div className="grid gap-8 px-4 py-6 md:px-6 lg:grid-cols-[20rem_minmax(0,1fr)]">
                    <div className="grid content-start gap-4">
                        <Field label="Letter" htmlFor="l-template">
                            <NativeSelect id="l-template" value={template.id} options={templates.map((t) => ({ value: t.id, label: t.name }))}
                                onChange={(e) => redraft({ template: Number(e.target.value) })} />
                        </Field>
                        {template.details_label && (
                            <Field label={template.details_label} htmlFor="l-details" hint="Goes where the template says {{details}}.">
                                <Textarea id="l-details" rows={5} value={extra} onChange={(e) => setExtra(e.target.value)} />
                            </Field>
                        )}
                        {draft.missing.length > 0 && (
                            <div className="rounded-lg border border-[#ecd29c] bg-pending-soft px-3 py-2.5 text-sm">
                                <p className="flex items-center gap-1.5 font-semibold"><TriangleAlert className="size-4" /> Not on file</p>
                                <p className="mt-1">{draft.missing.join(', ')}. They show in [brackets] in the letter: type them in, or add them to the profile and pick the letter again.</p>
                            </div>
                        )}
                        {canEmail ? (
                            <label className="flex items-start gap-2 text-sm">
                                <input type="checkbox" className="mt-0.5 size-4 accent-cascade" checked={form.data.notify} onChange={(e) => form.setData('notify', e.target.checked)} />
                                Email {employee.name} that the letter is on their My page (the letter itself isn't in the email)
                            </label>
                        ) : <p className="text-sm text-muted-foreground">{employee.name} has no login yet, so they won't get an email. The letter still shows on their My page once they have one.</p>}
                        <Button disabled={form.processing || !form.data.body.trim()} onClick={() => form.post(`/employees/${employee.id}/letters`)}>
                            <FileText /> Issue letter
                        </Button>
                        {form.errors.body && <p className="text-sm text-missing">{form.errors.body}</p>}
                    </div>

                    {/* The draft on a sheet of paper, editable before it is issued. */}
                    <div className="mx-auto w-full max-w-[52rem] rounded-sm border bg-white p-8 shadow-sm md:p-12">
                        <Input aria-label="Subject" className="mb-4 border-0 border-b px-0 text-base font-bold shadow-none focus-visible:ring-0" value={form.data.subject}
                            placeholder="Subject (optional)" onChange={(e) => form.setData('subject', e.target.value)} />
                        <Textarea aria-label="Letter text" className="min-h-[28rem] resize-y border-0 px-0 text-[15px] leading-relaxed shadow-none focus-visible:ring-0"
                            value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                    </div>
                </div>
            )}
        </>
    );
}
