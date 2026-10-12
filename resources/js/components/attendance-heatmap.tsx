import { useMemo, useState } from 'react';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';

/** app/Services/EmployeeRecords.php attendanceYear() */
export type AttendanceYear = { from: string; to: string; days: Record<string, string>; late: Record<string, number>; counts: Record<string, number> };

/**
 * One square per day for the last 12 months, weeks as columns (Monday on top), like a contribution calendar.
 * Colours are the app's status colours (same as the status pills), each named in the legend, and every
 * square says what it is on hover or focus, so colour is never the only way to read it.
 */
const KINDS: { code: string; label: string; cls: string }[] = [
    { code: 'P', label: 'Present', cls: 'bg-done' },
    { code: 'L', label: 'Late', cls: 'bg-[#D99A2B]' },
    { code: 'H', label: 'Half day', cls: 'bg-[#e9c46a]' },
    { code: 'M', label: 'Missing punch', cls: 'bg-[#c98a5a]' },
    { code: 'A', label: 'Absent', cls: 'bg-missing' },
    { code: 'V', label: 'On leave', cls: 'bg-muted-tone' },
    { code: 'W', label: 'Worked a day off', cls: 'bg-info' },
    { code: 'O', label: 'Day off or holiday', cls: 'bg-[#dfe4ea]' },
    { code: 'N', label: 'No data', cls: 'bg-transparent border border-dashed border-[#cfd6de]' },
];
const byCode = Object.fromEntries(KINDS.map((k) => [k.code, k]));
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const iso = (d: Date) => d.toISOString().slice(0, 10);
const utc = (s: string) => {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d));
};

export function AttendanceHeatmap({ year }: { year: AttendanceYear }) {
    const [hover, setHover] = useState<string | null>(null);

    // Columns of 7 days, Monday first, from the Monday on or before `from` to `to`.
    const weeks = useMemo(() => {
        const start = utc(year.from);
        start.setUTCDate(start.getUTCDate() - ((start.getUTCDay() + 6) % 7));
        const end = utc(year.to);
        const out: (string | null)[][] = [];
        for (const d = new Date(start); d <= end; ) {
            const week: (string | null)[] = [];
            for (let i = 0; i < 7; i++) {
                const s = iso(d);
                week.push(s >= year.from && s <= year.to ? s : null);
                d.setUTCDate(d.getUTCDate() + 1);
            }
            out.push(week);
        }
        return out;
    }, [year.from, year.to]);

    const monthLabels = weeks.map((w, i) => {
        const first = w.find((d) => d && d.slice(8) <= '07');
        return first && (i === 0 || !weeks[i - 1].includes(first)) ? MONTHS[Number(first.slice(5, 7)) - 1] : '';
    });

    const describe = (date: string) => {
        const code = year.days[date] ?? 'N';
        const late = year.late[date];
        return `${formatDate(date)}: ${byCode[code]?.label ?? 'No data'}${late ? `, ${late} min late` : ''}`;
    };
    const counted = KINDS.filter((k) => !['O', 'N'].includes(k.code) && year.counts[k.code]);
    const hasData = Object.values(year.days).some((c) => c !== 'N' && c !== 'O');

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap gap-2 text-sm">
                {counted.length === 0 ? (
                    <span className="text-muted-foreground">{hasData ? 'No working days with punches yet.' : 'No attendance files cover these 12 months yet.'}</span>
                ) : counted.map((k) => (
                    <span key={k.code} className="rounded-md border px-2.5 py-1">
                        <span className="text-muted-foreground">{k.label}</span> <span className="font-semibold tabular-nums">{year.counts[k.code]}</span>
                    </span>
                ))}
            </div>
            <div className="overflow-x-auto">
                <div className="inline-grid gap-1" style={{ gridTemplateColumns: `1.75rem repeat(${weeks.length}, 0.8rem)` }}>
                    <span />
                    {monthLabels.map((m, i) => <span key={i} className="text-[10px] leading-none text-muted-foreground">{m}</span>)}
                    {['Mon', '', 'Wed', '', 'Fri', '', 'Sun'].map((label, row) => (
                        <Row key={row} label={label} weeks={weeks} row={row} days={year.days} describe={describe} onHover={setHover} />
                    ))}
                </div>
            </div>
            <p className="min-h-5 text-xs text-muted-foreground" aria-live="polite">{hover ? describe(hover) : 'Hover a square to see the day.'}</p>
            <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                {KINDS.map((k) => (
                    <span key={k.code} className="flex items-center gap-1.5"><i className={cn('size-2.5 rounded-[3px]', k.cls)} /> {k.label}</span>
                ))}
            </div>
        </div>
    );
}

function Row({ label, weeks, row, days, describe, onHover }: {
    label: string; weeks: (string | null)[][]; row: number; days: Record<string, string>;
    describe: (d: string) => string; onHover: (d: string | null) => void;
}) {
    return (
        <>
            <span className="text-[10px] leading-[0.8rem] text-muted-foreground">{label}</span>
            {weeks.map((w, i) => {
                const date = w[row];
                if (!date) return <span key={i} className="size-[0.8rem]" />;
                const kind = byCode[days[date] ?? 'N'] ?? byCode.N;
                return (
                    <span key={i} tabIndex={0} role="img" aria-label={describe(date)} title={describe(date)}
                        onMouseEnter={() => onHover(date)} onMouseLeave={() => onHover(null)} onFocus={() => onHover(date)} onBlur={() => onHover(null)}
                        className={cn('size-[0.8rem] rounded-[3px] outline-none focus-visible:ring-2 focus-visible:ring-cascade', kind.cls)} />
                );
            })}
        </>
    );
}
