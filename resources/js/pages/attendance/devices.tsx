import { Head, router } from '@inertiajs/react';
import { Fingerprint, Pencil, Plug, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { ClockWarning, deviceLine, RecentSyncs } from '@/components/attendance';
import type { DeviceStatus } from '@/components/attendance';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { PageHeader } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime, timeAgo } from '@/lib/format';

/** app/Http/Controllers/AttendanceDeviceController.php index() */
type Device = {
    id: number; code: string; name: string; site: string | null; host: string; port: number; is_active: boolean; auto_sync: boolean;
    timeout_seconds: number; lookback_days: number; clock_warn_seconds: number; notes: string | null;
    model_name: string | null; serial: string | null; firmware: string | null; user_count: number | null; record_count: number | null;
    has_comm_key: boolean; info_read_at: string | null; punches: number; last_punch_at: string | null; status: DeviceStatus;
};
type Defaults = { port: number; timeout_seconds: number; lookback_days: number; clock_warn_seconds: number };
type Props = { devices: Device[]; defaults: Defaults; cronReady: boolean };

type Form = {
    name: string; site: string; host: string; port: string; comm_key: string; clear_comm_key: boolean; is_active: boolean; auto_sync: boolean;
    timeout_seconds: string; lookback_days: string; clock_warn_seconds: string; notes: string;
};

function DeviceDialog({ device, defaults, onClose }: { device: Device | null; defaults: Defaults; onClose: () => void }) {
    const [d, setD] = useState<Form>({
        name: device?.name ?? '', site: device?.site ?? '', host: device?.host ?? '', port: String(device?.port ?? defaults.port),
        comm_key: '', clear_comm_key: false, is_active: device?.is_active ?? true, auto_sync: device?.auto_sync ?? true,
        timeout_seconds: String(device?.timeout_seconds ?? defaults.timeout_seconds), lookback_days: String(device?.lookback_days ?? defaults.lookback_days),
        clock_warn_seconds: String(device?.clock_warn_seconds ?? defaults.clock_warn_seconds), notes: device?.notes ?? '',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const set = <K extends keyof Form>(k: K, v: Form[K]) => setD((s) => ({ ...s, [k]: v }));
    const save = (e: FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose, onError: setErrors };
        if (device) router.put(`/attendance/devices/${device.id}`, d, opts);
        else router.post('/attendance/devices', d, opts);
    };
    const check = (k: 'is_active' | 'auto_sync', label: string, hint: string) => (
        <label className="flex items-start gap-2 text-sm">
            <input type="checkbox" className="mt-1" checked={d[k]} onChange={(e) => set(k, e.target.checked)} />
            <span><span className="font-semibold">{label}</span><span className="block text-xs text-muted-foreground">{hint}</span></span>
        </label>
    );
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <form onSubmit={save} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{device ? `Edit ${device.name}` : 'Add an attendance device'}</DialogTitle>
                        <DialogDescription>A ZKTeco machine the app reads over the internet. The app only reads punches, the user list and the clock; it never changes anything on the machine.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Name" htmlFor="d-name" error={errors.name}>
                            <Input id="d-name" value={d.name} required maxLength={80} autoFocus placeholder="Main office" onChange={(e) => set('name', e.target.value)} />
                        </Field>
                        <Field label="Where it is (optional)" htmlFor="d-site" error={errors.site}>
                            <Input id="d-site" value={d.site} maxLength={120} placeholder="Reception, ground floor" onChange={(e) => set('site', e.target.value)} />
                        </Field>
                        <Field label="Address" htmlFor="d-host" error={errors.host} hint="The office's static IP (port forwarded to the machine), or its address on the network.">
                            <Input id="d-host" value={d.host} required maxLength={255} placeholder="203.0.113.10" className="font-mono" onChange={(e) => set('host', e.target.value)} />
                        </Field>
                        <Field label="Port" htmlFor="d-port" error={errors.port} hint="4370 unless changed on the machine.">
                            <Input id="d-port" type="number" min={1} max={65535} value={d.port} required className="font-mono" onChange={(e) => set('port', e.target.value)} />
                        </Field>
                        <Field label="Comm Key" htmlFor="d-key" error={errors.comm_key} className="sm:col-span-2"
                            hint={device?.has_comm_key ? 'A key is saved. Leave blank to keep it, or type a new one.' : 'As set on the machine (Comm. > Comm Key). Blank if the machine has none.'}>
                            <Input id="d-key" type="password" inputMode="numeric" autoComplete="new-password" value={d.comm_key} maxLength={6} disabled={d.clear_comm_key}
                                placeholder={device?.has_comm_key ? '••••••' : ''} onChange={(e) => set('comm_key', e.target.value)} />
                        </Field>
                        {device?.has_comm_key && (
                            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                <input type="checkbox" checked={d.clear_comm_key} onChange={(e) => set('clear_comm_key', e.target.checked)} /> The machine has no Comm Key any more
                            </label>
                        )}
                    </div>
                    <div className="grid gap-3 rounded-lg border p-3">
                        {check('is_active', 'Switched on', 'Off: never read. Its punches stay.')}
                        {check('auto_sync', 'Sync automatically', 'Read every few minutes by the automatic sync. Off: only when someone presses Sync now.')}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Wait for the machine (seconds)" htmlFor="d-timeout" error={errors.timeout_seconds} hint="Raise it for a slow line.">
                            <Input id="d-timeout" type="number" min={5} max={120} value={d.timeout_seconds} required onChange={(e) => set('timeout_seconds', e.target.value)} />
                        </Field>
                        <Field label="Re-check days" htmlFor="d-lookback" error={errors.lookback_days} hint="Each sync compares this many days back.">
                            <Input id="d-lookback" type="number" min={1} max={60} value={d.lookback_days} required onChange={(e) => set('lookback_days', e.target.value)} />
                        </Field>
                        <Field label="Clock warning (seconds)" htmlFor="d-warn" error={errors.clock_warn_seconds} hint="Warn when the clock is off by more.">
                            <Input id="d-warn" type="number" min={10} max={3600} value={d.clock_warn_seconds} required onChange={(e) => set('clock_warn_seconds', e.target.value)} />
                        </Field>
                    </div>
                    <Field label="Notes (optional)" htmlFor="d-notes" error={errors.notes}>
                        <Textarea id="d-notes" rows={2} value={d.notes} maxLength={1000} placeholder="Router port forward set up by ..., admin PIN kept by ..." onChange={(e) => set('notes', e.target.value)} />
                    </Field>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={busy}>{device ? 'Save device' : 'Add device'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DeviceCard({ device: d, onEdit }: { device: Device; onEdit: () => void }) {
    const [busy, setBusy] = useState<'test' | 'sync' | null>(null);
    const post = (what: 'test' | 'sync') => router.post(`/attendance/devices/${d.id}/${what}`, {}, {
        preserveScroll: true, onStart: () => setBusy(what), onFinish: () => setBusy(null), onError: (e) => toast.error(Object.values(e)[0] ?? 'Failed.'),
    });
    const remove = () => {
        if (confirm(`Remove ${d.name}? Its ${d.punches.toLocaleString()} punches stay in attendance. To stop reading it for now, switch it off instead.`)) {
            router.delete(`/attendance/devices/${d.id}`, { preserveScroll: true });
        }
    };
    const s = d.status;
    const failing = s.last?.ok === false;
    return (
        <section className={`grid gap-4 rounded-lg border p-4 ${!d.is_active ? 'opacity-70' : ''}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid gap-1">
                    <h2 className="flex flex-wrap items-center gap-2 text-base font-bold">
                        <Fingerprint className="size-4 text-muted-foreground" /> <span className="font-mono text-sm text-muted-foreground">{d.code}</span> {d.name}
                        {!d.is_active ? <StatusPill value="Switched off" tone="neutral" />
                            : failing ? <StatusPill value="Last sync failed" tone="missing" />
                                : s.last?.ok ? <StatusPill value="Working" tone="done" /> : <StatusPill value="Not synced yet" tone="pending" />}
                        {d.is_active && !d.auto_sync && <StatusPill value="Sync now only" tone="neutral" />}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {d.site && <>{d.site} · </>}<span className="font-mono">{d.host}:{d.port}</span> · {d.has_comm_key ? 'Comm Key set' : 'No Comm Key'}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" disabled={busy !== null || !d.is_active} onClick={() => post('test')}><Plug /> {busy === 'test' ? 'Connecting' : 'Test connection'}</Button>
                    <Button variant="outline" size="sm" disabled={busy !== null || !d.is_active} onClick={() => post('sync')}><RefreshCw className={busy === 'sync' ? 'animate-spin' : ''} /> {busy === 'sync' ? 'Reading' : 'Sync now'}</Button>
                    <Button variant="ghost" size="sm" onClick={onEdit}><Pencil /> Edit</Button>
                    <Button variant="ghost" size="sm" onClick={remove} aria-label={`Remove ${d.name}`}><Trash2 /></Button>
                </div>
            </div>

            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt className="text-xs text-muted-foreground">Machine</dt><dd>{d.model_name ?? '-'}{d.firmware && <span className="block text-xs text-muted-foreground">{d.firmware}</span>}</dd></div>
                <div><dt className="text-xs text-muted-foreground">Serial number</dt><dd className="font-mono">{d.serial ?? '-'}</dd></div>
                <div><dt className="text-xs text-muted-foreground">On the machine</dt><dd>{d.user_count !== null ? `${d.user_count.toLocaleString()} users, ${(d.record_count ?? 0).toLocaleString()} punches` : '-'}</dd></div>
                <div><dt className="text-xs text-muted-foreground">In the app</dt><dd>{d.punches.toLocaleString()} punches{d.last_punch_at && <span className="block text-xs text-muted-foreground">Latest {formatDateTime(d.last_punch_at)}</span>}</dd></div>
                <div className="sm:col-span-2"><dt className="text-xs text-muted-foreground">Last sync</dt><dd className={failing ? 'text-missing' : ''}>{deviceLine(s)}</dd></div>
                <div><dt className="text-xs text-muted-foreground">Settings</dt><dd>Waits {d.timeout_seconds} s, re-checks {d.lookback_days} {d.lookback_days === 1 ? 'day' : 'days'}, warns at {d.clock_warn_seconds} s off</dd></div>
                <div><dt className="text-xs text-muted-foreground">Machine details read</dt><dd>{d.info_read_at ? timeAgo(d.info_read_at) : 'Not yet'}</dd></div>
            </dl>
            {s.clock_wrong && <ClockWarning offset={s.clock_offset ?? 0} />}
            {d.notes && <p className="text-sm whitespace-pre-line text-muted-foreground">{d.notes}</p>}
            {s.recent.length > 0 && (
                <details className="text-sm">
                    <summary className="cursor-pointer text-muted-foreground">Recent syncs</summary>
                    <div className="mt-2"><RecentSyncs recent={s.recent} /></div>
                </details>
            )}
        </section>
    );
}

export default function Devices({ devices, defaults, cronReady }: Props) {
    const [editing, setEditing] = useState<Device | 'new' | null>(null);
    return (
        <>
            <Head title="Attendance devices" />
            <PageHeader
                back={{ href: '/attendance', label: 'Attendance' }}
                title="Attendance devices"
                description="The fingerprint and face machines the app reads punches from. Enrol each person under the same number on every machine, so one link covers all of them."
                actions={<Button onClick={() => setEditing('new')}><Plus /> Add device</Button>}
            />
            <div className="grid max-w-5xl grid-cols-1 gap-4 px-4 py-6 md:px-6">
                {!cronReady && devices.some((d) => d.is_active && d.auto_sync) && (
                    <p className="rounded-lg border p-3 text-sm text-muted-foreground">
                        Automatic sync needs the server's cron link (CRON_TOKEN) opened every few minutes by a cron service. Until then, devices are only read when someone presses Sync now.
                    </p>
                )}
                {devices.length === 0
                    ? <EmptyState icon={Fingerprint} title="No attendance devices yet." action={<Button onClick={() => setEditing('new')}><Plus /> Add device</Button>}>Add the office machine to read its punches automatically.</EmptyState>
                    : devices.map((d) => <DeviceCard key={d.id} device={d} onEdit={() => setEditing(d)} />)}
            </div>
            {editing && <DeviceDialog device={editing === 'new' ? null : editing} defaults={defaults} onClose={() => setEditing(null)} />}
        </>
    );
}
