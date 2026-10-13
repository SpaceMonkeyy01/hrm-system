<?php

namespace App\Services\Import;

use App\Models\AttendanceImport;
use App\Models\AttendancePunch;
use App\Support\Xls\XlsReader;
use App\Support\Xlsx\XlsxReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reads an attendance export and stores its punches. The kind of file is worked out from its columns:
 *
 *   machine  ZKTeco "Full day log": Name, No., Date/Time ("5/13/2026 9:58:03 AM"), VerifyCode
 *   hrmy     HR.my "Time Clock Records": Date (the shift's day), ID, Name, Action (In/Out),
 *            Time ("07:05 PM", or "09:51 AM (2026-10-09)" when it falls on the next day), Remark
 *   hrmy     HR.my "Attendance Transactions" (daily): Date, ID, Name, Status, First Clock In, Last Clock Out, ...
 *            read as an In and an Out punch per person per day
 *
 * The machine's Date/Time may be text or an Excel date number, depending on how the file was exported.
 *
 * The same punch is never stored twice, so an overlapping file only adds what's new. The ZKTeco "Full Report"
 * is refused: it drops punches outside fixed windows; the app works the day out from raw punches instead.
 */
class AttendanceFileImporter
{
    public const SOURCES = ['machine' => 'Attendance machine', 'hrmy' => 'HR.my'];

    private const HRMY_DAILY = ['Date', 'ID', 'First Clock In', 'Last Clock Out'];

    private const MACHINE_FORMATS = ['n/j/Y g:i:s A', 'n/j/Y g:i A', 'n/j/Y H:i:s', 'n/j/Y H:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'j/n/Y H:i:s'];

    /**
     * @return array{import: AttendanceImport, source: string, found: int, added: int, skipped: list<string>}
     *
     * @throws ValidationException when the file isn't a punch export we know
     */
    public function import(string $path, string $originalName, ?string $storedPath, ?int $userId): array
    {
        [$source, $records] = $this->read($path, $originalName);

        $rows = [];
        $skipped = [];
        foreach ($records as $i => $r) {
            $row = $source === 'machine' ? $this->machineRow($r) : $this->hrmyRow($r);
            if (is_string($row)) {
                $skipped[] = 'Row '.($i + 2).': '.$row;

                continue;
            }
            $rows[$row['device_user_id'].'|'.$row['punched_at']] = $row + ['source' => $source];
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'No punches could be read. '.implode('; ', array_slice($skipped, 0, 3))]);
        }

        return DB::transaction(function () use ($rows, $source, $originalName, $storedPath, $userId, $skipped) {
            $dates = array_column($rows, 'work_date');
            $import = AttendanceImport::create([
                'source' => $source, 'original_name' => $originalName, 'path' => $storedPath,
                'punches_found' => count($rows), 'date_from' => min($dates), 'date_to' => max($dates), 'uploaded_by' => $userId,
            ]);
            $added = 0;
            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                $added += AttendancePunch::query()->insertOrIgnore(array_map(fn ($r) => $r + [
                    'attendance_import_id' => $import->id, 'created_at' => now(),
                ], $chunk));
            }
            $import->update(['punches_added' => $added]);

            return ['import' => $import, 'source' => $source, 'found' => count($rows), 'added' => $added, 'skipped' => $skipped];
        });
    }

    /** @return array{0: string, 1: list<array<string, string>>} source and records */
    private function read(string $path, string $originalName): array
    {
        try {
            $table = str_ends_with(strtolower($originalName), '.xlsx') ? $this->xlsxRows($path) : (new XlsReader($path))->rows();
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['file' => 'The file could not be read: '.$e->getMessage()]);
        }
        ksort($table);
        foreach ($table as $cells) {
            $names = array_map('trim', $cells);
            if (in_array('AC-No.', $names, true) || in_array('Clock In', $names, true)) {
                throw ValidationException::withMessages(['file' => 'This is the machine\'s Full Report. Upload the "Full day log" instead (the file with a Date/Time column, one row per punch).']);
            }
            foreach (['machine' => ['No.', 'Date/Time'], 'hrmy' => ['Date', 'ID', 'Action', 'Time']] as $source => $required) {
                if (! array_diff($required, $names)) {
                    return [$source, self::recordsBelow($table, $required)];
                }
            }
            if (! array_diff(self::HRMY_DAILY, $names)) {
                return ['hrmy', self::dailyToPunches(self::recordsBelow($table, self::HRMY_DAILY))];
            }
        }
        throw ValidationException::withMessages(['file' => 'This isn\'t a file we know. Upload the machine\'s "Full day log" or an HR.my export (Time Clock Records or Attendance Transactions).']);
    }

    /** @return array<int, array<int, string>> rows of the first sheet, 0-based columns */
    private function xlsxRows(string $path): array
    {
        $reader = new XlsxReader($path);
        $out = [];
        foreach ($reader->rows($reader->sheetNames()[0]) as $n => $cells) {
            foreach ($cells as $letter => $value) {
                $col = 0;
                foreach (str_split($letter) as $ch) {
                    $col = $col * 26 + (ord($ch) - 64);
                }
                $out[$n][$col - 1] = $value;
            }
        }

        return $out;
    }

    /** @return list<array<string, string>> rows under the first row that has all $required columns, keyed by header */
    private static function recordsBelow(array $table, array $required): array
    {
        $header = null;
        $out = [];
        foreach ($table as $cells) {
            if ($header === null) {
                $names = array_map('trim', $cells);
                $header = array_diff($required, $names) ? null : $names;

                continue;
            }
            $record = [];
            foreach ($header as $col => $name) {
                $record[$name] = trim($cells[$col] ?? '');
            }
            if (array_filter($record, fn ($v) => $v !== '')) {
                $out[] = $record;
            }
        }

        return $out;
    }

    /**
     * HR.my "Attendance Transactions": one row per person per day with First Clock In and Last Clock Out.
     * Each becomes a punch like a Time Clock Records row; days with neither time (absent, leave) are left out.
     *
     * @return list<array<string, string>>
     */
    private static function dailyToPunches(array $records): array
    {
        $out = [];
        foreach ($records as $r) {
            foreach (['First Clock In' => 'In', 'Last Clock Out' => 'Out'] as $col => $action) {
                if ($r[$col] !== '') {
                    $out[] = ['Date' => $r['Date'], 'ID' => $r['ID'], 'Name' => $r['Name'] ?? '', 'Action' => $action, 'Time' => $r[$col]];
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|string the row, or why it was skipped */
    private function machineRow(array $r): array|string
    {
        $id = $r['No.'] ?? '';
        if ($id === '') {
            return 'no machine number';
        }
        // Some machine exports store Date/Time as an Excel date number (46307.5536921296), not text.
        $at = is_numeric($r['Date/Time'] ?? '') ? self::excelDate((float) $r['Date/Time']) : self::parse($r['Date/Time'] ?? '', self::MACHINE_FORMATS);
        if (! $at) {
            return 'date "'.($r['Date/Time'] ?? '').'" not understood';
        }

        return ['device_user_id' => $id, 'device_name' => ($r['Name'] ?? '') ?: null, 'punched_at' => $at->format('Y-m-d H:i:s'),
            'work_date' => $at->format('Y-m-d'), 'method' => ($r['VerifyCode'] ?? '') ?: null];
    }

    /** @return array<string, mixed>|string */
    private function hrmyRow(array $r): array|string
    {
        $id = $r['ID'] ?? '';
        if ($id === '') {
            return 'no ID';
        }
        $day = self::parse($r['Date'] ?? '', ['Y-m-d', 'n/j/Y', 'j/n/Y']);
        // "09:51 AM (2026-10-09)": the time fell on another day than the shift's date.
        if (! $day || ! preg_match('/^(\d{1,2}:\d{2}(?::\d{2})?\s*[AP]M)\s*(?:\((\d{4}-\d{2}-\d{2})\))?$/i', $r['Time'] ?? '', $m)) {
            return 'date "'.($r['Date'] ?? '').'" or time "'.($r['Time'] ?? '').'" not understood';
        }
        $on = ! empty($m[2]) ? $m[2] : $day->format('Y-m-d');
        $at = self::parse($on.' '.strtoupper(preg_replace('/\s+/', ' ', $m[1])), ['Y-m-d h:i A', 'Y-m-d h:i:s A', 'Y-m-d g:i A', 'Y-m-d g:i:s A']);
        if (! $at) {
            return 'time "'.($r['Time'] ?? '').'" not understood';
        }
        $action = ucfirst(strtolower($r['Action'] ?? ''));

        return ['device_user_id' => $id, 'device_name' => ($r['Name'] ?? '') ?: null, 'punched_at' => $at->format('Y-m-d H:i:s'),
            'work_date' => $day->format('Y-m-d'), 'method' => in_array($action, ['In', 'Out'], true) ? $action : null];
    }

    /** Excel date number (days since 1899-12-30, time as the fraction) to a date and time, to the nearest second. */
    private static function excelDate(float $serial): ?CarbonImmutable
    {
        if ($serial < 20000 || $serial > 80000) {   // 1954 to 2119: anything else isn't a punch
            return null;
        }

        // Counted in UTC: in the app's time zone, 1899 had a different offset (Karachi was +4:28) and times would shift.
        $wall = CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')->addSeconds((int) round($serial * 86400));

        return CarbonImmutable::parse($wall->format('Y-m-d H:i:s'));
    }

    private static function parse(string $value, array $formats): ?CarbonImmutable
    {
        $value = preg_replace('/\s+/', ' ', trim($value));
        foreach ($formats as $format) {
            $d = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($d !== false && ! ($errors && ($errors['warning_count'] || $errors['error_count']))) {
                return CarbonImmutable::instance($d);
            }
        }

        return null;
    }
}
