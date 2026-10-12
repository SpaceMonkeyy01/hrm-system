import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Inbox, Pencil, Upload } from 'lucide-react';
import { useState } from 'react';
import { AdjustDialog, DayStatus, duration, TimeCell } from '@/components/attendance';
import type { AttendanceDay } from '@/components/attendance';
import { EmptyState } from '@/components/empty-state';
import { NativeSelect } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate, plural } from '@/lib/format';
import type { SharedProps, Tone } from '@/types';

type Row = AttendanceDay & { employee: { id: number; code: string; name: string; department: string | null; linked: boolean } };
type Company = { id: number; name: string; departments: { id: number; name: string }[] };
type Props = {
    date: string; today: string; rows: Row[]; counts: Record<string, number>;
    filters: { company?: number; department?: number; status?: string; q?: string };
    companies: Company[]; statuses: Record<string, Tone>; hasSchedules: boolean; unlinked: number; lastImport: string | null; pendingRequests: number;
};

function shift(date: string, days: number): string {
    const [y, m, d] = date.split('-').map(Number);
    const t = new Date(Date.UTC(y, m - 1, d + days));
    return t.toISOString().slice(0, 10);
}

export default function AttendanceIndex(props: Props) {
    const { auth } = usePage<SharedProps>().props;
    const can = auth.can.edit;
    const [editing, setEditing] = useState<Row | null>(null);
    const [q, setQ] = useState(props.filters.q ?? '');
    const go = (changes: Record<string, unknown>) =>
        router.get('/attendance', { ...props.filters, date: props.date, ...changes }, { preserveState: true, preserveScroll: true, replace: true });

    const chips = ['Present', 'Late', 'Half day', 'Missing punch', 'Absent', 'In office', 'Not in yet', 'On leave', 'Holiday', 'Worked day off', 'Day off', 'No punches', 'Not linked']
        .filter((s) => props.counts[s]);
    const departments = props.companies.flatMap((c) => c.departments.map((d) => ({ value: d.id, label: props.companies.length > 1 ? `${d.name} (${c.name})` : d.name })));

    return (
        <>
            <Head title="Attendance" />
            <PageHeader
                title="Attendance"
                description={`Worked out from machine and HR.my punches, leave, holidays and each person's work schedule.${props.lastImport ? ` Punches loaded up to ${formatDate(props.lastImport)}.` : ''}`}
                actions={can && (
                    <>
                        <Button variant="outline" asChild><Link href="/attendance/requests"><Inbox /> Requests{props.pendingRequests ? ` (${props.pendingRequests})` : ''}</Link></Button>
                        <Button variant="outline" asChild><Link href="/attendance/imports"><Upload /> Upload attendance file</Link></Button>
                    </>
                )}
            >
                <div className="flex flex-wrap items-center gap-2">
                    <Button variant="outline" size="icon" aria-label="Previous day" onClick={() => go({ date: shift(props.date, -1) })}><ChevronLeft /></Button>
                    <Input type="date" className="w-40" value={props.date} max={props.today} aria-label="Date" onChange={(e) => e.target.value && go({ date: e.target.value })} />
                    <Button variant="outline" size="icon" aria-label="Next day" disabled={props.date >= props.today} onClick={() => go({ date: shift(props.date, 1) })}><ChevronRight /></Button>
                    {props.date !== props.today && <Button variant="ghost" onClick={() => go({ date: props.today })}>Today</Button>}
                    <NativeSelect className="w-56" value={props.filters.department ?? ''} placeholder="All departments" options={departments}
                        aria-label="Department" onChange={(e) => go({ department: e.target.value || undefined })} />
                    <form onSubmit={(e) => { e.preventDefault(); go({ q: q || undefined }); }}>
                        <Input className="w-56" placeholder="Search name or code" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search" />
                    </form>
                </div>
            </PageHeader>

            <div className="grid gap-4 px-4 py-6 md:px-6">
                {!props.hasSchedules && (
                    <p className="rounded-lg border border-[#ecd29c] bg-pending-soft px-4 py-3 text-sm">
                        No work schedules yet, so nobody is marked late, absent or on a half day. <Link className="font-semibold text-cascade underline" href="/attendance/schedules">Add work schedules</Link>.
                    </p>
                )}
                {can && props.unlinked > 0 && (
                    <p className="rounded-lg border bg-info-soft px-4 py-3 text-sm">
                        {plural(props.unlinked, 'machine or HR.my ID')} {props.unlinked === 1 ? "isn't" : "aren't"} linked to an employee yet, so their punches don't show here. <Link className="font-semibold text-cascade underline" href="/attendance/imports#link">Link them</Link>.
                    </p>
                )}

                <div className="flex flex-wrap gap-2" role="group" aria-label="Filter by status">
                    <Button size="sm" variant={!props.filters.status ? 'default' : 'outline'} onClick={() => go({ status: undefined })}>
                        Everyone {Object.entries(props.counts).filter(([k]) => k !== 'Late').reduce((n, [, v]) => n + v, 0)}
                    </Button>
                    {chips.map((s) => (
                        <Button key={s} size="sm" variant={props.filters.status === s ? 'default' : 'outline'} onClick={() => go({ status: s })}>
                            {s} {props.counts[s]}
                        </Button>
                    ))}
                </div>

                {props.rows.length === 0 ? (
                    <EmptyState title={props.filters.status || props.filters.q || props.filters.department ? 'Nobody matches these filters.' : 'No attendance for this day.'} />
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[56rem] text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-semibold">Employee</th>
                                    <th className="px-3 py-2 font-semibold">Schedule</th>
                                    <th className="px-3 py-2 font-semibold">In</th>
                                    <th className="px-3 py-2 font-semibold">Out</th>
                                    <th className="px-3 py-2 font-semibold">Worked</th>
                                    <th className="px-3 py-2 font-semibold">Status</th>
                                    <th className="px-3 py-2 font-semibold">Punches</th>
                                    {can && <th className="px-3 py-2"><span className="sr-only">Edit</span></th>}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {props.rows.map((r) => (
                                    <tr key={r.employee.id} className="hover:bg-accent/50">
                                        <td className="px-3 py-2">
                                            <Link href={`/employees/${r.employee.id}#attendance`} className="font-semibold hover:text-cascade hover:underline">{r.employee.name}</Link>
                                            <span className="block text-xs text-muted-foreground">{[r.employee.code, r.employee.department].filter(Boolean).join(', ')}</span>
                                        </td>
                                        <td className="px-3 py-2 text-muted-foreground">{r.schedule ?? 'None'}</td>
                                        <td className="px-3 py-2"><TimeCell time={r.in} source={r.in_source} /></td>
                                        <td className="px-3 py-2"><TimeCell time={r.out} source={r.out_source} /></td>
                                        <td className="px-3 py-2 tabular-nums">{duration(r.worked)}</td>
                                        <td className="px-3 py-2">
                                            <DayStatus day={r} statuses={props.statuses} />
                                            {r.note && <span className="mt-0.5 block max-w-64 truncate text-xs text-muted-foreground" title={r.note}>{r.note}</span>}
                                        </td>
                                        <td className="px-3 py-2 text-xs text-muted-foreground tabular-nums">{r.punchTimes.join(', ')}</td>
                                        {can && (
                                            <td className="px-3 py-2 text-right">
                                                <Button variant="ghost" size="sm" aria-label={`Set times for ${r.employee.name}`} onClick={() => setEditing(r)}><Pencil /></Button>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {editing && (
                <AdjustDialog open onClose={() => setEditing(null)} employee={{ id: editing.employee.id, name: editing.employee.name }} day={editing} />
            )}
        </>
    );
}
