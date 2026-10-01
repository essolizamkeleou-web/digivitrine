<?php

namespace Tests\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

trait AssertsDatabaseRejections
{
    /**
     * Asserts that the database rejects the given operation.
     *
     * The attempt runs in a nested transaction (a savepoint), so PostgreSQL
     * keeps working after the expected error and several attempts can be
     * chained in one test.
     */
    protected function assertRejected(callable $attempt, string $label): void
    {
        try {
            DB::transaction($attempt);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail("The database should have rejected: {$label}");
    }
}
