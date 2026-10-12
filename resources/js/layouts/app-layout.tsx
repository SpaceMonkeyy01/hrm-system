import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AppSidebar } from '@/components/app-sidebar';
import { useFlash } from '@/components/flash-toaster';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import type { SharedProps } from '@/types';

/** Sidebar on the left, the page on a white sheet to the right. */
export default function AppLayout({ children }: { children: ReactNode }) {
    const { sidebarOpen } = usePage<SharedProps>().props;
    useFlash();

    return (
        <SidebarProvider defaultOpen={sidebarOpen}>
            <AppSidebar />
            <SidebarInset className="min-w-0 overflow-x-clip">
                <div className="flex h-11 items-center px-3 md:hidden">
                    <SidebarTrigger />
                </div>
                <main className="flex min-w-0 flex-1 flex-col">{children}</main>
            </SidebarInset>
        </SidebarProvider>
    );
}
