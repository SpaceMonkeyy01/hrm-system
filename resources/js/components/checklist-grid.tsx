import { Link, router } from '@inertiajs/react';
import { Fragment, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { NativeSelect } from '@/components/native-select';
import { StatusPicker } from '@/components/status-picker';
import { Button } from '@/components/ui/button';
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
 */
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
}) {
    const [optimistic, setOptimistic] = useState<Record<string, string | null>>({});
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [bulkItem, setBulkItem] = useState<string>('');
    const [bulkStatus, setBulkStatus] = useState<string>('');
    const [bulkBusy, setBulkBusy] = useState(false);

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
                <div className="flex flex-wrap items-center gap-2 border-b bg-accent px-4 py-2 text-sm md:px-6" role="region" aria-label="Bulk update">
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
                    <Button size="sm" variant="ghost" onClick={() => setSelected(new Set())}>
                        Clear selection
                    </Button>
                </div>
            )}

            <div className="min-h-0 flex-1 overflow-auto" style={{ maxHeight: 'calc(100svh - 13rem)' }}>
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
                                        className={`w-28 min-w-28 px-2 pb-2 text-center align-bottom text-xs font-semibold ${firstOfGroup ? 'border-l' : ''}`}
                                    >
                                        <span className="line-clamp-2">{c.label}</span>
                                        {headerBadge?.(c)}
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
                                    </div>
                                </td>
                                {before.map((c) => (
                                    <td key={c.key} className={`px-2 py-1.5 ${c.className ?? ''}`}>
                                        {c.cell(row)}
                                    </td>
                                ))}
                                {columns.map((c, i) => {
                                    const k = `${row.id}:${c.key}`;
                                    const value = k in optimistic ? optimistic[k] : (row.values[c.key] ?? null);
                                    const firstOfGroup = i === 0 || (columns[i - 1].group ?? '') !== (c.group ?? '');
                                    return (
                                        <td key={c.id} className={`px-1 py-1.5 text-center ${firstOfGroup ? 'border-l' : ''}`}>
                                            <StatusPicker
                                                value={value}
                                                set={c.option_set}
                                                optionSets={optionSets}
                                                disabled={!canEdit}
                                                label={`${c.label} for ${row.name}`}
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
