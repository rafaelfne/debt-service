<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Money\Money;

final class PixPayment
{
    public function __construct(
        public readonly Money $totalComDesconto,
    ) {}
}
