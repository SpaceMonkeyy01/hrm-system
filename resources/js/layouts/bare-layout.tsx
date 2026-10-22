import type { ReactNode } from 'react';
import { useFlash } from '@/components/flash-toaster';

/** A page that draws the whole screen itself (the login scene). Only the flash messages come from here. */
export default function BareLayout({ children }: { children: ReactNode }) {
    useFlash();
    return <>{children}</>;
}
