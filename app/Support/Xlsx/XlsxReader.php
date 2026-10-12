<?php

namespace App\Support\Xlsx;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Minimal .xlsx reader with no dependencies (an .xlsx file is a zip of XML files).
 *
 * Reads cell values: text, numbers, booleans and the cached result of formulas
 * (Google Sheets and Excel both store the last calculated value next to each formula).
 * Numbers in date-formatted cells are returned as "Y-m-d".
 *
 * It is enough for importing the HR workbook. It does not evaluate formulas.
 */
class XlsxReader
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private ZipArchive $zip;

    /** @var array<int, string> */
    private array $sharedStrings = [];

    /** @var array<int, bool> cell style index => is a date format */
    private array $dateStyle = [];

    /** @var array<string, string> sheet name => path inside the zip */
    private array $sheets = [];

    /** The workbook uses the 1904 date system (<workbookPr date1904="1"/>) */
    private bool $date1904 = false;

    public function __construct(string $path)
    {
        if (! is_file($path)) {
            throw new RuntimeException("File not found: $path");
        }
        $this->zip = new ZipArchive;
        if ($this->zip->open($path) !== true) {
            throw new RuntimeException("Not a valid .xlsx file: $path");
        }
        $this->loadSheets();
        $this->loadSharedStrings();
        $this->loadStyles();
    }

    /** @return string[] */
    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    public function hasSheet(string $name): bool
    {
        return isset($this->sheets[$name]);
    }

    /**
     * All non-empty cells of a sheet.
     *
     * @return array<int, array<string, string>> row number => [column letter => value]
     */
    public function rows(string $sheet): array
    {
        if (! isset($this->sheets[$sheet])) {
            throw new RuntimeException("Sheet not found: $sheet");
        }
        $xml = $this->xml($this->sheets[$sheet]);
        $rows = [];
        $rowNum = 0;
        // children(NS_MAIN) also finds prefixed elements (<x:row>). r= is optional, so keep a running position.
        $sheetData = $xml->children(self::NS_MAIN)->sheetData;
        foreach (isset($sheetData) ? $sheetData->children(self::NS_MAIN)->row : [] as $row) {
            $r = (string) $row->attributes()['r'];
            $rowNum = ctype_digit($r) ? (int) $r : $rowNum + 1;
            $colNum = 0;
            foreach ($row->children(self::NS_MAIN)->c as $c) {
                if (preg_match('/^([A-Z]+)(\d+)$/', (string) $c->attributes()['r'], $m)) {
                    $colNum = self::columnIndex($m[1]);
                    $rowNum = (int) $m[2];
                } else {
                    $colNum++;
                }
                $value = $this->cellValue($c);
                if ($value === null || trim($value) === '') {
                    continue;
                }
                $rows[$rowNum][self::columnLetter($colNum)] = $value;
            }
        }
        ksort($rows);

        return $rows;
    }

    private function cellValue(SimpleXMLElement $c): ?string
    {
        $type = (string) $c->attributes()['t'];
        $style = (int) $c->attributes()['s'];
        $el = $c->children(self::NS_MAIN);

        return match ($type) {
            's' => $this->sharedStrings[(int) $el->v] ?? null,                // shared string
            'inlineStr' => $this->richText($el->is),                           // inline string
            'str' => isset($el->v) ? (string) $el->v : null,                   // formula returning text
            'b' => isset($el->v) ? ((string) $el->v === '1' ? 'TRUE' : 'FALSE') : null,
            'd' => isset($el->v) ? self::isoDate((string) $el->v) : null,      // ISO 8601: "2024-01-15T00:00:00"
            'e' => null,                                                       // #N/A, #REF! ...
            default => isset($el->v) ? $this->number((string) $el->v, $style) : null,
        };
    }

    private static function isoDate(string $v): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : $v;
    }

    /** "A" -> 1, "AA" -> 27 */
    private static function columnIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + ord($ch) - 64;
        }

        return $n;
    }

    /** 1 -> "A", 27 -> "AA" */
    private static function columnLetter(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $s = chr(65 + ($n - 1) % 26).$s;
            $n = intdiv($n - 1, 26);
        }

        return $s;
    }

    private function number(string $raw, int $style): string
    {
        if (($this->dateStyle[$style] ?? false) && is_numeric($raw)) {
            // Excel serial date: days since 1899-12-30, or since 1904-01-01 in the 1904 date system
            $days = (int) floor((float) $raw) + ($this->date1904 ? 1462 : 0);

            return gmdate('Y-m-d', ($days - 25569) * 86400);
        }

        return $raw;
    }

    private function richText(?SimpleXMLElement $node): ?string
    {
        if ($node === null) {
            return null;
        }
        $node = $node->children(self::NS_MAIN);
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $text = '';
        foreach ($node->r as $run) {            // rich text runs; phonetic runs (rPh) are ignored
            $text .= (string) $run->children(self::NS_MAIN)->t;
        }

        return $text;
    }

    private function loadSheets(): void
    {
        $rels = [];
        $relXml = $this->xml('xl/_rels/workbook.xml.rels');
        foreach ($relXml->Relationship as $r) {
            $target = (string) $r['Target'];
            $rels[(string) $r['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
        }
        $wb = $this->xml('xl/workbook.xml')->children(self::NS_MAIN);
        if (isset($wb->workbookPr)) {
            $this->date1904 = in_array(strtolower((string) $wb->workbookPr->attributes()['date1904']), ['1', 'true'], true);
        }
        foreach ($wb->sheets->children(self::NS_MAIN)->sheet as $s) {
            $rid = (string) $s->attributes(self::NS_REL)['id'];
            if (isset($rels[$rid])) {
                $this->sheets[(string) $s->attributes()['name']] = $rels[$rid];
            }
        }
    }

    private function loadSharedStrings(): void
    {
        if ($this->zip->locateName('xl/sharedStrings.xml') === false) {
            return;
        }
        foreach ($this->xml('xl/sharedStrings.xml')->children(self::NS_MAIN)->si as $si) {
            $this->sharedStrings[] = (string) $this->richText($si);
        }
    }

    private function loadStyles(): void
    {
        if ($this->zip->locateName('xl/styles.xml') === false) {
            return;
        }
        $xml = $this->xml('xl/styles.xml')->children(self::NS_MAIN);
        $custom = [];
        foreach ($xml->numFmts->numFmt ?? [] as $f) {
            $custom[(int) $f->attributes()['numFmtId']] = (string) $f->attributes()['formatCode'];
        }
        $i = 0;
        foreach ($xml->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf->attributes()['numFmtId'];
            $this->dateStyle[$i++] = self::isDateFormat($id, $custom[$id] ?? null);
        }
    }

    /**
     * Built-in date formats are ids 14-17, 22, 27-36, 50-58 (18-21 and 45-47 are times only);
     * custom ones contain d / y tokens.
     */
    public static function isDateFormat(int $id, ?string $code): bool
    {
        if (($id >= 14 && $id <= 17) || $id === 22 || ($id >= 27 && $id <= 36) || ($id >= 50 && $id <= 58)) {
            return true;
        }
        if ($code === null) {
            return false;
        }
        $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $code);   // drop quoted text, [colours], escaped chars

        // A date format has a day or year token; "h:mm" (time) and "#,##0.00" (number) do not.
        return (bool) preg_match('/[dy]/i', $code);
    }

    private function xml(string $path): SimpleXMLElement
    {
        $data = $this->zip->getFromName($path);
        if ($data === false) {
            throw new RuntimeException("Missing part in .xlsx: $path");
        }
        $xml = simplexml_load_string($data, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if ($xml === false) {
            throw new RuntimeException("Invalid XML in .xlsx part: $path");
        }
        $xml->registerXPathNamespace('m', self::NS_MAIN);

        return $xml;
    }
}
