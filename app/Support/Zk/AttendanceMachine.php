<?php

namespace App\Support\Zk;

/** What the sync reads from an attendance machine. ZkClient talks to the real one; tests use a fake. */
interface AttendanceMachine
{
    public function connect(): void;

    public function disconnect(): void;

    /** @return array{firmware: string, serial: string, name: string, users: int, records: int} */
    public function info(): array;

    /** "Y-m-d H:i:s" on the machine's clock */
    public function time(): string;

    /** @return array<string, string> machine number => name on the machine */
    public function users(): array;

    /** @return list<array{user_id: string, time: string, method: string}> */
    public function attendance(): array;
}
