import { Head, Link, router } from '@inertiajs/react';
import { Building2, House, MapPin, Plus, Trash2, Wifi } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { PageHeader, Section } from '@/components/page-header';
import { AttendanceTabs } from '@/components/section-tabs';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate, formatDateTime, plural } from '@/lib/format';

/** app/Http/Controllers/ClockInController.php index() */
type Network = { id: number | null; name: string; address: string; server: boolean };
type Row = {
    id: number; employee: { id: number; name: string; code: string; department: string | null } | null;
    date: string; at: string; time: string; method: 'In' | 'Out'; place: 'office' | 'remote' | null;
    ip: string | null; network: string | null; location: { lat: number; lng: number; accuracy: number | null } | null;
};
type Unlisted = { address: string; clockIns: number; people: string[]; last: string };
type Props = {
    filters: { from: string; to: string; unlisted: boolean };
    networks: Network[]; rows: Row[]; truncated: boolean; unlisted: Unlisted[];
    you: { ip: string | null; network: string | null; error: string | null };
    enabled: boolean; today: string;
};

const mapUrl = (l: NonNullable<Row['location']>) => `https://www.google.com/maps?q=${l.lat},${l.lng}`;

function AddNetwork({ address, onClose }: { address: string; onClose: () => void }) {
    const [d, setD] = useState({ name: '', address, recount: true });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const save = (e: FormEvent) => {
        e.preventDefault();
        router.post('/attendance/networks', d, {
            preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose, onError: setErrors,
        });
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={save} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Add an office network</DialogTitle>
                        <DialogDescription>Clock-ins on the app from this address will count as office time.</DialogDescription>
                    </DialogHeader>
                    <Field label="Name" htmlFor="n-name" error={errors.name} hint="So HR knows which line it is.">
                        <Input id="n-name" value={d.name} required maxLength={80} autoFocus placeholder="Main line, 2nd floor Wi-Fi"
                            onChange={(e) => setD({ ...d, name: e.target.value })} />
                    </Field>
                    <Field label="Internet address" htmlFor="n-address" error={errors.address}
                        hint="The public address: open whatismyip.com while on that network. A range like 203.0.113.0/24 works too.">
                        <Input id="n-address" value={d.address} required maxLength={50} placeholder="203.0.113.7" className="font-mono"
                            onChange={(e) => setD({ ...d, address: e.target.value })} />
                    </Field>
                    <label className="flex items-start gap-2 text-sm">
                        <input type="checkbox" className="mt-1" checked={d.recount} onChange={(e) => setD({ ...d, recount: e.target.checked })} />
                        <span>Also count earlier clock-ins from this address as office time</span>
                    </label>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={busy}>Add network</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function ClockIns(props: Props) {
    const [adding, setAdding] = useState<string | null>(null);
    const f = props.filters;
    const go = (next: Partial<Props['filters']>) => {
        const q = { ...f, ...next };
        router.get('/attendance/clock-ins', { from: q.from, to: q.to, ...(q.unlisted ? { unlisted: 1 } : {}) }, { preserveState: true, preserveScroll: true, replace: true });
    };
    const remove = (n: Network) => {
        if (confirm(`Remove ${n.name} (${n.address})? New clock-ins from it will count as remote.`)) {
            router.delete(`/attendance/networks/${n.id}`, { preserveScroll: true, onError: (e) => toast.error(Object.values(e)[0] ?? 'Not removed.') });
        }
    };

    return (
        <>
            <Head title="App clock-ins" />
            <PageHeader
                title="App clock-ins"
                tabs={<AttendanceTabs />}
                description="Clock-ins from My page. A clock-in from an office network counts as office time; from anywhere else, remote."
            />
            <div className="grid max-w-6xl grid-cols-1 gap-8 px-4 py-6 md:px-6">
                {!props.enabled && <p className="rounded-lg border border-pending/40 bg-pending/10 p-3 text-sm">Clocking in on the app is switched off on the server (HRM_CLOCK_ENABLED).</p>}

                <Section
                    title="Office networks"
                    description="The office's internet addresses. Add every line the office uses: each Wi-Fi or cable network that goes out through a different connection."
                    actions={<Button size="sm" onClick={() => setAdding('')}><Plus /> Add network</Button>}
                >
                    <div className="grid gap-3">
                        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/30 p-3 text-sm">
                            <span className="flex items-center gap-2">
                                <Wifi className="size-4 text-muted-foreground" />
                                <span>
                                    You are on <span className="font-mono font-semibold">{props.you.ip ?? 'unknown'}</span>
                                    {props.you.network ? <>, which is <span className="font-semibold">{props.you.network}</span>.</> : ', which is not on the list.'}
                                </span>
                            </span>
                            {!props.you.network && props.you.ip && !props.you.error && (
                                <Button size="sm" variant="outline" onClick={() => setAdding(props.you.ip ?? '')}><Plus /> Add the network I'm on now</Button>
                            )}
                            {!props.you.network && props.you.error && <span className="text-xs text-muted-foreground">This address can't be added (it isn't a public one).</span>}
                        </div>
                        {props.networks.length === 0 ? (
                            <EmptyState icon={Building2} title="No office networks yet.">Until you add them, every app clock-in counts as remote.</EmptyState>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {props.networks.map((n) => (
                                    <li key={n.address} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-sm">
                                        <span><span className="font-semibold">{n.name}</span> <span className="ml-2 font-mono text-muted-foreground">{n.address}</span></span>
                                        {n.server ? <span className="text-xs text-muted-foreground">Change it in the server settings (HRM_OFFICE_IPS)</span>
                                            : <Button variant="ghost" size="sm" onClick={() => remove(n)} aria-label={`Remove ${n.name}`}><Trash2 /></Button>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </Section>

                {props.unlisted.length > 0 && (
                    <Section title="Addresses not on the list" description={`Where people clocked in from, ${formatDate(f.from)} to ${formatDate(f.to)}. An address many colleagues use is probably an office line.`}>
                        <ul className="divide-y rounded-lg border">
                            {props.unlisted.map((u) => (
                                <li key={u.address} className="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5 text-sm">
                                    <span className="grid gap-0.5">
                                        <span><span className="font-mono font-semibold">{u.address}</span> <span className="ml-2 text-muted-foreground">{plural(u.people.length, 'person', 'people')}, {plural(u.clockIns, 'clock-in')}, last {formatDateTime(u.last)}</span></span>
                                        <span className="text-xs text-muted-foreground">{u.people.slice(0, 6).join(', ')}{u.people.length > 6 ? ` and ${u.people.length - 6} more` : ''}</span>
                                    </span>
                                    <Button size="sm" variant="outline" onClick={() => setAdding(u.address)}><Plus /> Add as office network</Button>
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}

                <Section
                    title="Clock-ins"
                    actions={
                        <div className="flex flex-wrap items-end gap-2">
                            <Input type="date" className="w-40" value={f.from} max={props.today} aria-label="From" onChange={(e) => e.target.value && go({ from: e.target.value })} />
                            <Input type="date" className="w-40" value={f.to} max={props.today} aria-label="To" onChange={(e) => e.target.value && go({ to: e.target.value })} />
                            <Button size="sm" variant={f.unlisted ? 'default' : 'outline'} onClick={() => go({ unlisted: !f.unlisted })}>Not from an office network</Button>
                        </div>
                    }
                >
                    {props.rows.length === 0 ? <EmptyState icon={MapPin} title="No clock-ins on the app for these days." /> : (
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full min-w-[48rem] text-sm">
                                <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-3 py-2 font-semibold">Person</th><th className="px-3 py-2 font-semibold">Day</th>
                                        <th className="px-3 py-2 font-semibold">Time</th><th className="px-3 py-2 font-semibold">Counted as</th>
                                        <th className="px-3 py-2 font-semibold">Address</th><th className="px-3 py-2 font-semibold">Location</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {props.rows.map((r) => (
                                        <tr key={r.id}>
                                            <td className="px-3 py-2">
                                                {r.employee ? <Link href={`/employees/${r.employee.id}#attendance`} className="font-semibold hover:underline">{r.employee.name}</Link> : <span className="text-muted-foreground">Unknown</span>}
                                                {r.employee?.department && <span className="block text-xs text-muted-foreground">{r.employee.department}</span>}
                                            </td>
                                            <td className="px-3 py-2 tabular-nums">{formatDate(r.date)}</td>
                                            <td className="px-3 py-2 tabular-nums">{r.method} {r.time}</td>
                                            <td className="px-3 py-2">
                                                {r.place === 'office'
                                                    ? <span className="inline-flex items-center gap-1"><Building2 className="size-3.5 text-muted-foreground" /> Office</span>
                                                    : <span className="inline-flex items-center gap-1"><House className="size-3.5 text-info" /> Remote</span>}
                                            </td>
                                            <td className="px-3 py-2">
                                                <span className="font-mono">{r.ip ?? '-'}</span>
                                                {r.network ? <span className="block text-xs text-muted-foreground">{r.network}</span>
                                                    : r.ip && <span className="block"><StatusPill value="Not on the list" tone="neutral" /></span>}
                                            </td>
                                            <td className="px-3 py-2">
                                                {r.location
                                                    ? <a href={mapUrl(r.location)} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-info hover:underline"><MapPin className="size-3.5" /> Map{r.location.accuracy ? ` (±${r.location.accuracy} m)` : ''}</a>
                                                    : <span className="text-muted-foreground" title="The person didn't allow location, or the browser couldn't tell">Not shared</span>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {props.truncated && <p className="mt-2 text-sm text-muted-foreground">Showing the latest 1,000. Pick fewer days to see the rest.</p>}
                </Section>
            </div>
            {adding !== null && <AddNetwork address={adding} onClose={() => setAdding(null)} />}
        </>
    );
}
