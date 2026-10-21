import { Head, router, useForm } from '@inertiajs/react';
import { Check, Download, Upload } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { PageHeader, Section } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { plural } from '@/lib/format';

/** app/Services/Import/SpreadsheetImport.php */
type Change = { name: string; code: string; label: string; old: string | null; new: string; replaces: boolean };
type Props = {
    kind: 'details' | 'assets';
    meta: { title: string; description: string; back: { href: string; label: string }; tips: string[]; key: string };
    upload: { name: string } | null;
    plan: { rows: number; matched: number; records: number; changes: Change[]; problems: { row: number; name: string; message: string }[] } | null;
    maxKb: number;
};

export default function SpreadsheetImportPage({ kind, meta, upload, plan, maxKb }: Props) {
    const form = useForm<{ file: File | null }>({ file: null });
    const replaced = plan?.changes.filter((c) => c.replaces).length ?? 0;
    const noun = kind === 'assets' ? ['asset', 'assets'] : ['person', 'people'];

    return (
        <>
            <Head title={meta.title} />
            <PageHeader back={meta.back} title={meta.title} help={meta.description} />

            <div className="grid max-w-5xl gap-10 px-4 py-6 md:px-6">
                {!plan && (
                    <ol className="grid gap-6 md:grid-cols-3">
                        <li className="rounded-lg border p-4">
                            <p className="font-semibold">1. Download the sheet</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {kind === 'details' ? 'One row per current employee, with what is on file now.' : 'The columns to fill in, one asset per row.'}
                            </p>
                            <Button variant="outline" className="mt-3" asChild><a href={`/imports/${kind}/template`}><Download /> Download for Excel</a></Button>
                        </li>
                        <li className="rounded-lg border p-4">
                            <p className="font-semibold">2. Fill it in</p>
                            <ul className="mt-1 grid gap-1 text-sm text-muted-foreground">{meta.tips.map((t) => <li key={t}>{t}</li>)}</ul>
                        </li>
                        <li className="rounded-lg border p-4">
                            <p className="font-semibold">3. Upload it</p>
                            <p className="mt-1 text-sm text-muted-foreground">Save as .xlsx or .csv. You'll see every change before anything is saved.</p>
                            <form className="mt-3 grid gap-2" onSubmit={(e) => { e.preventDefault(); form.post(`/imports/${kind}`, { forceFormData: true }); }}>
                                <Field label="File" htmlFor="i-file" error={form.errors.file} hint={`Up to ${Math.round(maxKb / 1024)} MB.`}>
                                    <Input id="i-file" type="file" accept=".xlsx,.csv" required onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} />
                                </Field>
                                <div><Button type="submit" disabled={!form.data.file || form.processing}><Upload /> Upload and preview</Button></div>
                            </form>
                        </li>
                    </ol>
                )}

                {plan && upload && (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-info-soft px-4 py-3">
                            <p className="text-sm">
                                <span className="font-semibold">{upload.name}</span>: {plan.rows} {plan.rows === 1 ? 'row' : 'rows'} read.{' '}
                                {plan.changes.length ? `${plural(plan.changes.length, kind === 'assets' ? 'asset' : 'detail')} to save` : 'Nothing new to save'}
                                {kind === 'details' && plan.changes.length ? ` for ${plural(plan.records, noun[0], noun[1])}` : ''}
                                {replaced ? `, ${replaced} replacing what is on file` : ''}.
                                {plan.problems.length > 0 && ` ${plural(plan.problems.length, 'problem')} (listed below) will be skipped.`}
                            </p>
                            <div className="flex gap-2">
                                <Button variant="ghost" onClick={() => router.delete(`/imports/${kind}`)}>Discard</Button>
                                <Button disabled={!plan.changes.length} onClick={() => router.post(`/imports/${kind}/apply`, {}, { preserveScroll: true })}>
                                    <Check /> Save {plural(plan.changes.length, kind === 'assets' ? 'asset' : 'detail')}
                                </Button>
                            </div>
                        </div>

                        {plan.problems.length > 0 && (
                            <Section title="Can't be used" description="Fix these in the file and upload it again, or save the rest now.">
                                <ul className="divide-y rounded-lg border text-sm">
                                    {plan.problems.map((p, i) => (
                                        <li key={i} className="grid gap-0.5 px-3 py-2 sm:grid-cols-[5rem_14rem_1fr]">
                                            <span className="text-muted-foreground tabular-nums">Row {p.row}</span>
                                            <span className="font-semibold">{p.name}</span>
                                            <span className="text-missing">{p.message}</span>
                                        </li>
                                    ))}
                                </ul>
                            </Section>
                        )}

                        <Section title="What will be saved">
                            {plan.changes.length === 0 ? <EmptyState title="Nothing in the file is new." /> : (
                                <div className="overflow-x-auto rounded-lg border">
                                    <table className="w-full min-w-[44rem] text-sm">
                                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                            <tr>
                                                <th className="px-3 py-2 font-semibold">{kind === 'assets' ? 'Tag' : 'Employee'}</th>
                                                <th className="px-3 py-2 font-semibold">What</th>
                                                {kind === 'details' && <th className="px-3 py-2 font-semibold">On file now</th>}
                                                <th className="px-3 py-2 font-semibold">From the file</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {plan.changes.map((c, i) => (
                                                <tr key={i}>
                                                    <td className="px-3 py-2"><span className="font-semibold">{c.name}</span> <span className="text-xs text-muted-foreground">{c.code}</span></td>
                                                    <td className="px-3 py-2">{c.label}</td>
                                                    {kind === 'details' && <td className="px-3 py-2 text-muted-foreground">{c.old ?? 'blank'}</td>}
                                                    <td className="px-3 py-2">{c.new} {c.replaces && <StatusPill value="Replaces" tone="pending" className="ml-1" />}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </Section>
                    </>
                )}
            </div>
        </>
    );
}
