import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import { timeAgo } from '@/lib/format';

/** app/Http/Controllers/RequestsInboxController.php */
type Queue = { key: string; title: string; description: string; href: string; pending: number; oldest: string | null };

export default function RequestsInbox({ queues }: { queues: Queue[] }) {
    const total = queues.reduce((n, q) => n + q.pending, 0);
    return (
        <>
            <Head title="Requests" />
            <PageHeader title="Requests" description={total ? `${total} waiting for HR.` : 'Nothing waiting.'} help="Everything staff asked for on their My page shows here." />
            <div className="grid max-w-3xl gap-3 px-4 py-6 md:px-6">
                {queues.map((q) => (
                    <Link key={q.key} href={q.href} className="flex items-center gap-4 rounded-lg border p-4 hover:bg-accent/40">
                        <div className="grid flex-1 gap-0.5">
                            <span className="flex items-center gap-2 font-semibold">
                                {q.title}
                                <StatusPill value={q.pending ? `${q.pending} waiting` : 'None waiting'} tone={q.pending ? 'pending' : 'done'} />
                            </span>
                            <span className="text-sm text-muted-foreground">{q.description}{q.oldest && ` Oldest sent ${timeAgo(q.oldest)}.`}</span>
                        </div>
                        <ChevronRight className="size-4 text-muted-foreground" />
                    </Link>
                ))}
            </div>
        </>
    );
}
