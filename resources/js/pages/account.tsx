import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field } from '@/components/field';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/password-input';

export default function Account({ user }: { user: { name: string; email: string; role: string } }) {
    const form = useForm({ name: user.name, current_password: '', password: '', password_confirmation: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put('/account', { preserveScroll: true, onSuccess: () => form.reset('current_password', 'password', 'password_confirmation') });
    };
    return (
        <>
            <Head title="Your account" />
            <PageHeader title="Your account" description={`Logged in as ${user.email}. Ask an admin to change your email or role.`} />
            <form onSubmit={submit} className="grid max-w-md gap-4 px-4 py-6 md:px-6">
                <Field label="Name" htmlFor="name" error={form.errors.name}>
                    <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                </Field>
                <h2 className="mt-4 font-bold">Change password</h2>
                <Field label="Current password" htmlFor="current_password" error={form.errors.current_password}>
                    <PasswordInput id="current_password" autoComplete="current-password" value={form.data.current_password} onChange={(e) => form.setData('current_password', e.target.value)} />
                </Field>
                <Field label="New password" htmlFor="password" error={form.errors.password} hint="At least 8 characters.">
                    <PasswordInput id="password" autoComplete="new-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                </Field>
                <Field label="Repeat new password" htmlFor="password_confirmation">
                    <PasswordInput id="password_confirmation" autoComplete="new-password" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} />
                </Field>
                <Button type="submit" className="justify-self-start" disabled={form.processing}>Save</Button>
            </form>
        </>
    );
}
