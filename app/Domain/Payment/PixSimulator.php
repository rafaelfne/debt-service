<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Money\Money;

final class PixSimulator
{
    /**
     * Default 0.95 reflects the canon 5% discount from the enunciado.
     * `0.90` = 10% discount, `1.00` = no discount, etc.
     */
    public function __construct(
        private readonly string $discountFactor = '0.95',
    ) {}

    public function simulate(Money $base): PixPayment
    {
        return new PixPayment($base->multiply($this->discountFactor));
    }
}
