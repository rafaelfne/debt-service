<?php

declare(strict_types=1);

namespace App\Domain\Debt;

use App\Domain\Money\Money;

interface InterestPolicy
{
    /**
     * Returns the interest amount (NOT the updated total) for the given original
     * value and overdue duration. Implementations preserve BigDecimal precision —
     * rounding HALF_UP to 2 places happens on Money output, never inside the policy.
     */
    public function calculate(Money $original, int $daysOverdue): Money;
}
