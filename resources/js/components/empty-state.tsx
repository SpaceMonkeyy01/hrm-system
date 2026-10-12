import type { ReactNode } from 'react';

export function EmptyState({ title, children }: { title: string; children?: ReactNode }) {
    return (
        <div className="rounded-lg border border-dashed px-6 py-12 text-center">
            <p className="font-semibold">{title}</p>
            {children && <div className="mt-1 text-sm text-muted-foreground">{children}</div>}
        </div>
    );
}
