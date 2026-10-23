import { Link, router } from '@inertiajs/react';
import { KeyRound, MailPlus, Power } from 'lucide-react';
import { toast } from 'sonner';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';

/** EmployeeController::show 'login' (HR and admin only). */
export type ProfileLogin = { id: number; email: string; role: string; is_active: boolean; last_login_at: string | null };

const firstError = (e: Record<string, string>) => toast.error(Object.values(e)[0] ?? 'Not done.');

/** Sends the password link again, after a confirm (StaffLoginsController::sendLink). */
export function sendLoginLink(login: { id: number; email: string }) {
    if (confirm(`Email ${login.email} a link to choose a password?`)) router.post(`/logins/${login.id}/send-link`, {}, { preserveScroll: true, onError: firstError });
}

/** Switches a staff login off or back on (StaffLoginsController::setActive). */
export function setLoginActive(login: { id: number; email: string }, active: boolean) {
    if (active || confirm(`Switch off ${login.email}? They won't be able to log in until it's switched back on.`)) {
        router.put(`/logins/${login.id}/active`, { is_active: active }, { preserveScroll: true, onError: firstError });
    }
}

/**
 * The profile's Login card: create a staff login from the work email and email the link, send it again, switch it off
 * or on. HR, viewer and admin logins only show here; admins change them in Settings > Users.
 */
export function LoginCard({ employee, login, isAdmin }: {
    employee: { id: number; full_name: string; email: string | null; status: string }; login: ProfileLogin | null; isAdmin: boolean;
}) {
    const current = ['active', 'offboarding'].includes(employee.status);
    const staff = login?.role === 'employee';

    return (
        <section className="rounded-lg border p-4">
            <h2 className="mb-3 font-bold">Login</h2>
            {login ? (
                <div className="grid gap-3 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-semibold break-all">{login.email}</span>
                        {!login.is_active ? <StatusPill value="Switched off" tone="neutral" />
                            : !staff && <StatusPill value={login.role === 'hr' ? 'HR' : login.role[0].toUpperCase() + login.role.slice(1)} tone="info" />}
                    </div>
                    <p className="m-0 text-muted-foreground">
                        {login.last_login_at ? `Last logged in ${formatDateTime(login.last_login_at)}.`
                            : <span className="font-semibold text-pending">Has never logged in.</span>}
                    </p>
                    {staff ? (
                        <div className="flex flex-wrap gap-2">
                            {login.is_active && <Button size="sm" variant="outline" onClick={() => sendLoginLink(login)}><KeyRound /> Send a password link</Button>}
                            <Button size="sm" variant="ghost" onClick={() => setLoginActive(login, !login.is_active)}>
                                <Power /> {login.is_active ? 'Switch off' : 'Switch back on'}
                            </Button>
                        </div>
                    ) : (
                        <p className="m-0 text-xs text-muted-foreground">
                            Not a staff login, so only an admin can change it{isAdmin ? <>: <Link href="/settings/users" className="text-cascade hover:underline">Settings › Users</Link></> : ''}.
                        </p>
                    )}
                </div>
            ) : !current ? (
                <p className="m-0 text-sm text-muted-foreground">No login. Only current staff can have one.</p>
            ) : !employee.email ? (
                <p className="m-0 text-sm text-muted-foreground">
                    No login yet. Add a work email first, then create one here. <Link href={`/employees/${employee.id}/edit#email`} className="font-semibold text-cascade hover:underline">Add work email</Link>
                </p>
            ) : (
                <div className="grid gap-3 text-sm">
                    <p className="m-0 text-muted-foreground">No login yet. They get an email at <span className="font-semibold text-ink">{employee.email}</span> with a link to choose their own password, then see My page.</p>
                    <Button size="sm" className="justify-self-start" onClick={() => confirm(`Create a login for ${employee.full_name} and email ${employee.email} the link?`)
                        && router.post(`/employees/${employee.id}/login`, {}, { preserveScroll: true, onError: firstError })}>
                        <MailPlus /> Create login and email it
                    </Button>
                </div>
            )}
        </section>
    );
}
