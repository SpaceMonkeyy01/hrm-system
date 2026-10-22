import { Head, Link, router } from '@inertiajs/react';
import { Check, CheckCheck, Inbox, X } from 'lucide-react';
import { useState } from 'react';
import { CompanySwitch, companyParam } from '@/components/company-switch';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { PageHeader } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/format';
import type { Tone } from '@/types';

type Row = {
    id: number; date: string; check_in: string | null; check_out: string | null; reason: string; status: string; status_label: string;
    decision_note: string | null; decided_by: string | null; decided_at: string | null; created_at: string | null;
    employee: { id: number; name: string; code: string; department: string | null };
    now: { status: string | null; in: string | null; out: string | null; punches: string[] } | null;
};
type Props = { status: string; company: number | null; companies: { id: number; name: string }[]; statuses: Record<string, string>; counts: Record<string, number>; requests: Row[]; attendanceStatuses: Record<string, Tone> };

const tone: Record<string, Tone> = { pending: 'pending', approved: 'done', rejected: 'missing', cancelled: 'neutral' };

function Decide({ row, action, onClose }: { row: Row; action: 'approve' | 'reject'; onClose: () => void }) {
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const submit = () => router.post(`/attendance/requests/${row.id}/${action}`, { note }, { preserveScroll: true, onSuccess: onClose, onError: setErrors });
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{action === 'approve' ? 'Approve' : "Don't approve"}: {row.employee.name}, {formatDate(row.date)}</DialogTitle>
                    <DialogDescription>
                        {action === 'approve'
                            ? 'The asked-for times replace the punches for that day. You can change them later on the Attendance page.'
                            : 'The person sees your reason on their page.'}
                    </DialogDescription>
                </DialogHeader>
                <Field label={action === 'approve' ? 'Note (optional)' : 'Reason'} htmlFor="d-note" error={errors.note ?? errors.request}>
                    <Textarea id="d-note" rows={2} value={note} required={action === 'reject'} onChange={(e) => setNote(e.target.value)}
                        placeholder={action === 'approve' ? 'Confirmed with manager' : 'The machine shows you came in at 10:20'} />
                </Field>
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>Cancel</Button>
                    <Button variant={action === 'approve' ? 'default' : 'destructive'} onClick={submit}>
                        {action === 'approve' ? 'Approve' : "Don't approve"}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function AttendanceRequests(props: Props) {
    const [deciding, setDeciding] = useState<{ row: Row; action: 'approve' | 'reject' } | null>(null);
    const pending = props.status === 'pending';

    return (
        <>
            <Head title="Attendance requests" />
            <PageHeader
                actions={<CompanySwitch companies={props.companies} value={props.company}
                    onChange={(id) => router.get('/attendance/requests', { ...(props.status === 'pending' ? {} : { status: props.status }), ...companyParam(id) }, { preserveState: true, replace: true })} />}
                back={{ href: `/attendance${props.company ? `?company=${props.company}` : ''}`, label: 'Attendance' }}
                title="Attendance requests"
                help="Fixes staff asked for on My page: a forgotten punch, the machine down, working from another site."
            >
                <div className="flex flex-wrap gap-2" role="group" aria-label="Filter by status">
                    {Object.entries(props.statuses).map(([key, label]) => (
                        <Button key={key} size="sm" variant={props.status === key ? 'default' : 'outline'}
                            onClick={() => router.get('/attendance/requests', { ...(key === 'pending' ? {} : { status: key }), ...companyParam(props.company) }, { preserveState: true, replace: true })}>
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
                                    <th className="px-3 py-2 font-semibold">Day</th>
                                    <th className="px-3 py-2 font-semibold">Asked for</th>
                                    <th className="px-3 py-2 font-semibold">Now</th>
                                    <th className="px-3 py-2 font-semibold">Reason</th>
                                    <th className="px-3 py-2 font-semibold">{pending ? 'Sent' : 'Answer'}</th>
                                    {pending && <th className="px-3 py-2"><span className="sr-only">Decide</span></th>}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {props.requests.map((r) => (
                                    <tr key={r.id} className="align-top">
                                        <td className="px-3 py-2">
                                            <Link href={`/employees/${r.employee.id}#attendance`} className="font-semibold hover:text-cascade hover:underline">{r.employee.name}</Link>
                                            <span className="block text-xs text-muted-foreground">{[r.employee.code, r.employee.department].filter(Boolean).join(', ')}</span>
                                        </td>
                                        <td className="px-3 py-2 whitespace-nowrap tabular-nums">
                                            <Link href={`/attendance?date=${r.date}&q=${encodeURIComponent(r.employee.code)}`} className="hover:text-cascade hover:underline">{formatDate(r.date)}</Link>
                                        </td>
                                        <td className="px-3 py-2 whitespace-nowrap tabular-nums">
                                            In {r.check_in ?? <span className="text-muted-foreground">as punched</span>}
                                            <span className="block">Out {r.check_out ?? <span className="text-muted-foreground">as punched</span>}</span>
                                        </td>
                                        <td className="px-3 py-2">
                                            {r.now?.status ? <StatusPill value={r.now.status} tone={props.attendanceStatuses[r.now.status] ?? 'neutral'} /> : null}
                                            <span className="mt-0.5 block text-xs text-muted-foreground tabular-nums">Punches: {r.now?.punches.length ? r.now.punches.join(', ') : 'none'}</span>
                                        </td>
                                        <td className="max-w-72 px-3 py-2">{r.reason}</td>
                                        <td className="px-3 py-2 text-xs text-muted-foreground">
                                            {pending ? formatDateTime(r.created_at) : (
                                                <>
                                                    <StatusPill value={r.status_label} tone={tone[r.status] ?? 'neutral'} />
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
