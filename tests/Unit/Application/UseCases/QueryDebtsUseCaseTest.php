<?php

declare(strict_types=1);

use App\Application\Ports\DebtProvider;
use App\Application\Ports\ProviderUnavailableException;
use App\Application\UseCases\DebtQueryResult;
use App\Application\UseCases\QueryDebtsUseCase;
use App\Domain\Debt\DebtType;
use App\Domain\Debt\InterestCalculator;
use App\Domain\Debt\IpvaInterestPolicy;
use App\Domain\Debt\MultaInterestPolicy;
use App\Domain\Debt\UnknownDebtTypeException;
use App\Domain\Payment\CreditCardSimulator;
use App\Domain\Payment\PaymentSimulator;
use App\Domain\Payment\PixSimulator;
use App\Domain\Plate\Plate;
use Tests\Support\Fakes\FakeDebtProvider;
use Tests\Support\Fixtures\DebtFixtures;

function makeUseCase(DebtProvider $provider): QueryDebtsUseCase
{
    return new QueryDebtsUseCase(
        provider: $provider,
        calculator: new InterestCalculator(
            referenceDate: DebtFixtures::utc(DebtFixtures::REFERENCE_DATE),
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

it('produces the enunciado canon for the combined IPVA + MULTA list', function (): void {
    $provider = FakeDebtProvider::withDebts(DebtFixtures::combinedCanonical());

    $result = makeUseCase($provider)->execute(Plate::fromString('ABC1234'));

    expect($result)->toBeInstanceOf(DebtQueryResult::class)
        ->and($result->plate->toString())->toBe('ABC1234')
        ->and($result->debts)->toHaveCount(2)
        ->and($result->debts[0]->updatedAmount->toString())->toBe('1800.00')
        ->and($result->debts[0]->daysOverdue)->toBe(121)
        ->and($result->debts[1]->updatedAmount->toString())->toBe('555.93')
        ->and($result->debts[1]->daysOverdue)->toBe(85)
        ->and($result->summary->totalOriginal->toString())->toBe('1800.50')
        ->and($result->summary->totalUpdated->toString())->toBe('2355.93')
        ->and($result->paymentOptions)->toHaveCount(3)
        ->and($result->paymentOptions[0]->type)->toBe('TOTAL')
        ->and($result->paymentOptions[0]->baseAmount->toString())->toBe('2355.93')
        ->and($result->paymentOptions[1]->type)->toBe('SOMENTE_IPVA')
        ->and($result->paymentOptions[1]->baseAmount->toString())->toBe('1800.00')
        ->and($result->paymentOptions[2]->type)->toBe('SOMENTE_MULTA')
        ->and($result->paymentOptions[2]->baseAmount->toString())->toBe('555.93');
});

it('forwards the plate to the provider verbatim', function (): void {
    $provider = FakeDebtProvider::withDebts([]);

    makeUseCase($provider)->execute(Plate::fromString('abc1d23'));

    expect($provider->callCount)->toBe(1)
        ->and($provider->platesSeen[0]->toString())->toBe('ABC1D23');
});

it('handles empty debts: result has no debts, zero summary, single TOTAL=0.00 option', function (): void {
    $result = makeUseCase(FakeDebtProvider::withDebts([]))
        ->execute(Plate::fromString('ABC1234'));

    expect($result->debts)->toBe([])
        ->and($result->summary->totalOriginal->toString())->toBe('0.00')
        ->and($result->summary->totalUpdated->toString())->toBe('0.00')
        ->and($result->paymentOptions)->toHaveCount(1)
        ->and($result->paymentOptions[0]->type)->toBe('TOTAL')
        ->and($result->paymentOptions[0]->baseAmount->toString())->toBe('0.00');
});

it('propagates ProviderUnavailableException unchanged (chain handles fallback)', function (): void {
    $useCase = makeUseCase(FakeDebtProvider::unavailable('upstream down'));

    $useCase->execute(Plate::fromString('ABC1234'));
})->throws(ProviderUnavailableException::class, 'upstream down');

it('propagates domain exceptions raw — they must NOT be wrapped as provider failures', function (): void {
    $useCase = makeUseCase(
        FakeDebtProvider::throwing(new UnknownDebtTypeException('LICENCIAMENTO')),
    );

    $useCase->execute(Plate::fromString('ABC1234'));
})->throws(UnknownDebtTypeException::class);
