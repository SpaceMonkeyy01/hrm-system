import { router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { type ReactNode, useEffect } from 'react';
import { TRAIL_EVENT } from '@/components/breadcrumbs';
import { AppSidebar } from '@/components/app-sidebar';
import { EmployeeSearchProvider, useEmployeeSearch } from '@/components/employee-search';
import { useFlash } from '@/components/flash-toaster';
import { Button } from '@/components/ui/button';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import { crumbTitle, recordPage, rememberScroll, retitle } from '@/lib/trail';
import type { SharedProps } from '@/types';

/** Keeps the breadcrumb trail (lib/trail.ts): records each page shown and where the last one was scrolled to. */
function useTrail() {
    const { url, component } = usePage();
    useEffect(() => router.on('before', () => rememberScroll()), []);
    useEffect(() => {
        const path = new URL(url, 'http://x').pathname;
        const scrollY = recordPage(url, component, crumbTitle(document.title));
        window.dispatchEvent(new Event(TRAIL_EVENT));
        if (scrollY !== null) requestAnimationFrame(() => window.scrollTo(0, scrollY));
        // Inertia's <Head> sets the title a moment later: follow it, so the crumb reads "Ali Asghar" and not
        // the previous page's title.
        const sync = () => {
            retitle(path, crumbTitle(document.title));
            window.dispatchEvent(new Event(TRAIL_EVENT));
        };
        const observer = new MutationObserver(sync);
        observer.observe(document.head, { childList: true, subtree: true, characterData: true });
        const t = setTimeout(sync, 50);
        return () => {
            observer.disconnect();
            clearTimeout(t);
        };
    }, [url, component]);
}

/** Phones: menu on the left, search on the right. */
function PhoneBar() {
    const { auth } = usePage<SharedProps>().props;
    const search = useEmployeeSearch();
    return (
        <div className="flex h-11 items-center justify-between px-3 md:hidden">
            <SidebarTrigger />
            {auth.can.view && <Button variant="ghost" size="icon" aria-label="Find an employee" onClick={search.open}><Search /></Button>}
        </div>
    );
}

/** Sidebar on the left, the page on a white sheet to the right. */
export default function AppLayout({ children }: { children: ReactNode }) {
    const { sidebarOpen } = usePage<SharedProps>().props;
    useFlash();
    useTrail();

    return (
        <EmployeeSearchProvider>
            <SidebarProvider defaultOpen={sidebarOpen}>
                <AppSidebar />
                <SidebarInset className="min-w-0 overflow-x-clip">
                    <PhoneBar />
                    <main className="flex min-w-0 flex-1 flex-col">{children}</main>
                </SidebarInset>
            </SidebarProvider>
        </EmployeeSearchProvider>
    );
}
