<?php

declare(strict_types=1);

namespace App\Domain\Debt;

use App\Domain\Money\Money;
use DateTimeImmutable;
use DateTimeZone;

final class Debt
{
    public function __construct(
        public readonly DebtType $type,
        public readonly Money $originalAmount,
        public readonly DateTimeImmutable $dueDate,
    ) {}

    /**
     * Whole days the debt is overdue relative to `$referenceDate`, computed in UTC
     * to avoid timezone-induced off-by-one.
     *
     * Returns 0 when the reference date is not strictly after the due date.
     */
    public function daysOverdue(DateTimeImmutable $referenceDate): int
    {
        $utc = new DateTimeZone('UTC');
        $due = $this->dueDate->setTimezone($utc);
        $ref = $referenceDate->setTimezone($utc);

        if ($ref <= $due) {
            return 0;
        }

        return $due->diff($ref)->days;
    }
}
