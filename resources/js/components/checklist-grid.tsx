import { Link, router } from '@inertiajs/react';
import { ChevronDown, Ellipsis } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { NativeSelect } from '@/components/native-select';
import { StatusPicker } from '@/components/status-picker';
import { StatusPill, toneClass, toneOf } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import type { ChecklistColumn, OptionSets } from '@/types';

export type GridRow = {
    id: number;
    code: string;
    name: string;
    values: Record<string, string | null>;
    needs_review?: boolean;
};

export type ExtraColumn<R> = {
    key: string;
    header: ReactNode;
    cell: (row: R) => ReactNode;
    className?: string;
};

/**
 * The spreadsheet replacement: one row per employee, one column per checklist item.
 * Header row and name column stay put while scrolling. Each cell saves on its own
 * (optimistically), and selected rows can be updated in bulk.
 *
 * Compact (remembered per browser) shows short codes in narrow columns, so far more fit on screen.
 * Each name shows how many of its items are done; each column how many people have it done.
 * Phones get one card per person instead of the grid; tapping it opens the checklist with big buttons.
 */
const COMPACT_KEY = 'hrm.grid.compact';

/**
 * A checklist answer on a phone: the set's first done and first not-done values (Yes / No, Done / Not Started) as
 * 44px buttons, and a ... for the others (Pending, Not Required ...) and for clearing it.
 */
function PhoneChoice({ value, set, optionSets, label, onChange }: {
    value: string | null; set: string; optionSets: OptionSets; label: string; onChange: (v: string | null) => void;
}) {
    const [open, setOpen] = useState(false);
    const options = optionSets[set] ?? [];
    const main = [options.find((o) => o.done), options.find((o) => !o.done)].filter((o): o is NonNullable<typeof o> => !!o);
    const others = options.filter((o) => !main.includes(o));
    const other = value && !main.some((o) => o.value === value);
    const choose = (v: string | null) => { setOpen(false); if (v !== value) onChange(v); };
    return (
        <span className="flex shrink-0 items-center gap-1.5">
            {other && <StatusPill value={value} tone={toneOf(value, set, optionSets)} />}
            <span role="group" aria-label={label} className="inline-flex overflow-hidden rounded-full border bg-background">
                {main.map((o, i) => (
                    <button key={o.value} type="button" aria-pressed={value === o.value} onClick={() => choose(o.value)}
                        className={cn('h-11 min-w-14 px-3.5 text-sm font-semibold', i > 0 && 'border-l',
                            value === o.value ? toneClass[o.tone] : 'text-muted-foreground')}>
                        {o.value}
                    </button>
                ))}
            </span>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <button type="button" aria-label={`More answers for ${label}`} className="inline-flex size-11 items-center justify-center rounded-full border bg-background text-muted-foreground">
                        <Ellipsis className="size-4" />
                    </button>
                </PopoverTrigger>
                <PopoverContent align="end" className="w-52">
                    <p className="px-2 pt-1 pb-1.5 text-xs text-muted-foreground">{label}</p>
                    {others.map((o) => (
                        <button key={o.value} type="button" onClick={() => choose(o.value)} className="flex min-h-11 w-full items-center rounded px-2 hover:bg-accent">
                            <StatusPill value={o.value} tone={o.tone} />
                        </button>
                    ))}
                    <button type="button" onClick={() => choose(null)} className="mt-1 min-h-11 w-full rounded border-t px-2 text-left text-sm text-muted-foreground hover:bg-accent">
                        Clear value
                    </button>
                </PopoverContent>
            </Popover>
        </span>
    );
}

function readCompact(): boolean {
    try {
        return localStorage.getItem(COMPACT_KEY) === '1';
    } catch {
        return false;
    }
}
export function ChecklistGrid<R extends GridRow>({
    rows,
    columns,
    optionSets,
    canEdit,
    before = [],
    after = [],
    headerBadge,
    reload,
    empty,
    profileTab,
    bulkExtra,
}: {
    rows: R[];
    columns: ChecklistColumn[];
    optionSets: OptionSets;
    canEdit: boolean;
    before?: ExtraColumn<R>[];
    after?: ExtraColumn<R>[];
    headerBadge?: (col: ChecklistColumn) => ReactNode;
    reload: string[];
    empty: ReactNode;
    /** Profile tab a phone card opens (#onboarding, #documents). */
    profileTab?: string;
    /** More actions in the bulk bar for the ticked rows (e.g. close onboarding). */
    bulkExtra?: (ids: number[], clear: () => void) => ReactNode;
}) {
    const [compact, setCompact] = useState(readCompact);
    const toggleCompact = () => setCompact((c) => {
        try {
            localStorage.setItem(COMPACT_KEY, c ? '0' : '1');
        } catch {
            // private window: the choice just isn't remembered
        }
        return !c;
    });
    const [optimistic, setOptimistic] = useState<Record<string, string | null>>({});
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [bulkItem, setBulkItem] = useState<string>('');
    const [bulkStatus, setBulkStatus] = useState<string>('');
    const [bulkBusy, setBulkBusy] = useState(false);
    const [openRow, setOpenRow] = useState<number | null>(null);

    const groups = useMemo(() => {
        const out: { name: string; span: number }[] = [];
        for (const c of columns) {
            const g = c.group ?? '';
            if (out.length && out[out.length - 1].name === g) out[out.length - 1].span++;
            else out.push({ name: g, span: 1 });
        }
        return out;
    }, [columns]);

    const save = (row: R, col: ChecklistColumn, value: string | null) => {
        const k = `${row.id}:${col.key}`;
        setOptimistic((s) => ({ ...s, [k]: value }));
        router.put(
            `/checklist/${row.id}/${col.id}`,
            { status: value },
            {
                preserveScroll: true,
                preserveState: true,
                only: reload,
                onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Not saved. Try again.'),
                onFinish: () =>
                    setOptimistic((s) => {
                        const next = { ...s };
                        delete next[k];
                        return next;
                    }),
            },
        );
    };

    const toggle = (id: number) =>
        setSelected((s) => {
            const next = new Set(s);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    const allSelected = rows.length > 0 && rows.every((r) => selected.has(r.id));
    const valueOf = (row: R, c: ChecklistColumn) => {
        const k = `${row.id}:${c.key}`;
        return k in optimistic ? optimistic[k] : (row.values[c.key] ?? null);
    };
    const isDone = (c: ChecklistColumn, v: string | null) => !!v && !!optionSets[c.option_set]?.find((o) => o.value === v)?.done;
    const doneIn = (row: R) => columns.filter((c) => isDone(c, valueOf(row, c))).length;
    const columnDone = (c: ChecklistColumn) => rows.filter((r) => isDone(c, valueOf(r, c))).length;
    const bulkColumn = columns.find((c) => String(c.id) === bulkItem);

    const applyBulk = () => {
        if (!bulkColumn) return;
        setBulkBusy(true);
        router.post(
            '/checklist/bulk',
            { employee_ids: [...selected], item_id: bulkColumn.id, status: bulkStatus || null },
            {
                preserveScroll: true,
                preserveState: true,
                only: [...reload, 'flash'],
                onSuccess: () => setSelected(new Set()),
                onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Not saved. Try again.'),
                onFinish: () => setBulkBusy(false),
            },
        );
    };

    if (rows.length === 0) {
        return <div className="p-4 md:p-6">{empty}</div>;
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            {canEdit && selected.size > 0 && (
                <div className="hidden flex-wrap items-center gap-2 border-b bg-accent px-4 py-2 text-sm md:flex md:px-6" role="region" aria-label="Bulk update">
                    <span className="font-semibold">{selected.size} selected.</span>
                    <span>Set</span>
                    <NativeSelect
                        aria-label="Checklist item"
                        className="w-56"
                        value={bulkItem}
                        onChange={(e) => {
                            setBulkItem(e.target.value);
                            setBulkStatus('');
                        }}
                        placeholder="Choose an item"
                        options={columns.map((c) => ({ value: c.id, label: c.label }))}
                    />
                    <span>to</span>
                    <NativeSelect
                        aria-label="New value"
                        className="w-40"
                        value={bulkStatus}
                        onChange={(e) => setBulkStatus(e.target.value)}
                        disabled={!bulkColumn}
                        placeholder="Blank"
                        options={(bulkColumn ? optionSets[bulkColumn.option_set] : []).map((o) => ({ value: o.value, label: o.value }))}
                    />
                    <Button size="sm" onClick={applyBulk} disabled={!bulkColumn || bulkBusy}>
                        Apply to {selected.size}
                    </Button>
                    {bulkExtra?.([...selected], () => setSelected(new Set()))}
                    <Button size="sm" variant="ghost" onClick={() => setSelected(new Set())}>
                        Clear selection
                    </Button>
                </div>
            )}

            <div className="hidden items-center justify-end gap-3 border-b px-4 py-1.5 text-xs text-muted-foreground md:flex md:px-6">
                {compact && <span>Short codes: hover a cell for the full value.</span>}
                <label className="flex cursor-pointer items-center gap-1.5 font-semibold text-foreground">
                    <input type="checkbox" className="size-3.5 accent-cascade" checked={compact} onChange={toggleCompact} />
                    Compact
                </label>
            </div>

            {/* Phones: one card per person; tap to open the checklist and answer with big buttons. */}
            <div className="md:hidden">
                <p className="border-b px-4 py-2 text-xs text-muted-foreground">
                    {rows.length} {rows.length === 1 ? 'person' : 'people'}.{canEdit ? ' Tap a person to update their checklist.' : ' Tap a person to see their checklist.'}
                </p>
                <ul className="divide-y border-b">
                    {rows.map((row) => {
                        const done = doneIn(row);
                        const isOpen = openRow === row.id;
                        const share = done / Math.max(columns.length, 1);
                        return (
                            <li key={row.id}>
                                <button type="button" aria-expanded={isOpen} onClick={() => setOpenRow(isOpen ? null : row.id)}
                                    className="grid w-full gap-1.5 px-4 py-3 text-left hover:bg-accent/50">
                                    <span className="flex items-center justify-between gap-3">
                                        <span className="min-w-0">
                                            <span className="font-bold">{row.name}</span> <span className="text-xs text-muted-foreground">{row.code}</span>
                                            {row.needs_review && <span className="ml-1.5 text-xs font-semibold text-pending">Check record</span>}
                                        </span>
                                        <span className="flex shrink-0 items-center gap-2 text-sm font-semibold text-muted-foreground tabular-nums">
                                            {done} of {columns.length}
                                            <ChevronDown className={cn('size-4 transition-transform', isOpen && 'rotate-180')} />
                                        </span>
                                    </span>
                                    <span className="flex h-1.5 overflow-hidden rounded-full bg-muted">
                                        <span className={share >= 1 ? 'bg-done' : share < 0.5 ? 'bg-pending' : 'bg-cascade'} style={{ width: `${share * 100}%` }} />
                                    </span>
                                </button>
                                {isOpen && (
                                    <div className="border-t bg-muted/30">
                                        <ul className="divide-y">
                                            {columns.map((c, i) => {
                                                const firstOfGroup = c.group && (i === 0 || (columns[i - 1].group ?? '') !== c.group);
                                                const value = valueOf(row, c);
                                                return (
                                                    <Fragment key={c.id}>
                                                        {firstOfGroup && <li className="px-4 pt-3 pb-1 text-xs font-semibold text-muted-foreground uppercase">{c.group}</li>}
                                                        <li className="flex items-center justify-between gap-3 px-4 py-2">
                                                            <span className="min-w-0 text-sm">{c.label}</span>
                                                            {canEdit
                                                                ? <PhoneChoice value={value} set={c.option_set} optionSets={optionSets} label={`${c.label} for ${row.name}`} onChange={(v) => save(row, c, v)} />
                                                                : <StatusPill value={value} tone={toneOf(value, c.option_set, optionSets)} />}
                                                        </li>
                                                    </Fragment>
                                                );
                                            })}
                                        </ul>
                                        <Link href={`/employees/${row.id}${profileTab ? `#${profileTab}` : ''}`} className="block border-t px-4 py-3 text-sm font-semibold text-cascade">
                                            Open the profile
                                        </Link>
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </div>

            <div className="hidden min-h-0 flex-1 overflow-auto md:block" style={{ maxHeight: 'calc(100svh - 15rem)' }}>
                <table className="hr-grid w-max min-w-full text-sm">
                    <thead>
                        <tr className="groups">
                            <th rowSpan={2} className="sticky-col min-w-64 px-3 text-left align-bottom text-xs font-semibold">
                                <span className="flex items-center gap-2 pb-2">
                                    {canEdit && (
                                        <input
                                            type="checkbox"
                                            aria-label="Select all rows"
                                            className="size-4 accent-cascade"
                                            checked={allSelected}
                                            onChange={() => setSelected(allSelected ? new Set() : new Set(rows.map((r) => r.id)))}
                                        />
                                    )}
                                    Employee ({rows.length})
                                </span>
                            </th>
                            {before.map((c) => (
                                <th key={c.key} rowSpan={2} className={`px-2 pb-2 text-left align-bottom text-xs font-semibold ${c.className ?? ''}`}>
                                    {c.header}
                                </th>
                            ))}
                            {groups.map((g, i) => (
                                <th key={i} colSpan={g.span} className="border-l px-2 text-left text-xs font-semibold text-muted-foreground">
                                    {g.name}
                                </th>
                            ))}
                            {after.map((c) => (
                                <th key={c.key} rowSpan={2} className={`border-l px-2 pb-2 text-left align-bottom text-xs font-semibold ${c.className ?? ''}`}>
                                    {c.header}
                                </th>
                            ))}
                        </tr>
                        <tr className="sub">
                            {columns.map((c, i) => {
                                const firstOfGroup = i === 0 || (columns[i - 1].group ?? '') !== (c.group ?? '');
                                return (
                                    <th
                                        key={c.id}
                                        title={c.description ? `${c.label} - ${c.description}` : c.label}
                                        className={`${compact ? 'w-20 min-w-20 text-[11px]' : 'w-28 min-w-28 text-xs'} px-2 pb-2 text-center align-bottom font-semibold ${firstOfGroup ? 'border-l' : ''}`}
                                    >
                                        <span className="line-clamp-2">{c.label}</span>
                                        {headerBadge ? headerBadge(c) : (
                                            <span className="mt-1 block text-[11px] font-normal text-muted-foreground tabular-nums">{columnDone(c)} of {rows.length} done</span>
                                        )}
                                    </th>
                                );
                            })}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.id} data-selected={selected.has(row.id)}>
                                <td className="sticky-col px-3 py-1.5">
                                    <div className="flex items-center gap-2">
                                        {canEdit && (
                                            <input
                                                type="checkbox"
                                                aria-label={`Select ${row.name}`}
                                                className="size-4 accent-cascade"
                                                checked={selected.has(row.id)}
                                                onChange={() => toggle(row.id)}
                                            />
                                        )}
                                        <div className="min-w-0">
                                            <Link href={`/employees/${row.id}`} className="block max-w-56 truncate font-semibold hover:text-cascade hover:underline">
                                                {row.name}
                                            </Link>
                                            <span className="text-xs text-muted-foreground">
                                                {row.code}
                                                {row.needs_review && <span className="ml-1.5 font-semibold text-pending">Check record</span>}
                                            </span>
                                        </div>
                                        <span className="ml-auto grid shrink-0 justify-items-end gap-0.5 pl-2" title={`${doneIn(row)} of ${columns.length} done`}>
                                            <span className="text-[11px] font-semibold text-muted-foreground tabular-nums">{doneIn(row)}/{columns.length}</span>
                                            <span className="flex h-1 w-10 overflow-hidden rounded-full bg-muted">
                                                <span className="bg-done" style={{ width: `${(doneIn(row) / Math.max(columns.length, 1)) * 100}%` }} />
                                            </span>
                                        </span>
                                    </div>
                                </td>
                                {before.map((c) => (
                                    <td key={c.key} className={`px-2 py-1.5 ${c.className ?? ''}`}>
                                        {c.cell(row)}
                                    </td>
                                ))}
                                {columns.map((c, i) => {
                                    const value = valueOf(row, c);
                                    const firstOfGroup = i === 0 || (columns[i - 1].group ?? '') !== (c.group ?? '');
                                    return (
                                        <td key={c.id} className={`px-1 py-1.5 text-center ${firstOfGroup ? 'border-l' : ''}`}>
                                            <StatusPicker
                                                value={value}
                                                set={c.option_set}
                                                optionSets={optionSets}
                                                disabled={!canEdit}
                                                label={`${c.label} for ${row.name}`}
                                                compact={compact}
                                                onChange={(v) => save(row, c, v)}
                                            />
                                        </td>
                                    );
                                })}
                                {after.map((c, i) => (
                                    <Fragment key={c.key}>
                                        <td className={`px-2 py-1.5 ${i === 0 ? 'border-l' : ''} ${c.className ?? ''}`}>{c.cell(row)}</td>
                                    </Fragment>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
