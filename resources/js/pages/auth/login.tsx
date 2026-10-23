import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, CircleAlert, Lock, Mail } from 'lucide-react';
import type { FormEvent } from 'react';
import { PasswordInput } from '@/components/password-input';
import { AuthCard, AuthIconField, authButton, authInput, authLabel } from '@/layouts/login-scene';
import { cn } from '@/lib/utils';

/** Log in (the scene around it: layouts/login-scene.tsx). */
export default function Login() {
    const form = useForm({ email: '', password: '', remember: false });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };
    const error = form.errors.email ?? form.errors.password;

    return (
        <AuthCard onSubmit={submit}>
            <Head title="Log in" />
            <h1 className="m-0 mb-1 font-display text-[32px] leading-[1.15] font-medium text-[#00435c] wide:text-[28px]">Welcome back</h1>
            {error && (
                <div role="alert" className="flex gap-2.5 rounded-[10px] bg-[#fbe5e3] px-3.5 py-3 text-sm leading-[1.4] text-[#8a1c15]">
                    <CircleAlert className="mt-px size-[18px] flex-none" />
                    <span>{error} Check both, or <Link href="/forgot-password" className="font-bold text-[#8a1c15] underline">reset your password</Link>.</span>
                </div>
            )}
            <div className="flex flex-col gap-2">
                <label className={authLabel} htmlFor="email">Email</label>
                <AuthIconField icon={Mail}>
                    <input id="email" className={cn(authInput, 'pl-11')} type="email" autoComplete="username" autoFocus required placeholder="name@company.com"
                        value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} aria-invalid={!!form.errors.email} />
                </AuthIconField>
            </div>
            <div className="flex flex-col gap-2">
                <div className="flex items-baseline justify-between">
                    <label className={authLabel} htmlFor="password">Password</label>
                    <Link href="/forgot-password" className="text-[13px] font-bold text-[#00435c] underline underline-offset-2 hover:no-underline">Forgot password?</Link>
                </div>
                <AuthIconField icon={Lock}>
                    <PasswordInput id="password" className={cn(authInput, 'pr-11 pl-11')} autoComplete="current-password" required value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)} />
                </AuthIconField>
            </div>
            <label className="flex items-center gap-3 text-[15px] text-[#3a4a5c] wide:text-sm">
                <input type="checkbox" className="size-5 flex-none accent-[#00435c] wide:size-[18px]" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} />
                <span>
                    Keep me logged in<span className="sm:hidden"> on this phone</span>
                    <span className="hidden text-[#7a8796] sm:inline"> (your own device)</span>
                </span>
            </label>
            <button type="submit" disabled={form.processing} className={cn(authButton, 'mt-1')}>
                {form.processing ? 'Logging in' : <>Log in <ArrowRight className="size-[18px]" /></>}
            </button>
        </AuthCard>
    );
}
