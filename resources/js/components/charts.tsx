import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Small hand-drawn SVG charts for Overview. Series colours were checked for colour-blind separation and
 * contrast on white (dataviz validator): slot 1 blue, slot 2 orange. Text never wears a series colour.
 */
export const SERIES = ['#2a78d6', '#eb6834'] as const;

const GRID = '#e6eaf0';
const AXIS = '#cbd3dd';

function useWidth<T extends HTMLElement>(): [React.RefObject<T | null>, number] {
    const ref = useRef<T>(null);
    const [width, setWidth] = useState(0);
    useEffect(() => {
        if (!ref.current) return;
        const ro = new ResizeObserver(([entry]) => setWidth(Math.floor(entry.contentRect.width)));
        ro.observe(ref.current);
        return () => ro.disconnect();
    }, []);
    return [ref, width];
}

/** 0 .. a round number at or above max, in about four steps. */
function ticks(max: number): number[] {
    if (max <= 0) return [0, 1];
    const rough = max / 4;
    const mag = 10 ** Math.floor(Math.log10(rough));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= rough) ?? rough;
    const out: number[] = [];
    for (let v = 0; v <= max + step * 0.001; v += step) out.push(Math.round(v * 100) / 100);
    if (out[out.length - 1] < max) out.push(out[out.length - 1] + step);
    return out;
}

const fmt = (n: number) => n.toLocaleString('en-GB');

function Tooltip({ x, width, children }: { x: number; width: number; children: ReactNode }) {
    const left = Math.min(Math.max(x, 70), Math.max(width - 70, 70));
    return (
        <div className="pointer-events-none absolute top-0 z-10 -translate-x-1/2 rounded-md border bg-popover px-2.5 py-1.5 text-xs shadow-md" style={{ left }}>
            {children}
        </div>
    );
}

export function Legend({ items }: { items: { label: string; color: string }[] }) {
    return (
        <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
            {items.map((i) => (
                <span key={i.label} className="flex items-center gap-1.5">
                    <i className="size-2.5 rounded-sm" style={{ background: i.color }} /> {i.label}
                </span>
            ))}
        </div>
    );
}

const PAD = { top: 24, right: 40, bottom: 24, left: 36 };

/** One series over time: 2px line, a light wash, the last value labelled, crosshair and tooltip on hover. */
export function LineChart({ data, height = 200, label }: { data: { label: string; value: number }[]; height?: number; label: string }) {
    const [ref, width] = useWidth<HTMLDivElement>();
    const [hover, setHover] = useState<number | null>(null);
    const max = Math.max(...data.map((d) => d.value), 0);
    const min = Math.min(...data.map((d) => d.value), max);
    // Headcount moves a little each month: start the axis near the lowest value so the change is visible.
    const floor = min > 20 ? Math.floor((min * 0.9) / 10) * 10 : 0;
    const yt = ticks(max - floor).map((t) => t + floor);
    const top = yt[yt.length - 1];
    const innerW = Math.max(width - PAD.left - PAD.right, 1);
    const innerH = height - PAD.top - PAD.bottom;
    const x = (i: number) => PAD.left + (data.length === 1 ? innerW / 2 : (i / (data.length - 1)) * innerW);
    const y = (v: number) => PAD.top + innerH - ((v - floor) / Math.max(top - floor, 1)) * innerH;
    const path = data.map((d, i) => `${i ? 'L' : 'M'}${x(i)},${y(d.value)}`).join(' ');
    const last = data.length - 1;

    return (
        <div ref={ref} className="relative" onMouseLeave={() => setHover(null)}>
            {width > 0 && (
                <svg width={width} height={height} role="img" aria-label={label}
                    onMouseMove={(e) => {
                        const r = e.currentTarget.getBoundingClientRect();
                        const i = Math.round(((e.clientX - r.left - PAD.left) / innerW) * (data.length - 1));
                        setHover(Math.min(Math.max(i, 0), last));
                    }}>
                    {yt.map((t) => (
                        <g key={t}>
                            <line x1={PAD.left} x2={width - PAD.right} y1={y(t)} y2={y(t)} stroke={t === floor ? AXIS : GRID} strokeWidth={1} />
                            <text x={PAD.left - 6} y={y(t)} dy="0.32em" textAnchor="end" className="fill-muted-foreground text-[11px] tabular-nums">{fmt(t)}</text>
                        </g>
                    ))}
                    {data.map((d, i) => (i % Math.ceil(data.length / 6) === 0 || i === last) && (
                        <text key={d.label} x={x(i)} y={height - 6} textAnchor="middle" className="fill-muted-foreground text-[11px]">{d.label}</text>
                    ))}
                    <path d={`${path} L${x(last)},${y(floor)} L${x(0)},${y(floor)} Z`} fill={SERIES[0]} opacity={0.1} />
                    <path d={path} fill="none" stroke={SERIES[0]} strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />
                    {hover !== null && <line x1={x(hover)} x2={x(hover)} y1={PAD.top} y2={PAD.top + innerH} stroke={AXIS} strokeWidth={1} />}
                    <circle cx={x(hover ?? last)} cy={y(data[hover ?? last].value)} r={4.5} fill={SERIES[0]} stroke="#fff" strokeWidth={2} />
                    {hover === null && (
                        <text x={x(last) + 8} y={y(data[last].value)} dy="0.32em" className="fill-foreground text-xs font-semibold tabular-nums">{fmt(data[last].value)}</text>
                    )}
                </svg>
            )}
            {hover !== null && (
                <Tooltip x={x(hover)} width={width}>
                    <span className="font-semibold">{data[hover].label}</span>: {fmt(data[hover].value)}
                </Tooltip>
            )}
        </div>
    );
}

/** Two series side by side per period (e.g. joined / left). Legend above; hover a period for both values. */
export function PairedColumns({ data, series, height = 200, label }: {
    data: { label: string; values: [number, number] }[]; series: [string, string]; height?: number; label: string;
}) {
    const [ref, width] = useWidth<HTMLDivElement>();
    const [hover, setHover] = useState<number | null>(null);
    const yt = ticks(Math.max(...data.flatMap((d) => d.values), 1));
    const top = yt[yt.length - 1];
    const innerW = Math.max(width - PAD.left - 8, 1);
    const innerH = height - PAD.top - PAD.bottom;
    const band = innerW / Math.max(data.length, 1);
    const bar = Math.min(18, Math.max((band - 10) / 2 - 1, 3));
    const y = (v: number) => PAD.top + innerH - (v / top) * innerH;
    const r = Math.min(4, bar / 2);
    // Rounded data end, square at the baseline.
    const column = (cx: number, v: number) => {
        const h = (v / top) * innerH;
        if (h <= 0) return '';
        const rr = Math.min(r, h);
        const x0 = cx;
        const y0 = PAD.top + innerH;
        return `M${x0},${y0} V${y0 - h + rr} Q${x0},${y0 - h} ${x0 + rr},${y0 - h} H${x0 + bar - rr} Q${x0 + bar},${y0 - h} ${x0 + bar},${y0 - h + rr} V${y0} Z`;
    };

    return (
        <div className="grid gap-2">
            <Legend items={[{ label: series[0], color: SERIES[0] }, { label: series[1], color: SERIES[1] }]} />
            <div ref={ref} className="relative" onMouseLeave={() => setHover(null)}>
                {width > 0 && (
                    <svg width={width} height={height} role="img" aria-label={label}>
                        {yt.map((t) => (
                            <g key={t}>
                                <line x1={PAD.left} x2={width - 8} y1={y(t)} y2={y(t)} stroke={t === 0 ? AXIS : GRID} strokeWidth={1} />
                                <text x={PAD.left - 6} y={y(t)} dy="0.32em" textAnchor="end" className="fill-muted-foreground text-[11px] tabular-nums">{fmt(t)}</text>
                            </g>
                        ))}
                        {data.map((d, i) => {
                            const mid = PAD.left + band * i + band / 2;
                            return (
                                <g key={d.label} onMouseEnter={() => setHover(i)}>
                                    <rect x={PAD.left + band * i} y={PAD.top} width={band} height={innerH} fill={hover === i ? '#f3f5f8' : 'transparent'} />
                                    <path d={column(mid - bar - 1, d.values[0])} fill={SERIES[0]} />
                                    <path d={column(mid + 1, d.values[1])} fill={SERIES[1]} />
                                    {(i % Math.ceil(data.length / 6) === 0 || i === data.length - 1) && (
                                        <text x={mid} y={height - 6} textAnchor="middle" className="fill-muted-foreground text-[11px]">{d.label}</text>
                                    )}
                                </g>
                            );
                        })}
                    </svg>
                )}
                {hover !== null && (
                    <Tooltip x={PAD.left + band * hover + band / 2} width={width}>
                        <p className="font-semibold">{data[hover].label}</p>
                        <p className="flex items-center gap-1.5"><i className="size-2 rounded-sm" style={{ background: SERIES[0] }} />{series[0]} {fmt(data[hover].values[0])}</p>
                        <p className="flex items-center gap-1.5"><i className="size-2 rounded-sm" style={{ background: SERIES[1] }} />{series[1]} {fmt(data[hover].values[1])}</p>
                    </Tooltip>
                )}
            </div>
        </div>
    );
}

/** Horizontal bars, one series: value at the tip, each row can link somewhere. */
export function BarList({ items, max }: { items: { key: string | number; label: ReactNode; value: number; href?: string; title?: string }[]; max?: number }) {
    const top = max ?? Math.max(...items.map((i) => i.value), 1);
    return (
        <ul className="grid gap-1.5">
            {items.map((i) => (
                <li key={i.key} className="grid grid-cols-[minmax(7rem,12rem)_1fr] items-center gap-3 text-sm" title={i.title}>
                    <span className="truncate">{i.label}</span>
                    <span className="flex items-center gap-2">
                        <span className="h-3 rounded-r-[4px]" style={{ width: `${Math.max((i.value / top) * 85, 0.5)}%`, background: SERIES[0] }} />
                        <span className="text-xs tabular-nums text-muted-foreground">{fmt(i.value)}</span>
                    </span>
                </li>
            ))}
        </ul>
    );
}

/** A part-to-whole bar of states (status colours), 2px gaps between segments, legend with counts underneath. */
export function StateBar({ parts, label, className }: { parts: { label: string; value: number; className: string }[]; label: string; className?: string }) {
    const total = parts.reduce((n, p) => n + p.value, 0);
    const shown = parts.filter((p) => p.value > 0);
    return (
        <div className={cn('grid gap-2', className)}>
            <div className="flex h-4 w-full gap-[2px] overflow-hidden rounded-[4px] bg-muted" role="img" aria-label={`${label}: ${shown.map((p) => `${p.label} ${p.value}`).join(', ')}`}>
                {shown.map((p) => (
                    <div key={p.label} className={p.className} style={{ width: `${(p.value / Math.max(total, 1)) * 100}%` }} title={`${p.label}: ${fmt(p.value)}`} />
                ))}
            </div>
            <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                {parts.map((p) => (
                    <span key={p.label} className="flex items-center gap-1.5">
                        <i className={cn('size-2.5 rounded-sm', p.className)} /> {p.label} <span className="font-semibold text-foreground tabular-nums">{fmt(p.value)}</span>
                    </span>
                ))}
            </div>
        </div>
    );
}

/** Label, big value, optional comparison line. */
export function StatTile({ label, value, detail, href, tone }: { label: string; value: ReactNode; detail?: ReactNode; href?: string; tone?: 'good' | 'bad' }) {
    const body = (
        <>
            <p className="text-xs font-semibold text-muted-foreground">{label}</p>
            <p className="mt-1 text-2xl font-bold">{value}</p>
            {detail && <p className={cn('mt-0.5 text-xs', tone === 'good' ? 'text-done' : tone === 'bad' ? 'text-missing' : 'text-muted-foreground')}>{detail}</p>}
        </>
    );
    return href ? (
        <Link href={href} className="rounded-lg border px-4 py-3 hover:bg-accent">{body}</Link>
    ) : (
        <div className="rounded-lg border px-4 py-3">{body}</div>
    );
}
