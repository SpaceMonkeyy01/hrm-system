<?php

namespace Tests\Unit;

use App\Support\Zk\ZkClient;
use PHPUnit\Framework\TestCase;

/** The machine protocol's pure parts. Expected values come from the probe that read the real uFace800 Plus. */
class ZkClientTest extends TestCase
{
    public function test_checksum_and_comm_key_match_the_machine(): void
    {
        $this->assertSame(64535, ZkClient::checksum(pack('vvvv', 1000, 0, 0, 65534)));
        $this->assertSame('281d3279', bin2hex(ZkClient::commKey(987654, 4321)));
        $this->assertSame(21621, ZkClient::checksum(pack('vvvv', 1102, 0, 4321, 0).ZkClient::commKey(987654, 4321)));
    }

    public function test_machine_time_is_decoded(): void
    {
        $this->assertSame('2026-01-30 21:51:51', ZkClient::decodeTime(pack('V', 838245111)));
    }

    private static function packTime(string $at): string
    {
        [$y, $mo, $d, $h, $mi, $s] = array_map('intval', preg_split('/[- :]/', $at));

        return pack('V', (((($y - 2000) * 12 + $mo - 1) * 31 + $d - 1) * 86400) + $h * 3600 + $mi * 60 + $s);
    }

    public function test_40_byte_punch_records_and_72_byte_users(): void
    {
        $rec = fn ($id, $verify, $at) => pack('v', 1).str_pad($id, 24, "\0").chr($verify).self::packTime($at).chr(0).str_repeat("\0", 8);
        $body = $rec('2', 15, '2026-10-13 09:58:03').$rec('172', 1, '2026-10-13 18:02:00').$rec('', 1, '2026-10-13 18:03:00');
        $this->assertSame([
            ['user_id' => '2', 'time' => '2026-10-13 09:58:03', 'method' => 'FACE'],
            ['user_id' => '172', 'time' => '2026-10-13 18:02:00', 'method' => 'FP'],
        ], ZkClient::parseAttendance(pack('V', strlen($body)).$body, 3));

        $user = fn ($id, $name) => pack('vC', 1, 0).str_repeat("\0", 8).str_pad($name, 24, "\0").pack('V', 0)."\0".str_pad('1', 7, "\0")."\0".str_pad($id, 24, "\0");
        $body = $user('2', 'Fake Person').$user('172', '');
        $this->assertSame(['2' => 'Fake Person', '172' => ''], ZkClient::parseUsers(pack('V', strlen($body)).$body, 2));
    }
}
