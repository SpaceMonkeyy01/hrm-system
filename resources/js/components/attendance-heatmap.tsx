import { useMemo, useState } from 'react';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';

/** app/Services/EmployeeRecords.php attendanceYear() */
export type AttendanceYear = { from: string; to: string; days: Record<string, string>; late: Record<string, number>; counts: Record<string, number> };

/**
 * One square per day, weeks as columns (Monday on top), like a contribution calendar. It starts at the first month
 * with attendance (not a year of empty squares) and stretches to the width, so it never scrolls sideways.
 * Late, Missing punch and Absent differ in lightness and shape as well as hue; each kind is named in the legend
 * with its count, and every square says what it is on hover or focus, so colour is never the only way to read it.
 */
const KINDS: { code: string; label: string; cls: string }[] = [
    { code: 'P', label: 'Present', cls: 'bg-done' },
    { code: 'L', label: 'Late', cls: 'bg-[#f0c36d]' },
    { code: 'H', label: 'Half day', cls: 'bg-[#c98a2b]' },
    { code: 'M', label: 'Missing punch', cls: 'bg-transparent border-2 border-[#b4532a]' },
    { code: 'A', label: 'Absent', cls: 'bg-[#8f1d1d]' },
    { code: 'V', label: 'On leave', cls: 'bg-muted-tone' },
    { code: 'W', label: 'Worked a day off', cls: 'bg-info' },
    { code: 'O', label: 'Day off or holiday', cls: 'bg-[#dfe4ea]' },
    { code: 'N', label: 'No data', cls: 'bg-transparent border border-dashed border-[#cfd6de]' },
];
const byCode = Object.fromEntries(KINDS.map((k) => [k.code, k]));
/** The square's look and name for a day code (P, L, A, ...), for other day strips (Today's last 14 days). */
export const dayKind = (code: string | undefined) => byCode[code ?? 'N'] ?? byCode.N;
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const iso = (d: Date) => d.toISOString().slice(0, 10);
const utc = (s: string) => {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d));
};

export function AttendanceHeatmap({ year }: { year: AttendanceYear }) {
    const [hover, setHover] = useState<string | null>(null);

    // From the 1st of the first month with attendance (days off and "no data" don't count), else the whole range.
    const from = useMemo(() => {
        const first = Object.keys(year.days).sort().find((d) => !['N', 'O'].includes(year.days[d]));
        return first && `${first.slice(0, 8)}01` > year.from ? `${first.slice(0, 8)}01` : year.from;
    }, [year.days, year.from]);

    // Columns of 7 days, Monday first, from the Monday on or before `from` to `to`.
    const weeks = useMemo(() => {
        const start = utc(from);
        start.setUTCDate(start.getUTCDate() - ((start.getUTCDay() + 6) % 7));
        const end = utc(year.to);
        const out: (string | null)[][] = [];
        for (const d = new Date(start); d <= end; ) {
            const week: (string | null)[] = [];
            for (let i = 0; i < 7; i++) {
                const s = iso(d);
                week.push(s >= from && s <= year.to ? s : null);
                d.setUTCDate(d.getUTCDate() + 1);
            }
            out.push(week);
        }
        return out;
    }, [from, year.to]);

    // A month's name over the first week that is mostly in it (its Thursday), once.
    const monthOf = (w: (string | null)[]) => (w[3] ?? w.find(Boolean) ?? '').slice(5, 7);
    const monthLabels = weeks.map((w, i) => (i === 0 || monthOf(w) !== monthOf(weeks[i - 1]) ? MONTHS[Number(monthOf(w)) - 1] ?? '' : ''));

    const describe = (date: string) => {
        const code = year.days[date] ?? 'N';
        const late = year.late[date];
        return `${formatDate(date)}: ${byCode[code]?.label ?? 'No data'}${late ? `, ${late} min late` : ''}`;
    };
    const counts = useMemo(() => {
        const c: Record<string, number> = {};
        for (const [d, code] of Object.entries(year.days)) {
            if (d >= from) c[code] = (c[code] ?? 0) + 1;
        }
        return c;
    }, [year.days, from]);
    const hasData = Object.values(year.days).some((c) => c !== 'N' && c !== 'O');

    if (!hasData) {
        return <p className="text-sm text-muted-foreground">No attendance yet. Once you clock in, or HR links your machine number, your days show here.</p>;
    }

    return (
        <div className="grid gap-3">
            <div className="grid gap-[3px]" style={{ gridTemplateColumns: `1.75rem repeat(${weeks.length}, minmax(0, 1fr))`, maxWidth: `calc(1.75rem + ${weeks.length} * 1.15rem)` }}>
                <span />
                {monthLabels.map((m, i) => <span key={i} className="overflow-visible text-[10px] leading-none whitespace-nowrap text-muted-foreground">{m}</span>)}
                {['Mon', '', 'Wed', '', 'Fri', '', 'Sun'].map((label, row) => (
                    <Row key={row} label={label} weeks={weeks} row={row} days={year.days} describe={describe} onHover={setHover} />
                ))}
            </div>
            <p className="min-h-5 text-xs text-muted-foreground" aria-live="polite">{hover ? describe(hover) : 'Hover or tap a square to see the day.'}</p>
            <div className="flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-muted-foreground">
                {KINDS.filter((k) => counts[k.code] || ['P', 'O', 'N'].includes(k.code)).map((k) => (
                    <span key={k.code} className="flex items-center gap-1.5">
                        <i className={cn('size-2.5 rounded-[3px]', k.cls)} /> {k.label}
                        {!['O', 'N'].includes(k.code) && <span className="font-semibold text-foreground tabular-nums">{counts[k.code] ?? 0}</span>}
                    </span>
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
            <span className="self-center text-[10px] leading-none text-muted-foreground">{label}</span>
            {weeks.map((w, i) => {
                const date = w[row];
                if (!date) return <span key={i} className="aspect-square" />;
                const kind = byCode[days[date] ?? 'N'] ?? byCode.N;
                return (
                    <span key={i} tabIndex={0} role="img" aria-label={describe(date)} title={describe(date)}
                        onMouseEnter={() => onHover(date)} onMouseLeave={() => onHover(null)} onFocus={() => onHover(date)} onBlur={() => onHover(null)} onClick={() => onHover(date)}
                        className={cn('aspect-square rounded-[3px] outline-none focus-visible:ring-2 focus-visible:ring-cascade', kind.cls)} />
                );
            })}
        </>
    );
}
