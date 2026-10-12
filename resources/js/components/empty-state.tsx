import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

/** Nothing to show yet: what that means, and (optionally) the one thing to do about it. */
export function EmptyState({ title, children, icon: Icon, action }: { title: string; children?: ReactNode; icon?: LucideIcon; action?: ReactNode }) {
    return (
        <div className="grid justify-items-center rounded-lg border border-dashed px-6 py-12 text-center">
            {Icon && (
                <span className="mb-3 inline-flex size-11 items-center justify-center rounded-full bg-accent text-cascade">
                    <Icon className="size-5" aria-hidden />
                </span>
            )}
            <p className="font-semibold">{title}</p>
            {children && <div className="mt-1 max-w-md text-sm text-muted-foreground">{children}</div>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}
