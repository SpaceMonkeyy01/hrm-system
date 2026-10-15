<?php

namespace App\Services;

use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Services\Import\NameMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who is linked to which machine number and HR.my ID (Attendance > Links, and the upload page). A link is a field on
 * the employee; punches are stored against the number, so linking once connects every punch, past and future.
 * App clock-ins need no link: they carry the employee id.
 */
class AttendanceLinks
{
    /** Device source => the employee field that holds that source's id for the person. */
    public const FIELDS = ['machine' => 'attendance_machine_id', 'hrmy' => 'hr_portal_id'];

    public const NOUNS = ['machine' => 'machine number', 'hrmy' => 'HR.my ID'];

    /** A link with no punch for this many days is listed as "no recent punches". */
    public const STALE_DAYS = 14;

    /** @return Collection<int, Employee> current staff, by name */
    public static function people(): Collection
    {
        return Employee::query()->whereIn('status', ['active', 'offboarding'])
            ->with(['company:id,name', 'attendanceDevice:id,code,name'])
            ->orderBy('full_name')
            ->get(['id', 'system_id', 'employee_code', 'full_name', 'company_id', 'work_mode', 'attendance_machine_id', 'hr_portal_id', 'attendance_device_id', 'photo_path']);
    }

    /**
     * Last day with a punch, per source and device user id.
     *
     * @return array{machine: array<string, string>, hrmy: array<string, string>, app: array<string, string>}
     */
    public static function lastPunches(): array
    {
        $out = ['machine' => [], 'hrmy' => [], 'app' => []];
        $rows = AttendancePunch::query()->selectRaw('source, device_user_id, max(work_date) as last')
            ->groupBy('source', 'device_user_id')->toBase()->get();
        foreach ($rows as $r) {
            $out[$r->source][(string) $r->device_user_id] = substr((string) $r->last, 0, 10);
        }

        return $out;
    }

    /**
     * One row per current employee for the Links table.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        $last = self::lastPunches();
        $staleBefore = CarbonImmutable::today()->subDays(self::STALE_DAYS)->toDateString();

        return self::people()->map(function (Employee $e) use ($last, $staleBefore) {
            $seen = [
                'machine' => $e->attendance_machine_id ? ($last['machine'][(string) $e->attendance_machine_id] ?? null) : null,
                'hrmy' => $e->hr_portal_id ? ($last['hrmy'][(string) $e->hr_portal_id] ?? null) : null,
                'app' => $last['app'][(string) $e->id] ?? null,
            ];
            $latest = max(array_filter($seen) ?: [null]);
            $linked = $e->attendance_machine_id || $e->hr_portal_id;
            $issues = array_values(array_filter([
                ! $e->attendance_machine_id ? 'no_machine' : null,
                ! $e->hr_portal_id ? 'no_hrmy' : null,
                ! $linked && ! $seen['app'] ? 'nothing' : null,
                ($linked || $seen['app']) && (! $latest || $latest < $staleBefore) ? 'stale' : null,
            ]));

            return [
                'id' => $e->id, 'code' => $e->employee_code, 'name' => $e->full_name, 'company' => $e->company?->name,
                'company_id' => $e->company_id, 'work_mode' => $e->work_mode, 'photo' => Employee::photoUrlFor($e->id, $e->photo_path),
                'machine' => $e->attendance_machine_id, 'hrmy' => $e->hr_portal_id,
                'device_id' => $e->attendance_device_id, 'device' => $e->attendanceDevice?->code,
                'last' => $seen, 'latest' => $latest, 'issues' => $issues,
            ];
        })->values()->all();
    }

    /**
     * Machine numbers and HR.my IDs that have punches but no person, with the person their name points to.
     * confidence: match (sure name match), check (similar), several (same name twice), none.
     *
     * @return list<array<string, mixed>>
     */
    public static function unlinked(?Collection $people = null): array
    {
        $people ??= self::people();
        $out = [];
        foreach (self::FIELDS as $source => $field) {
            $matcher = new NameMatcher;
            foreach ($people->whereNull($field) as $e) {
                $matcher->add($e->id, $e->full_name, $e->employee_code);
            }
            foreach (self::unlinkedNumbers($source) as $d) {
                $m = $d->device_name ? $matcher->match($d->device_name) : ['type' => 'none', 'ids' => []];
                $sure = in_array($m['type'], ['alias', 'exact', 'normalized', 'spelling'], true);
                $out[] = [
                    'source' => $source, 'device_user_id' => (string) $d->device_user_id, 'device_name' => $d->device_name,
                    'punches' => (int) $d->punches, 'last_seen' => substr((string) $d->last_seen, 0, 10),
                    'device_id' => $d->device_id !== null ? (int) $d->device_id : null,
                    'suggestion' => count($m['ids']) === 1 ? $m['ids'][0] : null,
                    'candidates' => $m['type'] === 'ambiguous' ? $m['ids'] : [],
                    'confidence' => count($m['ids']) === 1 ? ($sure ? 'match' : 'check') : ($m['type'] === 'ambiguous' ? 'several' : 'none'),
                ];
            }
        }
        usort($out, fn ($a, $b) => [$a['source'], $a['confidence'] !== 'match', $b['last_seen'], $a['device_user_id']]
            <=> [$b['source'], $b['confidence'] !== 'match', $a['last_seen'], $b['device_user_id']]);

        return $out;
    }

    public static function unlinkedCount(): int
    {
        return self::unlinkedNumbers('machine')->count() + self::unlinkedNumbers('hrmy')->count();
    }

    /**
     * Per synced device: how many machine numbers punched on it, and how many of those belong to someone.
     *
     * @return array<int, array{numbers: int, linked: int}>
     */
    public static function deviceSummary(): array
    {
        $linked = array_flip(Employee::query()->whereNotNull('attendance_machine_id')->pluck('attendance_machine_id')->map(fn ($v) => (string) $v)->all());
        $out = [];
        $rows = AttendancePunch::query()->where('source', 'machine')->whereNotNull('attendance_device_id')
            ->select('attendance_device_id', 'device_user_id')->distinct()->toBase()->get();
        foreach ($rows as $r) {
            $id = (int) $r->attendance_device_id;
            $out[$id] ??= ['numbers' => 0, 'linked' => 0];
            $out[$id]['numbers']++;
            $out[$id]['linked'] += isset($linked[(string) $r->device_user_id]) ? 1 : 0;
        }

        return $out;
    }

    /**
     * Link ids of one source to people. A number already on someone else, or a person who already has a different
     * number, stops the whole save with a message on that row. Machine numbers also get the device they punch on.
     *
     * @param  list<array{device_user_id: string, employee_id: int}>  $links
     */
    public static function link(string $source, array $links): int
    {
        $field = self::FIELDS[$source];
        $what = self::NOUNS[$source];

        return DB::transaction(function () use ($links, $field, $what) {
            $n = 0;
            foreach ($links as $i => $link) {
                $taken = Employee::where($field, $link['device_user_id'])->where('id', '!=', $link['employee_id'])->first();
                if ($taken) {
                    throw ValidationException::withMessages(["links.$i.employee_id" => ucfirst($what)." {$link['device_user_id']} already belongs to {$taken->full_name}."]);
                }
                $e = Employee::findOrFail($link['employee_id']);
                if ($e->{$field} && (string) $e->{$field} !== (string) $link['device_user_id']) {
                    throw ValidationException::withMessages(["links.$i.employee_id" => "{$e->full_name} already has $what {$e->{$field}}."]);
                }
                $update = [$field => $link['device_user_id']];
                if ($field === 'attendance_machine_id' && ! $e->attendance_device_id) {
                    $update['attendance_device_id'] = AttendancePunch::deviceFor($link['device_user_id']);   // ATD-### it punches on
                }
                $e->update($update);
                $n++;
            }

            return $n;
        });
    }

    private static function unlinkedNumbers(string $source): Collection
    {
        $field = self::FIELDS[$source];
        $linked = Employee::query()->whereNotNull($field)->pluck($field)->map(fn ($v) => (string) $v)->all();

        return AttendancePunch::query()->where('source', $source)
            ->when($linked, fn ($q) => $q->whereNotIn('device_user_id', $linked))
            ->groupBy('device_user_id')
            ->selectRaw('device_user_id, max(device_name) as device_name, count(*) as punches, max(punched_at) as last_seen, max(attendance_device_id) as device_id')
            ->toBase()->get();
    }
}
