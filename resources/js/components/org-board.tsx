import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { Avatar } from '@/components/avatar';
import { plural } from '@/lib/format';
import { cn } from '@/lib/utils';

type Person = { id: number; name: string; code: string; role: string | null; leaving: boolean; photo?: string | null };
type Department = { id: number | null; name: string; count: number; head?: { id: number; name: string } | null; teams: { name: string | null; people: Person[] }[] };

const SHOWN = 6;

function TeamCard({ name, people, needle }: { name: string | null; people: Person[]; needle: string }) {
    const hasHit = !!needle && people.some((p) => p.name.toLowerCase().includes(needle));
    const [all, setAll] = useState(false);
    const shown = all || hasHit ? people : people.slice(0, SHOWN);
    return (
        <div className="rounded-lg border bg-background p-2.5 shadow-xs">
            <p className="mb-2 flex items-center justify-between gap-2 text-xs font-semibold">
                <span className="truncate">{name ?? 'No team'}</span>
                <span className="shrink-0 rounded-full bg-muted px-1.5 text-muted-foreground tabular-nums">{people.length}</span>
            </p>
            <ul className="grid gap-1">
                {shown.map((p) => {
                    const hit = !!needle && p.name.toLowerCase().includes(needle);
                    return (
                        <li key={p.id}>
                            <Link href={`/employees/${p.id}`} title={[p.code, p.role].filter(Boolean).join(', ')}
                                className={cn('flex items-center gap-2 rounded-md px-1.5 py-1 text-xs hover:bg-accent', hit && 'bg-accent font-semibold ring-1 ring-cascade/40')}>
                                <Avatar name={p.name} photo={p.photo} className="size-6 text-[10px]" />
                                <span className="min-w-0 flex-1 truncate">{p.name}</span>
                                {p.leaving && <span className="text-[10px] text-pending">leaving</span>}
                            </Link>
                        </li>
                    );
                })}
            </ul>
            {people.length > SHOWN && !hasHit && (
                <button type="button" onClick={() => setAll((a) => !a)} className="mt-1 px-1.5 text-xs font-semibold text-cascade hover:underline">
                    {all ? 'Show fewer' : `${people.length - SHOWN} more`}
                </button>
            )}
        </div>
    );
}

/** Kanban-style board: one column per department, a card per team with its people. Scrolls sideways. */
export function OrgBoard({ companies, needle }: { companies: { name: string; count: number; departments: Department[] }[]; needle: string }) {
    return (
        <div className="grid gap-8">
            {companies.map((c) => (
                <section key={c.name}>
                    <h2 className="mb-2 font-bold">{c.name} <span className="text-sm font-normal text-muted-foreground">{plural(c.count, 'person', 'people')}</span></h2>
                    <div className="flex gap-3 overflow-x-auto pb-3">
                        {c.departments.map((d) => (
                            <div key={`${c.name}-${d.name}`} className="flex w-64 shrink-0 flex-col gap-2 rounded-lg bg-muted/60 p-2.5">
                                <p className="flex items-center justify-between gap-2 px-0.5 text-sm font-bold">
                                    {d.id ? <Link href={`/employees?department=${d.id}`} className="truncate hover:text-cascade hover:underline">{d.name}</Link> : <span className="truncate">{d.name}</span>}
                                    <span className="shrink-0 text-xs font-semibold text-muted-foreground tabular-nums">{d.count}</span>
                                </p>
                                {d.head && (
                                    <p className="-mt-1 px-0.5 text-xs text-muted-foreground">
                                        Head: <Link href={`/employees/${d.head.id}`} className="font-semibold text-foreground hover:text-cascade hover:underline">{d.head.name}</Link>
                                    </p>
                                )}
                                {d.teams.map((t) => <TeamCard key={t.name ?? '-'} name={t.name} people={t.people} needle={needle} />)}
                            </div>
                        ))}
                    </div>
                </section>
            ))}
        </div>
    );
}
