import { Head, Link, useForm } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import type { FormEvent } from 'react';
import { PasswordInput } from '@/components/password-input';
import { AuthCard, AuthFooter, AuthTitle, authButton, authInput, authLabel, authLink } from '@/layouts/login-scene';
import { cn } from '@/lib/utils';

/** The page the emailed link opens: a first password (invite) or a new one (the scene around it: layouts/login-scene.tsx). */
export default function ResetPassword({ token, email, invite }: { token: string; email: string; invite: boolean }) {
    const form = useForm({ token, email, invite, password: '', password_confirmation: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
    };
    const title = invite ? 'Choose your password' : 'Choose a new password';
    const error = form.errors.email ?? form.errors.token;

    return (
        <AuthCard onSubmit={submit}>
            <Head title={title} />
            <AuthTitle tile={<KeyRound className="size-7" />} solid title={title} subtitle="At least 8 characters. Only you will know it." />
            {error && (
                <div role="alert" className="rounded-lg bg-[#fbe5e3] px-[13px] py-[11px] text-sm leading-[1.4] text-[#8a1c15]">
                    {error} <Link href="/forgot-password" className="font-bold text-[#8a1c15] underline">Get a new link</Link>.
                </div>
            )}
            <div className="flex flex-col gap-1.5">
                <label className={authLabel} htmlFor="email">Work email</label>
                <input id="email" className={authInput} type="email" autoComplete="username" required value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)} />
            </div>
            <div className="flex flex-col gap-1.5">
                <label className={authLabel} htmlFor="password">{invite ? 'Password' : 'New password'}</label>
                <PasswordInput id="password" className={cn(authInput, 'pr-10')} autoComplete="new-password" autoFocus required value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)} aria-invalid={!!form.errors.password} />
                {form.errors.password && <p role="alert" className="m-0 text-sm text-[#8a1c15]">{form.errors.password}</p>}
            </div>
            <div className="flex flex-col gap-1.5">
                <label className={authLabel} htmlFor="password_confirmation">Same password again</label>
                <PasswordInput id="password_confirmation" className={cn(authInput, 'pr-10')} autoComplete="new-password" required value={form.data.password_confirmation}
                    onChange={(e) => form.setData('password_confirmation', e.target.value)} />
            </div>
            <button type="submit" disabled={form.processing} className={authButton}>{form.processing ? 'Saving' : 'Save password'}</button>
            <AuthFooter><Link href="/login" className={authLink}>Back to log in</Link></AuthFooter>
        </AuthCard>
    );
}
