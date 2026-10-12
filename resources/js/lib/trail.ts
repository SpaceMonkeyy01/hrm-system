/**
 * Breadcrumb trail, the way Odoo does it: the pages you drilled through to get here, kept for this browser tab
 * (sessionStorage), so "Org chart / Ali Asghar" can take you back to the chart as you left it.
 *
 *   - a page reached from the sidebar (a ROOT page) starts a new trail
 *   - any other page is added to the end, unless it is already in the trail (going back): then the trail is cut there
 *   - the same page with a different query (filters, month) replaces the last step instead of adding one
 *   - each step remembers its scroll position; going back to it scrolls there again
 *
 * Page state that isn't in the URL (the org chart's open boxes, zoom, view) is kept with useSessionState.
 */
export type Step = { url: string; path: string; title: string; scrollY: number };

const KEY = 'hrm.trail';
const RESTORE = 'hrm.trail.restore';

/** Inertia components the sidebar opens: they start a fresh trail. */
const ROOTS = new Set([
    'dashboard', 'overview', 'reports/index', 'employees/index', 'org-chart', 'onboarding/index', 'documents/index',
    'reviews/index', 'offboarding/index', 'attendance/index', 'attendance/schedules', 'leave/index', 'assets/index',
    'controlled-documents/index', 'settings/users', 'settings/structure', 'settings/checklists', 'letters/templates', 'me/show', 'account',
]);

function read<T>(key: string, fallback: T): T {
    try {
        const raw = sessionStorage.getItem(key);
        return raw ? (JSON.parse(raw) as T) : fallback;
    } catch {
        return fallback;
    }
}

function write(key: string, value: unknown): void {
    try {
        sessionStorage.setItem(key, JSON.stringify(value));
    } catch {
        // private mode or storage full: the trail just isn't kept
    }
}

export function getTrail(): Step[] {
    return read<Step[]>(KEY, []);
}

const pathOf = (url: string) => new URL(url, 'http://x').pathname;

/** Call once per page shown (AppLayout). Returns the scroll position to restore, if this was a step back. */
export function recordPage(url: string, component: string, title: string): number | null {
    const path = pathOf(url);
    let trail = getTrail();
    const at = trail.findIndex((s) => s.path === path);
    const step: Step = { url, path, title, scrollY: 0 };

    if (ROOTS.has(component) && at !== 0) {
        trail = [step];
    } else if (at >= 0) {
        // Back to a page already in the trail (or the same page with new filters): cut after it, keep its scroll.
        step.scrollY = trail[at].scrollY;
        trail = [...trail.slice(0, at), step];
    } else {
        trail = [...trail, step];
    }
    write(KEY, trail.slice(-8));

    const restore = read<string | null>(RESTORE, null);
    write(RESTORE, null);
    return restore === path ? step.scrollY : null;
}

/** The page's title arrived (Inertia sets it a moment after the page shows): put it on its crumb. */
export function retitle(path: string, title: string): void {
    const trail = getTrail();
    const last = trail[trail.length - 1];
    if (!last || last.path !== path || !title || last.title === title) return;
    last.title = title;
    write(KEY, trail);
}

/** Before leaving a page: remember how far down it was scrolled. */
export function rememberScroll(): void {
    const trail = getTrail();
    if (!trail.length) return;
    trail[trail.length - 1].scrollY = window.scrollY;
    write(KEY, trail);
}

/** Going back through a crumb: the next page restores its scroll position. */
export function markRestore(path: string): void {
    write(RESTORE, path);
}

/** Title of the page for its crumb: "Ali Asghar - HRM System" => "Ali Asghar". */
export function crumbTitle(documentTitle: string): string {
    return documentTitle.replace(/\s+-\s+[^-]+$/, '').trim() || documentTitle;
}
