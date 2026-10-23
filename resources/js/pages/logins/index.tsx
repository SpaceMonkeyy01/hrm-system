import { Head, Link, router } from '@inertiajs/react';
import { KeyRound, MailPlus, Power, Search } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Avatar } from '@/components/avatar';
import { EmptyState } from '@/components/empty-state';
import { HelpTip } from '@/components/help-tip';
import { sendLoginLink, setLoginActive } from '@/components/login-card';
import { PageHeader } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime, plural } from '@/lib/format';
import { cn } from '@/lib/utils';

/** app/Http/Controllers/StaffLoginsController.php index() */
type Login = { id: number; name: string; email: string; is_active: boolean; last_login_at: string | null; created_at: string | null;
    employee: { id: number; name: string; code: string; status: string } | null };
type Without = { id: number; name: string; code: string; email: string | null; problem: 'no_email' | 'email_taken' | null };
type Tab = 'with' | 'without';

const PER_PAGE = 50;
const firstError = (e: Record<string, string>) => toast.error(Object.values(e)[0] ?? 'Not done.');

/** Staff logins (My page only): HR creates them and sends the password link. HR, viewer and admin logins: Settings > Users. */
export default function StaffLogins({ logins, without }: { logins: Login[]; without: Without[] }) {
    const [tab, setTab] = useState<Tab>(() => (logins.length === 0 && without.length > 0 ? 'without' : 'with'));
    const [q, setQ] = useState('');
    const [never, setNever] = useState(false);
    const [page, setPage] = useState(1);
    const ready = without.filter((w) => !w.problem);
    const noEmail = without.filter((w) => w.problem === 'no_email').length;
    const taken = without.filter((w) => w.problem === 'email_taken').length;
    const neverCount = logins.filter((l) => l.is_active && !l.last_login_at).length;

    const term = q.trim().toLowerCase();
    const matches = (...s: (string | null | undefined)[]) => !term || s.some((x) => x?.toLowerCase().includes(term));
    const shownLogins = logins.filter((l) => matches(l.name, l.email, l.employee?.code)).filter((l) => !never || (l.is_active && !l.last_login_at));
    const shownWithout = without.filter((w) => matches(w.name, w.email, w.code));
    const total = tab === 'with' ? shownLogins.length : shownWithout.length;
    const pages = Math.max(1, Math.ceil(total / PER_PAGE));
    const from = (Math.min(page, pages) - 1) * PER_PAGE;
    const switchTab = (t: Tab) => { setTab(t); setPage(1); setNever(false); };

    const createAll = () => confirm(`Create ${plural(ready.length, 'login')} and email each person a link to choose their password?`)
        && router.post('/logins', { send: true }, { preserveScroll: true, onError: firstError });
    const createOne = (w: Without) => confirm(`Create a login for ${w.name} and email ${w.email} the link?`)
        && router.post(`/employees/${w.id}/login`, {}, { preserveScroll: true, onError: firstError });

    return (
        <>
            <Head title="Staff logins" />
            <PageHeader title="Staff logins" description="Logins for staff to see their own record on My page. Each person chooses their own password from an emailed link."
                actions={ready.length > 0 && <Button onClick={createAll}><MailPlus /> Create {plural(ready.length, 'login')}</Button>} />

            <div className="grid gap-4 px-4 py-4 md:px-6">
                {ready.length > 0 && (
                    <p className="rounded-lg border bg-muted/40 px-4 py-2.5 text-sm">
                        <span className="font-semibold">{plural(ready.length, 'current employee has', 'current employees have')} a work email and no login yet.</span>
                        {noEmail > 0 && <span className="text-muted-foreground"> {plural(noEmail, 'person has', 'people have')} no work email on file.</span>}
                        {taken > 0 && <span className="text-muted-foreground"> {taken} share an email with another login.</span>}
                    </p>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    <div className="inline-flex rounded-md border bg-background p-0.5" role="group" aria-label="Which people">
                        {([['with', 'Have a login', logins.length], ['without', 'No login yet', without.length]] as const).map(([key, label, n]) => (
                            <button key={key} type="button" aria-pressed={tab === key} onClick={() => switchTab(key)}
                                className={cn('rounded px-3 py-1.5 text-sm font-semibold', tab === key ? 'bg-primary text-primary-foreground' : 'hover:bg-accent')}>
                                {label} <span className="tabular-nums opacity-70">{n}</span>
                            </button>
                        ))}
                    </div>
                    <HelpTip label="About staff logins">
                        <ul className="grid gap-1.5">
                            <li>A staff login sees only that person's own record: attendance, leave, requests, letters.</li>
                            <li>The emailed link works for 3 days. If it runs out or goes to spam, send it again.</li>
                            <li>When someone leaves, their login is switched off automatically.</li>
                            <li>HR, viewer and admin logins are managed by an admin in Settings.</li>
                        </ul>
                    </HelpTip>
                    <div className="relative ml-auto w-full sm:w-64">
                        <Search className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                        <Input className="pl-8" placeholder="Search name, code or email" aria-label="Search people" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
                    </div>
                    {tab === 'with' && neverCount > 0 && (
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" className="size-4 accent-cascade" checked={never} onChange={(e) => { setNever(e.target.checked); setPage(1); }} />
                            Never logged in ({neverCount})
                        </label>
                    )}
                </div>

                {total === 0 ? (
                    <EmptyState title={term || never ? 'Nobody matches.' : tab === 'with' ? 'No staff logins yet.' : 'Every current employee has a login.'} />
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[44rem] text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-semibold">Person</th>
                                    <th className="px-3 py-2 font-semibold">{tab === 'with' ? 'Last login' : 'Work email'}</th>
                                    <th className="px-3 py-2"><span className="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {tab === 'with' ? shownLogins.slice(from, from + PER_PAGE).map((l) => (
                                    <tr key={l.id}>
                                        <td className="px-3 py-2.5"><Person name={l.employee?.name ?? l.name} code={l.employee?.code} id={l.employee?.id} sub={l.email} /></td>
                                        <td className="px-3 py-2.5">
                                            {!l.is_active ? <StatusPill value="Switched off" tone="neutral" />
                                                : l.last_login_at ? <span className="text-muted-foreground">{formatDateTime(l.last_login_at)}</span>
                                                    : <span className="font-semibold text-pending">Never logged in</span>}
                                        </td>
                                        <td className="px-3 py-2.5 text-right whitespace-nowrap">
                                            {l.is_active && <Button size="sm" variant="outline" onClick={() => sendLoginLink(l)}><KeyRound /> Send link</Button>}
                                            <Button size="sm" variant="ghost" onClick={() => setLoginActive(l, !l.is_active)}><Power /> {l.is_active ? 'Switch off' : 'Switch on'}</Button>
                                        </td>
                                    </tr>
                                )) : shownWithout.slice(from, from + PER_PAGE).map((w) => (
                                    <tr key={w.id}>
                                        <td className="px-3 py-2.5"><Person name={w.name} code={w.code} id={w.id} /></td>
                                        <td className="px-3 py-2.5">
                                            {w.problem === 'no_email' ? <Link href={`/employees/${w.id}/edit#email`} className="font-semibold text-cascade hover:underline">+ Add work email</Link>
                                                : <span className={cn(w.problem && 'text-muted-foreground')}>{w.email}{w.problem === 'email_taken' && ' (used by another login: ask an admin)'}</span>}
                                        </td>
                                        <td className="px-3 py-2.5 text-right">
                                            {!w.problem && <Button size="sm" variant="outline" onClick={() => createOne(w)}><MailPlus /> Create login</Button>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {pages > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground tabular-nums">{from + 1} to {Math.min(from + PER_PAGE, total)} of {total}</span>
                        <div className="flex gap-2">
                            <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</Button>
                            <Button size="sm" variant="outline" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>Next</Button>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

function Person({ name, code, id, sub }: { name: string; code?: string; id?: number; sub?: string }) {
    return (
        <div className="flex items-center gap-3">
            <Avatar name={name} className="size-8" />
            <div className="min-w-0">
                <p className="truncate font-semibold">
                    {id ? <Link href={`/employees/${id}`} className="hover:underline">{name}</Link> : name}
                    {code && <span className="ml-2 font-normal text-muted-foreground tabular-nums">{code}</span>}
                </p>
                {sub && <p className="truncate text-xs text-muted-foreground">{sub}</p>}
            </div>
        </div>
    );
}
