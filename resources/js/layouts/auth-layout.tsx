import type { ReactNode } from 'react';
import { AppLogo } from '@/components/app-sidebar';
import { useFlash } from '@/components/flash-toaster';

export default function AuthLayout({ children }: { children: ReactNode }) {
    useFlash();
    return (
        <div className="bg-blueprint relative isolate flex min-h-svh flex-col items-center justify-center overflow-hidden px-4 py-10">
            {/* The oversized faded word from the website hero */}
            <span aria-hidden className="pointer-events-none absolute inset-x-0 top-1/2 -z-10 -translate-y-1/2 text-center font-display text-[22vw] leading-none font-bold tracking-tight text-cascade/[0.04] select-none">
                CASCADE
            </span>
            <div className="w-full max-w-sm">
                <div className="mb-6 flex justify-center">
                    <AppLogo variant="full" />
                </div>
                <div className="rounded-lg border bg-white p-6 shadow-sm sm:p-8">{children}</div>
            </div>
        </div>
    );
}
