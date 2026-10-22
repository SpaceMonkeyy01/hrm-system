import { Head, Link, router, useForm } from '@inertiajs/react';
import { Banknote, Check, CheckCheck, Paperclip, Stethoscope, X } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { CompanySwitch, companyParam } from '@/components/company-switch';
import { DecideDialog } from '@/components/decide-dialog';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { rupees } from '@/components/loans';
import { medicalSummary, medicalTone, paidLine } from '@/components/medical';
import type { MedicalRow } from '@/components/medical';
import { PageHeader } from '@/components/page-header';
import { EmployeeCareTabs } from '@/components/section-tabs';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/format';

/** app/Http/Controllers/MedicalRequestController.php index() */
type Row = MedicalRow & { employee: { id: number; name: string; code: string; department: string | null }; this_year: number };
type Props = {
    status: string; company: number | null; companies: { id: number; name: string }[]; statuses: Record<string, string>; counts: Record<string, number>;
    requests: Row[]; year: number; today: string;
};

function PaidDialog({ row, today, onClose }: { row: Row; today: string; onClose: () => void }) {
    const form = useForm({ paid_on: today, paid_note: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/medical/${row.id}/paid`, { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Mark as paid: {row.employee.name}, {rupees(row.approved_amount)}</DialogTitle>
                        <DialogDescription>The person sees the date and how it was paid on their page.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Paid on" htmlFor="md-paid-on" error={form.errors.paid_on ?? (form.errors as Record<string, string>).status}>
                            <Input id="md-paid-on" type="date" required max={today} value={form.data.paid_on} onChange={(e) => form.setData('paid_on', e.target.value)} />
                        </Field>
                        <Field label="How (optional)" htmlFor="md-paid-note" error={form.errors.paid_note}>
                            <Input id="md-paid-note" placeholder="Bank transfer, cash, with salary" value={form.data.paid_note} onChange={(e) => form.setData('paid_note', e.target.value)} />
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>Mark as paid</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function MedicalRequests(props: Props) {
    const [deciding, setDeciding] = useState<{ row: Row; action: 'approve' | 'reject' } | null>(null);
    const [approved, setApproved] = useState('');
    const [paying, setPaying] = useState<Row | null>(null);
    const pending = props.status === 'pending';
    const open = (row: Row, action: 'approve' | 'reject') => { setApproved(String(row.amount)); setDeciding({ row, action }); };

    return (
        <>
            <Head title="Medical requests" />
            <PageHeader actions={<CompanySwitch companies={props.companies} value={props.company}
                    onChange={(id) => router.get('/medical', { ...(props.status === 'pending' ? {} : { status: props.status }), ...companyParam(id) }, { preserveState: true, replace: true })} />}
                back={{ href: `/requests${props.company ? `?company=${props.company}` : ''}`, label: 'Requests' }} title="Medical requests" tabs={<EmployeeCareTabs />}
                help="Bills staff claim back and advances for treatment, sent from My page. Approve the amount asked or less, then mark it paid once the money has gone out. There is no yearly limit; each request shows what the person had approved this year." />

            <div className="grid gap-4 px-4 py-6 md:px-6">
                <div className="flex flex-wrap gap-2" role="group" aria-label="Filter by status">
                    {Object.entries(props.statuses).map(([key, label]) => (
                        <Button key={key} size="sm" variant={props.status === key ? 'default' : 'outline'}
                            onClick={() => router.get('/medical', { ...(key === 'pending' ? {} : { status: key }), ...companyParam(props.company) }, { preserveState: true, replace: true })}>
                            {label} {props.counts[key] ?? 0}
                        </Button>
                    ))}
                </div>
                {props.requests.length === 0 ? (
                    <EmptyState icon={pending ? CheckCheck : Stethoscope} title={pending ? 'No medical requests waiting.' : 'No medical requests here.'} />
                ) : (
                    <ul className="grid gap-3">
                        {props.requests.map((r) => (
                            <li key={r.id} className="grid gap-3 rounded-lg border p-4 text-sm md:grid-cols-[1fr_auto]">
                                <div className="grid gap-1">
                                    <p className="flex flex-wrap items-center gap-2">
                                        <Link href={`/employees/${r.employee.id}`} className="font-semibold hover:text-cascade hover:underline">{r.employee.name}</Link>
                                        <span className="text-muted-foreground">{[r.employee.code, r.employee.department].filter(Boolean).join(', ')}</span>
                                        <StatusPill value={r.status_label} tone={medicalTone[r.status]} />
                                    </p>
                                    <p className="font-semibold tabular-nums">{medicalSummary(r)}</p>
                                    <p>{r.reason}</p>
                                    {r.attachment ? (
                                        <a href={`/medical/${r.id}/file`} target="_blank" rel="noreferrer" className="inline-flex w-fit items-center gap-1 text-cascade hover:underline">
                                            <Paperclip className="size-3.5" /> {r.attachment.name}
                                        </a>
                                    ) : <p className="text-xs text-muted-foreground">{r.kind === 'reimbursement' ? 'Bills on paper' : 'Nothing attached'}</p>}
                                    <p className="text-xs text-muted-foreground tabular-nums">Approved for them in {props.year} so far: {r.this_year ? rupees(r.this_year) : 'nothing'}</p>
                                    {r.paid_on && <p className="font-semibold">{paidLine(r)}{r.paid_by && <span className="font-normal text-muted-foreground">, recorded by {r.paid_by}</span>}</p>}
                                    <p className="text-xs text-muted-foreground">
                                        Sent {formatDateTime(r.created_at)}{r.decided_at && `, answered ${formatDateTime(r.decided_at)}${r.decided_by ? ` by ${r.decided_by}` : ''}`}
                                        {r.decision_note && `: ${r.decision_note}`}
                                    </p>
                                </div>
                                <div className="flex items-start gap-1 md:justify-end">
                                    {r.status === 'pending' && (<>
                                        <Button size="sm" onClick={() => open(r, 'approve')}><Check /> Approve</Button>
                                        <Button size="sm" variant="outline" onClick={() => open(r, 'reject')}><X /> No</Button>
                                    </>)}
                                    {r.status === 'approved' && <Button size="sm" variant="outline" onClick={() => setPaying(r)}><Banknote /> Mark as paid</Button>}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            {deciding && (
                <DecideDialog url={`/medical/${deciding.row.id}`} action={deciding.action} onClose={() => setDeciding(null)}
                    title={`${deciding.action === 'approve' ? 'Approve' : "Don't approve"}: ${deciding.row.employee.name}, ${rupees(deciding.row.amount)}`}
                    description={deciding.action === 'approve' ? 'Approve what they asked for, or lower it to what the company covers.' : 'The person sees your reason on their page.'}
                    extra={{ approved_amount: approved }}>
                    <Field label="Amount to approve (Rs)" htmlFor="md-approved"
                        hint={Number(approved) < deciding.row.amount ? `They asked for ${rupees(deciding.row.amount)}.` : undefined}>
                        <Input id="md-approved" type="number" min={1} max={deciding.row.amount} step="any" required value={approved} onChange={(e) => setApproved(e.target.value)} />
                    </Field>
                </DecideDialog>
            )}
            {paying && <PaidDialog row={paying} today={props.today} onClose={() => setPaying(null)} />}
        </>
    );
}
