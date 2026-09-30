<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TestEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_tests_run_on_the_dedicated_postgresql_test_database(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('digivitrine_test', DB::connection()->getDatabaseName());
    }

    public function test_migrations_are_applied_on_the_test_database(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('migrations'));
    }
}