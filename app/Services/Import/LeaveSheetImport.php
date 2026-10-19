<?php

namespace App\Services\Import;

use App\Models\Employee;
use App\Models\LeaveAdjustment;
use App\Models\LeaveType;
use App\Support\Xlsx\XlsxReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Opening balances from HR's own leave sheet ("BCC Leave balance", one block of columns per month, 2026), as it is.
 *
 * Each month block: Absent | Full day: Wedding, Maternity/Paternity, Annual, Casual, Sick | Half: Casual, Sick |
 * Short leave | Short leave due to LSI / early sign off | Early sign off | Total. Counted the way the sheet does:
 *   Annual    full days
 *   Casual    full days + half x 0.5 + short leave x 0.25 (the sheet doesn't type short leave; agreed: Casual)
 *   Sick      full days + half x 0.5
 *   Wedding, Maternity / Paternity (by gender; else over 7 days = Maternity) days, kept for the record
 *   deduction absent x 1 + late sign-in / early sign-off x 0.25 (LeaveBalances takes it from Casual, then Annual)
 * The sheet's own "Leave Count" pool is ignored: allowances come from the leave types (company policy).
 * People are matched by name; "Name (BCC-012)" matches by code. Uploading again replaces that person's sheet rows.
 */
class LeaveSheetImport implements SpreadsheetImport
{
    private const BLOCK = ['absent', 'wedding', 'maternity', 'annual', 'casual', 'sick', 'half_casual', 'half_sick', 'short', 'lsi', 'early', 'total'];

    public static function meta(): array
    {
        return [
            'title' => 'Upload HR\'s leave sheet',
            'description' => 'Bring in leave already counted in HR\'s own sheet (one block of columns per month) as opening balances.',
            'back' => ['href' => '/leave#balances', 'label' => 'Leave'],
            'key' => 'Name',
            'tips' => [
                'Upload the sheet as it is (.xlsx): Sr.No, Name, then one block per month (Absent, full day, half, short leave, LSI / early sign off, Total).',
                'Names are matched to the app. If two people share a name, or a name is spelt differently, write the employee code after it, e.g. "Asad Khan (BCC-012)".',
                'Casual = full + half x 0.5 + short leave x 0.25. Absences (1 day) and late / early sign-offs (0.25) come off Casual, then Annual.',
                'The sheet\'s own Leave Count is not used: allowances come from the leave types. Uploading again replaces what the last upload added.',
            ],
        ];
    }

    public static function template(): array
    {
        $month = ['Absent', 'Wedding leave', 'Maternity/ Paternity Leave', 'Annual Leave', 'Casual Leave', 'Sick Leave', 'Casual Leave (half)', 'Sick Leave (half)',
            'Short leave', 'Short Leave Due to LSI/ Early sign off', 'Early sign off', 'Total'];

        return [['Sr.No', 'Name', ...$month], [['1', 'Fake Person (BCC-000)', '', '', '', '', '1', '', '1', '', '2', '', '', '2.5']]];
    }

    public function plan(string $path, string $extension): array
    {
        [$year, $people] = $this->read($path, $extension);
        $emps = Employee::query()->get(['id', 'employee_code', 'full_name', 'gender', 'status']);
        $byCode = $emps->keyBy(fn ($e) => mb_strtolower($e->employee_code));
        $byName = $emps->groupBy(fn ($e) => self::norm($e->full_name));
        $types = LeaveType::query()->pluck('id', 'name');
        $out = ['rows' => count($people), 'matched' => 0, 'changes' => [], 'problems' => [], 'year' => $year];
        $seen = [];

        foreach ($people as $p) {
            $code = preg_match('/\(([^)]+)\)\s*$/', $p['name'], $m) ? trim($m[1]) : null;
            $name = trim(preg_replace('/\([^)]*\)\s*$/', '', $p['name']));
            $matches = $code !== null ? collect([$byCode->get(mb_strtolower($code))])->filter() : ($byName->get(self::norm($name)) ?? collect());
            $near = $matches->isEmpty() && $code === null ? self::closest($name, $emps) : null;
            $error = match (true) {
                $code !== null && $matches->isEmpty() => "No employee has code $code.",
                $near !== null => "No employee with this name. Closest in the app: {$near->full_name} ({$near->employee_code}). If that's them, write \"$name ({$near->employee_code})\".",
                $matches->isEmpty() => 'No employee with this name. Fix the spelling to match the app, or add their code after the name, e.g. "'.$name.' (BCC-000)".',
                $matches->count() > 1 => 'Several people are called '.$name.' ('.$matches->pluck('employee_code')->implode(', ').'). Add the right code after the name.',
                isset($seen[$matches->first()->id]) => 'This person is in the sheet twice (rows '.$seen[$matches->first()->id].' and '.$p['row'].'). Merge the rows.',
                default => null,
            };
            if ($error) {
                $out['problems'][] = ['row' => $p['row'], 'name' => $p['name'], 'message' => $error];

                continue;
            }
            $e = $matches->first();
            $seen[$e->id] = $p['row'];
            $out['matched']++;
            $t = $p['totals'];
            $mat = $t['maternity'] > 0 ? (($e->gender === 'Male' || ($e->gender === null && $t['maternity'] <= 7)) ? 'Paternity' : 'Maternity') : null;
            $amounts = array_filter([
                'Annual' => $t['annual'],
                'Casual' => $t['casual'] + 0.5 * $t['half_casual'] + 0.25 * $t['short'],
                'Sick' => $t['sick'] + 0.5 * $t['half_sick'],
                'Wedding' => $t['wedding'],
                $mat ?? 'Maternity' => $t['maternity'],
            ], fn ($v) => $v > 0);
            $deduction = $t['absent'] + 0.25 * ($t['lsi'] + $t['early']);
            $missing = array_diff(array_keys($amounts), $types->keys()->all());
            if ($missing) {
                $out['problems'][] = ['row' => $p['row'], 'name' => $p['name'], 'message' => 'There is no '.implode(' or ', $missing).' leave type in the app.'];
                $out['matched']--;

                continue;
            }
            $parts = array_map(fn ($k, $v) => "$k ".self::num($v), array_keys($amounts), $amounts);
            if ($deduction > 0) {
                $parts[] = 'deductions '.self::num($deduction).' ('.self::num($t['absent']).' absent, '.self::num($t['lsi'] + $t['early']).' late / early)';
            }
            $out['changes'][] = [
                'name' => $e->full_name, 'code' => $e->employee_code, 'label' => "Leave counted in $year ({$p['months']})",
                'old' => null, 'new' => $parts ? implode(', ', $parts) : 'nothing taken', 'replaces' => false,
                'employee_id' => $e->id, 'amounts' => array_map(fn ($k, $v) => ['type_id' => $types[$k], 'days' => $v], array_keys($amounts), $amounts),
                'deduction' => $deduction, 'months' => $p['months'],
            ];
        }

        return $out;
    }

    public function apply(array $plan, ?int $userId): string
    {
        $year = $plan['year'];
        DB::transaction(function () use ($plan, $userId, $year) {
            foreach ($plan['changes'] as $c) {
                LeaveAdjustment::query()->where('employee_id', $c['employee_id'])->where('year', $year)->where('source', 'sheet')->get()->each->delete();
                $note = "From HR's leave sheet ({$c['months']})";
                foreach ($c['amounts'] as $a) {
                    LeaveAdjustment::create(['employee_id' => $c['employee_id'], 'leave_type_id' => $a['type_id'], 'year' => $year,
                        'days' => $a['days'], 'source' => 'sheet', 'note' => $note, 'created_by' => $userId]);
                }
                if ($c['deduction'] > 0) {
                    LeaveAdjustment::create(['employee_id' => $c['employee_id'], 'leave_type_id' => null, 'year' => $year, 'days' => $c['deduction'],
                        'source' => 'sheet', 'note' => "$note: absences and late / early sign-offs", 'created_by' => $userId]);
                }
            }
        });
        $n = count($plan['changes']);

        return "Added opening balances for $n ".($n === 1 ? 'person' : 'people')." ($year).";
    }

    /**
     * @return array{0: int, 1: list<array{row: int, name: string, months: string, totals: array<string, float>}>}
     */
    private function read(string $path, string $extension): array
    {
        if ($extension !== 'xlsx') {
            throw new RuntimeException('Upload HR\'s leave sheet as .xlsx, as it is.');
        }
        $x = new XlsxReader($path);
        $sheet = $x->sheetNames()[0];
        $rows = $x->rows($sheet);
        $year = preg_match('/(20\d\d)/', $sheet, $m) ? (int) $m[1] : CarbonImmutable::today()->year;

        // The row with "Name"; its month titles ("... (July)") sit on it, the column names two rows below.
        $nameRow = null;
        $nameCol = null;
        foreach ($rows as $r => $cells) {
            foreach ($cells as $col => $v) {
                if (strcasecmp(trim((string) $v), 'Name') === 0) {
                    [$nameRow, $nameCol] = [$r, $col];
                    break 2;
                }
            }
        }
        if ($nameRow === null) {
            throw new RuntimeException('No "Name" column found. Upload HR\'s leave sheet as it is.');
        }
        // Each month block starts under its title on the Name row ("BCC Leave Record (July)") and runs for 12 columns
        // (BLOCK). The titles, not the "Absent" headings, mark the blocks: HR's sheet leaves one of those blank.
        $titles = array_filter($rows[$nameRow], fn ($v, $col) => self::colIndex((string) $col) > self::colIndex((string) $nameCol) && trim((string) $v) !== '',
            ARRAY_FILTER_USE_BOTH);
        uksort($titles, fn ($a, $b) => self::colIndex((string) $a) <=> self::colIndex((string) $b));
        $blocks = [];
        foreach ($titles as $col => $title) {
            $blocks[] = ['start' => self::colIndex((string) $col), 'month' => preg_match('/\((\w+)\)/', (string) $title, $mm) ? $mm[1] : 'month '.(count($blocks) + 1)];
        }
        if (! $blocks) {
            throw new RuntimeException('No month blocks found: each needs its title on the Name row, e.g. "BCC Leave Record (July)".');
        }
        $months = count($blocks) > 1 ? $blocks[0]['month'].' to '.end($blocks)['month'] : $blocks[0]['month'];

        $people = [];
        foreach ($rows as $r => $cells) {
            if ($r <= $nameRow + 2 || trim((string) ($cells[$nameCol] ?? '')) === '') {
                continue;
            }
            $totals = array_fill_keys(self::BLOCK, 0.0);
            foreach ($blocks as $b) {
                foreach (self::BLOCK as $i => $key) {
                    $totals[$key] += (float) ($cells[self::colLetter($b['start'] + $i)] ?? 0);
                }
            }
            $people[] = ['row' => $r, 'name' => trim(preg_replace('/\s+/', ' ', (string) $cells[$nameCol])), 'months' => $months, 'totals' => $totals];
        }

        return [$year, $people];
    }

    /** The employee whose name is spelt most like this one (80% alike or more), for the "did you mean" hint. */
    private static function closest(string $name, $emps): ?Employee
    {
        $best = null;
        $score = 80.0;
        foreach ($emps as $e) {
            similar_text(self::norm($name), self::norm($e->full_name), $pct);
            if ($pct >= $score) {
                [$best, $score] = [$e, $pct];
            }
        }

        return $best;
    }

    private static function norm(string $s): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($s)));
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private static function colIndex(string $col): int
    {
        $n = 0;
        foreach (str_split(strtoupper($col)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n;
    }

    private static function colLetter(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m).$s;
            $n = intdiv($n - 1, 26);
        }

        return $s;
    }
}
