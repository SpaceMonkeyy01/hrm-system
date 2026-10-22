import { Head, router, usePage } from '@inertiajs/react';
import { Archive, Download, Search, SearchX } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ChecklistGrid } from '@/components/checklist-grid';
import type { ExtraColumn, GridRow } from '@/components/checklist-grid';
import { EmptyState } from '@/components/empty-state';
import { ActiveFilters, FilterBar, type FilterChip } from '@/components/filter-bar';
import { NativeSelect, toOptions } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { computedTone, StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';
import { cn, query } from '@/lib/utils';
import type { ChecklistColumn, CompanyOption, OptionSets, SharedProps } from '@/types';

type Row = GridRow & {
    company: string | null; department: string | null; cohort: string | null; start_date: string | null;
    employment_type: string | null; probation_end_date: string | null; probation_result: string | null;
    overdue: boolean; ending: boolean; orientation: string; documents: string; status: string; closed_at: string | null;
};
type Filters = Partial<Record<'q' | 'company' | 'department' | 'cohort' | 'status' | 'orientation' | 'docs' | 'legal' | 'probation' | 'show', string>>;
type Props = {
    rows: Row[]; columns: ChecklistColumn[]; optionSets: OptionSets; filters: Filters; companies: CompanyOption[];
    cohorts: string[]; probationResults: string[]; notInOnboarding: number;
    closeCandidates: { before: string; dated: number; undated: number }; closedCount: number;
};

/** Close onboarding for everyone who started before a date: kept on file, no longer tracked (OnboardingController::close). */
function CloseOutDialog({ candidates, onClose }: { candidates: Props['closeCandidates']; onClose: () => void }) {
    const [before, setBefore] = useState(candidates.before);
    const [undated, setUndated] = useState(false);
    const [busy, setBusy] = useState(false);
    const submit = () => {
        setBusy(true);
        router.post('/onboarding/close', { started_before: before, include_undated: undated }, {
            preserveScroll: true, onSuccess: onClose, onFinish: () => setBusy(false),
            onError: (e) => toast.error(Object.values(e)[0] ?? 'Not saved.'),
        });
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Close out old onboarding records</DialogTitle>
                    <DialogDescription>
                        For people who joined before the app and were imported with half-filled checklists. Their record and checklist stay on file;
                        they just leave this tracker, the Home counts and the reminders. You can reopen anyone from their profile.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-3 text-sm">
                    <label className="grid gap-1">
                        <span className="font-semibold">Close everyone who started before</span>
                        <Input type="date" className="w-44" value={before} onChange={(e) => setBefore(e.target.value)} />
                        {before === candidates.before && <span className="text-xs text-muted-foreground">{candidates.dated} open records started before this date (6 months ago).</span>}
                    </label>
                    {candidates.undated > 0 && (
                        <label className="flex items-start gap-2">
                            <input type="checkbox" className="mt-0.5 size-4 accent-cascade" checked={undated} onChange={(e) => setUndated(e.target.checked)} />
                            <span>Also close the {candidates.undated} with no start date. <span className="text-muted-foreground">Leave this off if some of them might be new joiners.</span></span>
                        </label>
                    )}
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>Cancel</Button>
                    <Button onClick={submit} disabled={busy || !before}><Archive /> Close records</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function OnboardingIndex({ rows, columns, optionSets, filters, companies, cohorts, probationResults, notInOnboarding, closeCandidates, closedCount }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const can = auth.can.edit;
    const [closingOut, setClosingOut] = useState(false);
    const show = filters.show ?? 'open';
    const closeTicked = (ids: number[], clear: () => void) =>
        confirm(`Close onboarding for ${ids.length === 1 ? '1 person' : `${ids.length} people`}? Their records stay on file.`)
        && router.post('/onboarding/close', { ids }, { preserveScroll: true, onSuccess: clear, onError: (e) => toast.error(Object.values(e)[0] ?? 'Not saved.') });
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
    const chips: FilterChip[] = [
        filters.show && { key: 'show', label: filters.show === 'closed' ? 'Closed records' : 'Open and closed', clear: () => apply({ show: undefined }) },
        filters.cohort && { key: 'cohort', label: filters.cohort === 'none' ? 'No group' : `Group ${filters.cohort}`, clear: () => apply({ cohort: undefined }) },
        filters.status && { key: 'status', label: `Onboarding: ${filters.status === 'complete' ? 'Complete' : 'In progress'}`, clear: () => apply({ status: undefined }) },
        filters.orientation && { key: 'orientation', label: `Orientation: ${filters.orientation}`, clear: () => apply({ orientation: undefined }) },
        filters.docs && { key: 'docs', label: `Documents: ${filters.docs}`, clear: () => apply({ docs: undefined }) },
        filters.legal && { key: 'legal', label: 'Legal documents missing', clear: () => apply({ legal: undefined }) },
    ].filter(Boolean) as FilterChip[];
    const clearAll = () => { setQ(''); router.get('/onboarding', {}, { preserveScroll: true, replace: true }); };

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
        {
            key: 'status', header: 'Onboarding', className: 'w-28',
            cell: (r) => r.closed_at ? (
                <span className="grid justify-items-start gap-0.5">
                    <StatusPill value="Closed" tone="neutral" />
                    {can && <button type="button" className="text-[11px] font-semibold text-cascade hover:underline" onClick={() => router.post(`/onboarding/${r.id}/reopen`, {}, { preserveScroll: true })}>Reopen</button>}
                </span>
            ) : <StatusPill value={r.status === 'Onboarding Complete' ? 'Complete' : 'In progress'} tone={computedTone(r.status)} />,
        },
    ];

    return (
        <>
            <Head title="Onboarding" />
            <PageHeader
                title="Onboarding tracker"
                help="One row per person in onboarding. Click a status to change it, or focus it and type the first letter (Y, N, D). Tick rows to update many at once."
                actions={<>
                    {can && closeCandidates.dated + closeCandidates.undated > 0 && <Button variant="outline" onClick={() => setClosingOut(true)}><Archive /> Close out old records</Button>}
                    <Button variant="outline" asChild><a href="/export/onboarding"><Download /> Export CSV</a></Button>
                </>}
            >
                <FilterBar grow moreActive={chips.length} active={[filters.company, filters.department, filters.probation].filter(Boolean).length + chips.length} search={
                        <div className="relative w-full">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name or code" className="pl-8" aria-label="Search" />
                        </div>
                } more={
                    <>
                        <NativeSelect aria-label="Open or closed" value={show} onChange={(e) => apply({ show: e.target.value === 'open' ? undefined : e.target.value })}
                            options={[{ value: 'open', label: 'Being tracked' }, { value: 'closed', label: `Closed (${closedCount})` }, { value: 'all', label: 'Open and closed' }]} />
                        <NativeSelect aria-label="Group" value={filters.cohort ?? ''} placeholder="All groups"
                            onChange={(e) => apply({ cohort: e.target.value || undefined })} options={[...cohorts.map((c) => ({ value: c, label: `Group ${c}` })), { value: 'none', label: 'No group' }]} />
                        <NativeSelect aria-label="Onboarding status" value={filters.status ?? ''} placeholder="Any onboarding status"
                            onChange={(e) => apply({ status: e.target.value || undefined })} options={[{ value: 'in_progress', label: 'In progress' }, { value: 'complete', label: 'Complete' }]} />
                        <NativeSelect aria-label="Orientation" value={filters.orientation ?? ''} placeholder="Any orientation"
                            onChange={(e) => apply({ orientation: e.target.value || undefined })} options={toOptions(['Not Started', 'In Progress', 'Complete'])} />
                        <label className="flex items-center gap-2 px-1 text-sm">
                            <input type="checkbox" className="size-4 accent-cascade" checked={!!filters.legal} onChange={(e) => apply({ legal: e.target.checked ? 'pending' : undefined })} />
                            Legal documents missing
                        </label>
                    </>
                }>
                    <NativeSelect aria-label="Company" className="w-40" value={filters.company ?? ''} placeholder="All companies"
                        onChange={(e) => apply({ company: e.target.value || 'all', department: undefined })} options={companies.map((c) => ({ value: c.id, label: c.name }))} />
                    <NativeSelect aria-label="Department" className="w-52" value={filters.department ?? ''} placeholder="All departments"
                        onChange={(e) => apply({ department: e.target.value || undefined })} options={departments.map((d) => ({ value: d.id, label: d.name }))} />
                    <NativeSelect aria-label="Probation" className="w-44" value={filters.probation ?? ''} placeholder="Any probation"
                        onChange={(e) => apply({ probation: e.target.value || undefined })}
                        options={[{ value: 'overdue', label: 'Past end date' }, { value: 'ending', label: 'Ends within 7 days' }, ...toOptions(probationResults), { value: 'none', label: 'Not tracked' }]} />
                </FilterBar>
                <ActiveFilters chips={chips} onClearAll={clearAll} />
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
                profileTab="onboarding"
                bulkExtra={show !== 'closed' ? (ids, clear) => <Button size="sm" variant="outline" onClick={() => closeTicked(ids, clear)}><Archive /> Close onboarding</Button> : undefined}
                empty={<EmptyState icon={SearchX} title="Nobody matches these filters.">Clear a filter to see more people.</EmptyState>}
            />
            {closingOut && <CloseOutDialog candidates={closeCandidates} onClose={() => setClosingOut(false)} />}
        </>
    );
}
