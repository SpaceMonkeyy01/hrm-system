<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Import\HrWorkbookImporter;
use App\Services\Import\MasterValidationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * php artisan hr:import --master=storage/app/private/import/Employee_Master.xlsx \
 *                       --onboarding=storage/app/private/import/HR_Onboarding.xlsx --fresh
 *
 * One-time seed. The master (current BCC and Emerge staff) is checked first: any problem stops the import with the
 * full list and nothing is saved. Then the onboarding workbook is matched to the master by name. Without --master
 * it works as before: employees come from the workbook's department sheets (hr:import <path>).
 * Run it, read the report, fix names in aliases.csv, run again with --fresh, then go live. After go-live the app
 * is the source of truth: do not re-import.
 */
class ImportHrWorkbook extends Command
{
    protected $signature = 'hr:import
        {path? : Path to HR_Onboarding.xlsx (same as --onboarding)}
        {--master= : Employee_Master.xlsx: the employee list to seed from}
        {--onboarding= : HR_Onboarding.xlsx: onboarding tracker and document checklist (optional with --master)}
        {--fresh : Delete all HR data (employees and everything recorded about them, activity) first. Logins and punches are kept; with --master, attendance links are carried over by name.}
        {--aliases= : CSV with columns source_name,employee_code to force a name match (default: storage/app/private/import/aliases.csv)}
        {--links= : CSV with columns employee_code,source,device_user_id (source machine or hrmy): fixed attendance links (default: storage/app/private/import/links.csv)}';

    protected $description = 'Import employees, onboarding tracker and document checklist from the HR Excel workbook';

    public function handle(): int
    {
        $path = $this->option('onboarding') ?: $this->argument('path');
        $master = $this->option('master') ?: null;
        if (! $path && ! $master) {
            $this->error('Give --master=Employee_Master.xlsx, the onboarding workbook, or both.');

            return self::FAILURE;
        }
        foreach (array_filter([$master, $path]) as $file) {
            if (! is_file($file)) {
                $this->error("File not found: $file");

                return self::FAILURE;
            }
        }
        if (Employee::withTrashed()->exists() && ! $this->option('fresh')) {
            $this->error('The database already has employees. Run with --fresh to wipe HR data and import again (logins are kept).');

            return self::FAILURE;
        }

        $aliases = $this->loadAliases($this->option('aliases') ?: Storage::disk('local')->path('import/aliases.csv'));
        $links = $this->loadLinks($this->option('links') ?: Storage::disk('local')->path('import/links.csv'));
        $names = implode(' + ', array_map('basename', array_filter([$master, $path])));
        $this->info("Importing $names".($aliases ? ' with '.count($aliases).' aliases' : '').($links ? ', '.count($links).' fixed links' : '').' ...');

        try {
            $result = (new HrWorkbookImporter($path ?: null, $aliases, $master, $links))->run((bool) $this->option('fresh'));
        } catch (MasterValidationException $e) {
            $this->error($e->getMessage());
            foreach ($e->errors as $error) {
                $this->line("  - $error");
            }

            return self::FAILURE;
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

        $check = ['ambiguous', 'bad_alias', 'created', 'conflict', 'duplicate_id', 'invalid_value', 'manager_not_linked',
            'not_in_master', 'warning', 'link_not_carried', 'link_check', 'link_conflict', 'bad_link', 'link_moved'];
        $needsReview = collect($result['report'])->whereIn('result', $check)->count();
        $this->newLine();
        $this->line('Report: '.Storage::disk('local')->path($file));
        $this->line("Rows that need a look: $needsReview (results: ".implode(', ', $check).')');

        return self::SUCCESS;
    }

    /** @return list<array{employee_code: string, source: string, device_user_id: string, line: int}> */
    private function loadLinks(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $links = [];
        $fh = fopen($path, 'r');
        fgetcsv($fh, escape: '');
        for ($line = 2; ($row = fgetcsv($fh, escape: '')) !== false; $line++) {
            if (count($row) >= 3 && trim($row[0]) !== '') {
                $links[] = ['employee_code' => trim($row[0]), 'source' => strtolower(trim($row[1])), 'device_user_id' => trim($row[2]), 'line' => $line];
            }
        }
        fclose($fh);

        return $links;
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
