<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * php artisan hr:copy-data [--from=sqlite] [--to=copy_target] [--wipe]
 *
 * Copies every record from one database to another, e.g. this computer's SQLite file into the live Postgres
 * (COPY_TARGET_URL in .env). The data goes straight from database to database: it is never put in the code or git.
 *
 *   1. migrates the target, so it has the same tables
 *   2. refuses if the target already has employees, unless --wipe; every copied table is emptied first
 *   3. copies tables parents first (foreign keys), self-references (manager_id) in a second pass
 *   4. converts SQLite 0/1 to true/false for boolean columns, then moves Postgres id counters past the copied ids
 *
 * Sessions, cache, jobs and password-reset tokens are not copied: everyone logs in again on the new site.
 * Tables migrations fill (leave types) are emptied and refilled from the source.
 */
class CopyData extends Command
{
    protected $signature = 'hr:copy-data {--from=sqlite : Connection to read} {--to=copy_target : Connection to write} {--wipe : Empty the target first}';

    protected $description = 'Copy all HR records from one database connection to another (e.g. local SQLite to the live Postgres)';

    private const SKIP = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    public function handle(): int
    {
        $from = DB::connection($this->option('from'));
        $to = DB::connection($this->option('to'));
        $this->info("From {$from->getDriverName()} ({$this->option('from')}) to {$to->getDriverName()} ({$this->option('to')}).");

        Artisan::call('migrate', ['--database' => $this->option('to'), '--force' => true]);
        $this->line(trim(Artisan::output()));

        $tables = array_values(array_diff(array_column(Schema::connection($this->option('from'))->getTables(), 'name'), self::SKIP));
        $tables = array_values(array_filter($tables, fn ($t) => ! str_starts_with($t, 'sqlite_')));
        $order = $this->parentsFirst($tables);

        if ($to->table('employees')->exists() && ! $this->option('wipe')) {
            $this->error('The target already has employees. Run again with --wipe to replace everything in it.');

            return self::FAILURE;
        }

        $to->transaction(function () use ($from, $to, $order) {
            // Migrations fill some tables (leave types); the copy replaces those rows with the source's.
            foreach (array_reverse($order) as $t) {
                $to->table($t)->delete();
            }
            foreach ($order as $t) {
                $columns = collect(Schema::connection($this->option('to'))->getColumns($t))->keyBy('name');
                $booleans = $columns->filter(fn ($c) => in_array($c['type_name'], ['bool', 'boolean'], true))->keys()->all();
                $selfRefs = collect(Schema::connection($this->option('from'))->getForeignKeys($t))
                    ->filter(fn ($fk) => $fk['foreign_table'] === $t)->pluck('columns')->flatten()->all();
                $later = [];
                $n = 0;
                $from->table($t)->orderBy($columns->has('id') ? 'id' : $columns->keys()->first())->chunk(500, function ($rows) use ($to, $t, $booleans, $selfRefs, $columns, &$later, &$n) {
                    $batch = [];
                    foreach ($rows as $row) {
                        $row = array_intersect_key((array) $row, $columns->all());
                        foreach ($booleans as $b) {
                            if (array_key_exists($b, $row) && $row[$b] !== null) {
                                $row[$b] = (bool) $row[$b];
                            }
                        }
                        foreach ($selfRefs as $c) {
                            if (! empty($row[$c])) {
                                $later[] = ['id' => $row['id'], 'column' => $c, 'value' => $row[$c]];
                                $row[$c] = null;
                            }
                        }
                        $batch[] = $row;
                    }
                    $to->table($t)->insert($batch);
                    $n += count($batch);
                });
                foreach ($later as $l) {
                    $to->table($t)->where('id', $l['id'])->update([$l['column'] => $l['value']]);
                }
                if ($n && $columns->has('id') && $to->getDriverName() === 'pgsql') {
                    $to->statement("select setval(pg_get_serial_sequence('\"$t\"', 'id'), (select max(id) from \"$t\"))");
                }
                $this->line(sprintf('  %-28s %6d', $t, $n));
            }
        });

        $this->info('Done. Check the counts above against the local site, then log in on the live site.');

        return self::SUCCESS;
    }

    /** Tables ordered so every table comes after the tables its foreign keys point to. */
    private function parentsFirst(array $tables): array
    {
        $deps = [];
        foreach ($tables as $t) {
            $deps[$t] = collect(Schema::connection($this->option('from'))->getForeignKeys($t))
                ->pluck('foreign_table')->reject(fn ($p) => $p === $t)->intersect($tables)->unique()->values()->all();
        }
        $out = [];
        $visit = function (string $t, array $path) use (&$visit, &$out, $deps) {
            if (in_array($t, $out, true) || in_array($t, $path, true)) {
                return;
            }
            foreach ($deps[$t] as $p) {
                $visit($p, [...$path, $t]);
            }
            $out[] = $t;
        };
        foreach ($tables as $t) {
            $visit($t, []);
        }

        return $out;
    }
}
