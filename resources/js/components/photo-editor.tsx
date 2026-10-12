import { router } from '@inertiajs/react';
import { Camera } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { Avatar } from '@/components/avatar';
import { cn } from '@/lib/utils';

/**
 * The avatar with a camera badge: click to choose a new photo (cropped to a square on the server).
 * `uploadUrl` is /employees/{id}/photo for HR or /me/photo for the person; `removeUrl` only for HR.
 */
export function PhotoEditor({ name, photo, uploadUrl, removeUrl, className }: {
    name: string; photo: string | null; uploadUrl: string; removeUrl?: string; className?: string;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const upload = (file: File) => {
        if (file.size > 5 * 1024 * 1024) {
            toast.error('The picture is larger than 5 MB.');
            return;
        }
        setBusy(true);
        router.post(uploadUrl, { photo: file }, {
            forceFormData: true, preserveScroll: true,
            onError: (e) => toast.error(Object.values(e)[0] ?? 'Not saved.'),
            onFinish: () => setBusy(false),
        });
    };
    return (
        <span className="group relative inline-flex shrink-0">
            <button type="button" onClick={() => input.current?.click()} disabled={busy} aria-label={photo ? `Change ${name}'s photo` : `Add a photo of ${name}`}
                className="rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                <Avatar name={name} photo={photo} className={cn(className, busy && 'opacity-50')} />
                <span className="absolute -right-1 -bottom-1 inline-flex size-5 items-center justify-center rounded-full border-2 border-background bg-cascade text-white">
                    <Camera className="size-3" aria-hidden />
                </span>
            </button>
            {photo && removeUrl && (
                <button type="button" onClick={() => confirm(`Remove ${name}'s photo?`) && router.delete(removeUrl, { preserveScroll: true })}
                    className="absolute top-full left-1/2 mt-1 hidden -translate-x-1/2 text-[10px] whitespace-nowrap text-muted-foreground underline group-hover:block group-focus-within:block">
                    Remove
                </button>
            )}
            <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" tabIndex={-1}
                onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) upload(f); }} />
        </span>
    );
}
