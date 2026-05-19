<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Debt\UpdatedDebt;
use App\Domain\Money\Money;

final class PaymentSimulator
{
    public function __construct(
        private readonly PixSimulator $pix,
        private readonly CreditCardSimulator $card,
    ) {}

    /**
     * Always emits `TOTAL` first (sum of every updated amount), followed by one
     * `SOMENTE_<TYPE>` per distinct DebtType found in the input, in first-seen
     * order. Naming is always singular (e.g. `SOMENTE_IPVA`, never `SOMENTE_IPVAS`).
     *
     * @param  list<UpdatedDebt>  $debts
     * @return list<PaymentOption>
     */
    public function simulate(array $debts): array
    {
        $options = [$this->buildOption('TOTAL', $this->sumAll($debts))];

        foreach ($this->sumByType($debts) as $type => $sum) {
            $options[] = $this->buildOption("SOMENTE_{$type}", $sum);
        }

        return $options;
    }

    /**
     * @param  list<UpdatedDebt>  $debts
     */
    private function sumAll(array $debts): Money
    {
        return array_reduce(
            $debts,
            fn (Money $acc, UpdatedDebt $debt): Money => $acc->add($debt->updatedAmount),
            Money::zero(),
        );
    }

    /**
     * @param  list<UpdatedDebt>  $debts
     * @return array<string, Money> keyed by DebtType->value, first-seen order preserved
     */
    private function sumByType(array $debts): array
    {
        $byType = [];
        foreach ($debts as $debt) {
            $key = $debt->type->value;
            $byType[$key] = ($byType[$key] ?? Money::zero())->add($debt->updatedAmount);
        }

        return $byType;
    }

    private function buildOption(string $type, Money $base): PaymentOption
    {
        return new PaymentOption(
            type: $type,
            baseAmount: $base,
            pix: $this->pix->simulate($base),
            creditCard: $this->card->simulate($base),
        );
    }
}
