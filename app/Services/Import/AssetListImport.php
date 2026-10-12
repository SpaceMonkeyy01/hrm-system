<?php

namespace App\Services\Import;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Support\SpreadsheetRows;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Adds assets to the register from a list in Excel, optionally with who has each one now.
 * Only new tags are added: a tag already on the register is listed as a problem and left alone
 * (change existing assets on the Assets page, where giving and taking back keeps the history right).
 */
class AssetListImport implements SpreadsheetImport
{
    public static function meta(): array
    {
        return [
            'title' => 'Upload an asset list',
            'description' => 'Add many assets at once from Excel, with who has each one now.',
            'back' => ['href' => '/assets', 'label' => 'Assets'],
            'key' => 'Tag',
            'tips' => [
                'One row per asset. Tag and Make and model are required.',
                'Type: one of '.implode(', ', config('hrm.assets.types')).'.',
                "Given to: the employee code of whoever has it now, e.g. BCC-012. Leave it empty if it's in stock.",
                'Tags already on the register are skipped; change those on the Assets page.',
            ],
        ];
    }

    public static function template(): array
    {
        return [['Tag', 'Type', 'Make and model', 'Serial number', 'Bought on', 'Given to', 'Given on', 'Notes'], []];
    }

    public function plan(string $path, string $extension): array
    {
        $rows = SpreadsheetRows::read($path, $extension, 'Tag');
        $existing = Asset::pluck('asset_tag')->map(fn ($t) => mb_strtolower($t))->flip();
        $people = Employee::query()->whereIn('status', ['active', 'offboarding'])->get(['id', 'employee_code', 'full_name'])->keyBy(fn ($e) => mb_strtolower($e->employee_code));
        $types = collect(config('hrm.assets.types'))->keyBy(fn ($t) => mb_strtolower($t));
        $today = CarbonImmutable::today()->toDateString();
        $out = ['rows' => count($rows), 'matched' => 0, 'changes' => [], 'problems' => []];
        $seen = [];

        foreach ($rows as $n => $row) {
            $tag = trim($row['Tag'] ?? '');
            $name = trim($row['Make and model'] ?? '');
            $type = $types->get(mb_strtolower(trim($row['Type'] ?? ''))) ?? (trim($row['Type'] ?? '') === '' ? 'Other' : null);
            $holder = trim($row['Given to'] ?? '') !== '' ? $people->get(mb_strtolower(trim($row['Given to']))) : null;
            $bought = trim($row['Bought on'] ?? '') !== '' ? EmployeeDetailsImport::date(trim($row['Bought on'])) : null;
            $given = trim($row['Given on'] ?? '') !== '' ? EmployeeDetailsImport::date(trim($row['Given on'])) : $today;

            $error = match (true) {
                $tag === '' => 'Tag is empty.',
                mb_strlen($tag) > 40 => 'Tag is longer than 40 characters.',
                $existing->has(mb_strtolower($tag)) => 'This tag is already on the register. Skipped.',
                isset($seen[mb_strtolower($tag)]) => 'This tag is in the file twice.',
                $name === '' => 'Make and model is empty.',
                $type === null => 'Type "'.trim($row['Type']).'" isn\'t one of the types.',
                trim($row['Given to'] ?? '') !== '' && ! $holder => 'No current employee has code '.trim($row['Given to']).'.',
                trim($row['Bought on'] ?? '') !== '' && (! $bought || $bought > $today) => 'Bought on "'.trim($row['Bought on']).'" isn\'t a past date.',
                ! $given || $given > $today => 'Given on "'.trim($row['Given on']).'" isn\'t a past date.',
                default => null,
            };
            if ($error) {
                $out['problems'][] = ['row' => $n, 'name' => $tag ?: '(no tag)', 'message' => $error];

                continue;
            }
            $seen[mb_strtolower($tag)] = true;
            $out['matched']++;
            $out['changes'][] = [
                'name' => $tag, 'code' => '', 'label' => 'New asset', 'old' => null,
                'new' => "$type: $name".($holder ? ", with {$holder->full_name} since ".CarbonImmutable::parse($given)->format('j M Y') : ', in stock'),
                'replaces' => false,
                'asset' => ['asset_tag' => $tag, 'type' => $type, 'name' => $name, 'serial_number' => trim($row['Serial number'] ?? '') ?: null,
                    'purchase_date' => $bought, 'notes' => trim($row['Notes'] ?? '') ?: null, 'status' => $holder ? 'assigned' : 'in_stock'],
                'holder' => $holder?->id, 'given_on' => $given,
            ];
        }

        return $out;
    }

    public function apply(array $plan, ?int $userId): string
    {
        DB::transaction(function () use ($plan, $userId) {
            foreach ($plan['changes'] as $c) {
                $asset = Asset::create($c['asset']);
                if ($c['holder']) {
                    AssetAssignment::create(['asset_id' => $asset->id, 'employee_id' => $c['holder'], 'assigned_on' => $c['given_on'],
                        'assigned_by' => $userId, 'note' => 'From the asset list upload']);
                }
            }
        });
        $n = count($plan['changes']);
        $given = count(array_filter($plan['changes'], fn ($c) => $c['holder']));

        return "Added $n ".($n === 1 ? 'asset' : 'assets').", $given given to someone.";
    }
}
