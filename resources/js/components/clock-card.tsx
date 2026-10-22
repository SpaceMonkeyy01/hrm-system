import { router } from '@inertiajs/react';
import { Building2, Clock, House, LogIn, LogOut } from 'lucide-react';
import { useEffect, useState } from 'react';
import { duration, type Place } from '@/components/attendance';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';

/** app/Services/AttendanceClock.php state(). at = ISO 8601, time = "H:i" in the office's timezone. */
export type ClockState = {
    enabled: boolean;
    open: { at: string; time: string; place: Place | null } | null;
    date: string;
    presses: { at: string; time: string; method: 'In' | 'Out'; place: Place | null; stated: boolean }[];
    place: Place;
    /** "09:10": today's machine check-in has no check-out. Clocking in away from the office first asks when they left. */
    office_open: string | null;
};

/** The browser's location if the person allows it, else nothing (never blocks the clock-in). */
function location(): Promise<{ latitude?: number; longitude?: number; accuracy?: number }> {
    return new Promise((resolve) => {
        if (typeof navigator === 'undefined' || !navigator.geolocation) return resolve({});
        navigator.geolocation.getCurrentPosition(
            (p) => resolve({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy: p.coords.accuracy }),
            () => resolve({}),
            { timeout: 6000, maximumAge: 60000 },
        );
    });
}

function PlaceIcon({ place }: { place: Place | null }) {
    return place === 'office'
        ? <Building2 className="inline size-3.5 text-muted-foreground" aria-label="office" />
        : <House className="inline size-3.5 text-info" aria-label="remote" />;
}

/**
 * Clock in / out on the My page, as one line and the button. Each press is saved straight away; mistakes go to HR as
 * an "ask HR to fix a day". lastSync: when machine punches last arrived (people on the machine), so nobody clocks in
 * twice while the machine's punch is still on its way.
 */
export function ClockCard({ clock, today, lastSync }: { clock: ClockState; today: string; lastSync?: string | null }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [now, setNow] = useState(() => Date.now());
    const [leftOffice, setLeftOffice] = useState('');
    const open = clock.open;
    // Checked in on the machine, never checked out, now clocking in from elsewhere: close the office time first.
    const closeOffice = !open && clock.office_open !== null && clock.place === 'remote';

    useEffect(() => {
        if (!open) return;
        const t = window.setInterval(() => setNow(Date.now()), 30000);
        return () => window.clearInterval(t);
    }, [open]);

    if (!clock.enabled) return null;

    const press = async () => {
        if (closeOffice && !leftOffice) {
            setError('Enter the time you left the office first.');
            return;
        }
        setBusy(true);
        setError(null);
        const where = await location();
        router.post('/me/clock', { action: open ? 'out' : 'in', ...where, ...(closeOffice ? { left_office: leftOffice } : {}) }, {
            preserveScroll: true,
            onSuccess: () => setLeftOffice(''),
            onError: (e) => setError(e.left_office ?? e.clock ?? Object.values(e)[0] ?? 'Could not save. Try again.'),
            onFinish: () => setBusy(false),
        });
    };
    const since = open ? Math.max(0, Math.floor((now - new Date(open.at).getTime()) / 60000)) : 0;
    const placeText = clock.place === 'office' ? 'On the office network: counts as office time' : 'Away from the office: counts as remote time';
    const synced = lastSync ? new Date(lastSync).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) : null;

    return (
        <section className="grid gap-3 rounded-lg border bg-muted/40 p-4" aria-label="Clock in">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="hidden size-10 shrink-0 items-center justify-center rounded-full bg-background sm:flex"><Clock className="size-5 text-cascade" /></span>
                    <div className="min-w-0">
                        <p className="font-bold">
                            {open ? <>At work since {open.time}</> : clock.office_open ? <>On the office machine since {clock.office_open}</> : 'Not clocked in'}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {open ? `${duration(since)} so far · ${open.place === 'office' ? 'office' : 'remote'}` : placeText}
                            {synced && ` · machine last synced ${synced}`}
                        </p>
                    </div>
                </div>
                <Button size="lg" variant={open ? 'outline' : 'default'} disabled={busy} onClick={press} className="w-full sm:w-auto">
                    {open ? <LogOut /> : <LogIn />} {busy ? 'Saving' : open ? 'Clock out' : closeOffice ? 'Close office time and clock in' : 'Clock in'}
                </Button>
            </div>
            {clock.presses.length > 0 && (
                <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                    <span>{clock.date === today ? 'Today' : formatDate(clock.date)}:</span>
                    {clock.presses.map((p) => (
                        <span key={p.at} className="inline-flex items-center gap-1 text-foreground tabular-nums">
                            {p.method} {p.time} <PlaceIcon place={p.place} />
                            {p.stated && <span className="text-muted-foreground" title="The time you typed for leaving the office">(stated)</span>}
                        </span>
                    ))}
                </p>
            )}
            {closeOffice && (
                <label className="flex flex-wrap items-center gap-2 rounded-md border border-pending/40 bg-pending-soft px-3 py-2 text-sm">
                    <span>You didn't check out of the office ({clock.office_open}). I left at</span>
                    <Input type="time" className="h-9 w-32 bg-background" value={leftOffice} min={clock.office_open ?? undefined}
                        onChange={(e) => setLeftOffice(e.target.value)} aria-label="Time you left the office" />
                </label>
            )}
            {error && <p className="text-sm text-destructive" role="alert">{error}</p>}
        </section>
    );
}
