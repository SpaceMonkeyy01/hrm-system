<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Import\HrWorkbookImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * php artisan hr:import storage/app/import/HR_Onboarding.xlsx --fresh
 *
 * One-time migration from the Google Sheet. Run it, read the report, fix names in aliases.csv,
 * run again with --fresh, then go live. After go-live the app is the source of truth: do not re-import.
 */
class ImportHrWorkbook extends Command
{
    protected $signature = 'hr:import
        {path : Path to HR_Onboarding.xlsx}
        {--fresh : Delete all HR data (employees, checklists, plans, offboarding, documents, activity) first. Users are kept.}
        {--aliases= : CSV with columns source_name,employee_code to force a name match (default: storage/app/private/import/aliases.csv)}';

    protected $description = 'Import employees, onboarding tracker and document checklist from the HR Excel workbook';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (Employee::withTrashed()->exists() && ! $this->option('fresh')) {
            $this->error('The database already has employees. Run with --fresh to wipe HR data and import again (logins are kept).');

            return self::FAILURE;
        }

        $aliases = $this->loadAliases($this->option('aliases') ?: Storage::disk('local')->path('import/aliases.csv'));
        $this->info('Importing '.basename($path).($aliases ? ' with '.count($aliases).' aliases' : '').' ...');

        try {
            $result = (new HrWorkbookImporter($path, $aliases))->run((bool) $this->option('fresh'));
        } catch (Throwable $e) {
            $this->error('Import failed, nothing was saved: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Step', 'Count'], collect($result['stats'])->map(fn ($v, $k) => [str_replace('_', ' ', $k), $v])->values());

        $file = 'import/import-report-'.now()->format('Ymd-His').'-'.substr(uniqid(), -5).'.csv';
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, ['sheet', 'row', 'source_name', 'result', 'match_type', 'employee_code', 'employee_name', 'detail'], escape: '');
        foreach ($result['report'] as $line) {
            fputcsv($fh, array_values($line), escape: '');
        }
        rewind($fh);
        Storage::disk('local')->put($file, stream_get_contents($fh));

        $check = ['ambiguous', 'bad_alias', 'created', 'conflict', 'duplicate_id', 'invalid_value', 'manager_not_linked'];
        $needsReview = collect($result['report'])->whereIn('result', $check)->count();
        $this->newLine();
        $this->line('Report: '.Storage::disk('local')->path($file));
        $this->line("Rows that need a look: $needsReview (results: ".implode(', ', $check).')');

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    private function loadAliases(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $aliases = [];
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh, escape: '');
        while (($row = fgetcsv($fh, escape: '')) !== false) {
            if (count($row) >= 2 && trim($row[0]) !== '' && trim($row[1]) !== '') {
                $aliases[trim($row[0])] = trim($row[1]);
            }
        }
        fclose($fh);

        return $aliases;
    }
}
