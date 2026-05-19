<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Domain\Debt\UpdatedDebt;
use App\Domain\Payment\PaymentOption;
use App\Domain\Plate\Plate;

final class DebtQueryResult
{
    /**
     * @param  list<UpdatedDebt>  $debts
     * @param  list<PaymentOption>  $paymentOptions
     */
    public function __construct(
        public readonly Plate $plate,
        public readonly array $debts,
        public readonly DebtSummary $summary,
        public readonly array $paymentOptions,
    ) {}
}
