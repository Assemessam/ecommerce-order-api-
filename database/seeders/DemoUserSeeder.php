<?php

namespace Database\Seeders;

use App\Enums\InternalRole;
use App\Models\User;
use LogicException;

class DemoUserSeeder extends DemoSeeder
{
    protected function seed(): void
    {
        /** @var list<array{email: string, name: string, role: ?InternalRole}> $accounts */
        $accounts = [
            ['email' => 'demo-admin@example.test', 'name' => 'Demo | Amina Hassan', 'role' => InternalRole::Administrator],
            ['email' => 'demo-products@example.test', 'name' => 'Demo | Daniel Reed', 'role' => InternalRole::ProductManager],
            ['email' => 'demo-promotions@example.test', 'name' => 'Demo | Sofia Chen', 'role' => InternalRole::PromotionManager],
            ['email' => 'demo-alex@example.test', 'name' => 'Demo | Alex Morgan', 'role' => null],
            ['email' => 'demo-maya@example.test', 'name' => 'Demo | Maya Patel', 'role' => null],
            ['email' => 'demo-omar@example.test', 'name' => 'Demo | Omar Saleh', 'role' => null],
            ['email' => 'demo-lina@example.test', 'name' => 'Demo | Lina Torres', 'role' => null],
            ['email' => 'demo-noah@example.test', 'name' => 'Demo | Noah Brooks', 'role' => null],
        ];
        $createdAt = now('UTC')->subDays(90);

        foreach ($accounts as $account) {
            $user = User::query()->firstOrCreate(['email' => $account['email']], [
                'name' => $account['name'],
                'password' => config('demo.password'),
                'email_verified_at' => $createdAt->copy()->addDay(),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            if ($user->name !== $account['name']) {
                throw new LogicException('Demo account email collision: '.$account['email']);
            }

            if ($user->wasRecentlyCreated && $account['role'] !== null) {
                $user->assignRole($account['role']);
            }
        }
    }
}
