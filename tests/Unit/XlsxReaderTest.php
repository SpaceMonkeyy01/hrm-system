<?php

namespace Tests\Unit;

use App\Support\Xlsx\XlsxReader;
use PHPUnit\Framework\TestCase;
use Tests\Support\XlsxBuilder;

class XlsxReaderTest extends TestCase
{
    public function test_reads_strings_numbers_dates_and_cached_formula_values(): void
    {
        $path = (new XlsxBuilder)->sheet('People', [
            1 => ['A' => 'Name', 'B' => 'Start', 'C' => 'End', 'D' => 'ID', 'E' => 'Status'],
            2 => ['A' => 'Nida Qaddir', 'B' => ['date' => '2026-01-14'], 'C' => ['f' => '=B2+90', 'date' => '2026-04-14'], 'D' => 7, 'E' => ['f' => '=IF(1,"Complete","")', 'v' => 'Complete']],
            4 => ['A' => '  '],
        ])->save(sys_get_temp_dir().'/xlsx-reader-test.xlsx');

        $r = new XlsxReader($path);
        $this->assertSame(['People'], $r->sheetNames());
        $rows = $r->rows('People');
        $this->assertSame(['A' => 'Nida Qaddir', 'B' => '2026-01-14', 'C' => '2026-04-14', 'D' => '7', 'E' => 'Complete'], $rows[2]);
        $this->assertArrayNotHasKey(4, $rows, 'blank cells are skipped');
    }

    public function test_date_format_detection(): void
    {
        $this->assertTrue(XlsxReader::isDateFormat(14, null));
        $this->assertTrue(XlsxReader::isDateFormat(164, 'yyyy-mm-dd'));
        $this->assertTrue(XlsxReader::isDateFormat(165, '[$-409]d mmm yyyy'));
        $this->assertFalse(XlsxReader::isDateFormat(166, '#,##0.00'));
        $this->assertFalse(XlsxReader::isDateFormat(167, 'h:mm'));
        $this->assertFalse(XlsxReader::isDateFormat(0, null));
    }
}
