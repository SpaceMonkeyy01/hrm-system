import { router } from '@inertiajs/react';
import { Building2, House, LogIn, LogOut } from 'lucide-react';
import { useEffect, useState } from 'react';
import { duration, type Place } from '@/components/attendance';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';

/** app/Services/AttendanceClock.php state(). at = ISO 8601, time = "H:i" in the office's timezone. */
export type ClockState = {
    enabled: boolean;
    open: { at: string; time: string; place: Place | null } | null;
    date: string;
    presses: { at: string; time: string; method: 'In' | 'Out'; place: Place | null }[];
    place: Place;
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

/** Clock in / out on the My page. Each press is saved straight away; mistakes go to HR as an "ask HR to fix a day". */
export function ClockCard({ clock, today }: { clock: ClockState; today: string }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [now, setNow] = useState(() => Date.now());
    const open = clock.open;

    useEffect(() => {
        if (!open) return;
        const t = window.setInterval(() => setNow(Date.now()), 30000);
        return () => window.clearInterval(t);
    }, [open]);

    if (!clock.enabled) return null;

    const press = async () => {
        setBusy(true);
        setError(null);
        const where = await location();
        router.post('/me/clock', { action: open ? 'out' : 'in', ...where }, {
            preserveScroll: true,
            onError: (e) => setError(e.clock ?? Object.values(e)[0] ?? 'Could not save. Try again.'),
            onFinish: () => setBusy(false),
        });
    };
    const since = open ? Math.max(0, Math.floor((now - new Date(open.at).getTime()) / 60000)) : 0;

    return (
        <section className="flex flex-wrap items-center justify-between gap-4 rounded-lg border p-4" aria-label="Clock in">
            <div className="grid gap-1">
                <p className="font-bold">
                    {open ? <>Clocked in since {open.time} <span className="font-normal text-muted-foreground">({duration(since)})</span></> : 'You are not clocked in'}
                </p>
                <p className="text-sm text-muted-foreground">
                    {clock.place === 'office' ? 'You are on the office network, so this counts as office time.' : 'You are not on the office network, so this counts as remote time.'}
                </p>
                {clock.presses.length > 0 && (
                    <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                        <span className="text-muted-foreground">{clock.date === today ? 'Today' : formatDate(clock.date)}:</span>
                        {clock.presses.map((p) => (
                            <span key={p.at} className="inline-flex items-center gap-1 tabular-nums">{p.method} {p.time} <PlaceIcon place={p.place} /></span>
                        ))}
                    </p>
                )}
                {error && <p className="text-sm text-destructive" role="alert">{error}</p>}
            </div>
            <Button size="lg" variant={open ? 'outline' : 'default'} disabled={busy} onClick={press}>
                {open ? <LogOut /> : <LogIn />} {busy ? 'Saving' : open ? 'Clock out' : 'Clock in'}
            </Button>
        </section>
    );
}
