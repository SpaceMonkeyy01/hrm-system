import { Head, router, usePage } from '@inertiajs/react';
import { Download, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ChecklistGrid } from '@/components/checklist-grid';
import type { ExtraColumn, GridRow } from '@/components/checklist-grid';
import { EmptyState } from '@/components/empty-state';
import { NativeSelect, toOptions } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { computedTone, StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';
import { cn, query } from '@/lib/utils';
import type { ChecklistColumn, CompanyOption, OptionSets, SharedProps } from '@/types';

type Row = GridRow & {
    company: string | null; department: string | null; cohort: string | null; start_date: string | null;
    employment_type: string | null; probation_end_date: string | null; probation_result: string | null;
    overdue: boolean; ending: boolean; orientation: string; documents: string; status: string;
};
type Filters = Partial<Record<'q' | 'company' | 'department' | 'cohort' | 'status' | 'orientation' | 'docs' | 'legal' | 'probation', string>>;
type Props = {
    rows: Row[]; columns: ChecklistColumn[]; optionSets: OptionSets; filters: Filters; companies: CompanyOption[];
    cohorts: string[]; probationResults: string[]; notInOnboarding: number;
};

export default function OnboardingIndex({ rows, columns, optionSets, filters, companies, cohorts, probationResults, notInOnboarding }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const can = auth.can.edit;
    const [q, setQ] = useState(filters.q ?? '');
    const apply = (patch: Filters) => router.get('/onboarding', query({ ...filters, ...patch }), { preserveState: true, preserveScroll: true, replace: true });

    useEffect(() => {
        if ((filters.q ?? '') === q) return;
        const t = setTimeout(() => apply({ q }), 300);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q]);

    const saveField = (id: number, data: Record<string, string | null>, onSuccess?: () => void) =>
        router.put(`/onboarding/${id}`, data, { preserveScroll: true, preserveState: true, only: ['rows'], onSuccess,
            onError: (e) => toast.error(Object.values(e)[0] ?? 'Not saved.') });

    // Choosing "Extend" needs a new, later end date before it is saved (the server checks this too).
    const [extending, setExtending] = useState<{ id: number; date: string } | null>(null);
    const dayAfter = (date: string | null) => {
        if (!date) return undefined;
        const d = new Date(`${date}T00:00:00Z`);
        d.setUTCDate(d.getUTCDate() + 1);
        return d.toISOString().slice(0, 10);
    };
    const pickResult = (r: Row, value: string) => {
        if (value === 'Extend' && r.probation_result !== 'Extend') {
            setExtending({ id: r.id, date: '' });
            return;
        }
        setExtending(null);
        saveField(r.id, { probation_result: value || null });
    };
    const saveExtension = () => {
        if (!extending) return;
        if (!extending.date) {
            toast.error('Set the new probation end date (later than the current one).');
            return;
        }
        saveField(extending.id, { probation_result: 'Extend', probation_end_date: extending.date }, () => setExtending(null));
    };

    const company = companies.find((c) => String(c.id) === String(filters.company));
    const departments = company ? company.departments : companies.flatMap((c) => c.departments.map((d) => ({ ...d, name: `${d.name} (${c.name})` })));
    const active = Object.entries(filters).filter(([k, v]) => v && k !== 'q').length > 0;

    const before: ExtraColumn<Row>[] = [
        { key: 'department', header: 'Department', className: 'min-w-44 max-w-52', cell: (r) => <span className="line-clamp-2 text-xs">{r.department ?? <span className="text-muted-foreground">-</span>}</span> },
        {
            key: 'cohort', header: 'Group', className: 'w-20',
            cell: (r) => can ? (
                <select aria-label={`Group for ${r.name}`} className="h-7 rounded border border-input bg-white px-1 text-xs" value={r.cohort ?? ''}
                    onChange={(e) => saveField(r.id, { cohort: e.target.value || null })}>
                    <option value="">-</option>
                    {cohorts.map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
            ) : <span className="text-xs">{r.cohort ?? '-'}</span>,
        },
        { key: 'start', header: 'Start date', className: 'w-28 text-xs tabular-nums', cell: (r) => r.start_date ? formatDate(r.start_date) : <span className="text-muted-foreground">-</span> },
    ];
    const after: ExtraColumn<Row>[] = [
        {
            key: 'end', header: 'Probation ends', className: 'min-w-28 text-xs tabular-nums',
            cell: (r) => extending?.id === r.id ? (
                <div className="flex items-center gap-1">
                    <input type="date" aria-label={`New probation end date for ${r.name}`} className="h-7 rounded border border-input bg-white px-1 text-xs"
                        min={dayAfter(r.probation_end_date)} value={extending.date} autoFocus
                        onChange={(e) => setExtending({ id: r.id, date: e.target.value })}
                        onKeyDown={(e) => { if (e.key === 'Enter') saveExtension(); if (e.key === 'Escape') setExtending(null); }} />
                    <Button size="sm" className="h-7 px-2 text-xs" onClick={saveExtension}>Extend</Button>
                    <Button size="sm" variant="ghost" className="h-7 px-2 text-xs" onClick={() => setExtending(null)}>Cancel</Button>
                </div>
            ) : r.probation_end_date ? (
                <span className={cn(r.overdue && 'font-semibold text-missing', r.ending && 'font-semibold text-pending')} title={r.overdue ? `Past the end date, still ${r.probation_result}` : undefined}>
                    {formatDate(r.probation_end_date)}
                </span>
            ) : <span className="text-muted-foreground">-</span>,
        },
        {
            key: 'result', header: 'Probation result', className: 'w-28',
            cell: (r) => can ? (
                <select aria-label={`Probation result for ${r.name}`} className="h-7 rounded border border-input bg-white px-1 text-xs"
                    value={extending?.id === r.id ? 'Extend' : (r.probation_result ?? '')}
                    onChange={(e) => pickResult(r, e.target.value)}>
                    <option value="">Not tracked</option>
                    {probationResults.map((p) => <option key={p} value={p}>{p}</option>)}
                </select>
            ) : <span className="text-xs">{r.probation_result ?? 'Not tracked'}</span>,
        },
        { key: 'orientation', header: 'Orientation', className: 'w-28', cell: (r) => <StatusPill value={r.orientation} tone={computedTone(r.orientation)} /> },
        { key: 'documents', header: 'Documents', className: 'w-24', cell: (r) => <StatusPill value={r.documents} tone={computedTone(r.documents)} /> },
        { key: 'status', header: 'Onboarding', className: 'w-28', cell: (r) => <StatusPill value={r.status === 'Onboarding Complete' ? 'Complete' : 'In progress'} tone={computedTone(r.status)} /> },
    ];

    return (
        <>
            <Head title="Onboarding" />
            <PageHeader
                title="Onboarding tracker"
                description="One row per person in onboarding. Click a status to change it, or focus it and type the first letter (Y, N, D). Tick rows to update many at once."
                actions={<Button variant="outline" asChild><a href="/export/onboarding"><Download /> Export CSV</a></Button>}
            >
                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative w-full sm:w-60">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name or code" className="pl-8" aria-label="Search" />
                    </div>
                    <NativeSelect aria-label="Company" className="w-40" value={filters.company ?? ''} placeholder="All companies"
                        onChange={(e) => apply({ company: e.target.value || undefined, department: undefined })} options={companies.map((c) => ({ value: c.id, label: c.name }))} />
                    <NativeSelect aria-label="Department" className="w-52" value={filters.department ?? ''} placeholder="All departments"
                        onChange={(e) => apply({ department: e.target.value || undefined })} options={departments.map((d) => ({ value: d.id, label: d.name }))} />
                    <NativeSelect aria-label="Group" className="w-32" value={filters.cohort ?? ''} placeholder="All groups"
                        onChange={(e) => apply({ cohort: e.target.value || undefined })} options={[...cohorts.map((c) => ({ value: c, label: `Group ${c}` })), { value: 'none', label: 'No group' }]} />
                    <NativeSelect aria-label="Onboarding status" className="w-36" value={filters.status ?? ''} placeholder="Any status"
                        onChange={(e) => apply({ status: e.target.value || undefined })} options={[{ value: 'in_progress', label: 'In progress' }, { value: 'complete', label: 'Complete' }]} />
                    <NativeSelect aria-label="Orientation" className="w-40" value={filters.orientation ?? ''} placeholder="Any orientation"
                        onChange={(e) => apply({ orientation: e.target.value || undefined })} options={toOptions(['Not Started', 'In Progress', 'Complete'])} />
                    <NativeSelect aria-label="Probation" className="w-44" value={filters.probation ?? ''} placeholder="Any probation"
                        onChange={(e) => apply({ probation: e.target.value || undefined })}
                        options={[{ value: 'overdue', label: 'Past end date' }, { value: 'ending', label: 'Ends within 7 days' }, ...toOptions(probationResults), { value: 'none', label: 'Not tracked' }]} />
                    <label className="flex items-center gap-2 px-1 text-sm">
                        <input type="checkbox" className="size-4 accent-cascade" checked={!!filters.legal} onChange={(e) => apply({ legal: e.target.checked ? 'pending' : undefined })} />
                        Legal documents missing
                    </label>
                    {active && <Button variant="ghost" size="sm" onClick={() => router.get('/onboarding', query({ q: filters.q }))}>Clear filters</Button>}
                </div>
                {notInOnboarding > 0 && (
                    <p className="mt-3 text-xs text-muted-foreground">
                        {notInOnboarding} current employees aren't in onboarding (they weren't on either onboarding sheet). Start it from their profile.
                    </p>
                )}
            </PageHeader>
            <ChecklistGrid
                rows={rows}
                columns={columns}
                optionSets={optionSets}
                canEdit={can}
                before={before}
                after={after}
                reload={['rows']}
                empty={<EmptyState title="Nobody matches these filters.">Clear a filter to see more people.</EmptyState>}
            />
        </>
    );
}
