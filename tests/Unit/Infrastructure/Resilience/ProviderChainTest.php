<?php

declare(strict_types=1);

use App\Application\Ports\ProviderUnavailableException;
use App\Domain\Debt\Debt;
use App\Domain\Debt\DebtType;
use App\Domain\Debt\UnknownDebtTypeException;
use App\Domain\Money\Money;
use App\Domain\Plate\Plate;
use App\Infrastructure\Resilience\AllProvidersUnavailableException;
use App\Infrastructure\Resilience\ProviderChain;
use Tests\Support\Fakes\FakeDebtProvider;
use Tests\Support\Fixtures\DebtFixtures;

it('returns the result of the first provider that succeeds', function (): void {
    $debts = DebtFixtures::combinedCanonical();
    $chain = new ProviderChain([
        FakeDebtProvider::withDebts($debts),
        FakeDebtProvider::unavailable(),
    ]);

    $result = $chain->fetchDebts(Plate::fromString('ABC1234'));

    expect($result)->toBe($debts);
});

it('falls back from A (unavailable) to B (success) and returns B`s result', function (): void {
    $bDebts = [new Debt(
        DebtType::IPVA,
        Money::of('500.00'),
        DebtFixtures::utc('2024-01-01T00:00:00Z'),
    )];

    $providerA = FakeDebtProvider::unavailable('A down');
    $providerB = FakeDebtProvider::withDebts($bDebts);

    $chain = new ProviderChain([$providerA, $providerB]);

    expect($chain->fetchDebts(Plate::fromString('ABC1234')))->toBe($bDebts);
    expect($providerA->callCount)->toBe(1);
    expect($providerB->callCount)->toBe(1);
});

it('throws AllProvidersUnavailableException when every provider fails', function (): void {
    $chain = new ProviderChain([
        FakeDebtProvider::unavailable('A down'),
        FakeDebtProvider::unavailable('B down'),
    ]);

    try {
        $chain->fetchDebts(Plate::fromString('ABC1234'));
        $this->fail('Expected AllProvidersUnavailableException was not thrown');
    } catch (AllProvidersUnavailableException $exception) {
        expect($exception->errors)->toHaveCount(2);
        expect($exception->errors[0])->toBeInstanceOf(ProviderUnavailableException::class);
        expect($exception->errors[0]->getMessage())->toBe('A down');
        expect($exception->errors[1]->getMessage())->toBe('B down');
    }
});

it('does NOT fall back when a provider throws a domain exception — UnknownDebtTypeException propagates raw', function (): void {
    $providerA = FakeDebtProvider::throwing(new UnknownDebtTypeException('LICENCIAMENTO'));
    $providerB = FakeDebtProvider::withDebts(DebtFixtures::combinedCanonical());

    $chain = new ProviderChain([$providerA, $providerB]);

    try {
        $chain->fetchDebts(Plate::fromString('ABC1234'));
        $this->fail('Expected UnknownDebtTypeException to propagate');
    } catch (UnknownDebtTypeException $exception) {
        expect($exception->type)->toBe('LICENCIAMENTO');
    }

    // B was never asked — domain errors short-circuit the chain.
    expect($providerB->callCount)->toBe(0);
});

it('preserves the canonical order of providers when calling them', function (): void {
    $providerA = FakeDebtProvider::unavailable('A');
    $providerB = FakeDebtProvider::unavailable('B');

    try {
        (new ProviderChain([$providerA, $providerB]))->fetchDebts(Plate::fromString('ABC1234'));
    } catch (AllProvidersUnavailableException $e) {
        expect($e->errors[0]->getMessage())->toBe('A')
            ->and($e->errors[1]->getMessage())->toBe('B');
    }
});

it('returns an empty list when the winning provider returns no debts', function (): void {
    $chain = new ProviderChain([
        FakeDebtProvider::unavailable(),
        FakeDebtProvider::withDebts([]),
    ]);

    expect($chain->fetchDebts(Plate::fromString('ABC1234')))->toBe([]);
});
