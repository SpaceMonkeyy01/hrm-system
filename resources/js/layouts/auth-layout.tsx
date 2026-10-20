import { CalendarCheck, Clock, FileText } from 'lucide-react';
import type { ReactNode } from 'react';
import { useFlash } from '@/components/flash-toaster';
import { APP_CREDIT } from '@/lib/app-info';

/** What staff and HR use it for: three short lines on the brand panel. */
const FEATURES = [
    { icon: Clock, text: 'Clock in and out, from the office or from home' },
    { icon: CalendarCheck, text: 'Leave, work from home and other requests' },
    { icon: FileText, text: 'Your record, letters and documents in one place' },
];

/**
 * Login, forgot and reset password. After the bluecascade.org hero: a navy brand panel with the wireframe in white
 * and the thin, widely spaced Jost headline (desktop: left half; phones: a band above the form), then the form.
 */
export default function AuthLayout({ children }: { children: ReactNode }) {
    useFlash();
    const year = new Date().getFullYear();
    return (
        <div className="grid min-h-svh grid-cols-[minmax(0,1fr)] grid-rows-[auto_1fr] lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] lg:grid-rows-1">
            <aside className="bg-blueprint-dark relative flex flex-col justify-between gap-8 px-6 py-8 text-white sm:px-10 lg:px-14 lg:py-12">
                <img src="/images/logo-white.png" alt="Blue Cascade" width={800} height={519} className="h-auto w-36 lg:w-44" />
                <div className="grid gap-4 lg:gap-6">
                    <p className="font-display text-3xl font-light tracking-[0.18em] uppercase sm:text-4xl lg:text-5xl">HRM System</p>
                    <p className="max-w-md text-sm tracking-[0.08em] text-white/80 uppercase sm:text-base">BCC and Emerge</p>
                    <ul className="mt-2 hidden gap-3 lg:grid">
                        {FEATURES.map(({ icon: Icon, text }) => (
                            <li key={text} className="flex items-center gap-3 text-white/85">
                                <span className="grid size-8 shrink-0 place-items-center rounded-md bg-white/10"><Icon className="size-4" /></span>
                                {text}
                            </li>
                        ))}
                    </ul>
                </div>
                <p className="hidden text-xs text-white/60 lg:block">© {year} Blue Cascade. {APP_CREDIT}</p>
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
