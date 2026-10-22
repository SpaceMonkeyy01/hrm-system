import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { KeyRound, MailCheck } from 'lucide-react';
import type { FormEvent } from 'react';
import { AuthCard, AuthFooter, AuthTitle, authButton, authInput, authLabel, authLink } from '@/layouts/login-scene';
import type { SharedProps } from '@/types';

/** "Forgot it?" on the login page (the scene around it: layouts/login-scene.tsx). */
export default function ForgotPassword() {
    const { flash } = usePage<SharedProps>().props;
    const form = useForm({ email: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/forgot-password', { onSuccess: () => form.reset() });
    };
    const sent = Boolean(flash.success) && !form.isDirty;

    return (
        <AuthCard onSubmit={submit}>
            <Head title="Forgot your password" />
            <AuthTitle tile={sent ? <MailCheck className="size-7" /> : <KeyRound className="size-7" />} solid={sent}
                title={sent ? 'Check your email' : 'Forgot it?'} subtitle={sent ? 'The link works for a short while.' : 'We\'ll email you a link.'} />
            {sent ? (
                <div role="status" className="rounded-lg bg-[#e3f4ea] px-[13px] py-[11px] text-sm leading-[1.4] text-[#1e5a3a]">
                    {flash.success} Open it on this device to choose a new password. Not there? Check the spam folder.
                </div>
            ) : (
                <p className="m-0 text-sm text-[#3a4a5c]">Enter the work email you log in with. If it has a login, we'll send a link to choose a new password.</p>
            )}
            <div className="flex flex-col gap-1.5">
                <label className={authLabel} htmlFor="email">Work email</label>
                <input id="email" className={authInput} type="email" autoComplete="username" autoFocus required value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)} aria-invalid={!!form.errors.email} />
                {form.errors.email && <p role="alert" className="m-0 text-sm text-[#8a1c15]">{form.errors.email}</p>}
            </div>
            <button type="submit" disabled={form.processing} className={authButton}>{sent ? 'Send it again' : 'Send the link'}</button>
            <AuthFooter><Link href="/login" className={authLink}>Back to log in</Link></AuthFooter>
        </AuthCard>
    );
}
