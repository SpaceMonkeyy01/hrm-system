import type { ReactNode } from 'react';
import { useFlash } from '@/components/flash-toaster';
import { APP_CREDIT } from '@/lib/app-info';

/**
 * Login, forgot and reset password. After the bluecascade.org hero: a navy brand panel with the wireframe in white
 * and the logo (desktop: left half; phones: a band above the form), then the form on the light wireframe.
 */
export default function AuthLayout({ children }: { children: ReactNode }) {
    useFlash();
    const year = new Date().getFullYear();
    return (
        <div className="grid min-h-svh grid-cols-[minmax(0,1fr)] grid-rows-[auto_1fr] lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] lg:grid-rows-1">
            <aside className="bg-blueprint-dark relative flex flex-col items-center justify-center px-6 py-10 lg:py-12">
                <img src="/images/logo-white.png" alt="Blue Cascade" width={800} height={519} className="h-auto w-40 sm:w-48 lg:w-72" />
                <p className="absolute bottom-8 hidden text-xs text-white/60 lg:block">© {year} Blue Cascade. {APP_CREDIT}</p>
            </aside>
            <main className="bg-blueprint flex items-center justify-center px-4 py-10 sm:px-8">
                <div className="w-full max-w-sm">
                    <div className="rounded-xl border bg-white p-6 shadow-sm sm:p-8">{children}</div>
                    <p className="mt-4 text-center text-xs text-muted-foreground lg:hidden">© {year} Blue Cascade. {APP_CREDIT}</p>
                </div>
            </main>
        </div>
    );
}
