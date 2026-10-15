<?php

namespace App\Services\Import;

use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeps attendance links across a fresh import. Punches are stored against machine numbers and HR.my IDs, not
 * employees, so they survive the wipe; the links on the employee records do not. capture() reads them before the
 * wipe, apply() puts them on the new records by name.
 *
 *  - Sure name match (alias, exact, normalized, spelling): carried.
 *  - Similar spelling (fuzzy, subset): carried, and the new record is marked for review.
 *  - Ambiguous, but only one of the people is in the old record's company: carried there, marked for review.
 *  - Otherwise ambiguous: not carried; every person it could be is marked for review.
 *  - No match: not carried; reported with the numbers so HR can set them by hand (or put them in links.csv).
 *
 * Clock-ins made in the app store the employee id as the device user, so they are moved to the new id too.
 * A machine number without a device gets the device (ATD-###) its most recent punch came from.
 * Logins tied to an employee (self-service, HR) are tied to the new record the same way: sure or company match only.
 */
class LinkCarryOver
{
    public const SHEET = 'Carry-over';

    private const LINKS = ['attendance_machine_id', 'hr_portal_id', 'attendance_device_id', 'attendance_machine'];

    /** @var list<array<string, mixed>> */
    private array $old = [];

    /** @var list<array{user_id: int, email: string, employee_code: string, full_name: string, company: ?string}> */
    private array $logins = [];

    public function capture(): void
    {
        $withApp = AttendancePunch::query()->where('source', 'app')->distinct()->pluck('device_user_id')->map(fn ($v) => (int) $v)->all();
        $this->old = Employee::query()
            ->where(fn ($q) => $q->whereNotNull('attendance_machine_id')->orWhereNotNull('hr_portal_id')->orWhereIn('id', $withApp))
            ->orderBy('id')
            ->with('company:id,name')
            ->get(['id', 'company_id', 'employee_code', 'full_name', ...self::LINKS])
            ->map(fn (Employee $e) => $e->only(['id', 'employee_code', 'full_name', ...self::LINKS])
                + ['app' => in_array($e->id, $withApp, true), 'company' => $e->company?->name])
            ->all();

        foreach ($this->old as &$old) {
            if ($old['attendance_machine_id'] !== null && $old['attendance_device_id'] === null) {
                $old['attendance_device_id'] = AttendancePunch::deviceFor((string) $old['attendance_machine_id']);
            }
        }
        unset($old);

        $this->logins = User::query()->whereNotNull('employee_id')->with('employee.company:id,name')->orderBy('id')->get()
            ->filter(fn (User $u) => $u->employee)
            ->map(fn (User $u) => ['user_id' => $u->id, 'email' => $u->email, 'employee_code' => $u->employee->employee_code,
                'full_name' => $u->employee->full_name, 'company' => $u->employee->company?->name])
            ->values()->all();
    }

    public function count(): int
    {
        return count($this->old);
    }

    /**
     * @param  callable(string $sheet, int $row, string $name, string $result, string $match, ?Employee $e, string $detail): void  $log
     * @return array<string, int>
     */
    public function apply(NameMatcher $matcher, callable $log): array
    {
        $stats = [];
        $bump = function (string $k) use (&$stats) {
            $stats[$k] = ($stats[$k] ?? 0) + 1;
        };
        $remap = [];

        foreach ($this->old as $i => $old) {
            $links = array_filter(array_intersect_key($old, array_flip(self::LINKS)), fn ($v) => $v !== null && $v !== '');
            $what = $this->describe($old);
            $m = $this->find($matcher, $old['full_name'], $old['company']);
            if (! in_array($m['type'], ['alias', 'exact', 'normalized', 'spelling', 'fuzzy', 'subset', 'company'], true) || ! $m['ids']) {
                $ambiguous = $m['type'] === 'ambiguous';
                $codes = $ambiguous ? Employee::query()->whereIn('id', $m['ids'])->orderBy('employee_code')->pluck('employee_code')->implode(', ') : '';
                if ($ambiguous) {
                    self::flag($m['ids'], "Old record {$old['employee_code']} \"{$old['full_name']}\" ($what) could be this person or another with the same name ($codes). Its links were not carried: set them on the right person.");
                }
                $why = $ambiguous ? "more than one person has this name ($codes)" : 'nobody on the new list has this name';
                $log(self::SHEET, $i + 1, $old['full_name'], 'link_not_carried', $m['type'], null,
                    "Old {$old['employee_code']}: $why. Not carried: $what. Set it on the right person by hand.");
                $bump('links_not_carried');

                continue;
            }

            $employee = Employee::find($m['ids'][0]);
            $taken = [];
            $fill = [];
            foreach ($links as $field => $value) {
                $current = $employee->$field;
                if ($current === null || $current === '') {
                    $fill[$field] = $value;
                } elseif ((string) $current !== (string) $value) {
                    $taken[] = "$field already $current (kept), old record had $value";
                }
            }
            $sure = in_array($m['type'], ['alias', 'exact', 'normalized', 'spelling'], true);
            if (! $sure) {
                $how = $m['type'] === 'company' ? 'by name and company (another person has the same name)' : 'by similar spelling';
                $note = "Attendance links carried from old record {$old['employee_code']} \"{$old['full_name']}\" $how. Check it is the same person.";
                $fill['needs_review'] = true;
                $fill['review_note'] = $employee->review_note ? $employee->review_note.' | '.$note : $note;
            }
            if ($fill) {
                $employee->update($fill);
            }
            if ($old['app']) {
                $remap[(string) $old['id']] = (string) $employee->id;
            }
            $log(self::SHEET, $i + 1, $old['full_name'], $taken ? 'link_conflict' : ($sure ? 'link_carried' : 'link_check'), $m['type'], $employee,
                "Old {$old['employee_code']}: $what".($taken ? '. '.implode('; ', $taken) : ''));
            $bump($taken ? 'link_conflicts' : ($sure ? 'links_carried' : 'links_carried_check'));
        }

        // Two steps, so an old id that equals another person's new id is never mixed up.
        foreach ($remap as $from => $to) {
            DB::table('attendance_punches')->where('source', 'app')->where('device_user_id', $from)->update(['device_user_id' => "moving:$to"]);
        }
        foreach ($remap as $to) {
            DB::table('attendance_punches')->where('source', 'app')->where('device_user_id', "moving:$to")->update(['device_user_id' => $to]);
        }
        $stats['app_clock_in_people_moved'] = count($remap);

        foreach ($this->logins as $i => $login) {
            $m = $this->find($matcher, $login['full_name'], $login['company']);
            $employee = in_array($m['type'], ['alias', 'exact', 'normalized', 'spelling', 'company'], true) ? Employee::find($m['ids'][0]) : null;
            $taken = $employee ? User::query()->where('employee_id', $employee->id)->value('email') : null;
            if (! $employee || $taken) {
                $why = $taken ? "{$employee->employee_code} is already the login of $taken"
                    : ($m['type'] === 'ambiguous' ? 'more than one person has this name' : ($m['ids'] ? 'only a similar name' : 'nobody on the new list has this name'));
                $log(self::SHEET, $i + 1, $login['email'], 'login_not_linked', $m['type'], null,
                    "Login {$login['email']} was {$login['employee_code']} \"{$login['full_name']}\": $why. Link it under Settings > Users (or add the name to aliases.csv).");
                $bump('logins_not_linked');

                continue;
            }
            User::query()->whereKey($login['user_id'])->update(['employee_id' => $employee->id]);
            $log(self::SHEET, $i + 1, $login['email'], 'login_linked', $m['type'], $employee, "Login {$login['email']} (was {$login['employee_code']})");
            $bump('logins_linked');
        }

        return $stats;
    }

    /** Name match; a tie between namesakes goes to the one in $company, if only one is. */
    private function find(NameMatcher $matcher, string $name, ?string $company): array
    {
        $m = $matcher->match($name);
        if ($m['type'] === 'ambiguous' && $company) {
            $same = Employee::query()->whereIn('id', $m['ids'])->whereHas('company', fn ($q) => $q->where('name', $company))->pluck('id')->all();
            if (count($same) === 1) {
                return ['type' => 'company', 'ids' => $same, 'score' => 0.9];
            }
            if ($same) {
                $m['ids'] = $same;      // still ambiguous; only the namesakes in that company are flagged
            }
        }

        return $m;
    }

    /** Mark each employee for review with $note (added to what is there). */
    public static function flag(array $ids, string $note): void
    {
        foreach (Employee::query()->whereIn('id', $ids)->get() as $e) {
            if (! str_contains((string) $e->review_note, $note)) {
                $e->update(['needs_review' => true, 'review_note' => $e->review_note ? $e->review_note.' | '.$note : $note]);
            }
        }
    }

    private function describe(array $old): string
    {
        return implode(', ', array_filter([
            $old['attendance_machine_id'] ? "machine no. {$old['attendance_machine_id']}" : null,
            $old['hr_portal_id'] ? "HR.my ID {$old['hr_portal_id']}" : null,
            $old['app'] ? 'app clock-ins' : null,
        ])) ?: 'no links';
    }
}
