import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Field } from '@/components/field';
import { NativeSelect } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import type { SharedProps } from '@/types';

type User = { id: number; name: string; email: string; role: string; is_active: boolean; employee_id: number | null; employee: string | null; created_at: string | null };
type Option = { value: number; label: string };

const roleName = (r: string) => (r === 'hr' ? 'HR' : r[0].toUpperCase() + r.slice(1));

function UserRow({ user, roles, isMe, employees }: { user: User; roles: Record<string, string>; isMe: boolean; employees: Option[] }) {
    const form = useForm({ name: user.name, role: user.role, is_active: user.is_active, password: '', employee_id: user.employee_id ? String(user.employee_id) : '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(`/settings/users/${user.id}`, { preserveScroll: true, onSuccess: () => { form.setDefaults({ ...form.data, password: '' }); form.reset('password'); } });
    };
    return (
        <tr className="align-top">
            <td className="py-2 pr-3">
                <Input aria-label="Name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="h-8" />
                <span className="mt-1 block text-xs text-muted-foreground">{user.email}{isMe && ' (you)'}</span>
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
            </td>
            <td className="py-2 pr-3">
                <Input aria-label="New password" type="password" autoComplete="new-password" placeholder="Leave empty to keep" value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)} className="h-8" />
                {form.errors.password && <p className="mt-1 text-xs text-missing">{form.errors.password}</p>}
            </td>
            <td className="py-2 text-right">
                <form onSubmit={submit}><Button size="sm" type="submit" disabled={!form.isDirty || form.processing}>Save</Button></form>
            </td>
        </tr>
    );
}

export default function Users({ users, roles, employees }: { users: User[]; roles: Record<string, string>; employees: Option[] }) {
    const { auth } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);
    const form = useForm({ name: '', email: '', role: 'hr', password: '', employee_id: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/settings/users', { preserveScroll: true, onSuccess: () => { form.reset(); setOpen(false); } });
    };

    return (
        <>
            <Head title="Users" />
            <PageHeader title="Users" description="Who can log in and what they can do. There is no public sign-up: add each person here."
                actions={<Button onClick={() => setOpen(true)}><Plus /> Add login</Button>}>
                <ul className="grid gap-1 text-sm text-muted-foreground sm:grid-cols-2 xl:grid-cols-4">
                    {Object.entries(roles).map(([k, v]) => <li key={k}>{v}</li>)}
                </ul>
            </PageHeader>
            <div className="overflow-x-auto px-4 py-4 md:px-6">
                <table className="w-full min-w-[1080px] text-sm">
                    <thead>
                        <tr className="border-b text-left text-xs text-muted-foreground">
                            <th className="py-2.5 pr-3 font-semibold">Name and email</th>
                            <th className="w-40 py-2.5 pr-3 font-semibold">Role</th>
                            <th className="w-64 py-2.5 pr-3 font-semibold">Their employee record</th>
                            <th className="w-36 py-2.5 pr-3 font-semibold">Access</th>
                            <th className="w-56 py-2.5 pr-3 font-semibold">Reset password</th>
                            <th className="w-20 py-2.5" />
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
                        <DialogDescription>Share the password with the person directly. They can change it under Your account.</DialogDescription>
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
                        <Field label="Password" htmlFor="u-pass" error={form.errors.password} hint="At least 8 characters.">
                            <Input id="u-pass" type="text" required value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} autoComplete="off" />
                        </Field>
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
