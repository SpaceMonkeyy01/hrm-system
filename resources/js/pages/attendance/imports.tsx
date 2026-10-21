import { Head, Link, router } from '@inertiajs/react';
import { Check, CircleCheck, FileSearch, Info, Link2, TriangleAlert, X } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { MachineSyncBar } from '@/components/attendance';
import type { MachineStatus } from '@/components/attendance';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { PageHeader, Section } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate, formatDateTime, plural } from '@/lib/format';

type Import = { id: number; source: string; original_name: string; punches_found: number; punches_added: number; date_from: string | null; date_to: string | null; uploaded_by: string | null; uploaded_at: string | null };
type Source = 'machine' | 'hrmy';
type Props = { imports: Import[]; unlinkedCount: number; sources: Record<Source, string>; pending: Pending | null; machine: MachineStatus; maxKb: number };

type Warning = { tone: 'warn' | 'info'; text: string };
type Preview = {
    source: Source; source_label: string; format: string; punches: number; new: number; people: number; linked: number;
    unlinked: { id: string; name: string | null }[]; days: { date: string; punches: number; new: number; people: number; single: number }[];
    skipped: number; skipped_examples: string[]; warnings: Warning[];
};
type Pending = { expected_date: string | null; files: { id: string; name: string; error: string | null; preview: Preview | null }[] };

const longDay = new Intl.DateTimeFormat('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
const dayName = (d: string) => { const [y, m, dd] = d.split('-').map(Number); return longDay.format(new Date(y, m - 1, dd)); };
const yesterday = () => { const d = new Date(); d.setDate(d.getDate() - 1); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };

function UploadForm({ maxKb }: { maxKb: number }) {
    const [files, setFiles] = useState<File[]>([]);
    const [expected, setExpected] = useState(yesterday);
    const [error, setError] = useState<string>();
    const [busy, setBusy] = useState(false);
    const [key, setKey] = useState(0);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!files.length) return;
        router.post('/attendance/imports/check', { files, expected_date: expected || null }, {
            forceFormData: true, preserveScroll: true,
            onStart: () => setBusy(true), onFinish: () => setBusy(false),
            onSuccess: () => { setFiles([]); setError(undefined); setKey((k) => k + 1); },
            onError: (e) => setError(Object.values(e)[0]),
        });
    };
    return (
        <form onSubmit={submit} className="grid max-w-xl gap-3 rounded-lg border p-4">
            <Field label="Attendance files" htmlFor="att-file" error={error}
                hint={`The machine's "Full day log" .xls and the HR.my export (Time Clock Records or Attendance Transactions), up to ${Math.round(maxKb / 1024)} MB each. You can pick both at once. Nothing is saved until you have checked what the app found.`}>
                <Input key={key} id="att-file" type="file" accept=".xls,.xlsx" multiple required onChange={(e) => setFiles(Array.from(e.target.files ?? []))} />
            </Field>
            <Field label="Which day should these be?" htmlFor="att-expected" hint="Optional. You get a warning if a file has a different day.">
                <Input id="att-expected" type="date" className="w-48" value={expected} onChange={(e) => setExpected(e.target.value)} />
            </Field>
            <Button type="submit" className="justify-self-start" disabled={!files.length || busy}>
                <FileSearch /> {busy ? 'Reading the files' : files.length > 1 ? `Check ${files.length} files` : 'Check file'}
            </Button>
        </form>
    );
}

function PendingFiles({ pending }: { pending: Pending }) {
    const [busy, setBusy] = useState(false);
    const ready = pending.files.filter((f) => f.preview);
    const post = (url: string, id?: string) => router.post(url, id ? { id } : {}, {
        preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false),
        onError: (e) => toast.error(Object.values(e)[0] ?? 'Not done.'),
    });
    return (
        <Section title="Check before importing" help="What the app read from each file. Nothing is saved until you press Import.">
            <div className="grid gap-4">
                {pending.files.map((f) => (
                    <article key={f.id} className="rounded-lg border">
                        <header className="flex flex-wrap items-start justify-between gap-3 border-b px-4 py-3">
                            <div className="min-w-0">
                                <p className="font-semibold">{f.preview ? `${f.preview.source_label} · ${f.preview.format}` : "Can't import this file"}</p>
                                <p className="truncate text-xs text-muted-foreground">{f.name}</p>
                            </div>
                            <div className="flex gap-2">
                                <Button variant="ghost" size="sm" disabled={busy} onClick={() => post('/attendance/imports/discard', f.id)}><X /> {f.preview ? 'Cancel' : 'Remove'}</Button>
                                {f.preview && <Button size="sm" disabled={busy} onClick={() => post('/attendance/imports/confirm', f.id)}><Check /> Import</Button>}
                            </div>
                        </header>
                        {f.error && <p className="px-4 py-3 text-sm text-missing">{f.error}</p>}
                        {f.preview && <PreviewBody p={f.preview} />}
                    </article>
                ))}
                {ready.length > 1 && (
                    <div className="flex gap-2">
                        <Button disabled={busy} onClick={() => post('/attendance/imports/confirm')}><Check /> Import all {ready.length} files</Button>
                        <Button variant="ghost" disabled={busy} onClick={() => post('/attendance/imports/discard')}>Cancel all</Button>
                    </div>
                )}
            </div>
        </Section>
    );
}

function PreviewBody({ p }: { p: Preview }) {
    const noun = p.source === 'machine' ? ['machine number', 'machine numbers'] : ['HR.my ID', 'HR.my IDs'];
    return (
        <div className="grid gap-3 px-4 py-3 text-sm">
            <div>
                {p.days.map((d) => (
                    <p key={d.date}>
                        <span className="font-semibold">{dayName(d.date)}</span>
                        <span className="text-muted-foreground"> · {plural(d.punches, 'punch', 'punches')} for {plural(d.people, 'person', 'people')}{d.new < d.punches ? `, ${d.new} new` : ''}</span>
                    </p>
                ))}
            </div>
            <ul className="grid gap-1.5">
                <li className="flex gap-2"><CircleCheck className="mt-0.5 size-4 shrink-0 text-done" />{p.linked} of {plural(p.people, 'person', 'people')} in the file are linked to employees.</li>
                {p.unlinked.length > 0 && (
                    <li className="flex gap-2"><TriangleAlert className="mt-0.5 size-4 shrink-0 text-pending" />
                        <span>
                            {p.unlinked.length} {p.unlinked.length === 1 ? noun[0] : noun[1]} not linked yet: their punches are saved but show on nobody's attendance until you link them below.{' '}
                            <span className="text-muted-foreground">{p.unlinked.slice(0, 8).map((u) => (u.name ? `${u.id} ${u.name}` : u.id)).join(', ')}{p.unlinked.length > 8 ? `, and ${p.unlinked.length - 8} more` : ''}.</span>
                        </span>
                    </li>
                )}
                {p.warnings.map((w) => (
                    <li key={w.text} className="flex gap-2">
                        {w.tone === 'warn' ? <TriangleAlert className="mt-0.5 size-4 shrink-0 text-missing" /> : <Info className="mt-0.5 size-4 shrink-0 text-info" />}
                        <span className={w.tone === 'warn' ? 'font-semibold' : ''}>{w.text}</span>
                    </li>
                ))}
                {p.skipped > 0 && (
                    <li className="flex gap-2"><TriangleAlert className="mt-0.5 size-4 shrink-0 text-pending" />
                        <span>{plural(p.skipped, 'row')} could not be read and will be left out. <span className="text-muted-foreground">{p.skipped_examples.join('; ')}</span></span>
                    </li>
                )}
            </ul>
        </div>
    );
}

export default function AttendanceImports({ imports, unlinkedCount, sources, pending, machine, maxKb }: Props) {
    return (
        <>
            <Head title="Attendance files" />
            <PageHeader back={{ href: '/attendance', label: 'Attendance' }} title="Attendance files" help="Upload the machine and HR.my files. Link their IDs to employees on Attendance links." />
            <div className="grid gap-10 px-4 py-6 md:px-6">
                <MachineSyncBar machine={machine} canSync showRecent />
                <UploadForm maxKb={maxKb} />
                {pending && pending.files.length > 0 && <PendingFiles pending={pending} />}

                <Section title="Link IDs to employees" help="Each person has a number on the machine and an ID in HR.my. Linking them once connects all their punches, past and future.">
                    {unlinkedCount === 0
                        ? <EmptyState title="Every number in the attendance files belongs to someone." />
                        : (
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border px-4 py-3 text-sm">
                                <p>{plural(unlinkedCount, 'machine number or HR.my ID', 'machine numbers and HR.my IDs')} punch but belong to nobody yet.</p>
                                <Button asChild size="sm"><Link href="/attendance/links?tab=numbers"><Link2 /> Link them on Attendance links</Link></Button>
                            </div>
                        )}
                </Section>

                <Section title="Uploaded files">
                    {imports.length === 0 ? <EmptyState title="No files uploaded yet." /> : (
                        <ul className="divide-y rounded-lg border">
                            {imports.map((i) => (
                                <li key={i.id} className="grid gap-0.5 px-3 py-2 text-sm sm:grid-cols-[1fr_auto]">
                                    <span>
                                        <span className="font-semibold">{i.original_name}</span> <span className="text-xs text-muted-foreground">{sources[i.source as Source] ?? i.source}</span>
                                        <span className="block text-xs text-muted-foreground">
                                            {i.date_from === i.date_to ? formatDate(i.date_from) : `${formatDate(i.date_from)} to ${formatDate(i.date_to)}`}
                                            {` · ${i.punches_found} punches, ${i.punches_added} new`}
                                        </span>
                                    </span>
                                    <span className="text-xs text-muted-foreground">{[i.uploaded_by, formatDateTime(i.uploaded_at)].filter(Boolean).join(', ')}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            </div>
        </>
    );
}
