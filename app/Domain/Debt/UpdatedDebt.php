<?php

declare(strict_types=1);

namespace App\Domain\Debt;

use App\Domain\Money\Money;
use DateTimeImmutable;

final class UpdatedDebt
{
    public function __construct(
        public readonly DebtType $type,
        public readonly DateTimeImmutable $dueDate,
        public readonly Money $originalAmount,
        public readonly Money $updatedAmount,
        public readonly int $daysOverdue,
    ) {}
}
