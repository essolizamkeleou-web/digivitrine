<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run on any database whose name does not end with "_test".
     *
     * This runs right after the application boots and BEFORE traits such as
     * RefreshDatabase get a chance to drop tables.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests on database "%s": its name must end with "_test".',
                $database
            ));
        }
    }
}