import type { ReactNode } from 'react';
import { useFlash } from '@/components/flash-toaster';

/** Pages meant for paper (print/*): no sidebar or header, just the page. */
export default function PrintLayout({ children }: { children: ReactNode }) {
    useFlash();
    return <>{children}</>;
}
