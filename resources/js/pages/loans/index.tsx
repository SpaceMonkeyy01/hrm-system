import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check, CheckCheck, HandCoins, Plus, Trash2, X } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { DecideDialog } from '@/components/decide-dialog';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { loanSummary, loanTone, monthLabel, rupees } from '@/components/loans';
import type { LoanRow } from '@/components/loans';
import { PageHeader } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate, formatDateTime } from '@/lib/format';

/** app/Http/Controllers/LoanController.php index() */
type Row = LoanRow & {
    employee: { id: number; name: string; code: string; department: string | null; start_date: string | null };
    owing_other: number;
};
type Props = { status: string; statuses: Record<string, string>; counts: Record<string, number>; loans: Row[]; thisMonth: string };

/** Next month as "YYYY-MM": the usual first deduction. */
const nextMonth = (ym: string) => {
    const [y, m] = ym.split('-').map(Number);
    return m === 12 ? `${y + 1}-01` : `${y}-${String(m + 1).padStart(2, '0')}`;
};

function RepaymentDialog({ loan, thisMonth, onClose }: { loan: Row; thisMonth: string; onClose: () => void }) {
    const form = useForm({ month: thisMonth, amount: String(Math.min(loan.installment ?? loan.left, loan.left)), note: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/loans/${loan.id}/repayments`, { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Record a deduction: {loan.employee.name}</DialogTitle>
                        <DialogDescription>{rupees(loan.left)} left on this loan. When the deductions reach the amount, it closes as Repaid.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Salary month" htmlFor="rp-month" error={form.errors.month}>
                            <Input id="rp-month" type="month" required value={form.data.month} onChange={(e) => form.setData('month', e.target.value)} />
                        </Field>
                        <Field label="Amount (Rs)" htmlFor="rp-amount" error={form.errors.amount}>
                            <Input id="rp-amount" type="number" min={1} max={loan.left} step="any" required value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                        </Field>
                    </div>
                    <Field label="Note (optional)" htmlFor="rp-note" error={form.errors.note}>
                        <Input id="rp-note" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                    </Field>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>Record</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Loans(props: Props) {
    const [deciding, setDeciding] = useState<{ row: Row; action: 'approve' | 'reject' } | null>(null);
    const [firstMonth, setFirstMonth] = useState(nextMonth(props.thisMonth));
    const [months, setMonths] = useState('');
    const [recording, setRecording] = useState<Row | null>(null);
    const pending = props.status === 'pending';
    const open = (row: Row, action: 'approve' | 'reject') => { setMonths(String(row.months)); setFirstMonth(nextMonth(props.thisMonth)); setDeciding({ row, action }); };

    return (
        <>
            <Head title="Loans" />
            <PageHeader back={{ href: '/requests', label: 'Requests' }} title="Loans"
                description="Loan requests from My page and the loans being repaid. Until payroll is in the app, record each month's deduction here.">
                <div className="flex flex-wrap gap-2" role="group" aria-label="Filter by status">
                    {Object.entries(props.statuses).map(([key, label]) => (
                        <Button key={key} size="sm" variant={props.status === key ? 'default' : 'outline'}
                            onClick={() => router.get('/loans', key === 'pending' ? {} : { status: key }, { preserveState: true, replace: true })}>
                            {label} {props.counts[key] ?? 0}
                        </Button>
                    ))}
                </div>
            </PageHeader>

            <div className="px-4 py-6 md:px-6">
                {props.loans.length === 0 ? (
                    <EmptyState icon={pending ? CheckCheck : HandCoins} title={pending ? 'No loan requests waiting.' : 'No loans here.'} />
                ) : (
                    <ul className="grid gap-3">
                        {props.loans.map((l) => (
                            <li key={l.id} className="grid gap-3 rounded-lg border p-4 text-sm md:grid-cols-[1fr_auto]">
                                <div className="grid gap-1">
                                    <p className="flex flex-wrap items-center gap-2">
                                        <Link href={`/employees/${l.employee.id}`} className="font-semibold hover:text-cascade hover:underline">{l.employee.name}</Link>
                                        <span className="text-muted-foreground">{[l.employee.code, l.employee.department, l.employee.start_date && `joined ${formatDate(l.employee.start_date)}`].filter(Boolean).join(', ')}</span>
                                        <StatusPill value={l.status_label} tone={loanTone[l.status]} />
                                    </p>
                                    <p className="font-semibold tabular-nums">{loanSummary(l)}</p>
                                    <p>{l.reason}</p>
                                    {l.owing_other > 0 && <p className="text-pending">Still owes {rupees(l.owing_other)} on another loan.</p>}
                                    {l.repayments.length > 0 && (
                                        <ul className="mt-1 flex flex-wrap gap-2">
                                            {l.repayments.map((r) => (
                                                <li key={r.id} className="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs tabular-nums" title={r.note ?? undefined}>
                                                    {monthLabel(r.month)} {rupees(r.amount)}
                                                    <button type="button" className="text-muted-foreground hover:text-destructive" aria-label={`Remove the ${monthLabel(r.month)} deduction`}
                                                        onClick={() => confirm(`Remove the ${monthLabel(r.month)} deduction?`) && router.delete(`/loan-repayments/${r.id}`, { preserveScroll: true })}>
                                                        <Trash2 className="size-3" />
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    <p className="text-xs text-muted-foreground">
                                        Asked {formatDateTime(l.created_at)}{l.decided_at && `, answered ${formatDateTime(l.decided_at)}${l.decided_by ? ` by ${l.decided_by}` : ''}`}
                                        {l.decision_note && `: ${l.decision_note}`}
                                    </p>
                                </div>
                                <div className="flex items-start gap-1 md:justify-end">
                                    {l.status === 'pending' && (<>
                                        <Button size="sm" onClick={() => open(l, 'approve')}><Check /> Approve</Button>
                                        <Button size="sm" variant="outline" onClick={() => open(l, 'reject')}><X /> No</Button>
                                    </>)}
                                    {l.status === 'active' && <Button size="sm" variant="outline" onClick={() => setRecording(l)}><Plus /> Record deduction</Button>}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            {deciding && (
                <DecideDialog url={`/loans/${deciding.row.id}`} action={deciding.action} onClose={() => setDeciding(null)}
                    title={`${deciding.action === 'approve' ? 'Approve' : "Don't approve"}: ${deciding.row.employee.name}, ${rupees(deciding.row.amount)}`}
                    description={deciding.action === 'approve' ? 'Set when deductions start. You can change the months they asked for.' : 'The person sees your reason on their page.'}
                    extra={{ first_month: firstMonth, months }}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="First deduction from" htmlFor="ln-first">
                            <Input id="ln-first" type="month" required value={firstMonth} onChange={(e) => setFirstMonth(e.target.value)} />
                        </Field>
                        <Field label="Months" htmlFor="ln-m" hint={Number(months) > 0 ? `${rupees(Math.ceil(deciding.row.amount / Number(months)))} a month.` : undefined}>
                            <Input id="ln-m" type="number" min={1} max={60} required value={months} onChange={(e) => setMonths(e.target.value)} />
                        </Field>
                    </div>
                </DecideDialog>
            )}
            {recording && <RepaymentDialog loan={recording} thisMonth={props.thisMonth} onClose={() => setRecording(null)} />}
        </>
    );
}
