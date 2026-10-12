<?php

namespace Tests\Support;

use ZipArchive;

/**
 * Writes a small .xlsx for tests, shaped like a Google Sheets export:
 * shared strings, a date style, and formulas with cached values.
 *
 * Cell values: string | int | float | ['date' => 'Y-m-d'] | ['f' => '=H3+90', 'date' => 'Y-m-d'] | ['f' => '...', 'v' => 'text']
 */
class XlsxBuilder
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $sheets = [];

    private array $strings = [];

    public function sheet(string $name, array $rows): self
    {
        $this->sheets[$name] = $rows;

        return $this;
    }

    public function save(string $path): string
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $n = 0;
        $sheetsXml = $relsXml = $types = '';
        foreach ($this->sheets as $name => $rows) {
            $n++;
            $zip->addFromString("xl/worksheets/sheet$n.xml", $this->sheetXml($rows));
            $sheetsXml .= '<sheet name="'.htmlspecialchars($name).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $relsXml .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $types .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $relsXml .= '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            .'<Relationship Id="rIdT" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$types.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheetsXml.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relsXml.'</Relationships>');
        // style 0 = General, style 1 = built-in date format 14
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14" applyNumberFormat="1"/></cellXfs></styleSheet>');
        $sst = '';
        foreach ($this->strings as $s) {
            $sst .= '<si><t xml:space="preserve">'.htmlspecialchars($s, ENT_XML1).'</t></si>';
        }
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$sst.'</sst>');
        $zip->close();

        return $path;
    }

    private function sheetXml(array $rows): string
    {
        $xml = '';
        foreach ($rows as $r => $cells) {
            $xml .= '<row r="'.$r.'">';
            foreach ($cells as $col => $value) {
                $xml .= $this->cell("$col$r", $value);
            }
            $xml .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>';
    }

    private function cell(string $ref, mixed $value): string
    {
        if (is_array($value)) {
            $f = isset($value['f']) ? '<f>'.htmlspecialchars(ltrim($value['f'], '=')).'</f>' : '';
            if (isset($value['date'])) {
                return '<c r="'.$ref.'" s="1">'.$f.'<v>'.self::serial($value['date']).'</v></c>';
            }

            return '<c r="'.$ref.'" t="str">'.$f.'<v>'.htmlspecialchars((string) ($value['v'] ?? '')).'</v></c>';
        }
        if (is_int($value) || is_float($value)) {
            return '<c r="'.$ref.'"><v>'.$value.'</v></c>';
        }
        $i = array_search($value, $this->strings, true);
        if ($i === false) {
            $this->strings[] = $value;
            $i = count($this->strings) - 1;
        }

        return '<c r="'.$ref.'" t="s"><v>'.$i.'</v></c>';
    }

    public static function serial(string $date): int
    {
        return (int) (strtotime($date.' UTC') / 86400) + 25569;
    }
}
