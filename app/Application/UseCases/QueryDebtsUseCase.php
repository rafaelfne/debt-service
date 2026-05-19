<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Application\Ports\DebtProvider;
use App\Domain\Debt\Debt;
use App\Domain\Debt\InterestCalculator;
use App\Domain\Debt\UpdatedDebt;
use App\Domain\Money\Money;
use App\Domain\Payment\PaymentSimulator;
use App\Domain\Plate\Plate;

final class QueryDebtsUseCase
{
    public function __construct(
        private readonly DebtProvider $provider,
        private readonly InterestCalculator $calculator,
        private readonly PaymentSimulator $paymentSimulator,
    ) {}

    public function execute(Plate $plate): DebtQueryResult
    {
        $debts = $this->provider->fetchDebts($plate);

        $updated = array_map(
            fn (Debt $debt): UpdatedDebt => $this->calculator->calculate($debt),
            $debts,
        );

        return new DebtQueryResult(
            plate: $plate,
            debts: $updated,
            summary: $this->summarize($debts, $updated),
            paymentOptions: $this->paymentSimulator->simulate($updated),
        );
    }

    /**
     * @param  list<Debt>  $debts
     * @param  list<UpdatedDebt>  $updated
     */
    private function summarize(array $debts, array $updated): DebtSummary
    {
        $totalOriginal = array_reduce(
            $debts,
            fn (Money $acc, Debt $debt): Money => $acc->add($debt->originalAmount),
            Money::zero(),
        );

        $totalUpdated = array_reduce(
            $updated,
            fn (Money $acc, UpdatedDebt $debt): Money => $acc->add($debt->updatedAmount),
            Money::zero(),
        );

        return new DebtSummary(
            totalOriginal: $totalOriginal,
            totalUpdated: $totalUpdated,
        );
    }
}
