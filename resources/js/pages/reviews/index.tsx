import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { CycleForm } from '@/components/review-cycle-form';
import { cycleTone } from '@/components/reviews';
import type { Cycle } from '@/components/reviews';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';

type Props = { cycles: (Cycle & { counts: Record<string, number> })[]; statuses: Record<string, string>; cycleStatuses: Record<string, string> };

export default function ReviewsIndex({ cycles, cycleStatuses }: Props) {
    const [creating, setCreating] = useState(false);
    return (
        <>
            <Head title="Performance reviews" />
            <PageHeader title="Performance reviews" description="Review cycles: people rate themselves on My page, HR adds the manager review, people read and acknowledge it."
                actions={<Button onClick={() => setCreating(true)}><Plus /> New review cycle</Button>} />
            <div className="px-4 py-6 md:px-6">
                {cycles.length === 0 ? (
                    <EmptyState title="No review cycles yet.">Create one for a period, for example "2026 mid-year review".</EmptyState>
                ) : (
                    <ul className="grid gap-3 lg:grid-cols-2">
                        {cycles.map((c) => {
                            const total = Object.entries(c.counts).filter(([k]) => k !== 'cancelled').reduce((n, [, v]) => n + v, 0);
                            const done = c.counts.completed ?? 0;
                            return (
                                <li key={c.id}>
                                    <Link href={`/reviews/${c.id}`} className="grid gap-2 rounded-lg border px-4 py-3 hover:bg-accent">
                                        <span className="flex items-center justify-between gap-2">
                                            <span className="font-semibold">{c.name}</span>
                                            <StatusPill value={cycleStatuses[c.status]} tone={cycleTone[c.status]} />
                                        </span>
                                        <span className="text-sm text-muted-foreground">{formatDate(c.period_start)} to {formatDate(c.period_end)}</span>
                                        <span className="flex h-2 overflow-hidden rounded-full bg-muted" title={`${done} of ${total} done`}>
                                            <span className="bg-done" style={{ width: `${total ? (done / total) * 100 : 0}%` }} />
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {done} of {total} done{c.counts.self_review ? `, ${c.counts.self_review} waiting for a self-review` : ''}{c.counts.manager_review ? `, ${c.counts.manager_review} waiting for HR` : ''}
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
            {creating && <CycleForm onClose={() => setCreating(false)} />}
        </>
    );
}
