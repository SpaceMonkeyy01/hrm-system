import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field } from '@/components/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/password-input';

export default function ResetPassword({ token, email, invite }: { token: string; email: string; invite: boolean }) {
    const form = useForm({ token, email, invite, password: '', password_confirmation: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
    };
    return (
        <>
            <Head title={invite ? 'Choose your password' : 'Choose a new password'} />
            <h1 className="font-display text-xl font-semibold">{invite ? 'Choose your password' : 'Choose a new password'}</h1>
            <p className="mt-1 text-sm text-muted-foreground">At least 8 characters. Only you will know it.</p>
            <form onSubmit={submit} className="mt-6 grid gap-4">
                <Field label="Email" htmlFor="email" error={form.errors.email}>
                    <Input id="email" type="email" autoComplete="username" required value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                </Field>
                <Field label="Password" htmlFor="password" error={form.errors.password}>
                    <PasswordInput id="password" autoComplete="new-password" autoFocus required value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                </Field>
                <Field label="Same password again" htmlFor="password_confirmation">
                    <PasswordInput id="password_confirmation" autoComplete="new-password" required value={form.data.password_confirmation}
                        onChange={(e) => form.setData('password_confirmation', e.target.value)} />
                </Field>
                <Button type="submit" disabled={form.processing}>Save password</Button>
                <Link href="/login" className="text-center text-sm text-cascade hover:underline">Back to log in</Link>
            </form>
        </>
    );
}
