<?php

declare(strict_types=1);

namespace App\Domain\Debt;

use App\Domain\Money\Money;

final class MultaInterestPolicy implements InterestPolicy
{
    /**
     * Default reflects the canon value from the enunciado (1%/day, no cap).
     * String so it flows into Money/BigDecimal without float coercion.
     */
    public function __construct(
        private readonly string $dailyRate = '0.01',
    ) {}

    public function calculate(Money $original, int $daysOverdue): Money
    {
        if ($daysOverdue <= 0) {
            return Money::zero();
        }

        return $original->multiply($this->dailyRate)->multiply((string) $daysOverdue);
    }
}
