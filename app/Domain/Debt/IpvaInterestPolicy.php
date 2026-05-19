<?php

declare(strict_types=1);

namespace App\Domain\Debt;

use App\Domain\Money\Money;

final class IpvaInterestPolicy implements InterestPolicy
{
    /**
     * Both rates are strings (not floats) so they feed Money/BigDecimal directly
     * without round-tripping through PHP float — invariante #1 do CLAUDE.md.
     * Defaults reflect the canon values from the enunciado.
     */
    public function __construct(
        private readonly string $dailyRate = '0.0033',
        private readonly string $capRate = '0.20',
    ) {}

    public function calculate(Money $original, int $daysOverdue): Money
    {
        if ($daysOverdue <= 0) {
            return Money::zero();
        }

        $raw = $original->multiply($this->dailyRate)->multiply((string) $daysOverdue);
        $cap = $original->multiply($this->capRate);

        return Money::min($raw, $cap);
    }
}
