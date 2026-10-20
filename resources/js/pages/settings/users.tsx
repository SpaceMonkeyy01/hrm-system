import { Head, router, useForm, usePage } from '@inertiajs/react';
import { KeyRound, Mail, MailPlus, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Field } from '@/components/field';
import { NativeSelect } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/password-input';
import { toast } from 'sonner';
import { formatDateTime, plural } from '@/lib/format';
import type { SharedProps } from '@/types';

type User = { id: number; name: string; email: string; role: string; is_active: boolean; employee_id: number | null; employee: string | null; created_at: string | null;
    last_login_at: string | null; has_history: boolean };
type Option = { value: number; label: string };

const roleName = (r: string) => (r === 'hr' ? 'HR' : r[0].toUpperCase() + r.slice(1));

const firstError = (e: Record<string, string>) => toast.error(Object.values(e)[0] ?? 'Not done.');

function UserRow({ user, roles, isMe, employees }: { user: User; roles: Record<string, string>; isMe: boolean; employees: Option[] }) {
    const form = useForm({ name: user.name, email: user.email, role: user.role, is_active: user.is_active, password: '', employee_id: user.employee_id ? String(user.employee_id) : '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(`/settings/users/${user.id}`, { preserveScroll: true, onSuccess: () => { form.setDefaults({ ...form.data, password: '' }); form.reset('password'); } });
    };
    return (
        <tr className="align-top">
            <td className="py-2 pr-3">
                <Input aria-label="Name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="h-8" />
                <Input aria-label="Email" type="email" required value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} className="mt-1 h-8 text-xs" />
                {form.errors.email && <p className="mt-1 text-xs text-missing">{form.errors.email}</p>}
                {isMe && <span className="mt-1 block text-xs text-muted-foreground">You</span>}
            </td>
            <td className="py-2 pr-3">
                <NativeSelect aria-label="Role" value={form.data.role} disabled={isMe} onChange={(e) => form.setData('role', e.target.value)}
                    options={Object.keys(roles).map((r) => ({ value: r, label: roleName(r) }))} />
                {form.errors.role && <p className="mt-1 text-xs text-missing">{form.errors.role}</p>}
            </td>
            <td className="py-2 pr-3">
                <NativeSelect aria-label="Their employee record" value={form.data.employee_id} placeholder={form.data.role === 'employee' ? 'Pick the employee' : 'Not linked'}
                    options={user.employee_id && !employees.some((o) => o.value === user.employee_id) ? [{ value: user.employee_id, label: user.employee ?? 'Linked' }, ...employees] : employees}
                    onChange={(e) => form.setData('employee_id', e.target.value)} />
                {form.errors.employee_id && <p className="mt-1 text-xs text-missing">{form.errors.employee_id}</p>}
            </td>
            <td className="py-2 pr-3 pt-3.5">
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" className="size-4 accent-cascade" checked={form.data.is_active} disabled={isMe} onChange={(e) => form.setData('is_active', e.target.checked)} />
                    Can log in
                </label>
                <span className="mt-1.5 block text-xs text-muted-foreground">
                    {user.last_login_at ? `Last in ${formatDateTime(user.last_login_at)}` : 'Never logged in'}
                </span>
            </td>
            <td className="py-2 pr-3">
                <PasswordInput aria-label="New password" autoComplete="new-password" placeholder="Leave empty to keep" value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)} className="h-8" />
                {form.errors.password && <p className="mt-1 text-xs text-missing">{form.errors.password}</p>}
            </td>
            <td className="py-2 text-right">
                <form onSubmit={submit} className="flex justify-end gap-1">
                    <Button size="sm" type="submit" disabled={!form.isDirty || form.processing}>Save</Button>
                    <Button size="sm" type="button" variant="ghost" disabled={!user.is_active} aria-label={`Email ${user.email} a link to choose a password`}
                        title={user.is_active ? 'Email a link to choose a password' : 'Switched off'}
                        onClick={() => confirm(`Email ${user.email} a link to choose a password?`)
                            && router.post(`/settings/users/${user.id}/send-link`, {}, { preserveScroll: true, onError: firstError })}>
                        <KeyRound /> Send link
                    </Button>
                    {!isMe && !user.has_history && (
                        <Button size="sm" type="button" variant="ghost" aria-label={`Delete ${user.email}`} title="Delete: this login hasn't done anything yet"
                            onClick={() => confirm(`Delete the login for ${user.email}? This can't be undone.`)
                                && router.delete(`/settings/users/${user.id}`, { preserveScroll: true, onError: firstError })}>
                            <Trash2 />
                        </Button>
                    )}
                </form>
            </td>
        </tr>
    );
}

export default function Users({ users, roles, employees, staffLogins }: { users: User[]; roles: Record<string, string>; employees: Option[]; staffLogins: { ready: number; noEmail: number; emailTaken: number } }) {
    const { auth } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);
    const form = useForm({ name: '', email: '', role: 'hr', password: '', employee_id: '', send_invite: true });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/settings/users', { preserveScroll: true, onSuccess: () => { form.reset(); setOpen(false); } });
    };

    return (
        <>
            <Head title="Users" />
            <PageHeader title="Users" description="Who can log in and what they can do. There is no public sign-up: add each person here."
                actions={<>
                    <Button variant="outline" onClick={() => router.post('/settings/test-email', {}, { preserveScroll: true })}><Mail /> Send a test email</Button>
                    <Button onClick={() => setOpen(true)}><Plus /> Add login</Button>
                </>}>
                <ul className="grid gap-1 text-sm text-muted-foreground sm:grid-cols-2 xl:grid-cols-4">
                    {Object.entries(roles).map(([k, v]) => <li key={k}>{v}</li>)}
                </ul>
            </PageHeader>
            <div className="mx-4 mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border px-4 py-3 md:mx-6">
                <p className="max-w-3xl text-sm">
                    <span className="font-semibold">Staff logins for My page. </span>
                    {staffLogins.ready
                        ? `${plural(staffLogins.ready, 'current employee has', 'current employees have')} a work email and no login yet.`
                        : staffLogins.noEmail ? '' : 'Everyone with a work email already has a login.'}
                    {staffLogins.noEmail > 0 && ` ${plural(staffLogins.noEmail, 'person has', 'people have')} no work email on file: add it on their profile or with Employees, Fill in details.`}
                    {staffLogins.emailTaken > 0 && ` ${staffLogins.emailTaken} share an email with an existing login.`}
                    {' '}Each new login gets an email with a link to choose their own password.
                </p>
                <Button disabled={!staffLogins.ready}
                    onClick={() => confirm(`Create ${plural(staffLogins.ready, 'login')} and email each person a link to choose their password?`)
                        && router.post('/settings/users/staff-logins', { send: true }, { preserveScroll: true })}>
                    <MailPlus /> Create {plural(staffLogins.ready, 'staff login')}
                </Button>
            </div>
            <div className="overflow-x-auto px-4 py-4 md:px-6">
                <table className="w-full min-w-[1120px] text-sm">
                    <thead>
                        <tr className="border-b text-left text-xs text-muted-foreground">
                            <th className="min-w-56 py-2.5 pr-3 font-semibold">Name and email</th>
                            <th className="w-36 py-2.5 pr-3 font-semibold">Role</th>
                            <th className="w-52 py-2.5 pr-3 font-semibold">Their employee record</th>
                            <th className="w-36 py-2.5 pr-3 font-semibold">Access</th>
                            <th className="w-44 py-2.5 pr-3 font-semibold">Set a password</th>
                            <th className="w-56 py-2.5" />
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {users.map((u) => <UserRow key={u.id} user={u} roles={roles} employees={employees} isMe={u.id === auth.user?.id} />)}
                    </tbody>
                </table>
            </div>

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
