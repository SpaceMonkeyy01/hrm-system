<?php

namespace App\Support\Xls;

use RuntimeException;

/**
 * Minimal reader for old binary Excel (.xls) files, with no dependencies. Enough for attendance exports.
 *
 * ZKTeco's software writes a bare BIFF record stream (no OLE container) using Excel 2.x cell records
 * (LABEL 0x0004). Excel 97-2003 writes BIFF8 inside an OLE compound file (shared strings, RK numbers).
 * Both are read here. Returns the cells of the first worksheet as text; numbers come back as they would
 * print ("12", "0.5"). Dates stored as numbers are not converted: attendance exports store them as text.
 */
class XlsReader
{
    /** @var array<int, array<int, string>> row index => [column index => value], both 0-based */
    private array $rows = [];

    /** @var list<string> BIFF8 shared string table */
    private array $sst = [];

    private string $codepage = 'Windows-1252';

    public function __construct(string $path)
    {
        if (! is_file($path)) {
            throw new RuntimeException("File not found: $path");
        }
        $data = (string) file_get_contents($path);
        if (str_starts_with($data, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            $data = self::oleStream($data);
        }
        if (strlen($data) < 4 || ! in_array(unpack('v', $data)[1], [0x0009, 0x0209, 0x0409, 0x0809], true)) {
            throw new RuntimeException('Not an Excel .xls file.');
        }
        $this->parse($data);
    }

    /** @return array<int, array<int, string>> rows of the first worksheet, 0-based, empty cells left out */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * Rows as lists keyed by the header row: the first row that has all of $required (any row, for none), so
     * title lines above the table (HR.my: "Time Clock Records for ...", "Employer: ...") are skipped.
     *
     * @param  list<string>  $required
     * @return list<array<string, string>>
     */
    public function records(array $required = []): array
    {
        $rows = $this->rows;
        ksort($rows);
        $header = null;
        $out = [];
        foreach ($rows as $cells) {
            if ($header === null) {
                $names = array_map('trim', $cells);
                if (! array_diff($required, $names)) {
                    $header = $names;
                }

                continue;
            }
            $record = [];
            foreach ($header as $col => $name) {
                $record[$name] = trim($cells[$col] ?? '');
            }
            if (array_filter($record, fn ($v) => $v !== '')) {
                $out[] = $record;
            }
        }

        return $out;
    }

    private function parse(string $data): void
    {
        $len = strlen($data);
        $pos = 0;
        $sheet = -1;          // -1 = workbook globals (BIFF5+), 0 = first worksheet
        $sstParts = null;     // SST record body plus CONTINUE records, parsed once complete
        while ($pos + 4 <= $len) {
            ['t' => $type, 'l' => $size] = unpack('vt/vl', $data, $pos);
            $body = substr($data, $pos + 4, $size);
            $pos += 4 + $size;

            if ($sstParts !== null) {
                if ($type === 0x003C) {
                    $sstParts[] = $body;
                    continue;
                }
                $this->readSst($sstParts);
                $sstParts = null;
            }

            switch ($type) {
                case 0x0009: case 0x0209: case 0x0409: case 0x0809:   // BOF
                    $kind = strlen($body) >= 4 ? unpack('v', $body, 2)[1] : 0x10;
                    // BIFF2-4 files have no globals: their first BOF is the worksheet itself.
                    if ($kind === 0x0010 || $type !== 0x0809) {
                        $sheet++;
                    }
                    break;
                case 0x0042:                                          // CODEPAGE
                    $cp = unpack('v', $body)[1];
                    $this->codepage = match ($cp) { 1200 => 'UTF-16LE', 65001 => 'UTF-8', 367 => 'ASCII', default => 'Windows-'.$cp };
                    if (! in_array($cp, [1200, 65001, 367, 1250, 1251, 1252, 1253, 1254, 1255, 1256, 1257, 1258], true)) {
                        $this->codepage = 'Windows-1252';
                    }
                    break;
                case 0x00FC:                                          // SST
                    $sstParts = [$body];
                    break;
                case 0x000A:                                          // EOF
                    if ($sheet === 0) {
                        return;                                       // only the first worksheet is needed
                    }
                    break;
            }
            if ($sheet !== 0) {
                continue;
            }
            match ($type) {
                0x0004 => $this->put($body, $this->decode(substr($body, 8, ord($body[7] ?? "\0")))),   // LABEL (BIFF2)
                0x0003 => $this->put($body, self::number(unpack('e', $body, 7)[1])),                   // NUMBER (BIFF2)
                0x0002 => $this->put($body, (string) unpack('v', $body, 7)[1]),                        // INTEGER (BIFF2)
                0x0204 => $this->put($body, $this->unicodeString($body, 6, 2)),                        // LABEL (BIFF3-8)
                0x00FD => $this->put($body, $this->sst[unpack('V', $body, 6)[1]] ?? ''),               // LABELSST
                0x0203 => $this->put($body, self::number(unpack('e', $body, 6)[1])),                   // NUMBER
                0x027E => $this->put($body, self::number(self::rk(unpack('V', $body, 6)[1]))),         // RK
                0x00BD => $this->mulRk($body),                                                         // MULRK
                default => null,
            };
        }
    }

    private function put(string $body, string $value): void
    {
        ['r' => $row, 'c' => $col] = unpack('vr/vc', $body);
        if ($value !== '') {
            $this->rows[$row][$col] = $value;
        }
    }

    private function mulRk(string $body): void
    {
        ['r' => $row, 'c' => $first] = unpack('vr/vc', $body);
        $count = intdiv(strlen($body) - 6, 6);
        for ($i = 0; $i < $count; $i++) {
            $this->rows[$row][$first + $i] = self::number(self::rk(unpack('V', $body, 4 + $i * 6 + 2)[1]));
        }
    }

    /** BIFF8 XLUnicodeString at $offset with a $lenBytes-byte length prefix (BIFF5 and older: 8-bit text, no flags). */
    private function unicodeString(string $body, int $offset, int $lenBytes): string
    {
        $n = $lenBytes === 2 ? unpack('v', $body, $offset)[1] : ord($body[$offset]);
        $offset += $lenBytes;
        if (strlen($body) <= $offset + $n && strlen($body) === $offset + $n) {
            return $this->decode(substr($body, $offset, $n));      // BIFF3-5: no option flags byte
        }
        $flags = ord($body[$offset]);

        return ($flags & 0x01)
            ? mb_convert_encoding(substr($body, $offset + 1, $n * 2), 'UTF-8', 'UTF-16LE')
            : $this->decode(substr($body, $offset + 1, $n));
    }

    /** @param list<string> $parts SST body and its CONTINUE records */
    private function readSst(array $parts): void
    {
        $count = unpack('V', $parts[0], 4)[1];
        $part = 0;
        $buf = $parts[0];
        $pos = 8;
        $next = function () use (&$part, &$buf, &$pos, $parts): bool {
            if (! isset($parts[$part + 1])) {
                return false;
            }
            $buf = $parts[++$part];
            $pos = 0;

            return true;
        };
        for ($i = 0; $i < $count; $i++) {
            if ($pos + 3 > strlen($buf) && ! $next()) {
                break;
            }
            $chars = unpack('v', $buf, $pos)[1];
            $flags = ord($buf[$pos + 2]);
            $pos += 3;
            $runs = ($flags & 0x08) ? unpack('v', $buf, $pos)[1] : 0;
            $pos += ($flags & 0x08) ? 2 : 0;
            $ext = ($flags & 0x04) ? unpack('V', $buf, $pos)[1] : 0;
            $pos += ($flags & 0x04) ? 4 : 0;
            $wide = (bool) ($flags & 0x01);
            $text = '';
            while ($chars > 0) {
                $avail = intdiv(strlen($buf) - $pos, $wide ? 2 : 1);
                $take = min($chars, $avail);
                $raw = substr($buf, $pos, $take * ($wide ? 2 : 1));
                $text .= $wide ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : $this->decode($raw);
                $pos += strlen($raw);
                $chars -= $take;
                if ($chars > 0) {
                    if (! $next()) {
                        break 2;
                    }
                    $wide = (bool) (ord($buf[0]) & 0x01);   // a continued string restates its width
                    $pos = 1;
                }
            }
            $skip = $runs * 4 + $ext;                        // rich text runs and phonetic data: not needed
            while ($skip > 0) {
                $here = min($skip, strlen($buf) - $pos);
                $pos += $here;
                $skip -= $here;
                if ($skip > 0 && ! $next()) {
                    break 2;
                }
            }
            $this->sst[] = $text;
        }
    }

    private function decode(string $bytes): string
    {
        if ($this->codepage === 'UTF-8' || mb_check_encoding($bytes, 'ASCII')) {
            return $bytes;
        }

        return mb_convert_encoding($bytes, 'UTF-8', $this->codepage);
    }

    private static function rk(int $rk): float
    {
        $value = ($rk & 0x02)
            ? (float) (($rk >> 2) | (($rk & 0x80000000) ? ~0x3FFFFFFF : 0))            // 30-bit signed integer
            : unpack('e', pack('V2', 0, $rk & 0xFFFFFFFC))[1];                        // top 30 bits of a double

        return ($rk & 0x01) ? $value / 100 : $value;
    }

    private static function number(float $n): string
    {
        return $n == floor($n) && abs($n) < 1e15 ? (string) (int) $n : rtrim(rtrim(sprintf('%.10F', $n), '0'), '.');
    }

    /** The "Workbook" (BIFF8) or "Book" (BIFF5) stream from an OLE compound file. */
    private static function oleStream(string $ole): string
    {
        $h = unpack('vminor/vmajor/vorder/vsectorShift/vminiShift', $ole, 0x18);
        $sec = 1 << $h['sectorShift'];
        $mini = 1 << $h['miniShift'];
        ['fatCount' => $fatCount, 'dirStart' => $dirStart] = unpack('VfatCount/VdirStart', $ole, 0x2C);
        ['cutoff' => $cutoff, 'miniFatStart' => $miniFatStart, 'miniFatCount' => $miniFatCount,
            'difatStart' => $difatStart, 'difatCount' => $difatCount] = unpack('Vcutoff/VminiFatStart/VminiFatCount/VdifatStart/VdifatCount', $ole, 0x38);
        $sector = fn (int $id) => substr($ole, ($id + 1) * $sec, $sec);

        $fatSectors = array_values(unpack('V109', $ole, 0x4C));
        for ($d = $difatStart, $i = 0; $i < $difatCount && $d < 0xFFFFFFFA; $i++) {
            $ids = array_values(unpack('V'.($sec / 4), $sector($d)));
            $d = array_pop($ids);
            array_push($fatSectors, ...$ids);
        }
        $fat = [];
        foreach (array_slice($fatSectors, 0, $fatCount) as $id) {
            array_push($fat, ...array_values(unpack('V'.($sec / 4), $sector($id))));
        }
        $chain = function (int $start) use ($fat, $sector): string {
            $out = '';
            for ($id = $start, $guard = 0; $id < 0xFFFFFFFA && $guard < count($fat); $guard++) {
                $out .= $sector($id);
                $id = $fat[$id] ?? 0xFFFFFFFE;
            }

            return $out;
        };

        $dir = $chain($dirStart);
        $root = null;
        $found = null;
        for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
            $nameLen = unpack('v', $dir, $o + 64)[1];
            $name = mb_convert_encoding(substr($dir, $o, max(0, $nameLen - 2)), 'UTF-8', 'UTF-16LE');
            $entry = ['type' => ord($dir[$o + 66]), 'start' => unpack('V', $dir, $o + 116)[1], 'size' => unpack('V', $dir, $o + 120)[1]];
            if ($entry['type'] === 5) {
                $root = $entry;
            } elseif (in_array($name, ['Workbook', 'Book'], true) && $found === null) {
                $found = $entry;
            }
        }
        if (! $found) {
            throw new RuntimeException('This .xls file has no workbook inside.');
        }
        if ($found['size'] >= $cutoff) {
            return substr($chain($found['start']), 0, $found['size']);
        }
        // Small stream: lives in the mini stream (inside the root entry's chain), indexed by the mini FAT.
        $miniFat = [];
        if ($miniFatCount > 0) {
            $raw = $chain($miniFatStart);
            $miniFat = array_values(unpack('V'.intdiv(strlen($raw), 4), $raw));
        }
        $miniData = $root ? $chain($root['start']) : '';
        $out = '';
        for ($id = $found['start'], $guard = 0; $id < 0xFFFFFFFA && $guard <= count($miniFat); $guard++) {
            $out .= substr($miniData, $id * $mini, $mini);
            $id = $miniFat[$id] ?? 0xFFFFFFFE;
        }

        return substr($out, 0, $found['size']);
    }
}
