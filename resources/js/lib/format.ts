// Dates arrive from Laravel as "YYYY-MM-DD" (date columns) or ISO 8601 (timestamps).

const day = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
const dayTime = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

/** "2026-02-19" -> "19 Feb 2026" */
export function formatDate(value: string | null | undefined): string {
    if (!value) return '';
    const [y, m, d] = value.slice(0, 10).split('-').map(Number);
    return day.format(new Date(y, m - 1, d));
}

/** ISO timestamp -> "8 Oct, 14:05" */
export function formatDateTime(value: string | null | undefined): string {
    return value ? dayTime.format(new Date(value)) : '';
}

/**
 * Whole days from the browser's today to a "YYYY-MM-DD" date (negative = in the past).
 * @deprecated Uses the browser clock, which can disagree with the server's lists. Use `daysBetween(props.today, value)`.
 */
export function daysFromToday(value: string): number {
    const [y, m, d] = value.split('-').map(Number);
    const target = new Date(y, m - 1, d).getTime();
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
    return Math.round((target - today) / 86_400_000);
}

/**
 * Whole days from one "YYYY-MM-DD" date to another (negative = `to` is before `from`).
 * Counts calendar days in UTC, so the browser's timezone and DST never shift the answer.
 * Pass the server's `today` prop as `from` so labels agree with the server's lists.
 */
export function daysBetween(from: string, to: string): number {
    const utc = (value: string) => {
        const [y, m, d] = value.slice(0, 10).split('-').map(Number);
        return Date.UTC(y, m - 1, d);
    };
    return Math.round((utc(to) - utc(from)) / 86_400_000);
}

export function plural(n: number, one: string, many = `${one}s`): string {
    return `${n} ${n === 1 ? one : many}`;
}

export function percent(part: number, whole: number): string {
    return whole === 0 ? '0%' : `${Math.round((part / whole) * 100)}%`;
}
