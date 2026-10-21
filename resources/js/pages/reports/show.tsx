import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, Download } from 'lucide-react';
import { useMemo, useState } from 'react';
import { duration } from '@/components/attendance';
import { EmptyState } from '@/components/empty-state';
import { NativeSelect } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { StatusPill, toneClass } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate, plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Tone } from '@/types';

/** "2026-10-14" moved by n days, counted in UTC so the browser's timezone can't shift it. */
const shiftDate = (value: string, days: number) => {
    const [y, m, d] = value.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10);
};

/** app/Services/Reports/ReportResult.php */
type Column = { key: string; label: string; type: 'text' | 'number' | 'date' | 'minutes' | 'percent' | 'employee' | 'status' | 'day'; tones?: Record<string, Tone>; group?: string };
type Person = { id: number; name: string; code: string };
type DayCell = { code: string; tone: Tone | null; title: string };
type Row = Record<string, unknown>;
type Filters = { month: string; from: string; to: string; year: number; date: string; within: number; company: number | null; department: number | null; day: string; week: string };
type Company = { id: number; name: string; departments: { id: number; name: string }[] };
type Props = {
    report: { key: string; title: string; description: string; group: string; filters: string[]; wide: boolean };
    filters: Filters;
    today: string;
    companies: Company[];
    result: { columns: Column[]; rows: Row[]; summary: { label: string; value: string | number; tone?: Tone }[]; notes: string[] };
};

const numeric = (c: Column) => ['number', 'minutes', 'percent'].includes(c.type);

function sortValue(c: Column, v: unknown): string | number {
    if (v === null || v === undefined) return c.type === 'text' || c.type === 'date' || c.type === 'status' ? '' : -Infinity;
    if (c.type === 'employee') return (v as Person).name.toLowerCase();
    if (c.type === 'day') return (v as DayCell).code;
    return typeof v === 'string' ? v.toLowerCase() : (v as number);
}

function Cell({ c, v }: { c: Column; v: unknown }) {
    if (v === null || v === undefined || v === '') return <span className="text-muted-foreground"> </span>;
    switch (c.type) {
        case 'employee': {
            const p = v as Person;
            return (
                <Link href={`/employees/${p.id}`} className="font-semibold whitespace-nowrap hover:text-cascade hover:underline">
                    {p.name} <span className="font-normal text-muted-foreground">{p.code}</span>
                </Link>
            );
        }
        case 'date': return <span className="whitespace-nowrap tabular-nums">{formatDate(v as string)}</span>;
        case 'minutes': return <span className="tabular-nums">{(v as number) ? duration(v as number) : '0'}</span>;
        case 'percent': return <span className="tabular-nums">{v as number}%</span>;
        case 'number': return <span className="tabular-nums">{(v as number).toLocaleString('en-GB')}</span>;
        case 'status': return <StatusPill value={v as string} tone={c.tones?.[v as string] ?? 'neutral'} />;
        case 'day': {
            const d = v as DayCell;
            return d.code ? (
                <span title={d.title} className={cn('inline-flex h-6 w-7 items-center justify-center rounded text-[11px] font-semibold', toneClass[d.tone ?? 'neutral'])}>{d.code}</span>
            ) : <span title={d.title} className="inline-block h-6 w-7" />;
        }
        default: return <>{String(v)}</>;
    }
}

export default function ReportShow({ report, filters, today, companies, result }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [sort, setSort] = useState<{ key: string; dir: 1 | -1 } | null>(null);
    const [q, setQ] = useState('');
    const has = (f: string) => report.filters.includes(f);

    const params = (changes: Partial<Filters> = {}) => {
        const all = { ...filters, ...changes };
        const out: Record<string, string | number> = {};
        if (has('month')) out.month = all.month;
        if (has('range')) Object.assign(out, { from: all.from, to: all.to });
        if (has('year')) out.year = all.year;
        if (has('date')) out.date = all.date;
        if (has('day')) out.day = all.day;
        if (has('week')) out.week = all.week;
        if (has('within')) out.within = all.within;
        if (has('company') && all.company) out.company = all.company;
        if (has('department') && all.department) out.department = all.department;
        return out;
    };
    const go = (changes: Partial<Filters>) => router.get(`/reports/${report.key}`, params(changes), { preserveState: true, preserveScroll: true, replace: true });
    const exportUrl = `/reports/${report.key}/export?${new URLSearchParams(Object.entries(params()).map(([k, v]) => [k, String(v)]))}`;

    const departments = companies
        .filter((c) => !filters.company || c.id === filters.company)
        .flatMap((c) => c.departments.map((d) => ({ value: d.id, label: companies.length > 1 && !filters.company ? `${d.name} (${c.name})` : d.name })));
    const personKey = result.columns.find((c) => c.type === 'employee')?.key;

    const rows = useMemo(() => {
        let out = result.rows;
        if (q && personKey) {
            const needle = q.toLowerCase();
            out = out.filter((r) => {
                const p = r[personKey] as Person | undefined;
                return p && (p.name.toLowerCase().includes(needle) || p.code.toLowerCase().includes(needle));
            });
        }
        if (sort) {
            const c = result.columns.find((x) => x.key === sort.key)!;
            out = [...out].sort((a, b) => {
                const x = sortValue(c, a[c.key]);
                const y = sortValue(c, b[c.key]);
                return (x < y ? -1 : x > y ? 1 : 0) * sort.dir;
            });
        }
        return out;
    }, [result, q, sort, personKey]);

    const years = Array.from({ length: 6 }, (_, i) => Number(today.slice(0, 4)) + 1 - i);

    return (
        <>
            <Head title={report.title} />
            <PageHeader
                back={{ href: '/reports', label: 'Reports' }}
                title={report.title}
                help={report.description}
                actions={<Button variant="outline" asChild><a href={exportUrl}><Download /> Download for Excel</a></Button>}
            >
                <div className="flex flex-wrap items-end gap-2">
                    {has('month') && (
                        <label className="grid gap-1 text-xs font-semibold text-muted-foreground">Month
                            <Input type="month" className="w-44" value={filters.month} max={today.slice(0, 7)} onChange={(e) => e.target.value && go({ month: e.target.value })} />
                        </label>
                    )}
                    {has('range') && (
                        <>
                            <label className="grid gap-1 text-xs font-semibold text-muted-foreground">From
                                <Input type="date" className="w-40" value={filters.from} onChange={(e) => e.target.value && go({ from: e.target.value })} />
                            </label>
                            <label className="grid gap-1 text-xs font-semibold text-muted-foreground">To
                                <Input type="date" className="w-40" value={filters.to} onChange={(e) => e.target.value && go({ to: e.target.value })} aria-invalid={!!errors.to} />
                            </label>
                        </>
                    )}
                    {has('day') && (
                        <div className="flex items-end gap-1">
                            <label className="grid gap-1 text-xs font-semibold text-muted-foreground">Day
                                <Input type="date" className="w-40" value={filters.day} max={today} onChange={(e) => e.target.value && go({ day: e.target.value })} />
                            </label>
                            <Button variant="outline" size="icon" aria-label="Day before" onClick={() => go({ day: shiftDate(filters.day, -1) })}><ChevronLeft /></Button>
                            <Button variant="outline" size="icon" aria-label="Day after" disabled={filters.day >= today} onClick={() => go({ day: shiftDate(filters.day, 1) })}><ChevronRight /></Button>
                        </div>
                    )}
                    {has('week') && (
                        <div className="flex items-end gap-1">
                            <label className="grid gap-1 text-xs font-semibold text-muted-foreground">Week starting (Monday)
                                <Input type="date" className="w-44" value={filters.week} max={today} onChange={(e) => e.target.value && go({ week: e.target.value })} />
                            </label>
                            <Button variant="outline" size="icon" aria-label="Week before" onClick={() => go({ week: shiftDate(filters.week, -7) })}><ChevronLeft /></Button>
                            <Button variant="outline" size="icon" aria-label="Week after" disabled={shiftDate(filters.week, 7) > today} onClick={() => go({ week: shiftDate(filters.week, 7) })}><ChevronRight /></Button>
                        </div>
                    )}
                    {has('date') && (
                        <label className="grid gap-1 text-xs font-semibold text-muted-foreground">As of
                            <Input type="date" className="w-40" value={filters.date} onChange={(e) => e.target.value && go({ date: e.target.value })} />
                        </label>
                    )}
                    {has('year') && (
                        <label className="grid gap-1 text-xs font-semibold text-muted-foreground">Year
                            <NativeSelect className="w-28" value={filters.year} options={years.map((y) => ({ value: y, label: String(y) }))} onChange={(e) => go({ year: Number(e.target.value) })} />
                        </label>
                    )}
                    {has('within') && (
                        <label className="grid gap-1 text-xs font-semibold text-muted-foreground">Expiring within
                            <NativeSelect className="w-36" value={filters.within} options={[30, 60, 90, 180, 365].map((d) => ({ value: d, label: `${d} days` }))} onChange={(e) => go({ within: Number(e.target.value) })} />
                        </label>
                    )}
                    {has('company') && companies.length > 1 && (
                        <label className="grid gap-1 text-xs font-semibold text-muted-foreground">Company
                            <NativeSelect className="w-40" value={filters.company ?? ''} placeholder="All companies" options={companies.map((c) => ({ value: c.id, label: c.name }))}
                                onChange={(e) => go({ company: e.target.value ? Number(e.target.value) : null, department: null })} />
                        </label>
                    )}
                    {has('department') && (
                        <label className="grid gap-1 text-xs font-semibold text-muted-foreground">Department
                            <NativeSelect className="w-56" value={filters.department ?? ''} placeholder="All departments" options={departments}
                                onChange={(e) => go({ department: e.target.value ? Number(e.target.value) : null })} />
                        </label>
                    )}
                    {personKey && (
                        <label className="grid gap-1 text-xs font-semibold text-muted-foreground">Find a person
                            <Input className="w-52" placeholder="Name or code" value={q} onChange={(e) => setQ(e.target.value)} />
                        </label>
                    )}
                </div>
                {Object.values(errors).length > 0 && <p className="mt-2 text-sm text-missing">{Object.values(errors)[0]}</p>}
            </PageHeader>

            <div className="grid gap-4 px-4 py-6 md:px-6">
                {result.summary.length > 0 && (
                    <dl className="flex flex-wrap gap-3">
                        {result.summary.map((s) => (
                            <div key={s.label} className="min-w-32 rounded-lg border px-4 py-2.5">
                                <dt className="text-xs font-semibold text-muted-foreground">{s.label}</dt>
                                <dd className={cn('text-xl font-bold', s.tone === 'missing' && 'text-missing', s.tone === 'pending' && 'text-pending', s.tone === 'done' && 'text-done')}>
                                    {typeof s.value === 'number' ? s.value.toLocaleString('en-GB') : s.value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                )}
                {result.notes.map((n) => <p key={n} className="max-w-4xl text-sm text-muted-foreground">{n}</p>)}

                {rows.length === 0 ? (
                    <EmptyState title={q ? 'Nobody matches that name.' : 'Nothing to report for these filters.'} />
                ) : (
                    <>
                        <p className="text-xs text-muted-foreground">{plural(rows.length, 'row')}{q && ` matching "${q}"`}. Click a heading to sort.</p>
                        <div className={cn('overflow-auto rounded-lg border', report.wide && 'max-h-[70vh]')}>
                            <table className={cn('w-full text-sm', report.wide && 'border-separate border-spacing-0')}>
                                <thead className="sticky top-0 z-10 bg-muted text-left text-xs text-muted-foreground">
                                    <tr>
                                        {result.columns.map((c, i) => {
                                            const active = sort?.key === c.key;
                                            const weekend = c.group === 'Sat' || c.group === 'Sun';
                                            return (
                                                <th key={c.key} scope="col"
                                                    className={cn('font-semibold', c.type === 'day' ? 'px-0.5 py-1.5 text-center' : 'px-3 py-2', numeric(c) && 'text-right',
                                                        report.wide && i === 0 && 'sticky left-0 z-20 bg-muted', weekend && 'bg-neutral-soft')}>
                                                    <button type="button" className={cn('inline-flex items-center gap-0.5 hover:text-foreground', c.type === 'day' && 'flex-col gap-0 leading-tight')}
                                                        onClick={() => setSort(active ? (sort!.dir === 1 ? { key: c.key, dir: -1 } : null) : { key: c.key, dir: numeric(c) ? -1 : 1 })}>
                                                        {c.type === 'day' && <span className="text-[10px] font-normal">{c.group?.slice(0, 2)}</span>}
                                                        {c.label}
                                                        {active && (sort!.dir === 1 ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />)}
                                                    </button>
                                                </th>
                                            );
                                        })}
                                    </tr>
                                </thead>
                                <tbody className={cn(!report.wide && 'divide-y')}>
                                    {rows.map((r, n) => (
                                        <tr key={n} className="hover:bg-accent/50">
                                            {result.columns.map((c, i) => (
                                                <td key={c.key}
                                                    className={cn(c.type === 'day' ? 'px-0.5 py-0.5 text-center' : 'px-3 py-2', numeric(c) && 'text-right',
                                                        report.wide && 'border-b', report.wide && i === 0 && 'sticky left-0 z-[1] bg-background')}>
                                                    <Cell c={c} v={r[c.key]} />
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}
