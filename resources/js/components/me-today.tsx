import type { ReactNode } from 'react';
import { duration } from '@/components/attendance';
import { dayKind } from '@/components/attendance-heatmap';
import type { AttendanceYear } from '@/components/attendance-heatmap';
import type { AssetRow } from '@/components/assets';
import type { ConductRow, MeetingRow } from '@/components/feedback';
import { balanceTitle, leaveDates } from '@/components/leave';
import type { LeaveBalance } from '@/components/leave';
import { rupees } from '@/components/loans';
import type { LoanRow } from '@/components/loans';
import { openSection } from '@/components/me-sections';
import type { MedicalRow } from '@/components/medical';
import { Meter } from '@/components/meter';
import type { LeaveRequestRow } from '@/components/my-leave-requests';
import type { MyReview } from '@/components/my-reviews';
import type { WfhRequestRow } from '@/components/my-wfh-requests';
import { StatusPill } from '@/components/status-pill';
import { daysBetween, formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Tone } from '@/types';

/** app/Models/AttendanceRequest.php props() */
export type FixRequest = {
    id: number; date: string; check_in: string | null; check_out: string | null; reason: string; status: 'pending' | 'approved' | 'rejected' | 'cancelled';
    status_label: string; decision_note: string | null; decided_by: string | null; decided_at: string | null; created_at: string | null;
};

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const shortDate = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
const dayName = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
const range = (a: string, b: string) => (a === b ? shortDate(a) : `${shortDate(a)} to ${shortDate(b)}`);
const tones: Record<string, Tone> = { pending: 'pending', approved: 'done', active: 'info', paid: 'done', repaid: 'done', rejected: 'missing', cancelled: 'neutral' };

/** A Today card: a title, an optional link to the section with the rest, and its content. */
export function Card({ title, link, children, className }: { title: ReactNode; link?: { label: string; section: string }; children: ReactNode; className?: string }) {
    return (
        <section className={cn('grid content-start gap-3 rounded-lg border bg-card p-4', className)}>
            <div className="flex items-baseline justify-between gap-2">
                <h2 className="font-bold">{title}</h2>
                {link && <button type="button" className="text-sm font-semibold text-cascade underline-offset-2 hover:underline" onClick={() => openSection(link.section)}>{link.label}</button>}
            </div>
            {children}
        </section>
    );
}

/** One line in a card list: text on the left, something small on the right. Clickable when it leads somewhere. */
function Line({ children, end, onClick }: { children: ReactNode; end?: ReactNode; onClick?: () => void }) {
    const body = (<><span className="min-w-0">{children}</span>{end && <span className="shrink-0">{end}</span>}</>);
    return onClick ? (
        <li><button type="button" onClick={onClick} className="flex w-full items-center justify-between gap-3 py-2 text-left text-sm hover:text-cascade">{body}</button></li>
    ) : <li className="flex items-center justify-between gap-3 py-2 text-sm">{body}</li>;
}

/** Leave left this year as bars, and how much waiting leave isn't taken off yet. Also used on the Leave section. */
export function LeaveBars({ balances, waitingDays }: { balances: LeaveBalance[]; waitingDays: number }) {
    if (!balances.length) return <p className="text-sm text-muted-foreground">HR hasn't set yearly allowances yet.</p>;
    return (
        <div className="grid gap-3">
            {balances.map((b) => (
                <div key={b.type_id} className="grid gap-1" title={balanceTitle(b)}>
                    <div className="flex justify-between gap-2 text-sm">
                        <span>{b.type}</span>
                        <span className={cn('font-semibold tabular-nums', b.left < 0 && 'text-missing')}>{b.left >= 0 ? `${b.left} of ${b.allowance} days` : `${-b.left} days over`}</span>
                    </div>
                    <Meter value={Math.max(0, b.left)} max={b.allowance} label={`${b.type}: ${b.left} of ${b.allowance} days left`} tone={b.left < 0 ? 'missing' : 'primary'} />
                </div>
            ))}
            {waitingDays > 0 && <p className="text-xs text-muted-foreground">{waitingDays} {waitingDays === 1 ? 'day' : 'days'} waiting for HR {waitingDays === 1 ? 'is' : 'are'} not taken off yet.</p>}
        </div>
    );
}

type Waiting = { key: string; title: string; sub: string; status: string; label: string; at: string; open: string };

/** Everything they asked HR for that is still open, plus answers from the last 7 days. */
function waitingItems(p: { today: string; leaveRequests: LeaveRequestRow[]; wfhRequests: WfhRequestRow[]; requests: FixRequest[]; loans: LoanRow[]; medicalRequests: MedicalRow[] }): Waiting[] {
    const recent = (status: string, decidedAt: string | null) => status === 'pending' || (decidedAt !== null && daysBetween(decidedAt.slice(0, 10), p.today) <= 7);
    const items: Waiting[] = [
        ...p.leaveRequests.filter((r) => recent(r.status, r.decided_at)).map((r) => ({
            key: `l${r.id}`, title: `${r.type ?? 'Leave'} leave`, sub: `${leaveDates(r)}, ${r.days} ${r.days === 1 ? 'day' : 'days'}`, status: r.status, label: r.status_label, at: r.created_at ?? '', open: 'leave',
        })),
        ...p.wfhRequests.filter((r) => recent(r.status, r.decided_at)).map((r) => ({
            key: `w${r.id}`, title: 'Work from home', sub: range(r.start_date, r.end_date), status: r.status, label: r.status_label, at: r.created_at ?? '', open: 'attendance',
        })),
        ...p.requests.filter((r) => recent(r.status, r.decided_at)).map((r) => ({
            key: `f${r.id}`, title: `Fix ${shortDate(r.date)}`, sub: r.reason, status: r.status, label: r.status_label, at: r.created_at ?? '', open: 'attendance',
        })),
        ...p.loans.filter((l) => recent(l.status, l.decided_at)).map((l) => ({
            key: `n${l.id}`, title: 'Loan', sub: rupees(l.amount), status: l.status, label: l.status_label, at: l.created_at ?? '', open: 'care',
        })),
        ...p.medicalRequests.filter((m) => recent(m.status, m.decided_at)).map((m) => ({
            key: `m${m.id}`, title: 'Medical', sub: `${m.kind_label}, ${rupees(m.approved_amount ?? m.amount)}`, status: m.status, label: m.status_label, at: m.created_at ?? '', open: 'care',
        })),
    ];
    return items.sort((a, b) => Number(b.status === 'pending') - Number(a.status === 'pending') || b.at.localeCompare(a.at));
}

type ToDo = { key: string; text: ReactNode; end?: ReactNode; open: string };

export function TodayCards(p: {
    today: string; startDate: string | null; dateOfBirth: string | null;
    balances: LeaveBalance[]; leaveRequests: LeaveRequestRow[]; wfhRequests: WfhRequestRow[]; requests: FixRequest[]; loans: LoanRow[]; medicalRequests: MedicalRow[];
    month: { month: string; counts: Record<string, number>; worked: number; linked: boolean } | null; year?: AttendanceYear;
    reviews: MyReview[]; conduct: ConductRow[]; meetings: MeetingRow[]; missingContact: string[];
    holidays: { date: string; name: string }[]; assets: AssetRow[]; owed: string[];
}) {
    const waitingDays = p.leaveRequests.filter((r) => r.status === 'pending').reduce((n, r) => n + r.days, 0);
    const waiting = waitingItems(p);

    // Last 14 days, oldest first
    const strip = Array.from({ length: 14 }, (_, i) => {
        const d = new Date(`${p.today}T00:00:00Z`);
        d.setUTCDate(d.getUTCDate() - 13 + i);
        return d.toISOString().slice(0, 10);
    });
    const monthName = MONTHS[Number(p.today.slice(5, 7)) - 1];

    const todo: ToDo[] = [
        ...p.reviews.filter((r) => r.canSelfReview).map((r) => {
            const due = r.cycle.self_due ? daysBetween(p.today, r.cycle.self_due) : null;
            return {
                key: `r${r.id}`, text: <>Complete your self-review for <strong>{r.cycle.name}</strong></>, open: 'reviews',
                end: due === null ? null : <span className={cn('text-xs font-semibold', due < 3 ? 'text-missing' : 'text-muted-foreground')}>{due < 0 ? `${-due} days late` : due === 0 ? 'due today' : `due in ${due} ${due === 1 ? 'day' : 'days'}`}</span>,
            };
        }),
        ...p.reviews.filter((r) => r.status === 'completed' && !r.acknowledged_at).map((r) => ({ key: `a${r.id}`, text: <>Read and acknowledge your review for {r.cycle.name}</>, open: 'reviews' })),
        ...p.conduct.filter((c) => !c.acknowledged_at).map((c) => ({ key: `c${c.id}`, text: <>Read and acknowledge: {c.title}</>, end: <span className="text-xs text-muted-foreground">new</span>, open: 'reviews' })),
        ...p.meetings.filter((m) => !m.acknowledged_at).map((m) => ({ key: `t${m.id}`, text: <>Read and acknowledge a meeting note from HR</>, end: <span className="text-xs text-muted-foreground">new</span>, open: 'reviews' })),
        ...p.missingContact.map((what) => ({ key: `d${what}`, text: <>Add your {what}</>, end: <span className="text-xs text-muted-foreground">1 min</span>, open: 'details' })),
    ];

    // Coming up in the next 60 days: holidays, their work anniversary, their birthday
    const coming: { key: string; title: string; sub: string; date: string }[] = [
        ...p.holidays.filter((h) => h.date >= p.today && daysBetween(p.today, h.date) <= 60).slice(0, 3).map((h) => ({ key: `h${h.date}`, title: h.name, sub: 'Public holiday', date: h.date })),
    ];
    const next = (md: string) => {
        const y = Number(p.today.slice(0, 4));
        const d = `${y}-${md}`;
        return d >= p.today ? d : `${y + 1}-${md}`;
    };
    if (p.startDate) {
        const d = next(p.startDate.slice(5));
        const years = Number(d.slice(0, 4)) - Number(p.startDate.slice(0, 4));
        if (years > 0 && daysBetween(p.today, d) <= 60) coming.push({ key: 'anniversary', title: `${years} ${years === 1 ? 'year' : 'years'} with us`, sub: `Started ${formatDate(p.startDate)}`, date: d });
    }
    if (p.dateOfBirth) {
        const d = next(p.dateOfBirth.slice(5));
        if (daysBetween(p.today, d) <= 60) coming.push({ key: 'birthday', title: 'Your birthday', sub: d === p.today ? 'Happy birthday!' : 'From all of us', date: d });
    }
    coming.sort((a, b) => a.date.localeCompare(b.date));

    return (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Card title={`Leave left in ${p.today.slice(0, 4)}`} link={{ label: 'History', section: 'leave' }}>
                <LeaveBars balances={p.balances} waitingDays={waitingDays} />
            </Card>

            <Card title={`${monthName} so far`} link={{ label: 'All days', section: 'attendance' }}>
                {!p.month?.linked ? (
                    <p className="text-sm text-muted-foreground">HR hasn't linked your machine number yet, so only clock-ins on this page count.</p>
                ) : (<>
                    <div className="grid grid-cols-3 gap-2">
                        <Stat value={p.month.counts.Present ?? 0} label="present" className="text-done" />
                        <Stat value={p.month.counts.Late ?? 0} label="late" className={p.month.counts.Late ? 'text-pending' : undefined} />
                        <Stat value={p.month.worked ? duration(p.month.worked).replace(/ \d+m$/, '') : '0h'} label="worked" />
                    </div>
                    {p.year && (
                        <>
                            <div className="grid gap-1" style={{ gridTemplateColumns: 'repeat(14, minmax(0, 1fr))' }}>
                                {strip.map((d) => {
                                    const kind = dayKind(p.year!.days[d]);
                                    return <span key={d} title={`${dayName(d)}: ${kind.label}`} aria-label={`${dayName(d)}: ${kind.label}`} role="img" className={cn('aspect-square rounded-[3px]', kind.cls)} />;
                                })}
                            </div>
                            <p className="text-xs text-muted-foreground">Last 14 days. Hover a square for the day.</p>
                        </>
                    )}
                </>)}
            </Card>

            <Card title="Waiting for HR">
                {waiting.length === 0 ? <p className="text-sm text-muted-foreground">Nothing waiting. Requests you send show here until HR answers.</p> : (
                    <ul className="divide-y">
                        {waiting.slice(0, 5).map((w) => (
                            <Line key={w.key} onClick={() => openSection(w.open)} end={<StatusPill value={w.status === 'pending' ? 'Waiting' : w.label} tone={tones[w.status] ?? 'neutral'} />}>
                                <span className="block font-semibold">{w.title}</span>
                                <span className="block truncate text-xs text-muted-foreground">{w.sub}</span>
                            </Line>
                        ))}
                    </ul>
                )}
            </Card>

            <Card title="For you to do">
                {todo.length === 0 ? <p className="text-sm text-muted-foreground">Nothing to do. You're all set.</p> : (
                    <ul className="divide-y">{todo.map((t) => <Line key={t.key} onClick={() => openSection(t.open)} end={t.end}>{t.text}</Line>)}</ul>
                )}
            </Card>

            <Card title="Coming up">
                {coming.length === 0 ? <p className="text-sm text-muted-foreground">Nothing in the next two months.</p> : (
                    <ul className="divide-y">
                        {coming.map((c) => (
                            <Line key={c.key} end={<span className="text-xs text-muted-foreground tabular-nums">{dayName(c.date)}</span>}>
                                <span className="block font-semibold">{c.title}</span>
                                <span className="block text-xs text-muted-foreground">{c.sub}</span>
                            </Line>
                        ))}
                    </ul>
                )}
            </Card>

            <Card title="With you">
                {p.assets.length > 0 && (
                    <ul className="divide-y">
                        {p.assets.map((a) => (
                            <Line key={a.id} end={<span className="text-xs text-muted-foreground">since {shortDate(a.assigned_on)}</span>}>
                                <span className="font-semibold tabular-nums">{a.tag}</span> {a.name}
                            </Line>
                        ))}
                    </ul>
                )}
                <p className="text-sm text-muted-foreground">
                    {p.assets.length === 0 && 'No company property is with you. '}
                    {p.owed.length ? <>HR still needs from you: <span className="font-semibold text-foreground">{p.owed.join(', ')}</span>.</> : 'HR has every document it needs from you.'}
                </p>
            </Card>
        </div>
    );
}

function Stat({ value, label, className }: { value: ReactNode; label: string; className?: string }) {
    return (
        <div>
            <span className={cn('block text-3xl font-bold tabular-nums', className)}>{value}</span>
            <span className="text-xs text-muted-foreground">{label}</span>
        </div>
    );
}
