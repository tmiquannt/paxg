<?php
declare(strict_types=1);

namespace MiniERP\Infrastructure\Database;

use RuntimeException;

/** A database failure with a safe public message and numeric driver code. */
final class DatabaseException extends RuntimeException
{
    public function __construct(int $databaseErrorCode = 0)
    {
        parent::__construct('Database operation failed.', $databaseErrorCode);
    }
}
