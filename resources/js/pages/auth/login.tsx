import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { PasswordInput } from '@/components/password-input';
import { AuthCard, AuthTitle, authButton, authInput, authLabel, authLink } from '@/layouts/login-scene';
import { cn } from '@/lib/utils';

/** "sara.qureshi@..." -> "SQ" while they type; "?" before. */
function initialsFrom(email: string): string {
    const local = email.split('@')[0] ?? '';
    const parts = local.split(/[._-]+/).filter((p) => /^[a-z]/i.test(p));
    return parts.length ? parts.slice(0, 2).map((p) => p[0].toUpperCase()).join('') : '?';
}

/** Log in (the scene around it: layouts/login-scene.tsx). */
export default function Login() {
    const form = useForm({ email: '', password: '', remember: false });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };
    const initials = initialsFrom(form.data.email);
    const error = form.errors.email ?? form.errors.password;

    return (
        <AuthCard onSubmit={submit}>
            <Head title="Log in" />
            <AuthTitle tile={initials} solid={initials !== '?'} title="Log in" subtitle="Use your work email." />
            {error && (
                <div role="alert" className="rounded-lg bg-[#fbe5e3] px-[13px] py-[11px] text-sm leading-[1.4] text-[#8a1c15]">
                    {error} Check both, or <Link href="/forgot-password" className="font-bold text-[#8a1c15] underline">reset your password</Link>.
                </div>
            )}
            <div className="flex flex-col gap-1.5">
                <label className={authLabel} htmlFor="email">Work email</label>
                <input id="email" className={authInput} type="email" autoComplete="username" autoFocus required value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)} aria-invalid={!!form.errors.email} />
            </div>
            <div className="flex flex-col gap-1.5">
                <div className="flex items-baseline justify-between">
                    <label className={authLabel} htmlFor="password">Password</label>
                    <Link href="/forgot-password" className={cn(authLink, 'text-[13px]')}>Forgot it?</Link>
                </div>
                <PasswordInput id="password" className={cn(authInput, 'pr-10')} autoComplete="current-password" required value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)} />
            </div>
            <label className="flex items-center gap-2.5 text-sm text-[#3a4a5c]">
                <input type="checkbox" className="size-[18px] accent-[#00435c]" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} />
                Keep me logged in
            </label>
            <button type="submit" disabled={form.processing} className={authButton}>{form.processing ? 'Logging in' : 'Log in'}</button>
        </AuthCard>
    );
}
