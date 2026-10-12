import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast, Toaster } from 'sonner';
import type { SharedProps } from '@/types';

/** The toast container. Rendered once, outside the pages. */
export function FlashToaster() {
    return (
        <Toaster
            position="bottom-right"
            toastOptions={{ classNames: { toast: 'font-sans', error: '!text-missing', success: '!text-done' } }}
        />
    );
}

let lastShown: string | null = null;

/** Shows Laravel's ->with('success' | 'error', '...') messages as toasts, each one exactly once. */
export function useFlash() {
    const { flash } = usePage<SharedProps>().props;

    useEffect(() => {
        if (!flash?.id || flash.id === lastShown) return;
        lastShown = flash.id;
        if (flash.success) toast.success(flash.success);
        if (flash.error) toast.error(flash.error);
    }, [flash?.id, flash?.success, flash?.error]);
}
