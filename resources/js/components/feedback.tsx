import { Link, router, useForm } from '@inertiajs/react';
import { Award, CheckCircle2, MessagesSquare, Pencil, Plus, Trash2 } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { NativeSelect } from '@/components/native-select';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/format';
import type { Tone } from '@/types';

/** app/Models/ConductEntry.php props() */
export type ConductRow = {
    id: number; type: string; type_label: string; happened_on: string; title: string; details: string | null;
    letter: { id: number; reference: string } | null; acknowledged_at: string | null; employee_comment: string | null; by: string | null; created_at: string | null;
};
/** app/Models/Meeting.php props() */
export type MeetingRow = {
    id: number; type: string; type_label: string; held_on: string; attendees: string | null; notes: string; actions: string | null; shared: boolean;
    acknowledged_at: string | null; employee_comment: string | null; by: string | null; created_at: string | null;
};
/** app/Http/Controllers/FeedbackController.php forEmployee() */
export type Feedback = { conduct: ConductRow[]; meetings: MeetingRow[]; conductTypes: Record<string, string>; meetingTypes: Record<string, string> };

export const conductTone = (type: string): Tone => (type === 'appreciation' ? 'done' : type === 'verbal_warning' ? 'pending' : 'missing');
const options = (o: Record<string, string>) => Object.entries(o).map(([value, label]) => ({ value, label }));
const opts = { preserveScroll: true };

function Acknowledged({ at, comment, who }: { at: string | null; comment: string | null; who: string }) {
    return (
        <p className="mt-1 text-xs text-muted-foreground">
            {at ? <><CheckCircle2 className="mr-1 inline size-3.5 text-done" />{who} acknowledged it {formatDateTime(at)}.</> : `${who} hasn't acknowledged it yet.`}
            {comment && <span className="mt-0.5 block text-foreground">Comment: {comment}</span>}
        </p>
    );
}

function ConductDialog({ employeeId, entry, types, today, onClose }: { employeeId: number; entry: ConductRow | null; types: Record<string, string>; today: string; onClose: () => void }) {
    const form = useForm({ type: entry?.type ?? 'appreciation', happened_on: entry?.happened_on ?? today, title: entry?.title ?? '', details: entry?.details ?? '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (entry) form.put(`/conduct/${entry.id}`, { ...opts, onSuccess: onClose });
        else form.post(`/employees/${employeeId}/conduct`, { ...opts, onSuccess: onClose });
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{entry ? 'Change entry' : 'Add to the conduct record'}</DialogTitle>
                        <DialogDescription>
                            The person sees it on their My page and acknowledges it.{entry?.acknowledged_at ? ' Changing it asks them to acknowledge it again.' : ''}
                            {' '}To give them a letter too, issue a warning or appreciation letter from the Documents tab instead.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Type" htmlFor="ce-type" error={form.errors.type}>
                            <NativeSelect id="ce-type" value={form.data.type} options={options(types)} onChange={(e) => form.setData('type', e.target.value)} />
                        </Field>
                        <Field label="Date" htmlFor="ce-date" error={form.errors.happened_on}>
                            <Input id="ce-date" type="date" required max={today} value={form.data.happened_on} onChange={(e) => form.setData('happened_on', e.target.value)} />
                        </Field>
                    </div>
                    <Field label="Title" htmlFor="ce-title" error={form.errors.title}>
                        <Input id="ce-title" required placeholder="Excellent client launch" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                    </Field>
                    <Field label="Details" htmlFor="ce-details" error={form.errors.details}>
                        <Textarea id="ce-details" rows={4} value={form.data.details} onChange={(e) => form.setData('details', e.target.value)} />
                    </Field>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>Save</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function MeetingDialog({ employeeId, meeting, types, today, onClose }: { employeeId: number; meeting: MeetingRow | null; types: Record<string, string>; today: string; onClose: () => void }) {
    const form = useForm({
        type: meeting?.type ?? 'one_to_one', held_on: meeting?.held_on ?? today, attendees: meeting?.attendees ?? '',
        notes: meeting?.notes ?? '', actions: meeting?.actions ?? '', shared: meeting?.shared ?? false,
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (meeting) form.put(`/meetings/${meeting.id}`, { ...opts, onSuccess: onClose });
        else form.post(`/employees/${employeeId}/meetings`, { ...opts, onSuccess: onClose });
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-xl">
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{meeting ? 'Change meeting notes' : 'Add meeting notes'}</DialogTitle>
                        <DialogDescription>Only HR and admin see these, unless you share them with the person.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Meeting" htmlFor="mt-type" error={form.errors.type}>
                            <NativeSelect id="mt-type" value={form.data.type} options={options(types)} onChange={(e) => form.setData('type', e.target.value)} />
                        </Field>
                        <Field label="Date" htmlFor="mt-date" error={form.errors.held_on}>
                            <Input id="mt-date" type="date" required max={today} value={form.data.held_on} onChange={(e) => form.setData('held_on', e.target.value)} />
                        </Field>
                    </div>
                    <Field label="Who was there" htmlFor="mt-who" error={form.errors.attendees}>
                        <Input id="mt-who" placeholder="Manager, HR" value={form.data.attendees} onChange={(e) => form.setData('attendees', e.target.value)} />
                    </Field>
                    <Field label="Notes" htmlFor="mt-notes" error={form.errors.notes}>
                        <Textarea id="mt-notes" rows={5} required value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                    </Field>
                    <Field label="Agreed actions" htmlFor="mt-actions" error={form.errors.actions}>
                        <Textarea id="mt-actions" rows={3} placeholder="Weekly progress update to the manager" value={form.data.actions} onChange={(e) => form.setData('actions', e.target.value)} />
                    </Field>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" className="size-4 accent-cascade" checked={form.data.shared} onChange={(e) => form.setData('shared', e.target.checked)} />
                        Share with the person (they see the notes and actions on My page and acknowledge them)
                    </label>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>Save</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Profile, Feedback tab (HR and admin): the conduct record and meeting notes. */
export function FeedbackPanel({ employeeId, feedback, today }: { employeeId: number; feedback: Feedback; today: string }) {
    const [conduct, setConduct] = useState<ConductRow | null | 'new'>(null);
    const [meeting, setMeeting] = useState<MeetingRow | null | 'new'>(null);
    const remove = (url: string, what: string) => confirm(`Remove this ${what}? It can't be undone.`) && router.delete(url, opts);
    return (
        <div className="grid max-w-4xl gap-10">
            <section className="grid gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="font-bold">Conduct record</h2>
                    <Button variant="outline" onClick={() => setConduct('new')}><Plus /> Add entry</Button>
                </div>
                {feedback.conduct.length === 0 ? <EmptyState icon={Award} title="No warnings or appreciation yet." /> : (
                    <ul className="divide-y rounded-lg border">
                        {feedback.conduct.map((c) => (
                            <li key={c.id} className="grid gap-1 px-3 py-3 text-sm sm:grid-cols-[1fr_auto]">
                                <div>
                                    <p className="flex flex-wrap items-center gap-2">
                                        <StatusPill value={c.type_label} tone={conductTone(c.type)} />
                                        <span className="font-semibold">{c.title}</span>
                                        <span className="text-muted-foreground tabular-nums">{formatDate(c.happened_on)}</span>
                                    </p>
                                    {c.details && <p className="mt-1 whitespace-pre-line">{c.details}</p>}
                                    {c.letter && <Link href={`/letters/${c.letter.id}`} className="mt-1 inline-block text-xs text-cascade hover:underline">Letter {c.letter.reference}</Link>}
                                    <Acknowledged at={c.acknowledged_at} comment={c.employee_comment} who="They" />
                                    {c.by && <p className="text-xs text-muted-foreground">Added by {c.by}</p>}
                                </div>
                                <div className="flex gap-1 sm:justify-end">
                                    <Button variant="ghost" size="sm" aria-label="Change entry" onClick={() => setConduct(c)}><Pencil /></Button>
                                    <Button variant="ghost" size="sm" aria-label="Remove entry" onClick={() => remove(`/conduct/${c.id}`, 'entry')}><Trash2 /></Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
            <section className="grid gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="font-bold">Meetings</h2>
                    <Button variant="outline" onClick={() => setMeeting('new')}><Plus /> Add meeting notes</Button>
                </div>
                {feedback.meetings.length === 0 ? <EmptyState icon={MessagesSquare} title="No meeting notes yet.">One-to-ones, probation check-ins and review discussions.</EmptyState> : (
                    <ul className="divide-y rounded-lg border">
                        {feedback.meetings.map((m) => (
                            <li key={m.id} className="grid gap-1 px-3 py-3 text-sm sm:grid-cols-[1fr_auto]">
                                <div>
                                    <p className="flex flex-wrap items-center gap-2">
                                        <span className="font-semibold">{m.type_label}</span>
                                        <span className="text-muted-foreground tabular-nums">{formatDate(m.held_on)}</span>
                                        {m.attendees && <span className="text-muted-foreground">with {m.attendees}</span>}
                                        <StatusPill value={m.shared ? 'Shared' : 'HR only'} tone={m.shared ? 'info' : 'neutral'} />
                                    </p>
                                    <p className="mt-1 whitespace-pre-line">{m.notes}</p>
                                    {m.actions && <p className="mt-1 whitespace-pre-line"><span className="font-semibold">Actions:</span> {m.actions}</p>}
                                    {m.shared && <Acknowledged at={m.acknowledged_at} comment={m.employee_comment} who="They" />}
                                    {m.by && <p className="text-xs text-muted-foreground">Written by {m.by}</p>}
                                </div>
                                <div className="flex gap-1 sm:justify-end">
                                    <Button variant="ghost" size="sm" aria-label="Change meeting notes" onClick={() => setMeeting(m)}><Pencil /></Button>
                                    <Button variant="ghost" size="sm" aria-label="Remove meeting notes" onClick={() => remove(`/meetings/${m.id}`, 'meeting')}><Trash2 /></Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
            {conduct && <ConductDialog employeeId={employeeId} entry={conduct === 'new' ? null : conduct} types={feedback.conductTypes} today={today} onClose={() => setConduct(null)} />}
            {meeting && <MeetingDialog employeeId={employeeId} meeting={meeting === 'new' ? null : meeting} types={feedback.meetingTypes} today={today} onClose={() => setMeeting(null)} />}
        </div>
    );
}

function AcknowledgeDialog({ url, title, onClose }: { url: string; title: string; onClose: () => void }) {
    const form = useForm({ comment: '' });
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={(e) => { e.preventDefault(); form.post(url, { ...opts, onSuccess: onClose }); }} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Acknowledge: {title}</DialogTitle>
                        <DialogDescription>This tells HR you have read it. It doesn't mean you agree: add a comment if you want to say something.</DialogDescription>
                    </DialogHeader>
                    <Field label="Comment (optional)" htmlFor="ack-comment" error={form.errors.comment}>
                        <Textarea id="ack-comment" rows={3} value={form.data.comment} onChange={(e) => form.setData('comment', e.target.value)} />
                    </Field>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>I've read it</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** My page, Feedback tab: their conduct record and the meeting notes HR shared, each to acknowledge. */
export function MyFeedback({ conduct, meetings }: { conduct: ConductRow[]; meetings: MeetingRow[] }) {
    const [ack, setAck] = useState<{ url: string; title: string } | null>(null);
    const button = (url: string, title: string, at: string | null) => !at && <Button size="sm" onClick={() => setAck({ url, title })}>Acknowledge</Button>;
    return (
        <div className="grid max-w-3xl gap-8">
            <section className="grid gap-3">
                <h2 className="font-bold">Warnings and appreciation</h2>
                {conduct.length === 0 ? <EmptyState icon={Award} title="Nothing on your record." /> : (
                    <ul className="divide-y rounded-lg border">
                        {conduct.map((c) => (
                            <li key={c.id} className="grid gap-2 px-3 py-3 text-sm sm:grid-cols-[1fr_auto] sm:items-start">
                                <div>
                                    <p className="flex flex-wrap items-center gap-2">
                                        <StatusPill value={c.type_label} tone={conductTone(c.type)} />
                                        <span className="font-semibold">{c.title}</span>
                                        <span className="text-muted-foreground tabular-nums">{formatDate(c.happened_on)}</span>
                                    </p>
                                    {c.details && <p className="mt-1 whitespace-pre-line">{c.details}</p>}
                                    {c.letter && <a href={`/me/letters/${c.letter.id}`} className="mt-1 inline-block text-xs text-cascade hover:underline">Open the letter ({c.letter.reference})</a>}
                                    <Acknowledged at={c.acknowledged_at} comment={c.employee_comment} who="You" />
                                </div>
                                <div className="sm:text-right">{button(`/me/conduct/${c.id}/acknowledge`, c.title, c.acknowledged_at)}</div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
            <section className="grid gap-3">
                <h2 className="font-bold">Meeting notes</h2>
                {meetings.length === 0 ? <EmptyState icon={MessagesSquare} title="No meeting notes shared with you yet." /> : (
                    <ul className="divide-y rounded-lg border">
                        {meetings.map((m) => (
                            <li key={m.id} className="grid gap-2 px-3 py-3 text-sm sm:grid-cols-[1fr_auto] sm:items-start">
                                <div>
                                    <p className="flex flex-wrap items-center gap-2">
                                        <span className="font-semibold">{m.type_label}</span>
                                        <span className="text-muted-foreground tabular-nums">{formatDate(m.held_on)}</span>
                                        {m.attendees && <span className="text-muted-foreground">with {m.attendees}</span>}
                                    </p>
                                    <p className="mt-1 whitespace-pre-line">{m.notes}</p>
                                    {m.actions && <p className="mt-1 whitespace-pre-line"><span className="font-semibold">Actions:</span> {m.actions}</p>}
                                    <Acknowledged at={m.acknowledged_at} comment={m.employee_comment} who="You" />
                                </div>
                                <div className="sm:text-right">{button(`/me/meetings/${m.id}/acknowledge`, `${m.type_label}, ${formatDate(m.held_on)}`, m.acknowledged_at)}</div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
            {ack && <AcknowledgeDialog url={ack.url} title={ack.title} onClose={() => setAck(null)} />}
        </div>
    );
}
