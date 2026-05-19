<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Money\Money;

final class Installment
{
    public function __construct(
        public readonly int $count,
        public readonly Money $amount,
    ) {}
}
