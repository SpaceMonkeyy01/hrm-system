import { Eye, EyeOff } from 'lucide-react';
import { type ComponentProps, useState } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/** A password box with an eye button to show or hide what was typed. Takes every prop an Input does (type is fixed). */
export function PasswordInput({ className, ...props }: Omit<ComponentProps<typeof Input>, 'type'>) {
    const [shown, setShown] = useState(false);
    return (
        <div className="relative">
            <Input {...props} type={shown ? 'text' : 'password'} className={cn('pr-10', className)} />
            <button type="button" onClick={() => setShown((s) => !s)} disabled={props.disabled}
                className="absolute inset-y-0 right-0 grid w-10 place-items-center rounded-r-md text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50"
                aria-label={shown ? 'Hide password' : 'Show password'} aria-pressed={shown} title={shown ? 'Hide password' : 'Show password'}>
                {shown ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
            </button>
        </div>
    );
}
