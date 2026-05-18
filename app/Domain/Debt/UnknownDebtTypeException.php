<?php

declare(strict_types=1);

namespace App\Domain\Debt;

use RuntimeException;

class UnknownDebtTypeException extends RuntimeException
{
    public function __construct(public readonly string $type)
    {
        parent::__construct("Unknown debt type: {$type}");
    }
}
