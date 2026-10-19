import { formatDate } from '@/lib/format';

/** app/Http/Controllers/LeaveController.php recordProps() */
export type LeaveRecordRow = {
    id: number; employee: { id: number; name: string; code: string; department: string | null } | null; type: string;
    start_date: string; end_date: string; part: 'full' | 'first_half' | 'second_half'; days: number; note: string | null; source: string; by: string | null;
};

/** app/Services/LeaveBalances.php forEmployees(): one yearly leave type for one person */
export type LeaveBalance = { type_id: number; type: string; allowance: number; used: number; left: number };

/** "3 of 14 left", "2 over" */
export function balanceText(b: LeaveBalance): string {
    return b.left >= 0 ? `${b.left} of ${b.allowance} left` : `${-b.left} over`;
}

/** "12 May 2026 to 15 May 2026", or "12 May 2026, first half" */
export function leaveDates(r: Pick<LeaveRecordRow, 'start_date' | 'end_date' | 'part'>): string {
    const range = r.start_date === r.end_date ? formatDate(r.start_date) : `${formatDate(r.start_date)} to ${formatDate(r.end_date)}`;
    return r.part === 'full' ? range : `${range}, ${r.part === 'first_half' ? 'first' : 'second'} half`;
}
