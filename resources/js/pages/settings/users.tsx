import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Ellipsis, KeyRound, Mail, MailPlus, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { Avatar } from '@/components/avatar';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { HelpTip } from '@/components/help-tip';
import { NativeSelect } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { PasswordInput } from '@/components/password-input';
import { SettingsTabs } from '@/components/section-tabs';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { formatDateTime, plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { SharedProps, Tone } from '@/types';

/** app/Http/Controllers/Settings/UserController.php index() */
type User = { id: number; name: string; email: string; role: string; is_active: boolean; employee_id: number | null; employee: string | null; created_at: string | null;
    last_login_at: string | null; has_history: boolean; company_id: number | null };
type Option = { value: number; label: string };
type Tab = 'team' | 'staff' | 'off';

const roleName = (r: string) => (r === 'hr' ? 'HR' : r[0].toUpperCase() + r.slice(1));
const roleTone: Record<string, Tone> = { admin: 'info', hr: 'done', viewer: 'neutral', employee: 'muted' };
const PER_PAGE = 50;

const firstError = (e: Record<string, string>) => toast.error(Object.values(e)[0] ?? 'Not done.');

/** Everything about one login, in a dialog (the table only shows). */
function EditDialog({ user, roles, isMe, employees, companies, onClose }: {
    user: User; roles: Record<string, string>; isMe: boolean; employees: Option[]; companies: Option[]; onClose: () => void;
}) {
    const form = useForm({ name: user.name, email: user.email, role: user.role, is_active: user.is_active, password: '',
        employee_id: user.employee_id ? String(user.employee_id) : '', company_id: user.company_id ? String(user.company_id) : '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(`/settings/users/${user.id}`, { preserveScroll: true, onSuccess: onClose });
    };
    const employeeOptions = user.employee_id && !employees.some((o) => o.value === user.employee_id)
        ? [{ value: user.employee_id, label: user.employee ?? 'Linked' }, ...employees] : employees;
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{user.name}{isMe && ' (you)'}</DialogTitle>
                        <DialogDescription>{user.last_login_at ? `Last logged in ${formatDateTime(user.last_login_at)}.` : 'Has never logged in.'}</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Name" htmlFor="e-name" error={form.errors.name}>
                            <Input id="e-name" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        </Field>
                        <Field label="Email" htmlFor="e-email" error={form.errors.email}>
                            <Input id="e-email" type="email" required value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                        </Field>
                        <Field label="Role" htmlFor="e-role" error={form.errors.role} hint={isMe ? 'You can\'t change your own role.' : undefined}>
                            <NativeSelect id="e-role" value={form.data.role} disabled={isMe} onChange={(e) => form.setData('role', e.target.value)}
                                options={Object.keys(roles).map((r) => ({ value: r, label: roleName(r) }))} />
                        </Field>
                        {form.data.role !== 'employee' && companies.length > 1 && (
                            <Field label="Home company" htmlFor="e-company" error={form.errors.company_id} hint="Pages open on it; the 08:00 email covers only it.">
                                <NativeSelect id="e-company" value={form.data.company_id} placeholder="Both companies" options={companies}
                                    onChange={(e) => form.setData('company_id', e.target.value)} />
                            </Field>
                        )}
                        <Field label="Their employee record" htmlFor="e-emp" error={form.errors.employee_id} className="sm:col-span-2"
                            hint={form.data.role === 'employee' ? 'Employee logins see only this record, on My page.' : 'Optional. Linking gives them My page too.'}>
                            <NativeSelect id="e-emp" value={form.data.employee_id} placeholder={form.data.role === 'employee' ? 'Pick the employee' : 'Not linked'}
                                options={employeeOptions} onChange={(e) => form.setData('employee_id', e.target.value)} />
                        </Field>
                        <Field label="New password" htmlFor="e-pass" error={form.errors.password} hint="Leave empty to keep theirs. Or send a link from the ⋯ menu.">
                            <PasswordInput id="e-pass" autoComplete="new-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                        </Field>
                        <label className="flex items-center gap-2 self-center text-sm sm:pt-5">
                            <input type="checkbox" className="size-4 accent-cascade" checked={form.data.is_active} disabled={isMe} onChange={(e) => form.setData('is_active', e.target.checked)} />
                            Can log in
                        </label>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={!form.isDirty || form.processing}>Save</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function MoreMenu({ user, isMe, onEdit }: { user: User; isMe: boolean; onEdit: () => void }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button size="sm" type="button" variant="ghost" aria-label={`More for ${user.email}`} onClick={(e) => e.stopPropagation()}><Ellipsis /></Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64" onClick={(e) => e.stopPropagation()}>
                <DropdownMenuItem onSelect={onEdit}><Pencil /> Edit</DropdownMenuItem>
                <DropdownMenuItem disabled={!user.is_active}
                    onSelect={() => confirm(`Email ${user.email} a link to choose a password?`)
                        && router.post(`/settings/users/${user.id}/send-link`, {}, { preserveScroll: true, onError: firstError })}>
                    <KeyRound /> {user.is_active ? 'Email a password link' : 'Switched off: no link'}
                </DropdownMenuItem>
                {!isMe && !user.has_history && (
                    <DropdownMenuItem className="text-missing focus:text-missing"
                        onSelect={() => confirm(`Delete the login for ${user.email}? This can't be undone.`)
                            && router.delete(`/settings/users/${user.id}`, { preserveScroll: true, onError: firstError })}>
                        <Trash2 /> Delete this login
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export default function Users({ users, roles, employees, companies, staffLogins }: {
    users: User[]; roles: Record<string, string>; employees: Option[]; companies: Option[]; staffLogins: { ready: number; noEmail: number; emailTaken: number };
}) {
    const { auth } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<User | null>(null);
    const [tab, setTab] = useState<Tab>('team');
    const [q, setQ] = useState('');
    const [never, setNever] = useState(false);
    const [page, setPage] = useState(1);
    const form = useForm({ name: '', email: '', role: 'hr', password: '', employee_id: '', company_id: '', send_invite: true });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/settings/users', { preserveScroll: true, onSuccess: () => { form.reset(); setOpen(false); } });
    };
    const companyName = (id: number | null) => companies.find((c) => c.value === id)?.label;

    const inTab = (u: User, t: Tab) => (t === 'off' ? !u.is_active : u.is_active && (t === 'staff' ? u.role === 'employee' : u.role !== 'employee'));
    const counts = { team: users.filter((u) => inTab(u, 'team')).length, staff: users.filter((u) => inTab(u, 'staff')).length, off: users.filter((u) => inTab(u, 'off')).length };
    const shown = useMemo(() => {
        const term = q.trim().toLowerCase();
        return users.filter((u) => inTab(u, tab))
            .filter((u) => !term || [u.name, u.email, u.employee ?? ''].some((s) => s.toLowerCase().includes(term)))
            .filter((u) => !never || !u.last_login_at)
            // HR and admin first by role, then by name; staff by name
            .sort((a, b) => (tab === 'team' ? ['admin', 'hr', 'viewer'].indexOf(a.role) - ['admin', 'hr', 'viewer'].indexOf(b.role) : 0) || a.name.localeCompare(b.name));
    }, [users, tab, q, never]);
    const pages = Math.max(1, Math.ceil(shown.length / PER_PAGE));
    const rows = shown.slice((Math.min(page, pages) - 1) * PER_PAGE, Math.min(page, pages) * PER_PAGE);
    const neverCount = users.filter((u) => inTab(u, tab) && !u.last_login_at).length;
    const switchTab = (t: Tab) => { setTab(t); setPage(1); setNever(false); };

    return (
        <>
            <Head title="Users" />
            <PageHeader title="Users" tabs={<SettingsTabs />}
                actions={<>
                    <Button variant="outline" onClick={() => router.post('/settings/test-email', {}, { preserveScroll: true })}><Mail /> Send a test email</Button>
                    <Button onClick={() => setOpen(true)}><Plus /> Add login</Button>
                </>} />

            <div className="grid gap-4 px-4 py-4 md:px-6">
                {staffLogins.ready > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/40 px-4 py-2.5 text-sm">
                        <p>
                            <span className="font-semibold">{plural(staffLogins.ready, 'current employee has', 'current employees have')} a work email and no login yet.</span>
                            {staffLogins.noEmail > 0 && <span className="text-muted-foreground"> {plural(staffLogins.noEmail, 'person has', 'people have')} no work email on file.</span>}
                            {staffLogins.emailTaken > 0 && <span className="text-muted-foreground"> {staffLogins.emailTaken} share an email with an existing login.</span>}
                        </p>
                        <Button size="sm" onClick={() => confirm(`Create ${plural(staffLogins.ready, 'login')} and email each person a link to choose their password?`)
                            && router.post('/settings/users/staff-logins', { send: true }, { preserveScroll: true })}>
                            <MailPlus /> Create {plural(staffLogins.ready, 'staff login')}
                        </Button>
                    </div>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    <div className="inline-flex rounded-md border bg-background p-0.5" role="group" aria-label="Which logins">
                        {([['team', 'HR and admin'], ['staff', 'Staff'], ['off', 'Switched off']] as const).map(([key, label]) => (
                            <button key={key} type="button" aria-pressed={tab === key} onClick={() => switchTab(key)}
                                className={cn('rounded px-3 py-1.5 text-sm font-semibold', tab === key ? 'bg-primary text-primary-foreground' : 'hover:bg-accent')}>
                                {label} <span className="tabular-nums opacity-70">{counts[key]}</span>
                            </button>
                        ))}
                    </div>
                    <HelpTip label="What each role can do">
                        <ul className="grid gap-1.5">
                            {Object.entries(roles).map(([k, v]) => <li key={k}>{v}</li>)}
                            <li>Home company: pages open on that company, and the 08:00 email covers only its people. They can still pick All.</li>
                        </ul>
                    </HelpTip>
                    <div className="relative ml-auto w-full sm:w-64">
                        <Search className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                        <Input className="pl-8" placeholder="Search name or email" aria-label="Search logins" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
                    </div>
                    {neverCount > 0 && (
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" className="size-4 accent-cascade" checked={never} onChange={(e) => { setNever(e.target.checked); setPage(1); }} />
                            Never logged in ({neverCount})
                        </label>
                    )}
                </div>

                {rows.length === 0 ? (
                    <EmptyState title={q || never ? 'No logins match.' : tab === 'off' ? 'No switched-off logins.' : 'No logins here yet.'} />
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[46rem] text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-semibold">Login</th>
                                    <th className="px-3 py-2 font-semibold">Role</th>
                                    <th className="px-3 py-2 font-semibold">Employee record</th>
                                    <th className="px-3 py-2 font-semibold">Last login</th>
                                    <th className="px-3 py-2"><span className="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {rows.map((u) => {
                                    const isMe = u.id === auth.user?.id;
                                    return (
                                        <tr key={u.id} className="cursor-pointer hover:bg-accent/40" onClick={() => setEditing(u)}>
                                            <td className="px-3 py-2.5">
                                                <div className="flex items-center gap-3">
                                                    <Avatar name={u.name} className="size-8" />
                                                    <div className="min-w-0">
                                                        <p className="truncate font-semibold">{u.name}{isMe && <span className="ml-2 rounded-full bg-muted px-2 py-0.5 text-xs font-normal">You</span>}</p>
                                                        <p className="truncate text-xs text-muted-foreground">{u.email}</p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <StatusPill value={roleName(u.role)} tone={roleTone[u.role] ?? 'neutral'} />
                                                {u.role !== 'employee' && <span className="mt-1 block text-xs text-muted-foreground">{companyName(u.company_id) ?? 'Both companies'}</span>}
                                            </td>
                                            <td className="px-3 py-2.5">{u.employee ?? <span className="text-muted-foreground">Not linked</span>}</td>
                                            <td className="px-3 py-2.5">
                                                {!u.is_active ? <StatusPill value="Switched off" tone="neutral" />
                                                    : u.last_login_at ? <span className="text-muted-foreground">{formatDateTime(u.last_login_at)}</span>
                                                        : <span className="font-semibold text-pending">Never logged in</span>}
                                            </td>
                                            <td className="px-3 py-2.5 text-right" onClick={(e) => e.stopPropagation()}>
                                                <Button size="sm" variant="ghost" aria-label={`Edit ${u.email}`} onClick={() => setEditing(u)}><Pencil /></Button>
                                                <MoreMenu user={u} isMe={isMe} onEdit={() => setEditing(u)} />
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
                {pages > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground tabular-nums">
                            {(Math.min(page, pages) - 1) * PER_PAGE + 1} to {Math.min(Math.min(page, pages) * PER_PAGE, shown.length)} of {shown.length}
                        </span>
                        <div className="flex gap-2">
                            <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</Button>
                            <Button size="sm" variant="outline" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>Next</Button>
                        </div>
                    </div>
                )}
            </div>

            {editing && <EditDialog user={editing} roles={roles} isMe={editing.id === auth.user?.id} employees={employees} companies={companies} onClose={() => setEditing(null)} />}

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Add login</DialogTitle>
                        <DialogDescription>
                            {form.data.send_invite
                                ? 'They get an email with a link to choose their own password. The link works for 3 days.'
                                : 'Share the password with the person directly. They can change it under Your account.'}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="grid gap-4">
                        <Field label="Name" htmlFor="u-name" error={form.errors.name}><Input id="u-name" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field>
                        <Field label="Email" htmlFor="u-email" error={form.errors.email}><Input id="u-email" type="email" required value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} /></Field>
                        <Field label="Role" htmlFor="u-role" error={form.errors.role}>
                            <NativeSelect id="u-role" value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}
                                options={Object.entries(roles).map(([k, v]) => ({ value: k, label: v }))} />
                        </Field>
                        {form.data.role !== 'employee' && companies.length > 1 && (
                            <Field label="Home company" htmlFor="u-company" error={form.errors.company_id}
                                hint="Pages open on this company and the 08:00 email covers only its people. They can still pick All.">
                                <NativeSelect id="u-company" value={form.data.company_id} placeholder="Both companies" options={companies}
                                    onChange={(e) => form.setData('company_id', e.target.value)} />
                            </Field>
                        )}
                        <Field label="Their employee record" htmlFor="u-emp" error={form.errors.employee_id}
                            hint={form.data.role === 'employee' ? 'Employee logins see only this record, on My page.' : 'Optional. Linking gives them My page too.'}>
                            <NativeSelect id="u-emp" value={form.data.employee_id} placeholder={form.data.role === 'employee' ? 'Pick the employee' : 'Not linked'}
                                options={employees} onChange={(e) => form.setData('employee_id', e.target.value)} />
                        </Field>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" className="size-4 accent-cascade" checked={form.data.send_invite} onChange={(e) => form.setData('send_invite', e.target.checked)} />
                            Send them a welcome email
                        </label>
                        {!form.data.send_invite && (
                            <Field label="Password" htmlFor="u-pass" error={form.errors.password} hint="At least 8 characters.">
                                <Input id="u-pass" type="text" required value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} autoComplete="off" />
                            </Field>
                        )}
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={form.processing}>Add login</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
