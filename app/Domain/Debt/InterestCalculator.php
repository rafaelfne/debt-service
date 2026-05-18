<?php

declare(strict_types=1);

namespace App\Domain\Debt;

use DateTimeImmutable;

final class InterestCalculator
{
    /**
     * @param  array<string, InterestPolicy>  $policies  keyed by DebtType->value
     */
    public function __construct(
        private readonly DateTimeImmutable $referenceDate,
        private readonly array $policies,
    ) {}

    public function calculate(Debt $debt): UpdatedDebt
    {
        $days = $debt->daysOverdue($this->referenceDate);

        $policy = $this->policies[$debt->type->value]
            ?? throw new UnknownDebtTypeException($debt->type->value);

        $interest = $policy->calculate($debt->originalAmount, $days);

        return new UpdatedDebt(
            type: $debt->type,
            dueDate: $debt->dueDate,
            originalAmount: $debt->originalAmount,
            updatedAmount: $debt->originalAmount->add($interest),
            daysOverdue: $days,
        );
    }
}
