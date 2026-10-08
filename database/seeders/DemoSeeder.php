<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

abstract class DemoSeeder extends Seeder
{
    /** Guard every entrypoint and serialize demo fixture creation without persistent lock rows. */
    final public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException('Demo seeding is only allowed in local and testing environments.');
        }

        $password = config('demo.password');

        if (! is_string($password) || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new LogicException('Set DEMO_SEED_PASSWORD to a local-only password of 12 to 72 bytes without null bytes.');
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Demo seeding requires PostgreSQL.');
        }

        DB::transaction(function (): void {
            DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [20261008, 1]);
            $this->seed();
        }, attempts: 3);
    }

    abstract protected function seed(): void;
}
