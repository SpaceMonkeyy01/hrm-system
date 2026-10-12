import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field } from '@/components/field';
import type { Cycle } from '@/components/reviews';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

/** Create or edit a review cycle's name, period and due dates. */
export function CycleForm({ cycle, onClose }: { cycle?: Cycle; onClose: () => void }) {
    const year = new Date().getFullYear();
    const form = useForm({
        name: cycle?.name ?? `${year} mid-year review`, period_start: cycle?.period_start ?? `${year}-01-01`, period_end: cycle?.period_end ?? `${year}-06-30`,
        self_due: cycle?.self_due ?? '', manager_due: cycle?.manager_due ?? '', notes: cycle?.notes ?? '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, self_due: d.self_due || null, manager_due: d.manager_due || null, notes: d.notes || null }));
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (cycle) form.put(`/reviews/${cycle.id}`, opts);
        else form.post('/reviews', opts);
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{cycle ? 'Edit review cycle' : 'New review cycle'}</DialogTitle>
                        <DialogDescription>The period is the stretch of work being reviewed. Due dates show on people's My page.</DialogDescription>
                    </DialogHeader>
                    <Field label="Name" htmlFor="c-name" error={form.errors.name}>
                        <Input id="c-name" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Period from" htmlFor="c-from" error={form.errors.period_start}>
                            <Input id="c-from" type="date" required value={form.data.period_start} onChange={(e) => form.setData('period_start', e.target.value)} />
                        </Field>
                        <Field label="Period to" htmlFor="c-to" error={form.errors.period_end}>
                            <Input id="c-to" type="date" required value={form.data.period_end} onChange={(e) => form.setData('period_end', e.target.value)} />
                        </Field>
                        <Field label="Self-reviews due" htmlFor="c-self" error={form.errors.self_due}>
                            <Input id="c-self" type="date" value={form.data.self_due} onChange={(e) => form.setData('self_due', e.target.value)} />
                        </Field>
                        <Field label="HR reviews due" htmlFor="c-mgr" error={form.errors.manager_due}>
                            <Input id="c-mgr" type="date" value={form.data.manager_due} onChange={(e) => form.setData('manager_due', e.target.value)} />
                        </Field>
                    </div>
                    <Field label="Notes for HR" htmlFor="c-notes" error={form.errors.notes}>
                        <Input id="c-notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                    </Field>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>{cycle ? 'Save' : 'Create cycle'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
