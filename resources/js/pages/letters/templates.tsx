import { Head, Link, router } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate, plural } from '@/lib/format';
import { cn } from '@/lib/utils';

type Template = {
    id: number; name: string; subject: string | null; details_label: string | null; is_active: boolean;
    checklist: string | null; conduct: string | null; issued: number; last_issued: string | null;
};

/** Offered when issuing: flips straight away (logged like any change). */
function OfferedSwitch({ t }: { t: Template }) {
    const [busy, setBusy] = useState(false);
    return (
        <button type="button" role="switch" aria-checked={t.is_active} aria-label={`Offer ${t.name} when issuing`} disabled={busy}
            onClick={(e) => {
                e.stopPropagation();
                router.patch(`/letters/templates/${t.id}/active`, { is_active: !t.is_active }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
            }}
            className={cn('relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:outline-2 disabled:opacity-60',
                t.is_active ? 'bg-cascade' : 'bg-muted-foreground/30')}>
            <span className={cn('inline-block size-5 rounded-full bg-white shadow transition-transform', t.is_active ? 'translate-x-5.5' : 'translate-x-0.5')} />
        </button>
    );
}

/** What issuing the letter does besides filing it: the question it asks, the offboarding item it ticks, the conduct record. */
function WhenIssued({ t }: { t: Template }) {
    const chips = [
        t.details_label && `Asks: ${t.details_label}`,
        t.checklist && `Ticks: ${t.checklist}`,
        t.conduct && `Adds: ${t.conduct}`,
    ].filter(Boolean) as string[];
    if (!chips.length) return <span className="text-muted-foreground">-</span>;
    return (
        <span className="flex flex-wrap gap-1">
            {chips.map((c) => <span key={c} className="rounded-full border bg-muted/50 px-2 py-0.5 text-xs">{c}</span>)}
        </span>
    );
}

const issuedText = (t: Template) => (t.issued ? `${plural(t.issued, 'time')}, last ${formatDate(t.last_issued)}` : 'Not yet');

export default function LetterTemplates({ templates }: { templates: Template[] }) {
    const [q, setQ] = useState('');
    const term = q.trim().toLowerCase();
    const shown = term ? templates.filter((t) => `${t.name} ${t.subject ?? ''}`.toLowerCase().includes(term)) : templates;
    const open = (t: Template) => router.visit(`/letters/templates/${t.id}/edit`);

    return (
        <>
            <Head title="Letter templates" />
            <PageHeader title="Letter templates"
                help="The letters HR can issue from a profile. Click a template to change its text; switch it off to stop offering it when issuing. Letters already issued keep their own text."
                actions={<Button asChild><Link href="/letters/templates/new"><Plus /> Add template</Link></Button>} />
            <div className="grid gap-4 px-4 py-6 md:px-6">
                <div className="relative max-w-sm">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input className="pl-9" placeholder="Find a template" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Find a template" />
                </div>

                {shown.length === 0 ? (
                    <EmptyState title={term ? 'No template matches.' : 'No templates yet.'} />
                ) : (
                    <>
                        {/* Phones: one card per template. */}
                        <ul className="grid gap-2 md:hidden">
                            {shown.map((t) => (
                                <li key={t.id} className="grid gap-2 rounded-lg border p-3" onClick={() => open(t)}>
                                    <div className="flex items-start justify-between gap-3">
                                        <Link href={`/letters/templates/${t.id}/edit`} className="font-semibold text-cascade hover:underline" onClick={(e) => e.stopPropagation()}>{t.name}</Link>
                                        <OfferedSwitch t={t} />
                                    </div>
                                    <WhenIssued t={t} />
                                    <p className="text-xs text-muted-foreground">{t.issued ? `Issued ${issuedText(t)}` : 'Not issued yet'}</p>
                                </li>
                            ))}
                        </ul>

                        <div className="hidden overflow-x-auto rounded-lg border md:block">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                                        <th className="px-3 py-2.5 font-semibold">Template</th>
                                        <th className="px-3 py-2.5 font-semibold">Subject line</th>
                                        <th className="px-3 py-2.5 font-semibold">When issued</th>
                                        <th className="px-3 py-2.5 font-semibold">Issued</th>
                                        <th className="px-3 py-2.5 font-semibold">Offered</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {shown.map((t) => (
                                        <tr key={t.id} className={cn('cursor-pointer hover:bg-accent', !t.is_active && 'text-muted-foreground')} onClick={() => open(t)}>
                                            <td className="px-3 py-3">
                                                <Link href={`/letters/templates/${t.id}/edit`} className="font-semibold text-cascade hover:underline" onClick={(e) => e.stopPropagation()}>{t.name}</Link>
                                            </td>
                                            <td className="px-3 py-3">{t.subject || <span className="text-muted-foreground">-</span>}</td>
                                            <td className="px-3 py-3"><WhenIssued t={t} /></td>
                                            <td className="px-3 py-3 whitespace-nowrap tabular-nums">{issuedText(t)}</td>
                                            <td className="px-3 py-3"><OfferedSwitch t={t} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}
