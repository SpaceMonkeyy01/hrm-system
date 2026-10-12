<?php

namespace App\Services\Reports;

/**
 * What a report hands back. The screen and the CSV download both read it, so they always match.
 *
 * Column types (resources/js/pages/reports/show.tsx renders each):
 *   text, number, date (Y-m-d), minutes (int, shown 8h 05m, exported as hours), percent (float),
 *   employee ({id, name, code}; exported as two columns), status (string, coloured by the column's tones),
 *   day (attendance sheet cell {code, tone, title})
 */
final class ReportResult
{
    /** @var list<array{key: string, label: string, type: string, tones?: array<string, string>, group?: string}> */
    public array $columns = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<array{label: string, value: string|int|float, tone?: string}> */
    public array $summary = [];

    /** @var list<string> */
    public array $notes = [];

    public function column(string $key, string $label, string $type = 'text', array $extra = []): self
    {
        $this->columns[] = ['key' => $key, 'label' => $label, 'type' => $type] + $extra;

        return $this;
    }

    public function stat(string $label, string|int|float $value, ?string $tone = null): self
    {
        $this->summary[] = ['label' => $label, 'value' => $value] + ($tone ? ['tone' => $tone] : []);

        return $this;
    }

    public function note(string $note): self
    {
        $this->notes[] = $note;

        return $this;
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} header and rows for Csv::download */
    public function toCsv(): array
    {
        $header = [];
        foreach ($this->columns as $c) {
            match ($c['type']) {
                'employee' => array_push($header, 'Code', 'Name'),
                'minutes' => $header[] = $c['label'].' (hours)',
                'percent' => $header[] = $c['label'].' (%)',
                default => $header[] = $c['label'],
            };
        }
        $rows = [];
        foreach ($this->rows as $row) {
            $out = [];
            foreach ($this->columns as $c) {
                $v = $row[$c['key']] ?? null;
                match ($c['type']) {
                    'employee' => array_push($out, $v['code'] ?? '', $v['name'] ?? ''),
                    'minutes' => $out[] = $v === null ? '' : round($v / 60, 2),
                    'day' => $out[] = $v['code'] ?? '',
                    default => $out[] = $v ?? '',
                };
            }
            $rows[] = $out;
        }

        return [$header, $rows];
    }

    /** Employee cell. */
    public static function person(object $e): array
    {
        return ['id' => $e->id, 'name' => $e->full_name, 'code' => $e->employee_code];
    }
}
