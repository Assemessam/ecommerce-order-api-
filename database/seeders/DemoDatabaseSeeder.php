<?php

namespace Database\Seeders;

class DemoDatabaseSeeder extends DemoSeeder
{
    /**
     * Run the database seeds.
     */
    protected function seed(): void
    {
        $this->call([
            AuthorizationSeeder::class,
            DemoUserSeeder::class,
            DemoProductSeeder::class,
            DemoPromotionSeeder::class,
            DemoOrderSeeder::class,
            DemoCartSeeder::class,
        ]);
    }
}
