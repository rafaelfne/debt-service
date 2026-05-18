<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Money\Money;

final class PixSimulator
{
    private const DISCOUNT_FACTOR = '0.95';

    public function simulate(Money $base): PixPayment
    {
        return new PixPayment($base->multiply(self::DISCOUNT_FACTOR));
    }
}
