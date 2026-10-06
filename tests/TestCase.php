<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if (! $app->environment('testing')
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.host') !== 'postgres'
            || (string) config('database.connections.pgsql.port') !== '5432'
            || config('database.connections.pgsql.database') !== 'ecommerce_order_api_test'
            || filled(config('database.connections.pgsql.url'))) {
            throw new \RuntimeException(sprintf(
                'Tests must use the isolated Compose PostgreSQL test database (environment=%s, connection=%s, host=%s, database=%s).',
                $app->environment(), config('database.default'), config('database.connections.pgsql.host'), config('database.connections.pgsql.database'),
            ));
        }

        return $app;
    }
}
