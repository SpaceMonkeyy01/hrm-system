<?php

namespace App\Support;

use App\Support\Xlsx\XlsxReader;
use RuntimeException;

/** Rows of an uploaded .xlsx (first sheet) or .csv as heading => cell, starting after the row that holds $keyHeading. */
final class SpreadsheetRows
{
    /** @return array<int, array<string, string>> spreadsheet row number => heading => trimmed cell */
    public static function read(string $path, string $extension, string $keyHeading): array
    {
        $grid = [];
        if ($extension === 'csv') {
            $fh = fopen($path, 'r');
            $i = 0;
            while (($cells = fgetcsv($fh, escape: '')) !== false) {
                $i++;
                if ($i === 1 && isset($cells[0])) {
                    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
                }
                $grid[$i] = array_map(fn ($v) => (string) $v, $cells);
            }
            fclose($fh);
        } else {
            $x = new XlsxReader($path);
            foreach ($x->rows($x->sheetNames()[0]) as $r => $cells) {
                // The reader keys cells by column letter; turn them into positions and fill the gaps.
                $line = [];
                foreach ($cells as $col => $v) {
                    $line[self::colIndex((string) $col)] = (string) $v;
                }
                $grid[$r] = $line ? array_replace(array_fill(0, max(array_keys($line)) + 1, ''), $line) : [];
            }
        }
        $headerRow = null;
        foreach ($grid as $r => $cells) {
            if (in_array($keyHeading, array_map('trim', $cells), true)) {
                $headerRow = $r;
                break;
            }
        }
        if ($headerRow === null) {
            throw new RuntimeException("This file has no \"$keyHeading\" column. Download the file from this page, fill it in and upload that.");
        }
        $header = array_map('trim', $grid[$headerRow]);
        $out = [];
        foreach ($grid as $r => $cells) {
            if ($r <= $headerRow) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $h) {
                if ($h !== '') {
                    $row[$h] = trim((string) ($cells[$i] ?? ''));
                }
            }
            if (array_filter($row, fn ($v) => $v !== '')) {
                $out[$r] = $row;
            }
        }

        return $out;
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $c) {
            $n = $n * 26 + (ord($c) - 64);
        }

        return $n - 1;
    }
}
