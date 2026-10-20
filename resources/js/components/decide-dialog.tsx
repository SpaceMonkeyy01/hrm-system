import { router } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import { Field } from '@/components/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';

/**
 * Approve or turn down a request from My page: posts { note, ...extra } to `${url}/approve` or `${url}/reject`.
 * A reason is required to turn one down (the person sees it). children: extra fields for approving (e.g. a loan's first month).
 */
export function DecideDialog({ url, action, title, description, onClose, extra, children }: {
    url: string; action: 'approve' | 'reject'; title: string; description: string; onClose: () => void;
    extra?: Record<string, unknown>; children?: ReactNode;
}) {
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const submit = () => router.post(`${url}/${action}`, { note, ...(action === 'approve' ? extra : {}) }, { preserveScroll: true, onSuccess: onClose, onError: setErrors });
    const other = Object.entries(errors).filter(([k]) => k !== 'note').map(([, v]) => v);
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                {action === 'approve' && children}
                <Field label={action === 'approve' ? 'Note (optional)' : 'Reason'} htmlFor="decide-note" error={errors.note}>
                    <Textarea id="decide-note" rows={2} value={note} required={action === 'reject'} onChange={(e) => setNote(e.target.value)} />
                </Field>
                {other.map((m) => <p key={m} className="text-sm text-destructive">{m}</p>)}
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>Cancel</Button>
                    <Button variant={action === 'approve' ? 'default' : 'destructive'} onClick={submit}>{action === 'approve' ? 'Approve' : "Don't approve"}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
