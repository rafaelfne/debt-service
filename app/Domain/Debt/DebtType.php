<?php

declare(strict_types=1);

namespace App\Domain\Debt;

enum DebtType: string
{
    case IPVA = 'IPVA';
    case MULTA = 'MULTA';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new UnknownDebtTypeException($value);
    }
}
