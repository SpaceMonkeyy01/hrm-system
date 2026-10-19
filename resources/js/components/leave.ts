import { formatDate } from '@/lib/format';

/** app/Http/Controllers/LeaveController.php recordProps() */
export type LeaveRecordRow = {
    id: number; employee: { id: number; name: string; code: string; department: string | null } | null; type: string;
    start_date: string; end_date: string; part: 'full' | 'first_half' | 'second_half' | 'short'; days: number; note: string | null; source: string; by: string | null;
};

/** app/Services/LeaveBalances.php forEmployees(): one yearly leave type for one person */
export type LeaveBalance = { type_id: number; type: string; allowance: number; used: number; deducted: number; left: number };
/** LeaveBalances summary(): where the deductions came from */
export type LeaveDeductions = { absent: number; late_early: number; sheet: number; days: number; from?: string | null };
/** Leave counted outside the app (HR's sheet) */
export type LeaveCounted = { type: string; days: number; note: string | null };

/** "Used 3, deducted 1 of 14" (hover text on a balance) */
export function balanceTitle(b: LeaveBalance): string {
    return `Used ${b.used}${b.deducted ? `, deducted ${b.deducted}` : ''} of ${b.allowance}`;
}

/** "2 absences, 3 late / early since 1 Oct 2026, 1.5 from HR's sheet" */
export function deductionText(d: LeaveDeductions): string {
    return [d.absent && `${d.absent} ${d.absent === 1 ? 'absence' : 'absences'}`, d.late_early && `${d.late_early} late / early`, d.sheet && `${d.sheet} from HR's sheet`]
        .filter(Boolean).join(', ');
}

/** "3 of 14 left", "2 over" */
export function balanceText(b: LeaveBalance): string {
    return b.left >= 0 ? `${b.left} of ${b.allowance} left` : `${-b.left} over`;
}

/** "12 May 2026 to 15 May 2026", or "12 May 2026, first half" */
export function leaveDates(r: Pick<LeaveRecordRow, 'start_date' | 'end_date' | 'part'>): string {
    const range = r.start_date === r.end_date ? formatDate(r.start_date) : `${formatDate(r.start_date)} to ${formatDate(r.end_date)}`;
    return r.part === 'full' ? range : `${range}, ${partLabel(r.part)}`;
}

/** "first half", "second half", "short leave" */
export function partLabel(part: string): string {
    return part === 'first_half' ? 'first half' : part === 'second_half' ? 'second half' : part === 'short' ? 'short leave' : 'full day';
}
