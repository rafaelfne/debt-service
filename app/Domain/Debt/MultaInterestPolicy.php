<?php

declare(strict_types=1);

namespace App\Domain\Debt;

use App\Domain\Money\Money;

final class MultaInterestPolicy implements InterestPolicy
{
    private const DAILY_RATE = '0.01';

    public function calculate(Money $original, int $daysOverdue): Money
    {
        if ($daysOverdue <= 0) {
            return Money::zero();
        }

        return $original->multiply(self::DAILY_RATE)->multiply((string) $daysOverdue);
    }
}
