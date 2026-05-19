<?php

declare(strict_types=1);

use App\Application\Ports\DebtProvider;
use App\Application\UseCases\DebtQueryResult;
use App\Application\UseCases\QueryDebtsUseCase;
use App\Domain\Debt\Debt;
use App\Domain\Debt\DebtType;
use App\Domain\Debt\InterestCalculator;
use App\Domain\Debt\IpvaInterestPolicy;
use App\Domain\Debt\MultaInterestPolicy;
use App\Domain\Money\Money;
use App\Domain\Payment\CreditCardSimulator;
use App\Domain\Payment\PaymentSimulator;
use App\Domain\Payment\PixSimulator;
use App\Domain\Plate\Plate;

function makeUseCase(DebtProvider $provider): QueryDebtsUseCase
{
    return new QueryDebtsUseCase(
        provider: $provider,
        calculator: new InterestCalculator(
            referenceDate: new DateTimeImmutable('2024-05-10T00:00:00Z', new DateTimeZone('UTC')),
            policies: [
                DebtType::IPVA->value => new IpvaInterestPolicy,
                DebtType::MULTA->value => new MultaInterestPolicy,
            ],
        ),
        paymentSimulator: new PaymentSimulator(
            new PixSimulator,
            new CreditCardSimulator,
        ),
    );
}

function inlineProvider(array $debts): DebtProvider
{
    return new class($debts) implements DebtProvider
    {
        /** @param list<Debt> $debts */
        public function __construct(private readonly array $debts) {}

        public function fetchDebts(Plate $plate): array
        {
            return $this->debts;
        }
    };
}

it('orchestrates fetch → calculate → simulate and produces the enunciado canon', function (): void {
    $provider = inlineProvider([
        new Debt(
            DebtType::IPVA,
            Money::of('1500.00'),
            new DateTimeImmutable('2024-01-10T00:00:00Z', new DateTimeZone('UTC')),
        ),
        new Debt(
            DebtType::MULTA,
            Money::of('300.50'),
            new DateTimeImmutable('2024-02-15T00:00:00Z', new DateTimeZone('UTC')),
        ),
    ]);

    $result = makeUseCase($provider)->execute(Plate::fromString('ABC1234'));

    expect($result)->toBeInstanceOf(DebtQueryResult::class)
        ->and($result->plate->toString())->toBe('ABC1234')
        ->and($result->debts)->toHaveCount(2)
        ->and($result->summary->totalOriginal->toString())->toBe('1800.50')
        ->and($result->summary->totalUpdated->toString())->toBe('2355.93')
        ->and($result->paymentOptions)->toHaveCount(3)
        ->and($result->paymentOptions[0]->type)->toBe('TOTAL')
        ->and($result->paymentOptions[0]->baseAmount->toString())->toBe('2355.93')
        ->and($result->paymentOptions[1]->type)->toBe('SOMENTE_IPVA')
        ->and($result->paymentOptions[2]->type)->toBe('SOMENTE_MULTA');
});
