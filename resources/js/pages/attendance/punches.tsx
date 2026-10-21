import { Head, Link, router } from '@inertiajs/react';
import { Building2, Download, House, ListOrdered, MapPin } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { EmptyState } from '@/components/empty-state';
import { NativeSelect } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { AttendanceTabs } from '@/components/section-tabs';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';
import type { Paginated } from '@/types';

/** app/Http/Controllers/PunchLogController.php row() */
type Row = {
    id: number; at: string; date: string; time: string; work_date: string;
    employee: { id: number; code: string; name: string; department: string | null; deleted: boolean } | null;
    source: 'machine' | 'hrmy' | 'app'; source_label: string; device_user_id: string; device_name: string | null; method: string | null;
    place: 'office' | 'remote' | null; ip: string | null; location: { lat: number; lng: number; accuracy: number | null } | null; device: string | null; via: string;
};
type Filters = { from: string; to: string; source: string | null; q: string | null; place: string | null; linked: string | null };
type Props = { filters: Filters; punches: Paginated<Row>; counts: Record<string, number>; sources: Record<string, string>; today: string };

const sourceTone: Record<Row['source'], string> = {
    machine: 'bg-muted text-foreground', hrmy: 'bg-info/10 text-info', app: 'bg-done/10 text-done',
};

/** Query string without empty filters. */
const params = (f: Filters) => Object.fromEntries(Object.entries(f).filter(([, v]) => v !== null && v !== ''));

export default function PunchLog({ filters: f, punches, counts, sources, today }: Props) {
    const [q, setQ] = useState(f.q ?? '');
    const go = (next: Partial<Filters>) => router.get('/attendance/punches', params({ ...f, ...next }), { preserveState: true, preserveScroll: true, replace: true });
    const search = (e: FormEvent) => { e.preventDefault(); go({ q: q.trim() || null }); };
    const total = Object.values(counts).reduce((a, b) => a + b, 0);
    const csv = `/attendance/punches/csv?${new URLSearchParams(params(f) as Record<string, string>).toString()}`;

    return (
        <>
            <Head title="Punch log" />
            <PageHeader
                title="Punch log"
                tabs={<AttendanceTabs />}
                actions={<Button variant="outline" asChild><a href={csv}><Download /> Download CSV</a></Button>}
            />
            <div className="grid grid-cols-1 gap-4 px-4 py-6 md:px-6">
                <div className="flex flex-wrap items-end gap-2">
                    <div className="flex flex-wrap gap-2" role="group" aria-label="Source">
                        <Button size="sm" variant={!f.source ? 'default' : 'outline'} onClick={() => go({ source: null })}>All {total.toLocaleString()}</Button>
                        {Object.entries(sources).map(([key, label]) => (
                            <Button key={key} size="sm" variant={f.source === key ? 'default' : 'outline'} onClick={() => go({ source: key })}>
                                {label} {(counts[key] ?? 0).toLocaleString()}
                            </Button>
                        ))}
                    </div>
                </div>
                <div className="flex flex-wrap items-end gap-2">
                    <Input type="date" className="w-40" value={f.from} max={today} aria-label="From" onChange={(e) => e.target.value && go({ from: e.target.value })} />
                    <Input type="date" className="w-40" value={f.to} max={today} aria-label="To" onChange={(e) => e.target.value && go({ to: e.target.value })} />
                    <form onSubmit={search} className="flex gap-2">
                        <Input type="search" className="w-56" value={q} placeholder="Name, code or device ID" aria-label="Person" onChange={(e) => setQ(e.target.value)} />
                        <Button type="submit" size="sm" variant="outline">Search</Button>
                    </form>
                    <NativeSelect className="w-44" aria-label="Counted as" value={f.place ?? ''} onChange={(e) => go({ place: e.target.value || null })}
                        placeholder="Office and remote" options={[{ value: 'office', label: 'Office only' }, { value: 'remote', label: 'Remote only' }]} />
                    <NativeSelect className="w-44" aria-label="Linked" value={f.linked ?? ''} onChange={(e) => go({ linked: e.target.value || null })}
                        placeholder="Linked or not" options={[{ value: 'yes', label: 'Linked to a person' }, { value: 'no', label: 'Not linked yet' }]} />
                </div>

                {punches.data.length === 0 ? <EmptyState icon={ListOrdered} title="No punches match." /> : (<>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[60rem] text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-semibold">When</th><th className="px-3 py-2 font-semibold">Person</th>
                                    <th className="px-3 py-2 font-semibold">Source</th><th className="px-3 py-2 font-semibold">Type</th>
                                    <th className="px-3 py-2 font-semibold">Counted as</th><th className="px-3 py-2 font-semibold">Address</th>
                                    <th className="px-3 py-2 font-semibold">Brought in by</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {punches.data.map((r) => (
                                    <tr key={r.id}>
                                        <td className="px-3 py-2 whitespace-nowrap tabular-nums">
                                            {formatDate(r.date)} <span className="font-semibold">{r.time}</span>
                                            {r.work_date !== r.date && <span className="block text-xs text-muted-foreground">Counts on {formatDate(r.work_date)}</span>}
                                        </td>
                                        <td className="px-3 py-2">
                                            {r.employee
                                                ? <Link href={`/employees/${r.employee.id}#attendance`} className="font-semibold hover:underline">{r.employee.name}</Link>
                                                : <span className="text-muted-foreground">Not linked</span>}
                                            <span className="block text-xs text-muted-foreground">
                                                {r.employee ? [r.employee.code, r.employee.department, r.employee.deleted ? 'deleted record' : null].filter(Boolean).join(', ') : `${r.device_name ?? 'No name'}, ID ${r.device_user_id}`}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className={`rounded px-1.5 py-0.5 text-xs font-semibold ${sourceTone[r.source]}`}>{r.source_label}</span>
                                            {r.source !== 'app' && r.employee && <span className="block text-xs text-muted-foreground">ID {r.device_user_id}</span>}
                                        </td>
                                        <td className="px-3 py-2">{r.method ?? '-'}</td>
                                        <td className="px-3 py-2">
                                            {r.place === 'office' && <span className="inline-flex items-center gap-1"><Building2 className="size-3.5 text-muted-foreground" /> Office</span>}
                                            {r.place === 'remote' && <span className="inline-flex items-center gap-1"><House className="size-3.5 text-info" /> Remote</span>}
                                        </td>
                                        <td className="px-3 py-2">
                                            {r.ip ? <span className="font-mono text-xs">{r.ip}</span> : <span className="text-muted-foreground">-</span>}
                                            {r.location && (
                                                <a href={`https://www.google.com/maps?q=${r.location.lat},${r.location.lng}`} target="_blank" rel="noreferrer"
                                                    className="flex items-center gap-1 text-xs text-info hover:underline"><MapPin className="size-3" /> Map{r.location.accuracy ? ` (±${r.location.accuracy} m)` : ''}</a>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-xs text-muted-foreground">{r.via}{r.device && <span className="block">{r.device}</span>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Pagination page={punches} />
                </>)}
            </div>
        </>
    );
}
