import { Head, Link, router } from '@inertiajs/react';
import { Check, CheckCheck, Inbox, Paperclip, X } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { balanceText, leaveDates } from '@/components/leave';
import type { LeaveBalance } from '@/components/leave';
import { leaveRequestTone } from '@/components/my-leave-requests';
import type { LeaveRequestRow } from '@/components/my-leave-requests';
import { PageHeader } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/format';

type Row = LeaveRequestRow & {
    employee: { id: number; name: string; code: string; department: string | null };
    balance: LeaveBalance | null; over_per_leave: number; per_leave_days: number | null;
};
type Props = { status: string; statuses: Record<string, string>; counts: Record<string, number>; requests: Row[] };

/** What approving this would do to the balance: "Annual: 9 of 14 left, 4 after" / "goes 2 over". */
function BalanceLine({ r }: { r: Row }) {
    if (r.balance) {
        const after = r.balance.left - r.days;
        return (
            <span className={`block text-xs ${after < 0 ? 'font-semibold text-missing' : 'text-muted-foreground'}`}>
                {r.type}: {balanceText(r.balance)}{r.status === 'pending' && (after < 0 ? `, goes ${-after} over` : `, ${after} after`)}
            </span>
        );
    }
    if (r.over_per_leave > 0) {
        return <span className="block text-xs font-semibold text-missing">{r.over_per_leave} days longer than the {r.per_leave_days}-day allowance</span>;
    }
    return null;
}

function Decide({ row, action, onClose }: { row: Row; action: 'approve' | 'reject'; onClose: () => void }) {
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const submit = () => router.post(`/leave/requests/${row.id}/${action}`, { note }, { preserveScroll: true, onSuccess: onClose, onError: setErrors });
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{action === 'approve' ? 'Approve' : "Don't approve"}: {row.employee.name}, {row.type}</DialogTitle>
                    <DialogDescription>
                        {action === 'approve'
                            ? `${leaveDates(row)}. The leave is recorded, attendance shows On leave and it comes off the balance.`
                            : 'The person sees your reason on My page.'}
                    </DialogDescription>
                </DialogHeader>
                <Field label={action === 'approve' ? 'Note (optional)' : 'Reason'} htmlFor="d-note" error={errors.note ?? errors.status ?? errors.start_date}>
                    <Textarea id="d-note" rows={2} value={note} required={action === 'reject'} onChange={(e) => setNote(e.target.value)}
                        placeholder={action === 'approve' ? 'Form checked, signed by manager' : 'The form has no manager signature'} />
                </Field>
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>Cancel</Button>
                    <Button variant={action === 'approve' ? 'default' : 'destructive'} onClick={submit}>{action === 'approve' ? 'Approve' : "Don't approve"}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function LeaveRequests(props: Props) {
    const [deciding, setDeciding] = useState<{ row: Row; action: 'approve' | 'reject' } | null>(null);
    const pending = props.status === 'pending';

    return (
        <>
            <Head title="Leave requests" />
            <PageHeader
                back={{ href: '/leave', label: 'Leave' }}
                title="Leave requests"
                help="Leave staff applied for on My page. Check the attached form (the manager's approval is on it), then approve or say why not."
            >
                <div className="flex flex-wrap gap-2" role="group" aria-label="Filter by status">
                    {Object.entries(props.statuses).map(([key, label]) => (
                        <Button key={key} size="sm" variant={props.status === key ? 'default' : 'outline'}
                            onClick={() => router.get('/leave/requests', key === 'pending' ? {} : { status: key }, { preserveState: true, replace: true })}>
                            {label} {props.counts[key] ?? 0}
                        </Button>
                    ))}
                </div>
            </PageHeader>

            <div className="px-4 py-6 md:px-6">
                {props.requests.length === 0 ? (
                    <EmptyState icon={pending ? CheckCheck : Inbox} title={pending ? 'Nothing waiting. Every request has an answer.' : 'No requests here.'} />
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[56rem] text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-semibold">Employee</th>
                                    <th className="px-3 py-2 font-semibold">Leave</th>
                                    <th className="px-3 py-2 font-semibold">Reason</th>
                                    <th className="px-3 py-2 font-semibold">Form</th>
                                    <th className="px-3 py-2 font-semibold">{pending ? 'Sent' : 'Answer'}</th>
                                    {pending && <th className="px-3 py-2"><span className="sr-only">Decide</span></th>}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {props.requests.map((r) => (
                                    <tr key={r.id} className="align-top">
                                        <td className="px-3 py-2">
                                            <Link href={`/employees/${r.employee.id}#leave`} className="font-semibold hover:text-cascade hover:underline">{r.employee.name}</Link>
                                            <span className="block text-xs text-muted-foreground">{[r.employee.code, r.employee.department].filter(Boolean).join(', ')}</span>
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className="font-semibold">{r.type}</span> <span className="tabular-nums">{leaveDates(r)}</span>
                                            <span className="block text-xs text-muted-foreground tabular-nums">{r.days} {r.days === 1 ? 'working day' : 'working days'}</span>
                                            <BalanceLine r={r} />
                                        </td>
                                        <td className="max-w-72 px-3 py-2">{r.reason}</td>
                                        <td className="px-3 py-2">
                                            {r.attachment ? (
                                                <a href={`/leave/requests/${r.id}/file`} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-cascade hover:underline">
                                                    <Paperclip className="size-3.5" /> Open
                                                </a>
                                            ) : <span className="text-xs text-muted-foreground">On paper</span>}
                                        </td>
                                        <td className="px-3 py-2 text-xs text-muted-foreground">
                                            {pending ? formatDateTime(r.created_at) : (
                                                <>
                                                    <StatusPill value={r.status_label} tone={leaveRequestTone[r.status]} />
                                                    <span className="mt-1 block">{r.decided_by}{r.decided_at && `, ${formatDateTime(r.decided_at)}`}</span>
                                                    {r.decision_note && <span className="block text-foreground">{r.decision_note}</span>}
                                                </>
                                            )}
                                        </td>
                                        {pending && (
                                            <td className="px-3 py-2">
                                                <div className="flex justify-end gap-1">
                                                    <Button size="sm" onClick={() => setDeciding({ row: r, action: 'approve' })}><Check /> Approve</Button>
                                                    <Button size="sm" variant="outline" onClick={() => setDeciding({ row: r, action: 'reject' })}><X /> No</Button>
                                                </div>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
            {deciding && <Decide row={deciding.row} action={deciding.action} onClose={() => setDeciding(null)} />}
        </>
    );
}
