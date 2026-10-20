import { Link, router } from '@inertiajs/react';
import { Building2, House, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Field } from '@/components/field';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime, timeAgo } from '@/lib/format';
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
    leave: { type: string; part: 'full' | 'first_half' | 'second_half' | 'short' } | null;
    punches: number;
    working_day: boolean;
    adjusted: boolean;
    note: string | null;
    schedule: string | null;
    punchTimes: string[];
    /** Stretches of work: machine blocks and HR.my / app In/Out pairs. More than one = a split day. */
    sessions: {
        in: string | null; out: string | null; source: 'machine' | 'hrmy' | 'app' | 'manual' | 'mixed'; minutes: number | null;
        edited: boolean; in_where: Place | null; out_where: Place | null;
        /** wholly within another session (HR.my tapped during the office day): shown, adds no time or place */
        inside: boolean;
    }[];
    /** office = machine punches or app clock-ins from the office network, remote = HR.my or the app elsewhere, both = some of each */
    where: Place | 'both' | null;
    in_where: Place | null;
    out_where: Place | null;
};

export type Place = 'office' | 'remote';
const plainTime = (t: string | null) => (t ? `${String(Number(t.slice(0, 2)) % 24).padStart(2, '0')}${t.slice(2)}` : '?');

/** "09:58-14:00 machine, 20:00-23:00 HR.my" under the hours on a split day; nothing on an ordinary day. */
export function SessionsLine({ day, className }: { day: Pick<AttendanceDay, 'sessions'>; className?: string }) {
    if (day.sessions.length < 2) return null;
    return (
        <span className={`block text-xs font-normal text-muted-foreground ${className ?? ''}`} title="Each stretch of work. The time between them isn't counted; one marked (within) lies inside another and adds no time.">
            {day.sessions.map((s) => {
                const where = s.in_where && s.out_where && s.in_where !== s.out_where ? `${s.in_where} to ${s.out_where}` : (s.in_where ?? s.out_where ?? '');
                return `${plainTime(s.in)}-${plainTime(s.out)} ${where}${s.edited ? ' (HR)' : ''}${s.inside ? ' (within)' : ''}`.trim();
            }).join(', ')}
        </span>
    );
}

/** app/Services/Import/MachineSyncer.php deviceStatus() */
export type DeviceStatus = {
    id: number;
    name: string;
    last: { at: string; ok: boolean | null; added: number; error: string | null } | null;
    last_ok_at: string | null;
    clock_offset: number | null;
    clock_wrong: boolean;
    /** On automatic sync but no good sync for over an hour (MachineSyncer::isOverdue) */
    overdue: boolean;
    recent: { at: string; ok: boolean | null; added: number; checked: number; trigger: string; error: string | null }[];
};

/** app/Services/Import/MachineSyncer.php status(): every active device */
export type MachineStatus = { configured: boolean; devices?: DeviceStatus[] };

/** "Synced 4 minutes ago, 3 new punches", or the error when the last try failed. */
export function deviceLine(d: DeviceStatus) {
    const last = d.last;
    if (!last) return 'Not synced yet.';
    if (last.ok) return `Synced ${timeAgo(last.at)}${last.added ? `, ${last.added} new ${last.added === 1 ? 'punch' : 'punches'}` : ', nothing new'}.`;
    if (last.ok === null) return `Syncing since ${timeAgo(last.at)}.`;
    return `Last try ${timeAgo(last.at)} failed: ${last.error}${d.last_ok_at ? ` Last good sync ${timeAgo(d.last_ok_at)}.` : ''}`;
}

export function ClockWarning({ offset }: { offset: number }) {
    return (
        <p className="text-pending">
            The machine's clock is {Math.abs(offset) < 120 ? `${Math.abs(offset)} seconds` : duration(Math.round(Math.abs(offset) / 60))} {offset > 0 ? 'fast' : 'slow'}, so its punches are off by that much. Set the time on the machine (Menu, Date/Time).
        </p>
    );
}

export function RecentSyncs({ recent }: { recent: DeviceStatus['recent'] }) {
    if (recent.length === 0) return null;
    return (
        <ul className="grid gap-0.5 text-xs text-muted-foreground">
            {recent.map((r) => (
                <li key={r.at}>
                    {formatDateTime(r.at)}, {r.trigger === 'cron' ? 'automatic' : r.trigger === 'manual' ? 'Sync now' : r.trigger}:{' '}
                    {r.ok ? `${r.added} new of ${r.checked} recent` : r.ok === false ? <span className="text-missing">{r.error}</span> : 'did not finish'}
                </li>
            ))}
        </ul>
    );
}

/** Each attendance device's last sync with Sync now (all devices); the error when a device's last try failed. */
export function MachineSyncBar({ machine, canSync, showRecent }: { machine: MachineStatus; canSync: boolean; showRecent?: boolean }) {
    const [busy, setBusy] = useState(false);
    if (!machine.configured || !machine.devices) return null;
    const devices = machine.devices;
    const failing = devices.some((d) => d.last?.ok === false || d.overdue);
    const sync = () => router.post('/attendance/machine/sync', {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    return (
        <div className={`grid gap-2 rounded-lg border px-4 py-3 text-sm ${failing ? 'border-[#f0b8b8] bg-missing-soft' : ''}`}>
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="grid gap-1">
                    {devices.map((d) => (
                        <p key={d.id}><span className="font-semibold">{devices.length === 1 ? 'Attendance machine' : d.name}. </span>{deviceLine(d)}</p>
                    ))}
                </div>
                <div className="flex flex-wrap gap-2">
                    {canSync && <Button variant="ghost" size="sm" asChild><Link href="/attendance/devices">Devices</Link></Button>}
                    {canSync && <Button variant="outline" size="sm" disabled={busy} onClick={sync}><RefreshCw className={busy ? 'animate-spin' : ''} /> {busy ? 'Reading' : 'Sync now'}</Button>}
                </div>
            </div>
            {devices.filter((d) => d.overdue && d.last?.ok !== false).map((d) => (
                <p key={`o${d.id}`} className="font-semibold text-missing">
                    {devices.length > 1 && `${d.name}: `}Sync overdue: no automatic sync {d.last_ok_at ? `since ${timeAgo(d.last_ok_at)}` : 'yet'}. Check the cron job (cron-job.org) and that the machine is reachable; until then days show No data or Absent.
                </p>
            ))}
            {devices.filter((d) => d.clock_wrong).map((d) => (
                <div key={d.id}>{devices.length > 1 && <span className="font-semibold">{d.name}: </span>}<ClockWarning offset={d.clock_offset ?? 0} /></div>
            ))}
            {showRecent && devices.map((d) => (
                <div key={d.id} className="grid gap-0.5">
                    {devices.length > 1 && d.recent.length > 0 && <span className="text-xs font-semibold">{d.name}</span>}
                    <RecentSyncs recent={d.recent} />
                </div>
            ))}
        </div>
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
            {day.where === 'remote' && <StatusPill value="Remote" tone="info" />}
            {day.where === 'both' && <StatusPill value="Office + remote" tone="info" />}
            {day.leave && <span className="text-xs text-muted-foreground">{day.leave.type}{day.leave.part !== 'full' ? `, ${day.leave.part === 'short' ? 'short leave' : `${day.leave.part === 'first_half' ? 'first' : 'second'} half off`}` : ''}</span>}
            {day.holiday && <span className="text-xs text-muted-foreground">{day.holiday}</span>}
        </span>
    );
}

/** "09:58" with where it was punched (building = office, house = remote) and a marker when HR typed it. */
export function TimeCell({ time, source, where }: { time: string | null; source: string | null; where?: Place | null }) {
    if (!time) return <span className="text-muted-foreground">-</span>;
    // Night shifts: the rules send the next morning as 24+ hours ("33:00" = 09:00 the next day).
    const [h, m] = time.split(':').map(Number);
    const nextDay = h >= 24;
    return (
        <span className="whitespace-nowrap tabular-nums">
            {nextDay ? `${String(h - 24).padStart(2, '0')}:${String(m).padStart(2, '0')}` : time}
            {nextDay && <span className="ml-1 text-xs text-muted-foreground" title="The next day">+1</span>}
            {where === 'office' && <span title="Office (attendance machine or clocked in on the office network)"><Building2 className="ml-1 inline size-3.5 text-muted-foreground" aria-label="office" /></span>}
            {where === 'remote' && <span title="Remote (HR.my, or clocked in on the app away from the office)"><House className="ml-1 inline size-3.5 text-info" aria-label="remote" /></span>}
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
