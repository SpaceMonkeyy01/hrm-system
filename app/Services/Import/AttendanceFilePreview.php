<?php

namespace App\Services\Import;

use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;

/**
 * What an attendance file would add, before anything is saved: the days it covers, who is in it, what is new,
 * and anything HR should look at first (wrong day, already uploaded, exported mid-day, the other file missing).
 */
class AttendanceFilePreview
{
    private const LINK_FIELDS = ['machine' => 'attendance_machine_id', 'hrmy' => 'hr_portal_id'];

    /** More than this share of people with a single punch on a day looks like a file exported before the day ended. */
    private const SINGLE_PUNCH_SHARE = 0.4;

    /**
     * @param  array{source: string, format: string, rows: array<string, array<string, mixed>>, skipped: list<string>}  $read
     * @param  list<string>  $otherComing  days the other source's files in the same batch cover (no "upload that file too" for those)
     */
    public function build(array $read, ?string $expectedDate = null, array $otherComing = []): array
    {
        ['source' => $source, 'rows' => $rows] = $read;
        $other = $source === 'machine' ? 'hrmy' : 'machine';
        $times = array_column($rows, 'punched_at');

        // Punches already stored, by the same key the import uses to skip them.
        $existing = AttendancePunch::query()->where('source', $source)->whereBetween('punched_at', [min($times), max($times)])
            ->get(['device_user_id', 'punched_at'])
            ->mapWithKeys(fn ($p) => [$p->device_user_id.'|'.$p->punched_at->format('Y-m-d H:i:s') => true]);

        $days = [];
        $names = [];
        foreach ($rows as $key => $r) {
            $d = &$days[$r['work_date']];
            $d['punches'] = ($d['punches'] ?? 0) + 1;
            $d['new'] = ($d['new'] ?? 0) + ($existing->has($key) ? 0 : 1);
            $d['per_person'][$r['device_user_id']] = ($d['per_person'][$r['device_user_id']] ?? 0) + 1;
            $names[$r['device_user_id']] ??= $r['device_name'];
            unset($d);
        }
        ksort($days);
        $dates = array_keys($days);

        $ids = array_map('strval', array_keys($names));
        $linked = Employee::query()->whereIn(self::LINK_FIELDS[$source], $ids)->pluck(self::LINK_FIELDS[$source])->map(fn ($v) => (string) $v)->all();
        $unlinked = array_values(array_diff($ids, $linked));
        sort($unlinked, SORT_NATURAL);

        $holidays = Holiday::query()->whereIn('date', $dates)->get(['date', 'name'])->keyBy(fn ($h) => $h->date->toDateString());
        $workingDays = WorkSchedule::query()->where('is_default', true)->value('working_days') ?? [1, 2, 3, 4, 5];
        $workingDays = is_string($workingDays) ? json_decode($workingDays, true) : $workingDays;
        $otherHas = AttendancePunch::query()->where('source', $other)->whereIn('work_date', $dates)->distinct()->pluck('work_date')
            ->map(fn ($d) => substr((string) $d, 0, 10))->all();

        $today = CarbonImmutable::today();
        $warnings = [];
        $warn = function (string $text, string $tone = 'warn') use (&$warnings) {
            $warnings[] = ['tone' => $tone, 'text' => $text];
        };
        $label = fn (string $date) => CarbonImmutable::parse($date)->format('l j M Y');

        $found = count($rows);
        $new = array_sum(array_column($days, 'new'));
        if ($new === 0) {
            $warn('Nothing new: every punch in this file is already in. Importing it changes nothing.');
        } elseif ($new < $found) {
            $warn(($found - $new).' of '.$found.' punches are already in from an earlier upload. Only the '.$new.' new ones are added.', 'info');
        }
        if ($expectedDate && ! in_array($expectedDate, $dates, true)) {
            $warn('You expected '.$label($expectedDate).', but this file has '.(count($dates) === 1 ? $label($dates[0]) : count($dates).' other days').'.');
        }
        $out = [];
        foreach ($days as $date => $d) {
            $day = CarbonImmutable::parse($date);
            $people = count($d['per_person']);
            $single = count(array_filter($d['per_person'], fn ($n) => $n === 1));
            if ($day->greaterThan($today)) {
                $warn($label($date).' is in the future. Check the file is the right one.');
            } elseif ($day->lessThan($today->subDays(31))) {
                $warn($label($date).' is more than a month ago.', 'info');
            }
            if (! in_array($day->dayOfWeekIso, $workingDays, true)) {
                $warn($label($date).' is not a working day on the default schedule.', 'info');
            }
            if ($h = $holidays->get($date)) {
                $warn($label($date).' is a holiday: '.$h->name.'.', 'info');
            }
            if ($people >= 5 && $single / $people > self::SINGLE_PUNCH_SHARE) {
                $warn("On {$label($date)}, $single of $people people have only one punch. Was the file exported before the end of the day?");
            }
            if (! in_array($date, $otherHas, true) && ! in_array($date, $otherComing, true)) {
                $warn($label($date).' has no '.AttendanceFileImporter::SOURCES[$other].' data yet. Upload that file too.', 'info');
            }
            $out[] = ['date' => $date, 'punches' => $d['punches'], 'new' => $d['new'], 'people' => $people, 'single' => $single];
        }

        return [
            'source' => $source,
            'source_label' => AttendanceFileImporter::SOURCES[$source],
            'format' => $read['format'],
            'punches' => $found,
            'new' => $new,
            'people' => count($ids),
            'linked' => count($ids) - count($unlinked),
            'unlinked' => array_map(fn ($id) => ['id' => $id, 'name' => $names[$id]], $unlinked),
            'days' => $out,
            'skipped' => count($read['skipped']),
            'skipped_examples' => array_slice($read['skipped'], 0, 3),
            'warnings' => $warnings,
        ];
    }
}
