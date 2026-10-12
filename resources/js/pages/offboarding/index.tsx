import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { NativeSelect, toOptions } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { computedTone, StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format';
import type { SharedProps } from '@/types';

type Case = {
    id: number; type: string; status: string; notice_date: string | null; last_working_day: string | null;
    employee: { id: number; code: string; name: string; department: string | null }; cleared: number; total: number;
};

const statusLabel: Record<string, string> = { in_progress: 'In progress', completed: 'Completed', cancelled: 'Cancelled' };

export default function OffboardingIndex({ cases, filters, types, employees }: {
    cases: Case[]; filters: { status: string; q?: string }; types: string[]; employees: { id: number; label: string }[];
}) {
    const { auth } = usePage<SharedProps>().props;
    const startFor = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('start') : null;
    const [open, setOpen] = useState(!!startFor && auth.can.edit);
    const form = useForm({ employee_id: startFor ?? '', type: types[0], notice_date: '', last_working_day: '', reason: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v])));
        form.post('/offboarding');
    };

    return (
        <>
            <Head title="Offboarding" />
            <PageHeader
                title="Offboarding"
                description="Leavers, their last working day and clearance. Completing an offboarding marks the employee as exited."
                actions={auth.can.edit && <Button onClick={() => setOpen(true)}><Plus /> Start offboarding</Button>}
            >
                <div className="flex gap-2">
                    <NativeSelect aria-label="Status" className="w-44" value={filters.status}
                        onChange={(e) => router.get('/offboarding', { status: e.target.value }, { preserveState: true, replace: true })}
                        options={[{ value: 'in_progress', label: 'In progress' }, { value: 'completed', label: 'Completed' }, { value: 'cancelled', label: 'Cancelled' }, { value: 'all', label: 'All' }]} />
                </div>
            </PageHeader>

            <div className="px-4 py-4 md:px-6">
                {cases.length === 0 ? (
                    <EmptyState title={filters.status === 'in_progress' ? 'Nobody is leaving right now.' : 'No offboardings here.'}>
                        {auth.can.edit && 'Start one when someone resigns, retires or their contract ends.'}
                    </EmptyState>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[760px] text-sm">
                            <thead>
                                <tr className="border-b text-left text-xs text-muted-foreground">
                                    <th className="py-2.5 pr-3 font-semibold">Employee</th>
                                    <th className="py-2.5 pr-3 font-semibold">Type</th>
                                    <th className="py-2.5 pr-3 font-semibold">Notice given</th>
                                    <th className="py-2.5 pr-3 font-semibold">Last working day</th>
                                    <th className="py-2.5 pr-3 font-semibold">Clearance</th>
                                    <th className="py-2.5 font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {cases.map((c) => (
                                    <tr key={c.id} className="hover:bg-[#f7f9fc]">
                                        <td className="py-2.5 pr-3">
                                            <Link href={`/offboarding/${c.id}`} className="font-semibold hover:text-cascade hover:underline">{c.employee.name}</Link>
                                            <span className="block text-xs text-muted-foreground">{c.employee.code}{c.employee.department ? `, ${c.employee.department}` : ''}</span>
                                        </td>
                                        <td className="py-2.5 pr-3">{c.type}</td>
                                        <td className="py-2.5 pr-3 tabular-nums">{formatDate(c.notice_date) || '-'}</td>
                                        <td className="py-2.5 pr-3 tabular-nums">{formatDate(c.last_working_day) || '-'}</td>
                                        <td className="py-2.5 pr-3">
                                            <span className="tabular-nums">{c.cleared} of {c.total}</span>
                                            <span className="mt-1 block h-1.5 w-28 overflow-hidden rounded bg-muted">
                                                <span className="block h-full bg-done" style={{ width: `${(c.cleared / Math.max(c.total, 1)) * 100}%` }} />
                                            </span>
                                        </td>
                                        <td className="py-2.5"><StatusPill value={statusLabel[c.status]} tone={computedTone(c.status)} /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Start offboarding</DialogTitle>
                        <DialogDescription>The employee is marked as leaving. Their clearance checklist opens next.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="grid gap-4">
                        <Field label="Employee" htmlFor="ob-emp" error={form.errors.employee_id}>
                            <NativeSelect id="ob-emp" required value={form.data.employee_id} placeholder="Choose an active employee"
                                onChange={(e) => form.setData('employee_id', e.target.value)} options={employees.map((x) => ({ value: x.id, label: x.label }))} />
                        </Field>
                        <Field label="Type" htmlFor="ob-type" error={form.errors.type}>
                            <NativeSelect id="ob-type" value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} options={toOptions(types)} />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Notice given on" htmlFor="ob-notice" error={form.errors.notice_date}>
                                <Input id="ob-notice" type="date" value={form.data.notice_date} onChange={(e) => form.setData('notice_date', e.target.value)} />
                            </Field>
                            <Field label="Last working day" htmlFor="ob-last" error={form.errors.last_working_day}>
                                <Input id="ob-last" type="date" value={form.data.last_working_day} onChange={(e) => form.setData('last_working_day', e.target.value)} />
                            </Field>
                        </div>
                        <Field label="Reason" htmlFor="ob-reason" error={form.errors.reason}>
                            <Textarea id="ob-reason" rows={3} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                        </Field>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={form.processing}>Start offboarding</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
