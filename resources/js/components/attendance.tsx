import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Field } from '@/components/field';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format';
import type { Tone } from '@/types';

/** app/Services/AttendanceRules.php day() */
export type AttendanceDay = {
    date: string;
    status: string;
    in: string | null;
    out: string | null;
    in_source: 'punch' | 'manual' | null;
    out_source: 'punch' | 'manual' | 'auto' | null;
    worked: number | null;
    late: number;
    early: number;
    short: number;
    overtime: number;
    holiday: string | null;
    leave: { type: string; part: 'full' | 'first_half' | 'second_half' } | null;
    punches: number;
    working_day: boolean;
    adjusted: boolean;
    note: string | null;
    schedule: string | null;
    punchTimes: string[];
    /** Stretches of work: machine blocks and HR.my In/Out pairs. More than one = a split day. */
    sessions: { in: string | null; out: string | null; source: 'machine' | 'hrmy' | 'manual' | 'mixed'; minutes: number | null }[];
};

const sessionSource = { machine: 'machine', hrmy: 'HR.my', manual: 'HR', mixed: 'machine and HR.my' } as const;
const plainTime = (t: string | null) => (t ? `${String(Number(t.slice(0, 2)) % 24).padStart(2, '0')}${t.slice(2)}` : '?');

/** "09:58-14:00 machine, 20:00-23:00 HR.my" under the hours on a split day; nothing on an ordinary day. */
export function SessionsLine({ day, className }: { day: Pick<AttendanceDay, 'sessions'>; className?: string }) {
    if (day.sessions.length < 2) return null;
    return (
        <span className={`block text-xs font-normal text-muted-foreground ${className ?? ''}`} title="Each stretch of work. The time between them isn't counted.">
            {day.sessions.map((s) => `${plainTime(s.in)}-${plainTime(s.out)} ${sessionSource[s.source]}`).join(', ')}
        </span>
    );
}

export function duration(minutes: number | null | undefined): string {
    if (minutes === null || minutes === undefined) return '';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return h ? `${h}h ${String(m).padStart(2, '0')}m` : `${m}m`;
}

/** Status pill plus Late / Left early / Short flags. */
export function DayStatus({ day, statuses }: { day: AttendanceDay; statuses: Record<string, Tone> }) {
    return (
        <span className="flex flex-wrap items-center gap-1.5">
            <StatusPill value={day.status} tone={statuses[day.status] ?? 'neutral'} />
            {day.late > 0 && <StatusPill value={`Late ${duration(day.late)}`} tone="missing" />}
            {day.early > 0 && <StatusPill value={`Left early ${duration(day.early)}`} tone="pending" />}
            {day.short > 0 && <StatusPill value={`Short ${duration(day.short)}`} tone="pending" />}
            {day.overtime > 0 && <StatusPill value={`Overtime ${duration(day.overtime)}`} tone="info" />}
            {day.leave && <span className="text-xs text-muted-foreground">{day.leave.type}{day.leave.part !== 'full' ? `, ${day.leave.part === 'first_half' ? 'first' : 'second'} half off` : ''}</span>}
            {day.holiday && <span className="text-xs text-muted-foreground">{day.holiday}</span>}
        </span>
    );
}

/** "09:58" with a marker when HR typed it or the schedule filled it in. */
export function TimeCell({ time, source }: { time: string | null; source: string | null }) {
    if (!time) return <span className="text-muted-foreground">-</span>;
    // Night shifts: the rules send the next morning as 24+ hours ("33:00" = 09:00 the next day).
    const [h, m] = time.split(':').map(Number);
    const nextDay = h >= 24;
    return (
        <span className="tabular-nums">
            {nextDay ? `${String(h - 24).padStart(2, '0')}:${String(m).padStart(2, '0')}` : time}
            {nextDay && <span className="ml-1 text-xs text-muted-foreground" title="The next day">+1</span>}
            {source === 'manual' && <span className="ml-1 text-xs text-info" title="Entered by HR">HR</span>}
            {source === 'auto' && <span className="ml-1 text-xs text-muted-foreground" title="No punch out: checked out at the end time">auto</span>}
        </span>
    );
}

/** HR sets the check-in / check-out for one day, with a reason. */
export function AdjustDialog({ open, onClose, employee, day, only }: {
    open: boolean; onClose: () => void; employee: { id: number; name: string }; day: AttendanceDay; only?: string[];
}) {
    // "30:00" (next morning) shows as 06:00 in the time box; the rules put it on the next day again.
    const clock = (t: string | null) => (t ? `${String(Number(t.slice(0, 2)) % 24).padStart(2, '0')}${t.slice(2)}` : '');
    const [checkIn, setCheckIn] = useState(day.in_source === 'manual' ? clock(day.in) : '');
    const [checkOut, setCheckOut] = useState(day.out_source === 'manual' ? clock(day.out) : '');
    const [note, setNote] = useState(day.note ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const opts = { preserveScroll: true, only, onSuccess: () => onClose(), onError: setErrors };

    const save = (e: FormEvent) => {
        e.preventDefault();
        router.put(`/attendance/${employee.id}/${day.date}`, { check_in: checkIn || null, check_out: checkOut || null, note }, opts);
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={save} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{employee.name}, {formatDate(day.date)}</DialogTitle>
                        <DialogDescription>
                            Punches: {day.punchTimes.length ? day.punchTimes.join(', ') : 'none'}.
                            {day.sessions.length > 1 && ' This day has more than one session: check-in changes the first one, check-out the last one.'}
                            {' '}Leave a time empty to use the punch.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Check-in" htmlFor="adj-in" error={errors.check_in}>
                            <Input id="adj-in" type="time" value={checkIn} onChange={(e) => setCheckIn(e.target.value)} />
                        </Field>
                        <Field label="Check-out" htmlFor="adj-out" error={errors.check_out}>
                            <Input id="adj-out" type="time" value={checkOut} onChange={(e) => setCheckOut(e.target.value)} />
                        </Field>
                        <Field label="Reason" htmlFor="adj-note" error={errors.note} className="sm:col-span-2">
                            <Textarea id="adj-note" rows={2} required value={note} placeholder="Forgot to punch out, confirmed by manager" onChange={(e) => setNote(e.target.value)} />
                        </Field>
                    </div>
                    <DialogFooter className="gap-2 sm:justify-between">
                        {day.adjusted ? (
                            <Button type="button" variant="ghost" onClick={() => router.delete(`/attendance/${employee.id}/${day.date}`, opts)}>Remove manual times</Button>
                        ) : <span />}
                        <Button type="submit">Save times</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
