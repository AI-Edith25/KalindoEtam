<?php

namespace Tests\Unit\Support;

use App\Support\DuplicateKeyViolation;
use Exception;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;

class DuplicateKeyViolationTest extends TestCase
{
    public function test_detects_sqlite_unique_constraint_message(): void
    {
        $previous = new class extends PDOException
        {
            public function __construct()
            {
                parent::__construct('UNIQUE constraint failed: receipt_entries.reference_number_normalized');
            }
        };

        $e = new QueryException('sqlite', 'insert into x', [], $previous);

        $this->assertTrue(DuplicateKeyViolation::detected($e));
    }

    public function test_detects_mysql_1062_error_code(): void
    {
        $previous = new class extends PDOException
        {
            public function __construct()
            {
                parent::__construct("Duplicate entry 'OR/KE/1' for key 'receipt_entries_reference_number_normalized_unique'");
                $this->errorInfo = [23000, 1062, "Duplicate entry 'OR/KE/1' for key 'receipt_entries_reference_number_normalized_unique'"];
            }
        };

        $e = new QueryException('mysql', 'insert into x', [], $previous);

        $this->assertTrue(DuplicateKeyViolation::detected($e));
    }

    public function test_unrelated_query_exception_is_not_a_duplicate(): void
    {
        $previous = new class extends PDOException
        {
            public function __construct()
            {
                parent::__construct('NOT NULL constraint failed: receipt_entries.customer_id');
            }
        };

        $e = new QueryException('sqlite', 'insert into x', [], $previous);

        $this->assertFalse(DuplicateKeyViolation::detected($e));
    }

    public function test_non_query_exception_is_not_a_duplicate(): void
    {
        $this->assertFalse(DuplicateKeyViolation::detected(new Exception('something else')));
    }
}
