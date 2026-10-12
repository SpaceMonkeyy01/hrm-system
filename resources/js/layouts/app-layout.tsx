import { usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import type { ReactNode } from 'react';
import { AppSidebar } from '@/components/app-sidebar';
import { EmployeeSearchProvider, useEmployeeSearch } from '@/components/employee-search';
import { useFlash } from '@/components/flash-toaster';
import { Button } from '@/components/ui/button';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import type { SharedProps } from '@/types';

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
