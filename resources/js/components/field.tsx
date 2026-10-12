import type { ReactNode } from 'react';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/** Label + control + hint + error, stacked. */
export function Field({ label, htmlFor, error, hint, children, className }: {
    label: string; htmlFor?: string; error?: string; hint?: ReactNode; children: ReactNode; className?: string;
}) {
    return (
        <div className={cn('grid content-start gap-1.5', className)}>
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            {hint && !error && <p className="text-xs text-muted-foreground">{hint}</p>}
            {error && <p className="text-sm text-missing">{error}</p>}
        </div>
    );
}

/** Read-only label/value pair for detail pages. */
export function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm break-words">{children || <span className="text-muted-foreground/70 italic">Not recorded</span>}</dd>
        </div>
    );
}
