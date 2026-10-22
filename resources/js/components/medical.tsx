import { router, useForm } from '@inertiajs/react';
import { Paperclip, Stethoscope } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Field } from '@/components/field';
import { rupees } from '@/components/loans';
import { NativeSelect } from '@/components/native-select';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/format';
import type { Tone } from '@/types';

/** app/Models/MedicalRequest.php props() */
export type MedicalRow = {
    id: number; kind: 'reimbursement' | 'advance'; kind_label: string; patient: string; patient_label: string; treatment_date: string | null;
    amount: number; approved_amount: number | null; reason: string;
    status: 'pending' | 'approved' | 'paid' | 'rejected' | 'cancelled'; status_label: string;
    attachment: { name: string; size: number | null } | null; decision_note: string | null; decided_by: string | null; decided_at: string | null;
    paid_on: string | null; paid_note: string | null; paid_by: string | null; created_at: string | null;
};

export const medicalTone: Record<MedicalRow['status'], Tone> = { pending: 'pending', approved: 'info', paid: 'done', rejected: 'missing', cancelled: 'neutral' };

/** "Claim back a bill, Rs 8,000 (Rs 6,000 approved), for Child, treatment 12 Oct 2026" */
export function medicalSummary(r: MedicalRow): string {
    const approved = r.approved_amount !== null && r.approved_amount !== r.amount ? ` (${rupees(r.approved_amount)} approved)` : '';
    return [`${r.kind_label}, ${rupees(r.amount)}${approved}`, r.patient !== 'self' && `for ${r.patient_label.toLowerCase()}`,
        r.treatment_date && `treatment ${formatDate(r.treatment_date)}`].filter(Boolean).join(', ');
}

/** "Paid 24 Oct 2026 (bank transfer)" */
export const paidLine = (r: MedicalRow) => (r.paid_on ? `Paid ${formatDate(r.paid_on)}${r.paid_note ? ` (${r.paid_note})` : ''}` : '');

function AskDialog({ kinds, patients, uploads, onClose }: {
    kinds: Record<string, string>; patients: Record<string, string>; uploads: { enabled: boolean; max_mb: number }; onClose: () => void;
}) {
    const form = useForm<{ kind: string; patient: string; treatment_date: string; amount: string; reason: string; attachment: File | null }>({
        kind: 'reimbursement', patient: 'self', treatment_date: '', amount: '', reason: '', attachment: null,
    });
    const claim = form.data.kind === 'reimbursement';
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, attachment: uploads.enabled ? d.attachment : undefined }));
        form.post('/me/medical-requests', { preserveScroll: true, forceFormData: true, onSuccess: onClose });
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Medical request</DialogTitle>
                        <DialogDescription>HR decides how much is approved and tells you when it's paid. Only you and HR see this.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="What you need">
                        {Object.entries(kinds).map(([value, label]) => (
                            <button key={value} type="button" role="radio" aria-checked={form.data.kind === value} onClick={() => form.setData('kind', value)}
                                className={`rounded-md border px-3 py-2 text-left text-sm ${form.data.kind === value ? 'border-primary bg-primary/5 font-semibold' : 'hover:bg-accent'}`}>
                                {label}
                                <span className="block text-xs font-normal text-muted-foreground">
                                    {value === 'reimbursement' ? 'You paid already; attach the bills.' : 'You need money before the treatment.'}
                                </span>
                            </button>
                        ))}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Amount (Rs)" htmlFor="md-amount" error={form.errors.amount}>
                            <Input id="md-amount" type="number" inputMode="numeric" min={1} step="any" required value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                        </Field>
                        <Field label="For" htmlFor="md-patient" error={form.errors.patient}>
                            <NativeSelect id="md-patient" value={form.data.patient} options={Object.entries(patients).map(([value, label]) => ({ value, label }))}
                                onChange={(e) => form.setData('patient', e.target.value)} />
                        </Field>
                        <Field label={claim ? 'Treatment date' : 'Treatment date (if known)'} htmlFor="md-date" error={form.errors.treatment_date}>
                            <Input id="md-date" type="date" required={claim} value={form.data.treatment_date} onChange={(e) => form.setData('treatment_date', e.target.value)} />
                        </Field>
                    </div>
                    <Field label="What it's for" htmlFor="md-reason" error={form.errors.reason}>
                        <Textarea id="md-reason" required rows={2} placeholder={claim ? 'Doctor visit and medicines' : 'Surgery at the hospital next week'}
                            value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </Field>
                    {uploads.enabled ? (
                        <Field label={claim ? 'Bills' : 'Doctor\'s estimate (optional)'} htmlFor="md-file" error={form.errors.attachment}
                            hint={`PDF, JPG or PNG, up to ${uploads.max_mb} MB. A photo is fine; put several bills in one PDF.`}>
                            <Input id="md-file" type="file" required={claim} accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => form.setData('attachment', e.target.files?.[0] ?? null)} />
                        </Field>
                    ) : claim && <p className="text-sm text-muted-foreground">Attachments are switched off for now. Hand the bills to HR.</p>}
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>Send to HR</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** My page, Requests tab: ask for a medical claim or advance, and the requests so far. */
export function MyMedicalRequests({ requests, kinds, patients, uploads }: {
    requests: MedicalRow[]; kinds: Record<string, string>; patients: Record<string, string>; uploads: { enabled: boolean; max_mb: number };
}) {
    const [asking, setAsking] = useState(false);
    return (
        <section className="grid gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="font-bold">Medical</h2>
                <Button variant="outline" onClick={() => setAsking(true)}><Stethoscope /> Medical request</Button>
            </div>
            {requests.length > 0 && (
                <ul className="divide-y rounded-lg border">
                    {requests.map((r) => (
                        <li key={r.id} className="grid gap-2 px-3 py-3 text-sm sm:grid-cols-[1fr_auto] sm:items-start">
                            <div>
                                <p className="font-semibold tabular-nums">{medicalSummary(r)}</p>
                                <p className="text-muted-foreground">{r.reason}</p>
                                {r.attachment && (
                                    <a href={`/me/medical-requests/${r.id}/file`} target="_blank" rel="noreferrer" className="mt-1 inline-flex items-center gap-1 text-xs text-cascade hover:underline">
                                        <Paperclip className="size-3" /> {r.attachment.name}
                                    </a>
                                )}
                                {r.decision_note && <p className="mt-1">HR: {r.decision_note}</p>}
                                {r.paid_on && <p className="mt-1 font-semibold">{paidLine(r)}</p>}
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Sent {formatDateTime(r.created_at)}{r.decided_at && `, answered ${formatDateTime(r.decided_at)}${r.decided_by ? ` by ${r.decided_by}` : ''}`}
                                </p>
                            </div>
                            <div className="flex items-center gap-2 sm:justify-end">
                                <StatusPill value={r.status_label} tone={medicalTone[r.status]} />
                                {r.status === 'pending' && (
                                    <Button variant="ghost" size="sm" onClick={() => confirm('Cancel this medical request?') && router.delete(`/me/medical-requests/${r.id}`, { preserveScroll: true })}>Cancel</Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {asking && <AskDialog kinds={kinds} patients={patients} uploads={uploads} onClose={() => setAsking(false)} />}
        </section>
    );
}
