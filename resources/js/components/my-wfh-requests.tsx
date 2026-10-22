import { router, useForm } from '@inertiajs/react';
import { House } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Field } from '@/components/field';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/format';
import type { Tone } from '@/types';

/** app/Models/WfhRequest.php props() */
export type WfhRequestRow = {
    id: number; start_date: string; end_date: string; days: number; reason: string; status: 'pending' | 'approved' | 'rejected' | 'cancelled';
    status_label: string; decision_note: string | null; decided_by: string | null; decided_at: string | null; created_at: string | null;
};

const tone: Record<WfhRequestRow['status'], Tone> = { pending: 'pending', approved: 'done', rejected: 'missing', cancelled: 'neutral' };

function AskDialog({ today, onClose }: { today: string; onClose: () => void }) {
    const form = useForm({ start_date: today, end_date: today, reason: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/me/wfh-requests', { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Ask to work from home</DialogTitle>
                        <DialogDescription>HR approves it here. On approved days, clock in on this page from home as usual.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="From" htmlFor="wfh-from" error={form.errors.start_date}>
                            <Input id="wfh-from" type="date" required value={form.data.start_date}
                                onChange={(e) => form.setData((d) => ({ ...d, start_date: e.target.value, end_date: d.end_date < e.target.value ? e.target.value : d.end_date }))} />
                        </Field>
                        <Field label="To" htmlFor="wfh-to" error={form.errors.end_date}>
                            <Input id="wfh-to" type="date" required min={form.data.start_date} value={form.data.end_date} onChange={(e) => form.setData('end_date', e.target.value)} />
                        </Field>
                    </div>
                    <Field label="Reason" htmlFor="wfh-reason" error={form.errors.reason}>
                        <Textarea id="wfh-reason" required rows={2} placeholder="Waiting at home for a repair" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </Field>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>Send to HR</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** My page, Requests tab: ask to work from home, and the requests sent so far. */
export function MyWfhRequests({ requests, today }: { requests: WfhRequestRow[]; today: string }) {
    const [asking, setAsking] = useState(false);
    return (
        <section className="grid gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="font-bold">Work from home</h2>
                <Button onClick={() => setAsking(true)}><House /> Ask to work from home</Button>
            </div>
            {requests.length === 0 ? <p className="text-sm text-muted-foreground">No work from home requests yet.</p> : (
                <ul className="divide-y rounded-lg border">
                    {requests.map((r) => (
                        <li key={r.id} className="grid gap-2 px-3 py-3 text-sm sm:grid-cols-[1fr_auto] sm:items-start">
                            <div>
                                <p className="font-semibold tabular-nums">
                                    {r.start_date === r.end_date ? formatDate(r.start_date) : `${formatDate(r.start_date)} to ${formatDate(r.end_date)}`}
                                    <span className="font-normal text-muted-foreground"> ({r.days} {r.days === 1 ? 'day' : 'days'})</span>
                                </p>
                                <p className="text-muted-foreground">{r.reason}</p>
                                {r.decision_note && <p className="mt-1">HR: {r.decision_note}</p>}
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Sent {formatDateTime(r.created_at)}{r.decided_at && `, answered ${formatDateTime(r.decided_at)}${r.decided_by ? ` by ${r.decided_by}` : ''}`}
                                </p>
                            </div>
                            <div className="flex items-center gap-2 sm:justify-end">
                                <StatusPill value={r.status_label} tone={tone[r.status]} />
                                {r.status === 'pending' && (
                                    <Button variant="ghost" size="sm" onClick={() => confirm('Cancel this request?') && router.delete(`/me/wfh-requests/${r.id}`, { preserveScroll: true })}>Cancel</Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {asking && <AskDialog today={today} onClose={() => setAsking(false)} />}
        </section>
    );
}
