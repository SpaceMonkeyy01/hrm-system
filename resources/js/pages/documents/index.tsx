import { Head, router, usePage } from '@inertiajs/react';
import { Download, FolderOpen, History, Search, SearchX } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ChecklistGrid } from '@/components/checklist-grid';
import type { ExtraColumn, GridRow } from '@/components/checklist-grid';
import { EmptyState } from '@/components/empty-state';
import { ActiveFilters, FilterBar, type FilterChip } from '@/components/filter-bar';
import { NativeSelect } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { computedTone, StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { query } from '@/lib/utils';
import type { ChecklistColumn, CompanyOption, OptionSets, SharedProps } from '@/types';

type Row = GridRow & { company: string | null; department: string | null; drive_folder_url: string | null; status: string };
type Filters = Partial<Record<'q' | 'company' | 'department' | 'status' | 'missing' | 'scope', string>>;

type Baseline = { before: string; people: number; cells: number; undated: number; undated_cells: number };

/** Mark blank documents of long-serving staff "Old employee", so Pending means really owed (DocumentChecklistController::baseline). */
function BaselineDialog({ candidates, onClose }: { candidates: Baseline; onClose: () => void }) {
    const [before, setBefore] = useState(candidates.before);
    const [undated, setUndated] = useState(false);
    const [busy, setBusy] = useState(false);
    const submit = () => {
        setBusy(true);
        router.post('/documents/baseline', { started_before: before, include_undated: undated }, {
            preserveScroll: true, onSuccess: onClose, onFinish: () => setBusy(false),
            onError: (e) => toast.error(Object.values(e)[0] ?? 'Not saved.'),
        });
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Mark old staff</DialogTitle>
                    <DialogDescription>
                        Long-serving staff were never put through this checklist, so their blank cells count as Pending. This sets only the
                        BLANK cells to "Old employee". Anything already filled in (Yes, No, Pending...) stays as it is, and every change is in the activity log.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-3 text-sm">
                    <label className="grid gap-1">
                        <span className="font-semibold">Everyone who started before</span>
                        <Input type="date" className="w-44" value={before} onChange={(e) => setBefore(e.target.value)} />
                        {before === candidates.before && (
                            <span className="text-xs text-muted-foreground">
                                {candidates.cells} blank cells for {candidates.people} people started before this date (6 months ago).
                            </span>
                        )}
                    </label>
                    {candidates.undated > 0 && (
                        <label className="flex items-start gap-2">
                            <input type="checkbox" className="mt-0.5 size-4 accent-cascade" checked={undated} onChange={(e) => setUndated(e.target.checked)} />
                            <span>Also the {candidates.undated} with no start date ({candidates.undated_cells} blank cells). <span className="text-muted-foreground">Leave this off if some of them might be new joiners.</span></span>
                        </label>
                    )}
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>Cancel</Button>
                    <Button onClick={submit} disabled={busy || !before}><History /> Mark as Old employee</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function DocumentsIndex({ rows, columns, owed, optionSets, filters, companies, baseline }: {
    rows: Row[]; columns: ChecklistColumn[]; owed: Record<string, number>; optionSets: OptionSets; filters: Filters; companies: CompanyOption[]; baseline: Baseline | null;
}) {
    const { auth } = usePage<SharedProps>().props;
    const [marking, setMarking] = useState(false);
    const [q, setQ] = useState(filters.q ?? '');
    const apply = (patch: Filters) => router.get('/documents', query({ ...filters, ...patch }), { preserveState: true, preserveScroll: true, replace: true });

    useEffect(() => {
        if ((filters.q ?? '') === q) return;
        const t = setTimeout(() => apply({ q }), 300);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q]);

    const company = companies.find((c) => String(c.id) === String(filters.company));
    const departments = company ? company.departments : companies.flatMap((c) => c.departments.map((d) => ({ ...d, name: `${d.name} (${c.name})` })));
    const missing = columns.find((c) => c.key === filters.missing);
    const chips: FilterChip[] = [
        missing && { key: 'missing', label: `Owes ${missing.label}`, clear: () => apply({ missing: undefined }) },
        filters.scope && { key: 'scope', label: filters.scope === 'onboarding' ? 'Only people in onboarding' : 'Joined in the last 6 months', clear: () => apply({ scope: undefined }) },
    ].filter(Boolean) as FilterChip[];
    const clearAll = () => { setQ(''); router.get('/documents', {}, { preserveScroll: true, replace: true }); };

    const before: ExtraColumn<Row>[] = [
        { key: 'department', header: 'Department', className: 'min-w-44 max-w-52', cell: (r) => <span className="line-clamp-2 text-xs">{r.department ?? <span className="text-muted-foreground">-</span>}</span> },
    ];
    const after: ExtraColumn<Row>[] = [
        { key: 'status', header: 'Status', className: 'w-24', cell: (r) => <StatusPill value={r.status} tone={computedTone(r.status)} /> },
        {
            key: 'drive', header: 'Folder', className: 'w-16 text-center',
            cell: (r) => r.drive_folder_url ? (
                <a href={r.drive_folder_url} target="_blank" rel="noreferrer" aria-label={`Drive folder for ${r.name}`} className="inline-flex text-cascade hover:text-ink"><FolderOpen className="size-4" /></a>
            ) : <span className="text-xs text-muted-foreground">None</span>,
        },
    ];

    return (
        <>
            <Head title="Documents" />
            <PageHeader
                title="Document checklist"
                description={missing ? `People who still owe: ${missing.label}.` : undefined}
                help="The documents HR holds for every current employee. A person is Complete when every document is Yes, Not Required, Old employee or not available."
                actions={<>
                    {baseline && baseline.cells + baseline.undated_cells > 0 && <Button variant="outline" onClick={() => setMarking(true)}><History /> Mark old staff</Button>}
                    <Button variant="outline" asChild><a href="/export/documents"><Download /> Export CSV</a></Button>
                </>}
            >
                <FilterBar grow moreActive={chips.length} active={[filters.company, filters.department, filters.status].filter(Boolean).length + chips.length} search={
                        <div className="relative w-full">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name or code" className="pl-8" aria-label="Search" />
                        </div>
                } more={
                    <>
                        <NativeSelect aria-label="Still owes" value={filters.missing ?? ''} placeholder="Owes any document"
                            onChange={(e) => apply({ missing: e.target.value || undefined })} options={columns.map((c) => ({ value: c.key, label: `Owes ${c.label}` }))} />
                        <NativeSelect aria-label="Who" value={filters.scope ?? 'all'}
                            onChange={(e) => apply({ scope: e.target.value === 'all' ? undefined : e.target.value })}
                            options={[{ value: 'all', label: 'All current employees' }, { value: 'onboarding', label: 'Only people in onboarding' }, { value: 'recent', label: 'Joined in the last 6 months' }]} />
                    </>
                }>
                    <NativeSelect aria-label="Company" className="w-40" value={filters.company ?? ''} placeholder="All companies"
                        onChange={(e) => apply({ company: e.target.value || undefined, department: undefined })} options={companies.map((c) => ({ value: c.id, label: c.name }))} />
                    <NativeSelect aria-label="Department" className="w-52" value={filters.department ?? ''} placeholder="All departments"
                        onChange={(e) => apply({ department: e.target.value || undefined })} options={departments.map((d) => ({ value: d.id, label: d.name }))} />
                    <NativeSelect aria-label="Status" className="w-36" value={filters.status ?? ''} placeholder="Any status"
                        onChange={(e) => apply({ status: e.target.value || undefined })} options={[{ value: 'Pending', label: 'Pending' }, { value: 'Complete', label: 'Complete' }]} />
                </FilterBar>
                <ActiveFilters chips={chips} onClearAll={clearAll} />
            </PageHeader>
            <ChecklistGrid
                rows={rows}
                columns={columns}
                optionSets={optionSets}
                canEdit={auth.can.edit}
                before={before}
                after={after}
                reload={['rows', 'owed']}
                profileTab="documents"
                headerBadge={(c) => (owed[c.key] ? <span className="mt-1 block text-[11px] font-normal text-muted-foreground">{owed[c.key]} owed</span> : <span className="mt-1 block text-[11px] font-normal text-done">All on file</span>)}
                empty={<EmptyState icon={SearchX} title="Nobody matches these filters.">Clear a filter to see more people.</EmptyState>}
            />
            {marking && baseline && <BaselineDialog candidates={baseline} onClose={() => setMarking(false)} />}
        </>
    );
}
