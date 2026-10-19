import { router, useForm } from '@inertiajs/react';
import { Paperclip, Plus } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Field } from '@/components/field';
import { leaveDates } from '@/components/leave';
import { NativeSelect } from '@/components/native-select';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/format';
import type { Tone } from '@/types';

/** app/Models/LeaveRequest.php props() */
export type LeaveRequestRow = {
    id: number; type: string | null; start_date: string; end_date: string; part: 'full' | 'first_half' | 'second_half'; days: number;
    reason: string | null; status: 'pending' | 'approved' | 'rejected' | 'cancelled'; status_label: string;
    attachment: { name: string; size: number | null } | null; decision_note: string | null; decided_by: string | null;
    decided_at: string | null; created_at: string | null;
};
export type LeaveTypeOption = { id: number; name: string; yearly_days: number | null; per_leave_days: number | null };

export const leaveRequestTone: Record<LeaveRequestRow['status'], Tone> = { pending: 'pending', approved: 'done', rejected: 'missing', cancelled: 'neutral' };

function ApplyDialog({ types, parts, uploads, today, onClose }: {
    types: LeaveTypeOption[]; parts: Record<string, string>; uploads: { enabled: boolean; max_mb: number }; today: string; onClose: () => void;
}) {
    const form = useForm<{ leave_type_id: string; start_date: string; end_date: string; part: string; reason: string; attachment: File | null }>({
        leave_type_id: '', start_date: today, end_date: today, part: 'full', reason: '', attachment: null,
    });
    const type = types.find((t) => String(t.id) === form.data.leave_type_id);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, attachment: uploads.enabled ? d.attachment : undefined }));
        form.post('/me/leave-requests', { preserveScroll: true, forceFormData: true, onSuccess: onClose });
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Apply for leave</DialogTitle>
                        <DialogDescription>
                            {uploads.enabled
                                ? 'Attach the signed leave form with your manager\'s approval. HR checks it and approves the leave here.'
                                : 'Hand the signed leave form with your manager\'s approval to HR. Attachments are switched off for now.'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Leave type" htmlFor="lr-type" error={form.errors.leave_type_id}
                            hint={type?.per_leave_days ? `Up to ${type.per_leave_days} days each time.` : type?.yearly_days ? `${type.yearly_days} days a year.` : undefined}>
                            <NativeSelect id="lr-type" required value={form.data.leave_type_id} placeholder="Pick a type" options={types.map((t) => ({ value: t.id, label: t.name }))}
                                onChange={(e) => form.setData('leave_type_id', e.target.value)} />
                        </Field>
                        <Field label="Day" htmlFor="lr-part" error={form.errors.part}>
                            <NativeSelect id="lr-part" value={form.data.part} options={Object.entries(parts).map(([value, label]) => ({ value, label }))}
                                onChange={(e) => { form.setData((d) => ({ ...d, part: e.target.value, end_date: e.target.value === 'full' ? d.end_date : d.start_date })); }} />
                        </Field>
                        <Field label="From" htmlFor="lr-from" error={form.errors.start_date}>
                            <Input id="lr-from" type="date" required value={form.data.start_date}
                                onChange={(e) => form.setData((d) => ({ ...d, start_date: e.target.value, end_date: d.part !== 'full' || d.end_date < e.target.value ? e.target.value : d.end_date }))} />
                        </Field>
                        <Field label="To" htmlFor="lr-to" error={form.errors.end_date}>
                            <Input id="lr-to" type="date" required disabled={form.data.part !== 'full'} min={form.data.start_date} value={form.data.end_date}
                                onChange={(e) => form.setData('end_date', e.target.value)} />
                        </Field>
                    </div>
                    <Field label="Reason" htmlFor="lr-reason" error={form.errors.reason}>
                        <Textarea id="lr-reason" required rows={2} placeholder="Family wedding in Lahore" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </Field>
                    {uploads.enabled && (
                        <Field label="Signed leave form" htmlFor="lr-file" error={form.errors.attachment} hint={`PDF, JPG or PNG, up to ${uploads.max_mb} MB. A photo of the signed form is fine.`}>
                            <Input id="lr-file" type="file" required accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => form.setData('attachment', e.target.files?.[0] ?? null)} />
                        </Field>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>Send to HR</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** My page, Leave tab: apply, and the requests sent so far. */
export function MyLeaveRequests({ requests, types, parts, uploads, today }: {
    requests: LeaveRequestRow[]; types: LeaveTypeOption[]; parts: Record<string, string>; uploads: { enabled: boolean; max_mb: number }; today: string;
}) {
    const [applying, setApplying] = useState(false);
    return (
        <section className="grid gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="font-bold">Leave requests</h2>
                <Button onClick={() => setApplying(true)}><Plus /> Apply for leave</Button>
            </div>
            {requests.length > 0 && (
                <ul className="divide-y rounded-lg border">
                    {requests.map((r) => (
                        <li key={r.id} className="grid gap-2 px-3 py-3 text-sm sm:grid-cols-[1fr_auto] sm:items-start">
                            <div>
                                <p className="font-semibold">{r.type} <span className="font-normal text-muted-foreground">{leaveDates(r)}, {r.days} {r.days === 1 ? 'day' : 'days'}</span></p>
                                {r.reason && <p className="text-muted-foreground">{r.reason}</p>}
                                {r.attachment && (
                                    <a href={`/me/leave-requests/${r.id}/file`} target="_blank" rel="noreferrer" className="mt-1 inline-flex items-center gap-1 text-xs text-cascade hover:underline">
                                        <Paperclip className="size-3" /> {r.attachment.name}
                                    </a>
                                )}
                                {r.decision_note && <p className="mt-1">HR: {r.decision_note}</p>}
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Sent {formatDateTime(r.created_at)}{r.decided_at && `, answered ${formatDateTime(r.decided_at)}${r.decided_by ? ` by ${r.decided_by}` : ''}`}
                                </p>
                            </div>
                            <div className="flex items-center gap-2 sm:justify-end">
                                <StatusPill value={r.status_label} tone={leaveRequestTone[r.status]} />
                                {r.status === 'pending' && (
                                    <Button variant="ghost" size="sm" onClick={() => confirm('Cancel this leave request?') && router.delete(`/me/leave-requests/${r.id}`, { preserveScroll: true })}>Cancel</Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {applying && <ApplyDialog types={types} parts={parts} uploads={uploads} today={today} onClose={() => setApplying(false)} />}
        </section>
    );
}
