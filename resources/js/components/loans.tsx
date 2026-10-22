import { router, useForm } from '@inertiajs/react';
import { HandCoins } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Field } from '@/components/field';
import { Meter } from '@/components/meter';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/format';
import type { Tone } from '@/types';

/** app/Models/Loan.php props() */
export type LoanRow = {
    id: number; amount: number; months: number; installment: number | null; first_month: string | null; reason: string;
    status: 'pending' | 'active' | 'repaid' | 'rejected' | 'cancelled'; status_label: string; repaid: number; left: number;
    repayments: { id: number; month: string; amount: number; note: string | null }[];
    decision_note: string | null; decided_by: string | null; decided_at: string | null; repaid_at: string | null; created_at: string | null;
};

export const loanTone: Record<LoanRow['status'], Tone> = { pending: 'pending', active: 'info', repaid: 'done', rejected: 'missing', cancelled: 'neutral' };
export const rupees = (n: number | null | undefined) => (n === null || n === undefined ? '' : `Rs ${n.toLocaleString('en-PK', { maximumFractionDigits: 2 })}`);
/** "2026-06" as "Jun 2026" */
export const monthLabel = (ym: string | null) => (ym ? new Date(`${ym}-01T00:00:00`).toLocaleDateString('en-GB', { month: 'short', year: 'numeric' }) : '');

/** "Rs 4,000 of Rs 12,000 repaid, Rs 8,000 left; Rs 2,000 a month from Jun 2026" */
export function loanSummary(l: LoanRow): string {
    if (l.status === 'pending' || l.status === 'rejected' || l.status === 'cancelled') return `${rupees(l.amount)} over ${l.months} ${l.months === 1 ? 'month' : 'months'}`;
    return `${rupees(l.repaid)} of ${rupees(l.amount)} repaid${l.left > 0 ? `, ${rupees(l.left)} left` : ''}; ${rupees(l.installment)} a month from ${monthLabel(l.first_month)}`;
}

function AskDialog({ onClose }: { onClose: () => void }) {
    const form = useForm({ amount: '', months: '6', reason: '' });
    const per = Number(form.data.amount) > 0 && Number(form.data.months) > 0 ? Math.ceil(Number(form.data.amount) / Number(form.data.months)) : null;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/me/loans', { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Ask for a loan</DialogTitle>
                        <DialogDescription>HR decides, and repayments come off your salary each month. Only you and HR see this.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Amount (Rs)" htmlFor="ln-amount" error={form.errors.amount}>
                            <Input id="ln-amount" type="number" inputMode="numeric" min={1000} step={500} required value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                        </Field>
                        <Field label="Repay over (months)" htmlFor="ln-months" error={form.errors.months} hint={per ? `About ${rupees(per)} a month.` : undefined}>
                            <Input id="ln-months" type="number" min={1} max={60} required value={form.data.months} onChange={(e) => form.setData('months', e.target.value)} />
                        </Field>
                    </div>
                    <Field label="What it's for" htmlFor="ln-reason" error={form.errors.reason}>
                        <Textarea id="ln-reason" required rows={2} placeholder="Medical bills" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
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

/** My page, Requests tab: ask for a loan, and the loans so far with what is repaid. */
export function MyLoans({ loans }: { loans: LoanRow[] }) {
    const [asking, setAsking] = useState(false);
    return (
        <section className="grid gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="font-bold">Loans</h2>
                <Button variant="outline" onClick={() => setAsking(true)}><HandCoins /> Ask for a loan</Button>
            </div>
            {loans.length === 0 ? <p className="text-sm text-muted-foreground">No loan requests yet.</p> : (
                <ul className="divide-y rounded-lg border">
                    {loans.map((l) => (
                        <li key={l.id} className="grid gap-2 px-3 py-3 text-sm sm:grid-cols-[1fr_auto] sm:items-start">
                            <div>
                                <p className="font-semibold tabular-nums">{loanSummary(l)}</p>
                                {(l.status === 'active' || l.status === 'repaid') && (
                                    <Meter value={l.repaid} max={l.amount} label={`${rupees(l.repaid)} of ${rupees(l.amount)} repaid`} tone={l.status === 'repaid' ? 'done' : 'primary'} className="my-1.5 max-w-sm" />
                                )}
                                <p className="text-muted-foreground">{l.reason}</p>
                                {l.repayments.length > 0 && (
                                    <p className="mt-1 text-xs text-muted-foreground tabular-nums">Deducted: {l.repayments.map((r) => `${monthLabel(r.month)} ${rupees(r.amount)}`).join(', ')}</p>
                                )}
                                {l.decision_note && <p className="mt-1">HR: {l.decision_note}</p>}
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Asked {formatDateTime(l.created_at)}{l.decided_at && `, answered ${formatDateTime(l.decided_at)}${l.decided_by ? ` by ${l.decided_by}` : ''}`}
                                </p>
                            </div>
                            <div className="flex items-center gap-2 sm:justify-end">
                                <StatusPill value={l.status_label} tone={loanTone[l.status]} />
                                {l.status === 'pending' && (
                                    <Button variant="ghost" size="sm" onClick={() => confirm('Cancel this loan request?') && router.delete(`/me/loans/${l.id}`, { preserveScroll: true })}>Cancel</Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {asking && <AskDialog onClose={() => setAsking(false)} />}
        </section>
    );
}
