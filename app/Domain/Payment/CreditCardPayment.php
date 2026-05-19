<?php

declare(strict_types=1);

namespace App\Domain\Payment;

final class CreditCardPayment
{
    /**
     * @param  list<Installment>  $installments
     */
    public function __construct(
        public readonly array $installments,
    ) {}
}
