<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but an in-memory database.
     *
     * This is not paranoia. phpunit.xml sets DB_DATABASE to :memory:, but a
     * cached configuration overrides that env var, and RefreshDatabase then
     * cheerfully migrates:fresh the real file — which is exactly how the
     * imported catalog and the collection were wiped once. Failing loudly
     * costs a second; the alternative costs a re-import and whatever the user
     * had entered.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $database = config('database.connections.'.config('database.default').'.database');

        if ($database !== ':memory:') {
            throw new RuntimeException(
                "Tests must run against an in-memory database, but the connection points at [{$database}]. "
                .'The configuration is probably cached: run `php artisan config:clear`.'
            );
        }
    }
}
