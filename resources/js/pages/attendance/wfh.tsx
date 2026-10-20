import { Head, Link, router } from '@inertiajs/react';
import { Check, CheckCheck, Inbox, X } from 'lucide-react';
import { useState } from 'react';
import { DecideDialog } from '@/components/decide-dialog';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/lib/format';
import type { Tone } from '@/types';

/** app/Models/WfhRequest.php props() + employee */
type Row = {
    id: number; start_date: string; end_date: string; days: number; reason: string; status: string; status_label: string;
    decision_note: string | null; decided_by: string | null; decided_at: string | null; created_at: string | null;
    employee: { id: number; name: string; code: string; department: string | null; work_mode: string | null };
};
type Props = { status: string; statuses: Record<string, string>; counts: Record<string, number>; requests: Row[] };

const tone: Record<string, Tone> = { pending: 'pending', approved: 'done', rejected: 'missing', cancelled: 'neutral' };
const dates = (r: Row) => (r.start_date === r.end_date ? formatDate(r.start_date) : `${formatDate(r.start_date)} to ${formatDate(r.end_date)}`);

export default function WfhRequests(props: Props) {
    const [deciding, setDeciding] = useState<{ row: Row; action: 'approve' | 'reject' } | null>(null);
    const pending = props.status === 'pending';

    return (
        <>
            <Head title="Work from home requests" />
            <PageHeader
                back={{ href: '/requests', label: 'Requests' }}
                title="Work from home requests"
                description="Days staff asked to work from home. Approved days show WFH approved on attendance; remote time on other days is flagged."
            >
                <div className="flex flex-wrap gap-2" role="group" aria-label="Filter by status">
                    {Object.entries(props.statuses).map(([key, label]) => (
                        <Button key={key} size="sm" variant={props.status === key ? 'default' : 'outline'}
                            onClick={() => router.get('/attendance/wfh', key === 'pending' ? {} : { status: key }, { preserveState: true, replace: true })}>
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
                        <table className="w-full min-w-[48rem] text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-semibold">Employee</th>
                                    <th className="px-3 py-2 font-semibold">Days</th>
                                    <th className="px-3 py-2 font-semibold">Reason</th>
                                    <th className="px-3 py-2 font-semibold">{pending ? 'Sent' : 'Answer'}</th>
                                    {pending && <th className="px-3 py-2"><span className="sr-only">Decide</span></th>}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {props.requests.map((r) => (
                                    <tr key={r.id} className="align-top">
                                        <td className="px-3 py-2">
                                            <Link href={`/employees/${r.employee.id}#time`} className="font-semibold hover:text-cascade hover:underline">{r.employee.name}</Link>
                                            <span className="block text-xs text-muted-foreground">
                                                {[r.employee.code, r.employee.department, r.employee.work_mode && `works ${r.employee.work_mode.toLowerCase()}`].filter(Boolean).join(', ')}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 whitespace-nowrap tabular-nums">
                                            {dates(r)}
                                            <span className="block text-xs text-muted-foreground">{r.days} {r.days === 1 ? 'day' : 'days'}</span>
                                        </td>
                                        <td className="max-w-80 px-3 py-2">{r.reason}</td>
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
            {deciding && (
                <DecideDialog url={`/attendance/wfh/${deciding.row.id}`} action={deciding.action} onClose={() => setDeciding(null)}
                    title={`${deciding.action === 'approve' ? 'Approve' : "Don't approve"}: ${deciding.row.employee.name}, ${dates(deciding.row)}`}
                    description={deciding.action === 'approve' ? 'Those days show WFH approved on attendance, and remote time on them is not flagged.' : 'The person sees your reason on their page.'} />
            )}
        </>
    );
}
