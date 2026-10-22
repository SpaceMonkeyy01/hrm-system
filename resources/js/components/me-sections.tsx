import { Clock, FileText, HeartHandshake, House, Palmtree, Star, UserRound } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * The sections of My page, one page switched by the #hash (/me#leave). Staff logins get them as their sidebar and,
 * on phones, as a bottom bar (the first four plus More); HR logins with a record of their own see them as tabs.
 */
export type MeSection = { key: string; title: string; short?: string; icon: LucideIcon };

export const ME_SECTIONS: MeSection[] = [
    { key: 'today', title: 'Today', icon: House },
    { key: 'attendance', title: 'Attendance', icon: Clock },
    { key: 'leave', title: 'Leave', icon: Palmtree },
    { key: 'care', title: 'Employee care', short: 'Care', icon: HeartHandshake },
    { key: 'reviews', title: 'Reviews and feedback', icon: Star },
    { key: 'letters', title: 'Letters and policies', icon: FileText },
    { key: 'details', title: 'My details', icon: UserRound },
];

/** Old links (emails, bookmarks) still land: #requests was Employee care, #feedback and #documents were their own tabs. */
const ALIASES: Record<string, string> = { requests: 'care', feedback: 'reviews', documents: 'letters', calendar: 'leave' };

export function sectionFromHash(hash: string): string {
    const key = ALIASES[hash] ?? hash;
    return ME_SECTIONS.some((s) => s.key === key) ? key : 'today';
}

/** The current #hash without the "#", kept in step with the address bar. */
export function useHash(): string {
    const [hash, setHash] = useState(() => (typeof window === 'undefined' ? '' : window.location.hash.slice(1)));
    useEffect(() => {
        const update = () => setHash(window.location.hash.slice(1));
        window.addEventListener('hashchange', update);
        update();
        return () => window.removeEventListener('hashchange', update);
    }, []);
    return hash;
}

/** Go to a section: sets the hash (back button works) and scrolls to the top. */
export function openSection(key: string) {
    if (window.location.pathname !== '/me') {
        window.location.href = `/me#${key}`;
        return;
    }
    window.location.hash = key;
    window.scrollTo({ top: 0 });
}
