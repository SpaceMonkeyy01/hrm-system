import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field } from '@/components/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export default function Login() {
    const form = useForm({ email: '', password: '', remember: true });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <>
            <Head title="Log in" />
            <h1 className="font-display text-xl font-semibold">Log in</h1>
            <p className="mt-1 text-sm text-muted-foreground">Use the email your HR admin set up for you.</p>
            <form onSubmit={submit} className="mt-6 grid gap-4">
                <Field label="Email" htmlFor="email" error={form.errors.email}>
                    <Input id="email" type="email" autoComplete="username" autoFocus required value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)} aria-invalid={!!form.errors.email} />
                </Field>
                <Field label="Password" htmlFor="password" error={form.errors.password}>
                    <Input id="password" type="password" autoComplete="current-password" required value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)} />
                </Field>
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" className="size-4 accent-cascade" checked={form.data.remember}
                        onChange={(e) => form.setData('remember', e.target.checked)} />
                    Keep me logged in on this computer
                </label>
                <Button type="submit" disabled={form.processing} className="mt-2">
                    {form.processing ? 'Logging in' : 'Log in'}
                </Button>
                <Link href="/forgot-password" className="text-center text-sm text-cascade hover:underline">Forgot your password?</Link>
            </form>
        </>
    );
}
