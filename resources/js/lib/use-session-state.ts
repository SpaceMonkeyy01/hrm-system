import { useEffect, useState } from 'react';

/**
 * useState that survives leaving the page and coming back in the same browser tab (sessionStorage).
 * For view state that isn't in the URL, so a breadcrumb back lands where you left off. Sets aren't JSON:
 * store arrays.
 */
export function useSessionState<T>(key: string, initial: T | (() => T)): [T, (v: T | ((prev: T) => T)) => void] {
    const [value, setValue] = useState<T>(() => {
        try {
            const raw = sessionStorage.getItem(`hrm.state.${key}`);
            if (raw !== null) return JSON.parse(raw) as T;
        } catch {
            // storage blocked: start fresh
        }
        return typeof initial === 'function' ? (initial as () => T)() : initial;
    });
    useEffect(() => {
        try {
            sessionStorage.setItem(`hrm.state.${key}`, JSON.stringify(value));
        } catch {
            // not remembered, the page still works
        }
    }, [key, value]);
    return [value, setValue];
}
