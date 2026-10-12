import { Head, router } from '@inertiajs/react';
import { Link2, Upload } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { NativeSelect } from '@/components/native-select';
import { PageHeader, Section } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate, formatDateTime, plural } from '@/lib/format';

type Import = { id: number; source: string; original_name: string; punches_found: number; punches_added: number; date_from: string | null; date_to: string | null; uploaded_by: string | null; uploaded_at: string | null };
type Source = 'machine' | 'hrmy';
type Unlinked = { source: Source; device_user_id: string; device_name: string | null; punches: number; last_seen: string; suggestion: number | null; confidence: 'match' | 'check' | 'several' | 'none' };
type Person = { id: number; label: string; machine: string | null; hrmy: string | null };
type Props = { imports: Import[]; unlinked: Unlinked[]; people: Person[]; sources: Record<Source, string>; maxKb: number };

const confidence = {
    match: { label: 'Name matches', tone: 'done' },
    check: { label: 'Check this', tone: 'pending' },
    several: { label: 'Several people', tone: 'missing' },
    none: { label: 'No match', tone: 'neutral' },
} as const;

function UploadForm({ maxKb }: { maxKb: number }) {
    const [file, setFile] = useState<File | null>(null);
    const [error, setError] = useState<string>();
    const [busy, setBusy] = useState(false);
    const [key, setKey] = useState(0);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!file) return;
        router.post('/attendance/imports', { file }, {
            forceFormData: true, preserveScroll: true,
            onStart: () => setBusy(true), onFinish: () => setBusy(false),
            onSuccess: () => { setFile(null); setError(undefined); setKey((k) => k + 1); },
            onError: (e) => setError(e.file ?? Object.values(e)[0]),
        });
    };
    return (
        <form onSubmit={submit} className="grid max-w-xl gap-3 rounded-lg border p-4">
            <Field label="Attendance file" htmlFor="att-file" error={error}
                hint={`The machine's "Full day log" .xls, or HR.my's Time Clock Records export, up to ${Math.round(maxKb / 1024)} MB. The app recognises which one it is. Uploading the same days again only adds new punches.`}>
                <Input key={key} id="att-file" type="file" accept=".xls,.xlsx" required onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
            </Field>
            <Button type="submit" className="justify-self-start" disabled={!file || busy}><Upload /> {busy ? 'Reading the file' : 'Upload punches'}</Button>
        </form>
    );
}

function LinkTable({ source, unlinked, people }: { source: Source; unlinked: Unlinked[]; people: Person[] }) {
    const free = useMemo(() => people.filter((p) => !p[source]), [people, source]);
    const noun = source === 'machine' ? 'machine number' : 'HR.my ID';
    const [pick, setPick] = useState<Record<string, string>>(() =>
        Object.fromEntries(unlinked.map((u) => [u.device_user_id, u.confidence === 'match' && u.suggestion ? String(u.suggestion) : ''])));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const chosen = Object.entries(pick).filter(([, v]) => v);
    const pickedTwice = new Set(chosen.map(([, v]) => v).filter((v, i, all) => all.indexOf(v) !== i));

    const save = () => {
        const links = chosen.map(([device_user_id, employee_id]) => ({ device_user_id, employee_id: Number(employee_id) }));
        router.post('/attendance/link', { source, links }, {
            preserveScroll: true,
            onSuccess: () => setErrors({}),
            onError: (e) => { setErrors(e); toast.error(Object.values(e)[0] ?? 'Not saved.'); },
        });
    };

    return (
        <div className="grid gap-3">
            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full min-w-[48rem] text-sm">
                    <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                        <tr>
                            <th className="px-3 py-2 font-semibold">{source === 'machine' ? 'Machine no.' : 'HR.my ID'}</th>
                            <th className="px-3 py-2 font-semibold">{source === 'machine' ? 'Name on the machine' : 'Name in HR.my'}</th>
                            <th className="px-3 py-2 font-semibold">Punches</th>
                            <th className="px-3 py-2 font-semibold">Suggestion</th>
                            <th className="px-3 py-2 font-semibold">Employee</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {unlinked.map((u, i) => (
                            <tr key={u.device_user_id}>
                                <td className="px-3 py-2 font-semibold tabular-nums">{u.device_user_id}</td>
                                <td className="px-3 py-2">{u.device_name ?? '-'}</td>
                                <td className="px-3 py-2 text-muted-foreground tabular-nums">{u.punches}, last {formatDate(u.last_seen)}</td>
                                <td className="px-3 py-2"><StatusPill value={confidence[u.confidence].label} tone={confidence[u.confidence].tone} /></td>
                                <td className="px-3 py-2">
                                    <NativeSelect className="w-72" value={pick[u.device_user_id] ?? ''} placeholder="Not linked"
                                        aria-label={`Employee for ${noun} ${u.device_user_id}`}
                                        aria-invalid={!!errors[`links.${chosen.findIndex(([d]) => d === u.device_user_id)}.employee_id`] || pickedTwice.has(pick[u.device_user_id])}
                                        options={free.map((p) => ({ value: p.id, label: p.id === u.suggestion ? `${p.label} (suggested)` : p.label }))}
                                        onChange={(e) => setPick((s) => ({ ...s, [u.device_user_id]: e.target.value }))} />
                                    {pickedTwice.has(pick[u.device_user_id]) && <p className="mt-1 text-xs text-missing">Picked for another {noun} too.</p>}
                                    {u.confidence !== 'match' && u.suggestion && !pick[u.device_user_id] && i >= 0 && (
                                        <button type="button" className="mt-1 text-xs text-cascade hover:underline" onClick={() => setPick((s) => ({ ...s, [u.device_user_id]: String(u.suggestion) }))}>
                                            Use {free.find((p) => p.id === u.suggestion)?.label ?? 'the suggestion'}
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="flex items-center gap-3">
                <Button onClick={save} disabled={chosen.length === 0 || pickedTwice.size > 0}><Link2 /> Link {plural(chosen.length, noun)}</Button>
                <span className="text-sm text-muted-foreground">Clear names are picked for you. Check the rest before linking.</span>
            </div>
        </div>
    );
}

export default function AttendanceImports({ imports, unlinked, people, sources, maxKb }: Props) {
    const bySource = (['machine', 'hrmy'] as const).map((s) => ({ source: s, rows: unlinked.filter((u) => u.source === s) })).filter((g) => g.rows.length > 0);
    return (
        <>
            <Head title="Attendance files" />
            <PageHeader back={{ href: '/attendance', label: 'Attendance' }} title="Attendance files" description="Upload the machine and HR.my files, and link their IDs to employees." />
            <div className="grid gap-10 px-4 py-6 md:px-6">
                <UploadForm maxKb={maxKb} />

                <Section title="Link IDs to employees" description="Each person has a number on the machine and an ID in HR.my. Linking them once connects all their punches, past and future. HR.my IDs look like employee codes but are numbered differently, so check the names." className="scroll-mt-4">
                    <span id="link" />
                    {bySource.length === 0
                        ? <EmptyState title="Every ID in the uploaded files is linked to an employee." />
                        : bySource.map((g) => (
                            <div key={g.source} className="mb-8">
                                <h3 className="mb-2 font-semibold">{sources[g.source]} ({g.rows.length})</h3>
                                <LinkTable source={g.source} unlinked={g.rows} people={people} />
                            </div>
                        ))}
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
