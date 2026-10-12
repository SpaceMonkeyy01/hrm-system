<?php

namespace Tests\Support;

/**
 * Writes a tiny .xls the way the ZKTeco software does: a bare BIFF record stream (no OLE container),
 * every cell an Excel 2.x LABEL record. For tests only, with fake data.
 */
class XlsBuilder
{
    /** @param list<list<string>> $rows first row = headers */
    public static function biff2(array $rows): string
    {
        $out = self::record(0x0809, pack('vvvv', 0, 0x10, 0, 0));     // BOF: worksheet
        foreach ($rows as $r => $cells) {
            foreach (array_values($cells) as $c => $value) {
                if ($value === '') {
                    continue;
                }
                $text = substr($value, 0, 255);
                $out .= self::record(0x0004, pack('vv', $r, $c)."\x00\x00\x00".chr(strlen($text)).$text);
            }
        }

        return $out.self::record(0x000A, '');                           // EOF
    }

    /** Machine "Full day log" with the real headers. @param list<array{0: string, 1: string, 2: string}> $punches [name, no, "5/13/2026 9:58:03 AM"] */
    public static function punchLog(array $punches): string
    {
        $rows = [['Department', 'Name', 'No.', 'Date/Time', 'Location ID', 'ID Number', 'VerifyCode', 'CardNo']];
        foreach ($punches as [$name, $no, $at]) {
            $rows[] = ['OUR COMPANY', $name, $no, $at, '1', '', 'FACE', ''];
        }

        return self::biff2($rows);
    }

    public static function save(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xls').'.xls';
        file_put_contents($path, $bytes);

        return $path;
    }

    private static function record(int $type, string $body): string
    {
        return pack('vv', $type, strlen($body)).$body;
    }
}
