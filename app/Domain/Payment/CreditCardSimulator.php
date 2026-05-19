<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Money\Money;
use Brick\Math\BigDecimal;

final class CreditCardSimulator
{
    private const MONTHLY_RATE = '0.025';

    private const INSTALLMENT_COUNTS = [1, 6, 12];

    public function simulate(Money $base): CreditCardPayment
    {
        $installments = [];
        foreach (self::INSTALLMENT_COUNTS as $n) {
            $installments[] = new Installment(
                count: $n,
                amount: $this->pmt($base, $n),
            );
        }

        return new CreditCardPayment($installments);
    }

    /**
     * Standard PMT: pmt = base × i × (1+i)^n / ((1+i)^n − 1).
     *
     * BigDecimal preserves precision through power/multiply/minus; the final
     * dividedBy in Money::divide rounds at scale 20 + HALF_UP, more than
     * enough for the 2-decimal Money output.
     */
    private function pmt(Money $base, int $count): Money
    {
        if ($count === 1) {
            return $base;
        }

        $rate = BigDecimal::of(self::MONTHLY_RATE);
        $factor = BigDecimal::of('1')->plus($rate)->power($count);  // (1+i)^n
        $denominator = $factor->minus(BigDecimal::of('1'));         // (1+i)^n − 1

        return $base->multiply($rate)->multiply($factor)->divide($denominator);
    }
}
