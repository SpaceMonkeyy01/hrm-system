import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

/** Build a query string from a filter object, dropping empty values. */
export function query(params: Record<string, unknown>): Record<string, string> {
    const out: Record<string, string> = {};
    for (const [k, v] of Object.entries(params)) {
        if (v !== null && v !== undefined && v !== '' && v !== false) {
            out[k] = String(v === true ? 1 : v);
        }
    }
    return out;
}
