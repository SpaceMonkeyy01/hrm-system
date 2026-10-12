import { ChevronDown } from 'lucide-react';
import * as React from 'react';
import { cn } from '@/lib/utils';

type Option = { value: string | number; label: string };

/** A plain <select>: keyboard friendly, works with empty values, no portal. Used for filters and forms. */
export function NativeSelect({
    options,
    placeholder,
    className,
    ...props
}: Omit<React.ComponentProps<'select'>, 'children'> & { options: Option[]; placeholder?: string }) {
    return (
        <div className={cn('relative', className)}>
            <select
                className="h-9 w-full appearance-none rounded-md border border-input bg-background py-1 pr-8 pl-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30 focus-visible:outline-none disabled:opacity-60 aria-invalid:border-destructive"
                {...props}
            >
                {placeholder !== undefined && <option value="">{placeholder}</option>}
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
            <ChevronDown className="pointer-events-none absolute top-1/2 right-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
        </div>
    );
}

export const toOptions = (values: string[]): Option[] => values.map((v) => ({ value: v, label: v }));
