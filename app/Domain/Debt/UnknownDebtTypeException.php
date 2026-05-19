<?php

declare(strict_types=1);

namespace App\Domain\Debt;

use App\Domain\Exceptions\DomainException;

final class UnknownDebtTypeException extends DomainException
{
    public function __construct(public readonly string $type)
    {
        parent::__construct("Unknown debt type: {$type}");
    }
}
