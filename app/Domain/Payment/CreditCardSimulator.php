<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Money\Money;
use Brick\Math\BigDecimal;

final class CreditCardSimulator
{
    private const INSTALLMENT_COUNTS = [1, 6, 12];

    /**
     * Default 0.025 is the canon 2.5%/month from the enunciado. The installment
     * counts stay hardcoded because changing them breaks the byte-for-byte JSON
     * contract — that's a structural change, not a knob.
     */
    public function __construct(
        private readonly string $monthlyRate = '0.025',
    ) {}

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

        $rate = BigDecimal::of($this->monthlyRate);
        $factor = BigDecimal::of('1')->plus($rate)->power($count);  // (1+i)^n
        $denominator = $factor->minus(BigDecimal::of('1'));         // (1+i)^n − 1

        return $base->multiply($rate)->multiply($factor)->divide($denominator);
    }
}
