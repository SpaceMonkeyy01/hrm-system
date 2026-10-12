import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field } from '@/components/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export default function ForgotPassword() {
    const form = useForm({ email: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/forgot-password', { onSuccess: () => form.reset() });
    };
    return (
        <>
            <Head title="Forgot your password" />
            <h1 className="font-display text-xl font-semibold">Forgot your password?</h1>
            <p className="mt-1 text-sm text-muted-foreground">Enter the email you log in with. We'll send a link to choose a new password.</p>
            <form onSubmit={submit} className="mt-6 grid gap-4">
                <Field label="Email" htmlFor="email" error={form.errors.email}>
                    <Input id="email" type="email" autoComplete="username" autoFocus required value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                </Field>
                <Button type="submit" disabled={form.processing}>Send the link</Button>
                <Link href="/login" className="text-center text-sm text-cascade hover:underline">Back to log in</Link>
            </form>
        </>
    );
}
