import '@fontsource-variable/atkinson-hyperlegible-next';
import '@fontsource-variable/jost';
import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { FlashToaster } from '@/components/flash-toaster';
import { TooltipProvider } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import LoginScene from '@/layouts/login-scene';
import PrintLayout from '@/layouts/print-layout';

const appName = import.meta.env.VITE_APP_NAME || 'HRM System';

// Every file in ./pages is a page. The controller's Inertia::render('employees/show')
// loads ./pages/employees/show.tsx. Each page is its own file, loaded when first opened;
// app.blade.php also names the current page so the first load fetches both at once.
const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx');

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name) => {
        const load = pages[`./pages/${name}.tsx`];
        if (!load) {
            throw new Error(`Page component not found: resources/js/pages/${name}.tsx`);
        }
        return (await load()).default;
    },
    // Log in, forgot and reset password share the night scene with the live clock (layouts/login-scene.tsx).
    layout: (name) => (name.startsWith('auth/') ? LoginScene : name.startsWith('print/') ? PrintLayout : AppLayout),
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
