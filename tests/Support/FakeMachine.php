<?php

namespace Tests\Support;

use App\Support\Zk\AttendanceMachine;
use RuntimeException;

/** Stands in for the ZKTeco machine in tests: fixed users, punches and clock, or a connection failure. */
class FakeMachine implements AttendanceMachine
{
    public int $connects = 0;

    /** @param list<array{user_id: string, time: string, method: string}> $punches */
    public function __construct(public array $punches = [], public array $users = [], public string $clock = '', public ?string $fail = null) {}

    public function connect(): void
    {
        $this->connects++;
        if ($this->fail) {
            throw new RuntimeException($this->fail);
        }
    }

    public function disconnect(): void {}

    public function info(): array
    {
        return ['firmware' => 'Ver 6.60', 'serial' => 'FAKE0001', 'name' => 'Fake machine', 'users' => count($this->users), 'records' => count($this->punches)];
    }

    public function time(): string
    {
        return $this->clock ?: now()->format('Y-m-d H:i:s');
    }

    public function users(): array
    {
        return $this->users;
    }

    public function attendance(): array
    {
        return $this->punches;
    }
}
