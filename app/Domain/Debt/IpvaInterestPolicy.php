<?php

declare(strict_types=1);

namespace App\Domain\Debt;

use App\Domain\Money\Money;

final class IpvaInterestPolicy implements InterestPolicy
{
    private const DAILY_RATE = '0.0033';

    private const CAP_RATE = '0.20';

    public function calculate(Money $original, int $daysOverdue): Money
    {
        if ($daysOverdue <= 0) {
            return Money::zero();
        }

        $raw = $original->multiply(self::DAILY_RATE)->multiply((string) $daysOverdue);
        $cap = $original->multiply(self::CAP_RATE);

        return Money::min($raw, $cap);
    }
}
