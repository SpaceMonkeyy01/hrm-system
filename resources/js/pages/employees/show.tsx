import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, ExternalLink, FileText, FolderOpen, Pencil, Trash2, Upload } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { toast } from 'sonner';
import { EmptyState } from '@/components/empty-state';
import { Detail, Field } from '@/components/field';
import { NativeSelect, toOptions } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { StatusPicker } from '@/components/status-picker';
import { computedTone, StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/format';
import type { ActivityItem, ChecklistColumn, OptionSets, SharedProps, Tone } from '@/types';
import { AdjustDialog, DayStatus, duration, TimeCell } from '@/components/attendance';
import type { AttendanceDay } from '@/components/attendance';
import { AssetList } from '@/components/assets';
import type { AssetRow } from '@/components/assets';
import { leaveDates } from '@/components/leave';
import type { LeaveRecordRow } from '@/components/leave';

type Employee = {
    id: number; system_id: string; employee_code: string; full_name: string; first_name: string | null; last_name: string | null; cnic: string | null;
    email: string | null; phone: string | null; role_title: string | null; employment_type: string | null; status: string;
    hr_portal_id: string | null; attendance_machine: string | null; attendance_machine_id: string | null; drive_folder_url: string | null;
    notes: string | null; needs_review: boolean; review_note: string | null; source_ref: string | null;
    start_date: string | null; exit_date: string | null; company: string | null; department: string | null; team: string | null;
    manager: { id: number; name: string; code: string } | null;
    gender: string | null; personal_email: string | null; city: string | null; work_mode: string | null; work_location: string | null;
    confirmation_date: string | null;
    /** True for viewer logins: the private fields below are null for them. */
    private_hidden: boolean;
    cnic_expiry: string | null; father_name: string | null; date_of_birth: string | null; marital_status: string | null; blood_group: string | null;
    alternate_phone: string | null; current_address: string | null; permanent_address: string | null;
    emergency_contact_name: string | null; emergency_contact_relation: string | null; emergency_contact_phone: string | null;
    bank_name: string | null; bank_account_title: string | null; bank_account_number: string | null;
};
type AttendanceMonth = {
    month: string; days: AttendanceDay[]; counts: Record<string, number>; worked: number;
    schedule: { name: string; summary: string } | null; linked: boolean; statuses: Record<string, Tone>;
};
type EmployeeFile = { id: number; checklist_item_id: number | null; label: string | null; name: string; mime: string; size: number; uploaded_by: string | null; uploaded_at: string | null };
type HistoryEntry = { id: number; effective_date: string; change_type: string; changes: Record<string, [string | null, string | null]>; note: string | null; by: string | null };
type Onboarding = {
    cohort: string | null; probation_end_date: string | null; probation_result: string | null; notes: string | null; plan_url: string | null;
    overdue: boolean; orientation: string; documents: string; status: string;
};
type List = { columns: ChecklistColumn[]; values: Record<string, string | null> };
type Props = {
    employee: Employee;
    onboarding: Onboarding | null;
    documentStatus: string;
    lists: Record<'onboarding' | 'document' | 'offboarding', List>;
    optionSets: OptionSets;
    plan: { updated_at: string | null } | null;
    offboardings: { id: number; type: string; status: string; last_working_day: string | null }[];
    employmentHistory: HistoryEntry[];
    attendance: AttendanceMonth;
    leave: { year: number; records: LeaveRecordRow[]; totals: Record<string, number> };
    assets: AssetRow[];
    /** Null for viewer logins: they only get fileCount. */
    files: EmployeeFile[] | null;
    fileCount: number;
    uploads: { extensions: string[]; max_kb: number };
    activity: ActivityItem[];
    options: { probationResults: string[]; cohorts: string[]; historyTypes: string[] };
};

const statusTone = { active: 'done', offboarding: 'pending', exited: 'neutral' } as const;
const statusLabel: Record<string, string> = { active: 'Active', offboarding: 'Offboarding', exited: 'Exited' };
const reload = ['lists', 'onboarding', 'documentStatus', 'activity'];

function ChecklistList({ employeeId, list, optionSets, canEdit, name }: { employeeId: number; list: List; optionSets: OptionSets; canEdit: boolean; name: string }) {
    const [optimistic, setOptimistic] = useState<Record<string, string | null>>({});
    const save = (c: ChecklistColumn, value: string | null) => {
        setOptimistic((s) => ({ ...s, [c.key]: value }));
        router.put(`/checklist/${employeeId}/${c.id}`, { status: value }, {
            preserveScroll: true, preserveState: true, only: reload,
            onError: (e) => toast.error(Object.values(e)[0] ?? 'Not saved.'),
            onFinish: () => setOptimistic((s) => { const n = { ...s }; delete n[c.key]; return n; }),
        });
    };
    return (
        <ul className="divide-y rounded-lg border">
            {list.columns.map((c) => (
                <li key={c.id} className="flex items-center justify-between gap-4 px-3 py-2">
                    <span className="min-w-0">
                        <span className="text-sm font-semibold">{c.label}</span>
                        {c.description && <span className="block truncate text-xs text-muted-foreground">{c.description}</span>}
                    </span>
                    <StatusPicker
                        value={c.key in optimistic ? optimistic[c.key] : list.values[c.key] ?? null}
                        set={c.option_set} optionSets={optionSets} disabled={!canEdit}
                        label={`${c.label} for ${name}`} onChange={(v) => save(c, v)}
                    />
                </li>
            ))}
        </ul>
    );
}

function AttendanceTab({ e, a, canEdit }: { e: Employee; a: AttendanceMonth; canEdit: boolean }) {
    const [editing, setEditing] = useState<AttendanceDay | null>(null);
    const order = ['Present', 'Late', 'Half day', 'Missing punch', 'Absent', 'On leave', 'Holiday', 'Worked day off'];
    return (
        <div className="grid max-w-4xl gap-4">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="text-sm">
                    <p><span className="text-muted-foreground">Schedule:</span> {a.schedule ? <><span className="font-semibold">{a.schedule.name}</span> ({a.schedule.summary})</> : 'None'}</p>
                    {!a.linked && <p className="text-muted-foreground">No machine number linked, so no punches show. Link it under Attendance, Upload machine file.</p>}
                </div>
                <Input type="month" className="w-44" value={a.month} aria-label="Month"
                    onChange={(ev) => ev.target.value && router.get(`/employees/${e.id}`, { month: ev.target.value }, { only: ['attendance'], preserveState: true, preserveScroll: true, replace: true })} />
            </div>
            <div className="flex flex-wrap gap-2 text-sm">
                {order.filter((s) => a.counts[s]).map((s) => (
                    <span key={s} className="rounded-md border px-2.5 py-1"><span className="text-muted-foreground">{s}</span> <span className="font-semibold tabular-nums">{a.counts[s]}</span></span>
                ))}
                {a.worked > 0 && <span className="rounded-md border px-2.5 py-1"><span className="text-muted-foreground">Worked</span> <span className="font-semibold tabular-nums">{duration(a.worked)}</span></span>}
            </div>
            {a.days.length === 0 ? <EmptyState title="No attendance this month." /> : (
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-[40rem] text-sm">
                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr>
                                <th className="px-3 py-2 font-semibold">Day</th><th className="px-3 py-2 font-semibold">In</th><th className="px-3 py-2 font-semibold">Out</th>
                                <th className="px-3 py-2 font-semibold">Worked</th><th className="px-3 py-2 font-semibold">Status</th>
                                {canEdit && <th className="px-3 py-2"><span className="sr-only">Edit</span></th>}
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {a.days.map((d) => (
                                <tr key={d.date} className={d.status === 'Day off' ? 'text-muted-foreground' : undefined}>
                                    <td className="px-3 py-2 tabular-nums">{formatDate(d.date)}</td>
                                    <td className="px-3 py-2"><TimeCell time={d.in} source={d.in_source} /></td>
                                    <td className="px-3 py-2"><TimeCell time={d.out} source={d.out_source} /></td>
                                    <td className="px-3 py-2 tabular-nums">{duration(d.worked)}</td>
                                    <td className="px-3 py-2">
                                        <DayStatus day={d} statuses={a.statuses} />
                                        {d.note && <span className="mt-0.5 block text-xs text-muted-foreground">{d.note}</span>}
                                    </td>
                                    {canEdit && (
                                        <td className="px-3 py-2 text-right">
                                            {d.status !== 'Day off' || d.punches > 0 ? (
                                                <Button variant="ghost" size="sm" aria-label={`Set times for ${formatDate(d.date)}`} onClick={() => setEditing(d)}><Pencil /></Button>
                                            ) : null}
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {editing && <AdjustDialog open onClose={() => setEditing(null)} employee={{ id: e.id, name: e.full_name }} day={editing} only={['attendance', 'activity', 'flash']} />}
        </div>
    );
}

function LeaveTab({ leave }: { leave: Props['leave'] }) {
    const totals = Object.entries(leave.totals);
    return (
        <div className="grid max-w-3xl gap-4">
            <p className="text-sm text-muted-foreground">Approved leave in {leave.year}. Requests and balances are in HR.my; HR records leave on the Leave page.</p>
            {totals.length > 0 && (
                <div className="flex flex-wrap gap-2 text-sm">
                    {totals.map(([type, days]) => (
                        <span key={type} className="rounded-md border px-2.5 py-1"><span className="text-muted-foreground">{type}</span> <span className="font-semibold tabular-nums">{days}</span></span>
                    ))}
                </div>
            )}
            {leave.records.length === 0 ? <EmptyState title={`No leave recorded in ${leave.year}.`} /> : (
                <ul className="divide-y rounded-lg border">
                    {leave.records.map((r) => (
                        <li key={r.id} className="grid gap-0.5 px-3 py-2 text-sm sm:grid-cols-[10rem_1fr_auto]">
                            <span className="font-semibold">{r.type}</span>
                            <span className="tabular-nums">{leaveDates(r)}{r.note && <span className="block text-xs text-muted-foreground">{r.note}</span>}</span>
                            <span className="text-muted-foreground tabular-nums">{r.days} {r.days === 1 ? 'day' : 'days'}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function fileSize(bytes: number): string {
    return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function DocumentFiles({ e, files, columns, uploads }: { e: Employee; files: EmployeeFile[]; columns: ChecklistColumn[]; uploads: Props['uploads'] }) {
    const [file, setFile] = useState<File | null>(null);
    const [itemId, setItemId] = useState('');
    const [label, setLabel] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [progress, setProgress] = useState<number | null>(null);
    const [inputKey, setInputKey] = useState(0);
    const maxMb = Math.round(uploads.max_kb / 1024);
    const accept = uploads.extensions.map((x) => `.${x}`).join(',');

    const upload = (ev: FormEvent) => {
        ev.preventDefault();
        if (!file) return;
        if (file.size > uploads.max_kb * 1024) {
            setErrors({ file: `The file is larger than ${maxMb} MB.` });
            return;
        }
        router.post(`/employees/${e.id}/files`, { file, checklist_item_id: itemId || null, label: label || null }, {
            forceFormData: true, preserveScroll: true, only: ['files', 'fileCount', 'activity', 'flash'],
            onProgress: (p) => setProgress(p?.percentage ?? null),
            onSuccess: () => { setFile(null); setLabel(''); setErrors({}); setInputKey((k) => k + 1); },
            onError: setErrors,
            onFinish: () => setProgress(null),
        });
    };

    const groups = [
        ...columns.map((c) => ({ key: String(c.id), title: c.label, rows: files.filter((f) => f.checklist_item_id === c.id) })),
        { key: 'other', title: 'Other files', rows: files.filter((f) => f.checklist_item_id === null || !columns.some((c) => c.id === f.checklist_item_id)) },
    ].filter((g) => g.rows.length > 0);

    return (
        <div className="grid content-start gap-4">
            <h2 className="font-bold">Scanned files</h2>
            <form onSubmit={upload} className="grid gap-3 rounded-lg border p-4">
                <Field label="File" htmlFor="upload-file" error={errors.file} hint={`PDF, JPG or PNG, up to ${maxMb} MB.`}>
                    <Input key={inputKey} id="upload-file" type="file" accept={accept} required onChange={(ev) => setFile(ev.target.files?.[0] ?? null)} />
                </Field>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Which document" htmlFor="upload-item" error={errors.checklist_item_id}>
                        <NativeSelect id="upload-item" value={itemId} placeholder="Other" onChange={(ev) => setItemId(ev.target.value)}
                            options={columns.map((c) => ({ value: c.id, label: c.label }))} />
                    </Field>
                    <Field label="Note" htmlFor="upload-label" error={errors.label}>
                        <Input id="upload-label" value={label} placeholder="Front and back" onChange={(ev) => setLabel(ev.target.value)} />
                    </Field>
                </div>
                <div className="flex items-center gap-3">
                    <Button type="submit" disabled={!file || progress !== null}><Upload /> Upload file</Button>
                    {progress !== null && <span className="text-sm text-muted-foreground tabular-nums">Uploading {Math.round(progress)}%</span>}
                </div>
                <p className="text-xs text-muted-foreground">Uploading doesn't change the checklist. Set the document to Yes on the left once you've checked the file.</p>
            </form>

            {groups.length === 0 ? (
                <p className="rounded-lg border px-3 py-4 text-sm text-muted-foreground">No files uploaded yet.</p>
            ) : groups.map((g) => (
                <div key={g.key}>
                    <h3 className="mb-1.5 text-sm font-semibold">{g.title}</h3>
                    <ul className="divide-y rounded-lg border">
                        {g.rows.map((f) => (
                            <li key={f.id} className="flex items-center gap-3 px-3 py-2 text-sm">
                                <FileText className="size-4 shrink-0 text-muted-foreground" />
                                <span className="min-w-0 flex-1">
                                    <a href={`/employees/${e.id}/files/${f.id}`} target="_blank" rel="noreferrer" className="block truncate font-semibold text-cascade hover:underline">{f.name}</a>
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {[f.label, fileSize(f.size), f.uploaded_by && `by ${f.uploaded_by}`, formatDateTime(f.uploaded_at)].filter(Boolean).join(' · ')}
                                    </span>
                                </span>
                                <Button variant="ghost" size="sm" asChild aria-label={`Download ${f.name}`}>
                                    <a href={`/employees/${e.id}/files/${f.id}?download=1`}><Download /></a>
                                </Button>
                                <Button variant="ghost" size="sm" aria-label={`Remove ${f.name}`} onClick={() => {
                                    if (confirm(`Remove ${f.name}? The file is deleted from the server.`)) {
                                        router.delete(`/employees/${e.id}/files/${f.id}`, { preserveScroll: true, only: ['files', 'fileCount', 'activity', 'flash'] });
                                    }
                                }}><Trash2 /></Button>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </div>
    );
}

function JobHistory({ e, entries, types, canEdit, canDelete }: { e: Employee; entries: HistoryEntry[]; types: string[]; canEdit: boolean; canDelete: boolean }) {
    const [adding, setAdding] = useState(false);
    const [form, setForm] = useState({ effective_date: '', change_type: '', note: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const add = (ev: FormEvent) => {
        ev.preventDefault();
        router.post(`/employees/${e.id}/history`, form, {
            preserveScroll: true, only: ['employmentHistory', 'activity', 'flash'],
            onSuccess: () => { setAdding(false); setForm({ effective_date: '', change_type: '', note: '' }); setErrors({}); },
            onError: setErrors,
        });
    };

    return (
        <div className="grid max-w-3xl gap-4">
            <p className="text-sm text-muted-foreground">Job changes saved on Edit are added here automatically. Add older changes, such as a past promotion, by hand.</p>
            {canEdit && !adding && <Button variant="outline" className="justify-self-start" onClick={() => setAdding(true)}>Add a past change</Button>}
            {adding && (
                <form onSubmit={add} className="grid gap-4 rounded-lg border p-4 sm:grid-cols-2">
                    <Field label="Date" htmlFor="h-date" error={errors.effective_date}>
                        <Input id="h-date" type="date" required value={form.effective_date} onChange={(ev) => setForm({ ...form, effective_date: ev.target.value })} />
                    </Field>
                    <Field label="Type of change" htmlFor="h-type" error={errors.change_type}>
                        <NativeSelect id="h-type" required value={form.change_type} placeholder="Choose" options={toOptions(types)} onChange={(ev) => setForm({ ...form, change_type: ev.target.value })} />
                    </Field>
                    <Field label="What changed" htmlFor="h-note" error={errors.note} className="sm:col-span-2">
                        <Textarea id="h-note" required rows={2} value={form.note} placeholder="Promoted from Designer to Senior Designer" onChange={(ev) => setForm({ ...form, note: ev.target.value })} />
                    </Field>
                    <div className="flex gap-2 sm:col-span-2">
                        <Button type="submit">Add to history</Button>
                        <Button type="button" variant="ghost" onClick={() => setAdding(false)}>Cancel</Button>
                    </div>
                </form>
            )}
            <ol className="divide-y rounded-lg border">
                {entries.map((h) => (
                    <li key={h.id} className="grid gap-1 px-3 py-2.5 text-sm sm:grid-cols-[7rem_1fr_auto]">
                        <span className="text-muted-foreground tabular-nums">{formatDate(h.effective_date)}</span>
                        <span className="min-w-0">
                            <span className="font-semibold">{h.change_type}</span>
                            {Object.entries(h.changes).map(([label, [from, to]]) => (
                                <span key={label} className="block">{label}: {from ?? 'blank'} to {to ?? 'blank'}</span>
                            ))}
                            {h.note && <span className="block whitespace-pre-line text-muted-foreground">{h.note}</span>}
                            {h.by && <span className="block text-xs text-muted-foreground">Recorded by {h.by}</span>}
                        </span>
                        {canDelete && (
                            <Button variant="ghost" size="sm" aria-label={`Remove ${h.change_type} on ${formatDate(h.effective_date)}`} onClick={() => {
                                if (confirm('Remove this line from the employment history?')) router.delete(`/employees/${e.id}/history/${h.id}`, { preserveScroll: true, only: ['employmentHistory', 'activity', 'flash'] });
                            }}><Trash2 /></Button>
                        )}
                    </li>
                ))}
                <li className="grid gap-1 px-3 py-2.5 text-sm sm:grid-cols-[7rem_1fr_auto]">
                    <span className="text-muted-foreground tabular-nums">{e.start_date ? formatDate(e.start_date) : 'No date'}</span>
                    <span className="font-semibold">Joined{e.company ? ` ${e.company}` : ''}</span>
                </li>
            </ol>
        </div>
    );
}

function describe(a: ActivityItem): string {
    if (a.event === 'imported') return a.description ?? 'Imported';
    const changes = a.changes && !Array.isArray(a.changes) ? Object.entries(a.changes) : [];
    const parts = changes
        .filter(([k]) => !['updated_by', 'id', 'employee_id', 'checklist_item_id'].includes(k))
        .map(([k, v]) => (Array.isArray(v) ? `${k.replaceAll('_', ' ')}: ${v[0] ?? 'blank'} to ${v[1] ?? 'blank'}` : `${k.replaceAll('_', ' ')}: ${v ?? 'blank'}`));
    return `${a.description ?? 'Record'} ${a.event}${parts.length ? `. ${parts.join('; ')}` : ''}`;
}

export default function EmployeeShow(props: Props) {
    const { employee: e, onboarding: o, lists, optionSets } = props;
    const { auth } = usePage<SharedProps>().props;
    const can = auth.can.edit;
    const [notes, setNotes] = useState(o?.notes ?? '');

    const saveOnboarding = (data: Record<string, string | null>) =>
        router.put(`/onboarding/${e.id}`, data, { preserveScroll: true, preserveState: true, only: reload,
            onSuccess: () => toast.success('Saved.'), onError: (err) => toast.error(Object.values(err)[0] ?? 'Not saved.') });

    const legal = lists.document.columns.filter((c) => ['policy_acknowledgment', 'nda_signed', 'nca_signed'].includes(c.key));

    return (
        <>
            <Head title={e.full_name} />
            <PageHeader
                back={{ href: '/employees', label: 'Employees' }}
                title={e.full_name}
                description={
                    <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span className="font-semibold text-ink tabular-nums">{e.employee_code}</span>
                        <StatusPill value={statusLabel[e.status] ?? e.status} tone={statusTone[e.status as keyof typeof statusTone] ?? 'neutral'} />
                        <span>{[...new Set([e.role_title, e.team, e.department, e.company].filter(Boolean))].join(', ') || 'No department yet'}</span>
                    </span>
                }
                actions={
                    <>
                        {e.drive_folder_url && (
                            <Button variant="outline" asChild>
                                <a href={e.drive_folder_url} target="_blank" rel="noreferrer"><FolderOpen /> Drive folder</a>
                            </Button>
                        )}
                        {props.plan ? (
                            <Button variant="outline" asChild><Link href={`/employees/${e.id}/plan`}>90-day plan</Link></Button>
                        ) : can && (
                            <Button variant="outline" onClick={() => router.post(`/employees/${e.id}/plan`)}>Start 90-day plan</Button>
                        )}
                        {can && <Button asChild><Link href={`/employees/${e.id}/edit`}><Pencil /> Edit</Link></Button>}
                        {auth.can.admin && (
                            <Button variant="ghost" aria-label="Delete employee" onClick={() => {
                                if (confirm(`Delete ${e.full_name}? The record moves to the archive and can be restored by a developer.`)) router.delete(`/employees/${e.id}`);
                            }}><Trash2 /></Button>
                        )}
                    </>
                }
            />

            {e.needs_review && (
                <div className="mx-4 mt-4 rounded-lg border border-[#ecd29c] bg-pending-soft px-4 py-3 text-sm md:mx-6">
                    <p className="font-semibold text-pending">Check this record</p>
                    <p className="mt-0.5">{e.review_note}</p>
                    {can && <p className="mt-1 text-muted-foreground">Fix anything wrong with Edit, then tick "Record checked".</p>}
                </div>
            )}

            <Tabs defaultValue={typeof window !== 'undefined' && window.location.hash ? window.location.hash.slice(1) : 'overview'} className="px-4 pt-4 pb-10 md:px-6"
                onValueChange={(v) => history.replaceState(history.state, '', `#${v}`)}>
                <TabsList>
                    <TabsTrigger value="overview">Details</TabsTrigger>
                    <TabsTrigger value="onboarding">Onboarding {o && <StatusPill value={o.status === 'Onboarding Complete' ? 'Complete' : 'In progress'} tone={computedTone(o.status)} />}</TabsTrigger>
                    <TabsTrigger value="documents">Documents <StatusPill value={props.documentStatus} tone={computedTone(props.documentStatus)} /></TabsTrigger>
                    <TabsTrigger value="attendance">Attendance</TabsTrigger>
                    <TabsTrigger value="leave">Leave</TabsTrigger>
                    <TabsTrigger value="assets">Assets{props.assets.some((x) => !x.returned_on) ? ` (${props.assets.filter((x) => !x.returned_on).length})` : ''}</TabsTrigger>
                    <TabsTrigger value="offboarding">Offboarding</TabsTrigger>
                    <TabsTrigger value="job">Job history</TabsTrigger>
                    <TabsTrigger value="history">Change log</TabsTrigger>
                </TabsList>

                <TabsContent value="overview">
                    <div className="grid gap-8 lg:grid-cols-3">
                        <dl className="grid content-start gap-4">
                            <h2 className="font-bold">Identity</h2>
                            <Detail label="Employee code">{e.employee_code}</Detail>
                            <Detail label="System ID">{e.system_id}</Detail>
                            <Detail label="Full name">{e.full_name}</Detail>
                            <Detail label="First and last name">{[e.first_name, e.last_name].filter(Boolean).join(' ')}</Detail>
                            <Detail label="Gender">{e.gender}</Detail>
                            <Detail label="Work email">{e.email}</Detail>
                            <Detail label="Phone">{e.phone}</Detail>
                            <Detail label="City">{e.city}</Detail>
                        </dl>
                        <dl className="grid content-start gap-4">
                            <h2 className="font-bold">Work</h2>
                            <Detail label="Company">{e.company}</Detail>
                            <Detail label="Department and team">{[e.department, e.team].filter(Boolean).join(', ')}</Detail>
                            <Detail label="Role">{e.role_title}</Detail>
                            <Detail label="Manager">{e.manager && <Link className="text-cascade hover:underline" href={`/employees/${e.manager.id}`}>{e.manager.name}</Link>}</Detail>
                            <Detail label="Employment type">{e.employment_type}</Detail>
                            <Detail label="Work mode">{[e.work_mode, e.work_location].filter(Boolean).join(', ')}</Detail>
                            <Detail label="Start date">{formatDate(e.start_date)}</Detail>
                            <Detail label="Confirmation date">{formatDate(e.confirmation_date)}</Detail>
                            {e.exit_date && <Detail label="Exit date">{formatDate(e.exit_date)}</Detail>}
                        </dl>
                        <dl className="grid content-start gap-4">
                            <h2 className="font-bold">Systems</h2>
                            <Detail label="HR.my ID">{e.hr_portal_id}</Detail>
                            <Detail label="Attendance machine">{[e.attendance_machine, e.attendance_machine_id && `ID ${e.attendance_machine_id}`].filter(Boolean).join(', ')}</Detail>
                            <Detail label="Drive folder">{e.drive_folder_url && <a className="inline-flex items-center gap-1 text-cascade hover:underline" href={e.drive_folder_url} target="_blank" rel="noreferrer">Open folder <ExternalLink className="size-3.5" /></a>}</Detail>
                            <Detail label="Notes">{e.notes && <span className="whitespace-pre-line">{e.notes}</span>}</Detail>
                            <Detail label="Imported from">{e.source_ref}</Detail>
                        </dl>
                    </div>
                    {e.private_hidden ? (
                        <p className="mt-8 rounded-lg border px-3 py-3 text-sm text-muted-foreground">Personal, emergency contact and bank details are only shown to HR and admin logins.</p>
                    ) : (
                        <div className="mt-8 grid gap-8 border-t pt-6 lg:grid-cols-3">
                            <dl className="grid content-start gap-4">
                                <h2 className="font-bold">Personal</h2>
                                <Detail label="Father or husband name">{e.father_name}</Detail>
                                <Detail label="CNIC">{e.cnic && `${e.cnic}${e.cnic_expiry ? `, expires ${formatDate(e.cnic_expiry)}` : ''}`}</Detail>
                                <Detail label="Date of birth">{formatDate(e.date_of_birth)}</Detail>
                                <Detail label="Marital status">{e.marital_status}</Detail>
                                <Detail label="Blood group">{e.blood_group}</Detail>
                            </dl>
                            <dl className="grid content-start gap-4">
                                <h2 className="font-bold">Contact</h2>
                                <Detail label="Personal email">{e.personal_email}</Detail>
                                <Detail label="Other phone">{e.alternate_phone}</Detail>
                                <Detail label="Current address">{e.current_address && <span className="whitespace-pre-line">{e.current_address}</span>}</Detail>
                                <Detail label="Permanent address">{e.permanent_address && <span className="whitespace-pre-line">{e.permanent_address}</span>}</Detail>
                            </dl>
                            <dl className="grid content-start gap-4">
                                <h2 className="font-bold">Emergency contact</h2>
                                <Detail label="Name">{[e.emergency_contact_name, e.emergency_contact_relation && `(${e.emergency_contact_relation})`].filter(Boolean).join(' ')}</Detail>
                                <Detail label="Phone">{e.emergency_contact_phone}</Detail>
                                <h2 className="mt-2 font-bold">Salary account</h2>
                                <Detail label="Bank">{e.bank_name}</Detail>
                                <Detail label="Account title">{e.bank_account_title}</Detail>
                                <Detail label="Account number or IBAN">{e.bank_account_number}</Detail>
                            </dl>
                        </div>
                    )}
                </TabsContent>

                <TabsContent value="attendance">
                    <AttendanceTab e={e} a={props.attendance} canEdit={can} />
                </TabsContent>

                <TabsContent value="leave">
                    <LeaveTab leave={props.leave} />
                </TabsContent>

                <TabsContent value="assets">
                    <div className="grid max-w-3xl gap-3">
                        <p className="text-sm text-muted-foreground">Company property given to {e.full_name}. Still with them first, then returned.</p>
                        <AssetList rows={props.assets} empty="Nothing from the asset register has been given to them." />
                        {can && <Link href={`/assets?q=${encodeURIComponent(e.employee_code)}`} className="text-sm font-semibold text-cascade hover:underline">Give or take back on the Assets page</Link>}
                    </div>
                </TabsContent>

                <TabsContent value="job">
                    <JobHistory e={e} entries={props.employmentHistory} types={props.options.historyTypes} canEdit={can} canDelete={auth.can.admin} />
                </TabsContent>

                <TabsContent value="onboarding">
                    {!o ? (
                        <EmptyState title={`${e.full_name} isn't in the onboarding process.`}>
                            {can && <Button className="mt-3" onClick={() => router.post(`/onboarding/${e.id}/start`, {}, { preserveScroll: true })}>Start onboarding</Button>}
                        </EmptyState>
                    ) : (
                        <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
                            <div className="grid content-start gap-5">
                                <div className="grid grid-cols-3 gap-2 text-center text-xs">
                                    {[['Onboarding', o.status === 'Onboarding Complete' ? 'Complete' : 'In progress', o.status], ['Orientation', o.orientation, o.orientation], ['Documents', o.documents, o.documents]].map(([label, text, raw]) => (
                                        <div key={label} className="rounded-lg border px-2 py-3">
                                            <p className="mb-1.5 text-muted-foreground">{label}</p>
                                            <StatusPill value={text} tone={computedTone(raw)} />
                                        </div>
                                    ))}
                                </div>
                                <div className="grid gap-4 rounded-lg border p-4 sm:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <label className="text-sm font-medium" htmlFor="prob-result">Probation result</label>
                                        <NativeSelect id="prob-result" disabled={!can} value={o.probation_result ?? ''} placeholder="Not tracked"
                                            options={toOptions(props.options.probationResults)}
                                            onChange={(ev) => saveOnboarding({ probation_result: ev.target.value || null })} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <label className="text-sm font-medium" htmlFor="prob-end">Probation ends</label>
                                        <Input id="prob-end" type="date" disabled={!can} defaultValue={o.probation_end_date ?? ''} key={o.probation_end_date}
                                            onBlur={(ev) => ev.target.value !== (o.probation_end_date ?? '') && saveOnboarding({ probation_end_date: ev.target.value || null })}
                                            aria-invalid={o.overdue} />
                                        {o.overdue && <p className="text-xs text-missing">Past the end date and still Pending.</p>}
                                    </div>
                                    <div className="grid gap-1.5">
                                        <label className="text-sm font-medium" htmlFor="cohort">Group</label>
                                        <NativeSelect id="cohort" disabled={!can} value={o.cohort ?? ''} placeholder="No group"
                                            options={toOptions(props.options.cohorts).map((x) => ({ ...x, label: `Group ${x.label}` }))}
                                            onChange={(ev) => saveOnboarding({ cohort: ev.target.value || null })} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <span className="text-sm font-medium">90-day plan</span>
                                        {props.plan ? <Link className="text-sm text-cascade hover:underline" href={`/employees/${e.id}/plan`}>Open plan</Link>
                                            : o.plan_url ? <a className="text-sm text-cascade hover:underline" href={o.plan_url} target="_blank" rel="noreferrer">Old sheet copy</a>
                                            : <span className="text-sm text-muted-foreground">Not started</span>}
                                    </div>
                                    <div className="grid gap-1.5 sm:col-span-2">
                                        <label className="text-sm font-medium" htmlFor="onb-notes">Notes</label>
                                        <Textarea id="onb-notes" disabled={!can} value={notes} onChange={(ev) => setNotes(ev.target.value)} rows={3} />
                                        {can && notes !== (o.notes ?? '') && <Button size="sm" className="justify-self-start" onClick={() => saveOnboarding({ notes: notes || null })}>Save notes</Button>}
                                    </div>
                                </div>
                                <div>
                                    <h2 className="mb-2 font-bold">Legal documents</h2>
                                    <p className="mb-2 text-sm text-muted-foreground">These are the same values as on the Documents tab.</p>
                                    <ChecklistList employeeId={e.id} name={e.full_name} optionSets={optionSets} canEdit={can}
                                        list={{ columns: legal, values: lists.document.values }} />
                                </div>
                            </div>
                            <div>
                                <h2 className="mb-2 font-bold">Onboarding checklist</h2>
                                <ChecklistList employeeId={e.id} name={e.full_name} list={lists.onboarding} optionSets={optionSets} canEdit={can} />
                            </div>
                        </div>
                    )}
                </TabsContent>

                <TabsContent value="documents">
                    <div className="grid gap-8 lg:grid-cols-2">
                        <div>
                            <p className="mb-3 text-sm text-muted-foreground">
                                Status is Complete when every document is Yes, Not Required, Old employee or not available (same rule as the sheet).
                            </p>
                            <ChecklistList employeeId={e.id} name={e.full_name} list={lists.document} optionSets={optionSets} canEdit={can} />
                        </div>
                        {props.files ? (
                            <DocumentFiles e={e} files={props.files} columns={lists.document.columns} uploads={props.uploads} />
                        ) : (
                            <p className="self-start rounded-lg border px-3 py-3 text-sm text-muted-foreground">
                                {props.fileCount === 1 ? '1 scanned file is' : `${props.fileCount} scanned files are`} on record. Only HR and admin logins can open them.
                            </p>
                        )}
                    </div>
                </TabsContent>

                <TabsContent value="offboarding">
                    {props.offboardings.length === 0 ? (
                        <EmptyState title="No offboarding on record.">
                            {can && e.status === 'active' && (
                                <Button className="mt-3" variant="outline" asChild><Link href={`/offboarding?start=${e.id}`}>Start offboarding</Link></Button>
                            )}
                        </EmptyState>
                    ) : (
                        <ul className="max-w-2xl divide-y rounded-lg border">
                            {props.offboardings.map((x) => (
                                <li key={x.id} className="flex items-center justify-between gap-3 px-3 py-2.5 text-sm">
                                    <Link href={`/offboarding/${x.id}`} className="font-semibold text-cascade hover:underline">{x.type}</Link>
                                    <span className="text-muted-foreground">{x.last_working_day ? `Last day ${formatDate(x.last_working_day)}` : 'Last day not set'}</span>
                                    <StatusPill value={x.status.replace('_', ' ')} tone={computedTone(x.status)} />
                                </li>
                            ))}
                        </ul>
                    )}
                </TabsContent>

                <TabsContent value="history">
                    {props.activity.length === 0 ? <EmptyState title="No changes recorded yet." /> : (
                        <ol className="max-w-3xl divide-y rounded-lg border">
                            {props.activity.map((a) => (
                                <li key={a.id} className="grid gap-0.5 px-3 py-2 text-sm sm:grid-cols-[8rem_7rem_1fr]">
                                    <span className="text-xs text-muted-foreground tabular-nums">{formatDateTime(a.when)}</span>
                                    <span className="font-semibold">{a.user}</span>
                                    <span className="break-words">{describe(a)}</span>
                                </li>
                            ))}
                        </ol>
                    )}
                </TabsContent>
            </Tabs>
        </>
    );
}
