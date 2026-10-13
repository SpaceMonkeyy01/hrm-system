<?php

namespace App\Support\Zk;

use RuntimeException;

/**
 * Talks to a ZKTeco attendance machine over TCP (port 4370), the protocol the machine's own PC software uses.
 * READ ONLY: it connects, reads the user list, the punch log, the clock and the counts, and disconnects. There is
 * deliberately no method that writes, clears or restarts anything on the machine.
 *
 * Tested against a uFace800 Plus, firmware Ver 6.60 (40-byte punch records, 72-byte user records); the older
 * 8- and 16-byte punch and 28-byte user layouts are read too.
 */
class ZkClient implements AttendanceMachine
{
    private const CMD_CONNECT = 1000;

    private const CMD_EXIT = 1001;

    private const CMD_AUTH = 1102;

    private const CMD_GET_VERSION = 1100;

    private const CMD_OPTIONS_RRQ = 11;

    private const CMD_GET_TIME = 201;

    private const CMD_GET_FREE_SIZES = 50;

    private const CMD_USERTEMP_RRQ = 9;

    private const CMD_ATTLOG_RRQ = 13;

    private const CMD_PREPARE_DATA = 1500;

    private const CMD_DATA = 1501;

    private const CMD_FREE_DATA = 1502;

    private const CMD_PREPARE_BUFFER = 1503;

    private const CMD_READ_BUFFER = 1504;

    private const ACK_OK = 2000;

    private const ACK_UNAUTH = 2005;

    private const FCT_USER = 5;

    private const MAX_CHUNK = 0xFFC0;

    private const USHRT_MAX = 65535;

    /** How the machine was unlocked: verify type numbers to the words the "Full day log" uses. */
    private const VERIFY = [0 => 'PW', 1 => 'FP', 2 => 'CARD', 3 => 'PW', 4 => 'CARD', 15 => 'FACE', 25 => 'PALM'];

    /** @var resource|null */
    private $socket = null;

    private int $session = 0;

    private int $reply = self::USHRT_MAX - 1;

    private ?array $sizes = null;

    public function __construct(private string $host, private int $port = 4370, private int $commKey = 0, private int $timeout = 15) {}

    public function connect(): void
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, $this->timeout);
        if (! $socket) {
            throw new RuntimeException("Couldn't reach the machine at {$this->host}:{$this->port} ($error).");
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
        $this->session = 0;
        $this->reply = self::USHRT_MAX - 1;

        [$cmd, $session] = $this->command(self::CMD_CONNECT);
        $this->session = $session;
        if ($cmd === self::ACK_UNAUTH) {
            [$cmd] = $this->command(self::CMD_AUTH, self::commKey($this->commKey, $session));
            if ($cmd !== self::ACK_OK) {
                $this->close();
                throw new RuntimeException('The machine refused the Comm Key. Check ZK_COMM_KEY against the machine (Comm. > Comm Key).');
            }
        } elseif ($cmd !== self::ACK_OK) {
            $this->close();
            throw new RuntimeException("The machine didn't accept the connection (reply $cmd).");
        }
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            try {
                $this->command(self::CMD_EXIT);
            } catch (RuntimeException) {
                // Leaving anyway.
            }
            $this->close();
        }
    }

    /** @return array{firmware: string, serial: string, name: string, users: int, records: int} */
    public function info(): array
    {
        $sizes = $this->sizes();

        return [
            'firmware' => self::cString($this->command(self::CMD_GET_VERSION)[2]),
            'serial' => $this->option('~SerialNumber'),
            'name' => $this->option('~DeviceName'),
            'users' => $sizes['users'],
            'records' => $sizes['records'],
        ];
    }

    /** The machine's own clock, as "Y-m-d H:i:s" (local time on the machine). */
    public function time(): string
    {
        return self::decodeTime($this->command(self::CMD_GET_TIME)[2]);
    }

    /** @return array<string, string> machine number (the "No." in the Full day log) => name typed on the machine */
    public function users(): array
    {
        $count = $this->sizes()['users'];
        if ($count === 0) {
            return [];
        }

        return self::parseUsers($this->readBuffer(self::CMD_USERTEMP_RRQ, self::FCT_USER), $count);
    }

    /** @return list<array{user_id: string, time: string, method: string}> every punch stored on the machine, oldest first */
    public function attendance(): array
    {
        $count = $this->sizes()['records'];
        if ($count === 0) {
            return [];
        }

        return self::parseAttendance($this->readBuffer(self::CMD_ATTLOG_RRQ), $count);
    }

    /** @return array{users: int, records: int} */
    private function sizes(): array
    {
        if ($this->sizes === null) {
            $f = array_values(unpack('V20', substr($this->command(self::CMD_GET_FREE_SIZES)[2], 0, 80)));
            $this->sizes = ['users' => $f[4], 'records' => $f[8]];
        }

        return $this->sizes;
    }

    private function option(string $name): string
    {
        $value = self::cString($this->command(self::CMD_OPTIONS_RRQ, $name."\0")[2]);

        return str_contains($value, '=') ? explode('=', $value, 2)[1] : $value;
    }

    /** Asks the machine to prepare a table, then reads it in chunks. */
    private function readBuffer(int $command, int $fct = 0, int $ext = 0): string
    {
        [$cmd, , $data] = $this->command(self::CMD_PREPARE_BUFFER, pack('cvVV', 1, $command, $fct, $ext));
        if ($cmd === self::CMD_DATA) {
            return $data;
        }
        $size = unpack('V', substr($data, 1, 4))[1];
        $out = '';
        for ($start = 0; $start < $size; $start += $n) {
            $n = min(self::MAX_CHUNK, $size - $start);
            [$cmd, , $data] = $this->command(self::CMD_READ_BUFFER, pack('VV', $start, $n));
            if ($cmd === self::CMD_DATA) {
                $out .= $data;
            } elseif ($cmd === self::CMD_PREPARE_DATA) {
                $want = unpack('V', substr($data, 0, 4))[1];
                $got = '';
                while (strlen($got) < $want) {
                    [$c, , $d] = $this->packet();
                    if ($c === self::CMD_DATA) {
                        $got .= $d;
                    }
                }
                $this->packet();   // the closing ACK_OK
                $out .= $got;
            } else {
                throw new RuntimeException("Unexpected reply $cmd while reading from the machine.");
            }
        }
        $this->command(self::CMD_FREE_DATA);

        return $out;
    }

    /** @return array{0: int, 1: int, 2: string} reply command, session id, data */
    private function command(int $cmd, string $data = ''): array
    {
        // The checksum is taken over the previous reply id; the packet then carries the next one.
        $checksum = self::checksum(pack('vvvv', $cmd, 0, $this->session, $this->reply).$data);
        $this->reply = ($this->reply + 1) % self::USHRT_MAX;
        $packet = pack('vvvv', $cmd, $checksum, $this->session, $this->reply).$data;
        if (@fwrite($this->socket, pack('vvV', 0x5050, 0x7D82, strlen($packet)).$packet) === false) {
            throw new RuntimeException('Lost the connection to the machine.');
        }

        return $this->packet();
    }

    /** @return array{0: int, 1: int, 2: string} */
    private function packet(): array
    {
        $size = unpack('vmagic1/vmagic2/Vsize', $this->read(8))['size'];
        $body = $this->read($size);
        $h = unpack('vcmd/vsum/vsession/vreply', substr($body, 0, 8));

        return [$h['cmd'], $h['session'], substr($body, 8)];
    }

    private function read(int $length): string
    {
        $out = '';
        while (strlen($out) < $length) {
            $chunk = fread($this->socket, $length - strlen($out));
            if ($chunk === false || $chunk === '') {
                $timedOut = stream_get_meta_data($this->socket)['timed_out'] ?? false;
                throw new RuntimeException($timedOut ? 'The machine stopped answering (timed out).' : 'The machine closed the connection.');
            }
            $out .= $chunk;
        }

        return $out;
    }

    private function close(): void
    {
        if ($this->socket) {
            fclose($this->socket);
        }
        $this->socket = null;
        $this->sizes = null;
    }

    // ---------------------------------------------------------------- pure helpers (unit tested)

    public static function checksum(string $buf): int
    {
        $sum = 0;
        $len = strlen($buf);
        for ($i = 0; $i + 1 < $len; $i += 2) {
            $sum += ord($buf[$i]) | (ord($buf[$i + 1]) << 8);
            if ($sum > self::USHRT_MAX) {
                $sum -= self::USHRT_MAX;
            }
        }
        if ($len % 2) {
            $sum += ord($buf[$len - 1]);
        }
        while ($sum > self::USHRT_MAX) {
            $sum -= self::USHRT_MAX;
        }
        $sum = ~$sum;
        while ($sum < 0) {
            $sum += self::USHRT_MAX;
        }

        return $sum;
    }

    /** The 4 bytes that unlock a machine with a Comm Key, scrambled with the session id. */
    public static function commKey(int $key, int $session, int $ticks = 50): string
    {
        $k = 0;
        for ($i = 0; $i < 32; $i++) {
            $k = ($key & (1 << $i)) ? (($k << 1) | 1) : ($k << 1);
            $k &= 0xFFFFFFFF;
        }
        $b = pack('V', ($k + $session) & 0xFFFFFFFF);
        $b = chr(ord($b[0]) ^ ord('Z')).chr(ord($b[1]) ^ ord('K')).chr(ord($b[2]) ^ ord('S')).chr(ord($b[3]) ^ ord('O'));
        $h = unpack('v2', $b);
        $b = pack('vv', $h[2], $h[1]);
        $t = $ticks & 0xFF;

        return chr(ord($b[0]) ^ $t).chr(ord($b[1]) ^ $t).chr($t).chr(ord($b[3]) ^ $t);
    }

    /** The machine's packed date-time (seconds in a 31-day-month calendar from 2000) to "Y-m-d H:i:s". */
    public static function decodeTime(string $raw): string
    {
        $t = unpack('V', substr($raw, 0, 4))[1];
        $sec = $t % 60;
        $t = intdiv($t, 60);
        $min = $t % 60;
        $t = intdiv($t, 60);
        $hour = $t % 24;
        $t = intdiv($t, 24);
        $day = $t % 31 + 1;
        $t = intdiv($t, 31);
        $month = $t % 12 + 1;
        $year = intdiv($t, 12) + 2000;

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $min, $sec);
    }

    /** @return array<string, string> */
    public static function parseUsers(string $raw, int $count): array
    {
        $total = unpack('V', substr($raw, 0, 4))[1];
        $size = intdiv($total, max(1, $count));
        $body = substr($raw, 4);
        $users = [];
        for ($i = 0; $i + $size <= $total && $size > 0; $i += $size) {
            $u = substr($body, $i, $size);
            if ($size === 72) {
                $users[self::cString(substr($u, 48, 24))] = self::cString(substr($u, 11, 24));
            } elseif ($size === 28) {
                $users[(string) unpack('V', substr($u, 24, 4))[1]] = self::cString(substr($u, 8, 8));
            } else {
                throw new RuntimeException("Unknown user record size $size bytes.");
            }
        }
        unset($users['']);

        return $users;
    }

    /** @return list<array{user_id: string, time: string, method: string}> */
    public static function parseAttendance(string $raw, int $count): array
    {
        $total = unpack('V', substr($raw, 0, 4))[1];
        $size = intdiv($total, max(1, $count));
        $body = substr($raw, 4);
        $rows = [];
        for ($i = 0; $i + $size <= $total && $size > 0; $i += $size) {
            $r = substr($body, $i, $size);
            [$user, $verify, $time] = match ($size) {
                40 => [self::cString(substr($r, 2, 24)), ord($r[26]), substr($r, 27, 4)],
                16 => [(string) unpack('V', substr($r, 0, 4))[1], ord($r[8]), substr($r, 4, 4)],
                8 => ['uid:'.unpack('v', substr($r, 0, 2))[1], ord($r[2]), substr($r, 3, 4)],
                default => throw new RuntimeException("Unknown punch record size $size bytes."),
            };
            if ($user !== '') {
                $rows[] = ['user_id' => $user, 'time' => self::decodeTime($time), 'method' => self::VERIFY[$verify] ?? 'Other'];
            }
        }

        return $rows;
    }

    private static function cString(string $s): string
    {
        return trim(explode("\0", $s, 2)[0]);
    }
}
