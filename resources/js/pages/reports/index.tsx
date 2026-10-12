import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, Lock } from 'lucide-react';
import { PageHeader, Section } from '@/components/page-header';

type Report = { key: string; title: string; description: string; private: boolean };
type Props = { groups: { key: string; label: string; reports: Report[] }[] };

export default function ReportsIndex({ groups }: Props) {
    return (
        <>
            <Head title="Reports" />
            <PageHeader title="Reports" description="Ready-made reports. Each one opens on screen with filters and downloads for Excel with the same numbers." />
            <div className="grid gap-8 p-4 md:p-6">
                {groups.filter((g) => g.reports.length).map((g) => (
                    <Section key={g.key} title={g.label}>
                        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {g.reports.map((r) => (
                                <li key={r.key}>
                                    <Link href={`/reports/${r.key}`} className="group flex h-full flex-col rounded-lg border px-4 py-3 hover:border-cascade/40 hover:bg-accent">
                                        <span className="flex items-center justify-between gap-2 font-semibold group-hover:text-cascade">
                                            {r.title}
                                            <ArrowUpRight className="size-4 shrink-0 text-muted-foreground opacity-0 group-hover:opacity-100" />
                                        </span>
                                        <span className="mt-1 text-sm text-muted-foreground">{r.description}</span>
                                        {r.private && <span className="mt-2 inline-flex items-center gap-1 text-xs text-muted-foreground"><Lock className="size-3" /> HR and admin only</span>}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Section>
                ))}
            </div>
        </>
    );
}
