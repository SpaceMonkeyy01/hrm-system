import '@fontsource-variable/atkinson-hyperlegible-next';
import '@fontsource-variable/jost';
import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { FlashToaster } from '@/components/flash-toaster';
import { TooltipProvider } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';

const appName = import.meta.env.VITE_APP_NAME || 'HRM System';

// Every file in ./pages is a page. The controller's Inertia::render('employees/show')
// loads ./pages/employees/show.tsx. Bundled up front (eager) - it's a small internal app.
const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx', { eager: true });

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => {
        const page = pages[`./pages/${name}.tsx`];
        if (!page) {
            throw new Error(`Page component not found: resources/js/pages/${name}.tsx`);
        }
        return page;
    },
    layout: (name) => (name.startsWith('auth/') ? AuthLayout : AppLayout),
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={300}>
                {app}
                <FlashToaster />
            </TooltipProvider>
        );
    },
    progress: { color: '#00435C', delay: 150 },
});
