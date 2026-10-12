<?php

namespace App\Services\Import;

/**
 * A "download, fill in Excel, upload, preview, save" import (SpreadsheetImportController).
 *
 * plan() never saves. Each change is one line HR reads in the preview: who or what (name, code), which detail (label),
 * before (old, null = blank) and after (new), and whether it replaces something already on file.
 */
interface SpreadsheetImport
{
    /** @return array{title: string, description: string, back: array{href: string, label: string}, tips: list<string>, key: string} */
    public static function meta(): array;

    /** @return array{0: list<string>, 1: list<list<mixed>>} header and rows for Csv::download */
    public static function template(): array;

    /** @return array{rows: int, matched: int, changes: list<array{name: string, code: string, label: string, old: ?string, new: string, replaces: bool}>, problems: list<array{row: int, name: string, message: string}>} */
    public function plan(string $path, string $extension): array;

    /** Save a plan. Returns a one-line summary for HR. */
    public function apply(array $plan, ?int $userId): string;
}
