<?php

namespace Tests\Unit;

use App\Support\Xlsx\XlsxReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** Valid OOXML that XlsxBuilder doesn't write: hand-made parts, fake values only. */
class XlsxReaderEdgeCasesTest extends TestCase
{
    private const WS = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>%s</sheetData></worksheet>';

    /** Style 0 = General, 1 = built-in date 14, 2 = built-in time 20 (h:mm). */
    private function book(string $sheetXml, string $workbookExtra = ''): XlsxReader
    {
        $path = sys_get_temp_dir().'/xlsx-edge-'.uniqid().'.xlsx';
        $z = new ZipArchive;
        $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.$workbookExtra.'<sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="x" Target="worksheets/sheet1.xml"/></Relationships>');
        $z->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $z->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="20"/></cellXfs></styleSheet>');
        $z->close();

        return new XlsxReader($path);
    }

    /** I8 */
    public function test_1904_date_system_is_honoured(): void
    {
        $r = $this->book(sprintf(self::WS, '<row r="1"><c r="A1" s="1"><v>43831</v></c></row>'), '<workbookPr date1904="1"/>');
        $this->assertSame([1 => ['A' => '2024-01-02']], $r->rows('S'));

        $r = $this->book(sprintf(self::WS, '<row r="1"><c r="A1" s="1"><v>43831</v></c></row>'));
        $this->assertSame([1 => ['A' => '2020-01-01']], $r->rows('S'), '1900 system unchanged');
    }

    /** I9 */
    public function test_cells_and_rows_without_r_attribute_use_their_position(): void
    {
        $r = $this->book(sprintf(self::WS,
            '<row r="1"><c t="inlineStr"><is><t>Name</t></is></c><c t="inlineStr"><is><t>Start</t></is></c></row>'
            .'<row><c t="inlineStr"><is><t>Ali Khan</t></is></c><c r="D2"><v>3</v></c><c><v>4</v></c></row>'));
        $this->assertSame([1 => ['A' => 'Name', 'B' => 'Start'], 2 => ['A' => 'Ali Khan', 'D' => '3', 'E' => '4']], $r->rows('S'));
    }

    /** I10 */
    public function test_prefixed_main_namespace_is_read(): void
    {
        $r = $this->book('<?xml version="1.0"?><x:worksheet xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><x:sheetData>'
            .'<x:row r="1"><x:c r="A1"><x:v>5</x:v></x:c><x:c r="B1" t="inlineStr"><x:is><x:t>Test Person</x:t></x:is></x:c><x:c r="C1" s="1"><x:v>45306</x:v></x:c></x:row>'
            .'</x:sheetData></x:worksheet>');
        $this->assertSame([1 => ['A' => '5', 'B' => 'Test Person', 'C' => '2024-01-15']], $r->rows('S'));
    }

    /** I11 */
    public function test_time_formats_are_not_dates_and_iso_date_cells_are_trimmed(): void
    {
        $r = $this->book(sprintf(self::WS, '<row r="1"><c r="A1" s="2"><v>0.4375</v></c><c r="B1" t="d"><v>2024-01-15T00:00:00</v></c></row>'));
        $this->assertSame([1 => ['A' => '0.4375', 'B' => '2024-01-15']], $r->rows('S'));

        foreach ([18, 19, 20, 21, 45, 46, 47] as $id) {
            $this->assertFalse(XlsxReader::isDateFormat($id, null), "format $id is a time");
        }
        foreach ([14, 15, 16, 17, 22, 27, 36, 50, 58] as $id) {
            $this->assertTrue(XlsxReader::isDateFormat($id, null), "format $id is a date");
        }
    }

    public function test_rich_text_shared_strings_and_wide_columns(): void
    {
        $path = sys_get_temp_dir().'/xlsx-edge-'.uniqid().'.xlsx';
        $z = new ZipArchive;
        $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="x" Target="worksheets/sheet1.xml"/></Relationships>');
        $z->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><r><t>Ali </t></r><r><rPr><b/></rPr><t>Khan</t></r><rPh><t>XX</t></rPh></si><si><t>plain</t></si></sst>');
        $z->addFromString('xl/worksheets/sheet1.xml', sprintf(self::WS,
            '<row r="3"><c r="AA3" t="s"><v>0</v></c><c r="AB3" t="b"><v>1</v></c><c r="AC3" t="e"><v>#N/A</v></c><c r="AD3" t="str"><f>A1</f><v>Done</v></c><c r="AE3" t="s"><v>1</v></c></row>'));
        $z->close();

        $this->assertSame([3 => ['AA' => 'Ali Khan', 'AB' => 'TRUE', 'AD' => 'Done', 'AE' => 'plain']], (new XlsxReader($path))->rows('S'));
    }
}
