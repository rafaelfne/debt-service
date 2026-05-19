<?php

declare(strict_types=1);

use App\Application\Ports\DebtProvider;
use App\Application\Ports\ProviderUnavailableException;
use App\Domain\Debt\UnknownDebtTypeException;
use App\Domain\Plate\Plate;
use App\Infrastructure\Resilience\CircuitBreaker;
use App\Infrastructure\Resilience\CircuitBreakerDebtProvider;
use App\Infrastructure\Resilience\CircuitOpenException;
use Tests\Support\Fakes\FakeDebtProvider;
use Tests\Support\Fixtures\DebtFixtures;

function decorateWithBreaker(DebtProvider $inner, int $threshold = 3): CircuitBreakerDebtProvider
{
    return new CircuitBreakerDebtProvider(
        inner: $inner,
        breaker: new CircuitBreaker(
            failureThreshold: $threshold,
            cooldownSeconds: 30.0,
            shouldRecordFailure: fn (Throwable $e): bool => $e instanceof ProviderUnavailableException,
        ),
    );
}

it('delegates fetchDebts to the inner provider when the circuit is closed', function (): void {
    $debts = DebtFixtures::combinedCanonical();
    $decorated = decorateWithBreaker(FakeDebtProvider::withDebts($debts));

    expect($decorated->fetchDebts(Plate::fromString('ABC1234')))->toBe($debts);
});

it('trips after threshold ProviderUnavailableException failures and short-circuits with CircuitOpenException', function (): void {
    $unavailable = FakeDebtProvider::unavailable('inner down');
    $decorated = decorateWithBreaker($unavailable, threshold: 3);

    // 3 failures trip the breaker
    for ($i = 0; $i < 3; $i++) {
        try {
            $decorated->fetchDebts(Plate::fromString('ABC1234'));
        } catch (ProviderUnavailableException) {
            // expected
        }
    }
    expect($unavailable->callCount)->toBe(3);

    // 4th call: circuit is open, inner provider is NOT invoked
    try {
        $decorated->fetchDebts(Plate::fromString('ABC1234'));
        $this->fail('Expected CircuitOpenException');
    } catch (CircuitOpenException $e) {
        // CircuitOpenException is itself a ProviderUnavailableException, so the
        // ProviderChain will fall back to the next provider when it gets one.
        expect($e)->toBeInstanceOf(ProviderUnavailableException::class);
        expect($unavailable->callCount)->toBe(3); // still 3 — inner not called
    }
});

it('does NOT trip the breaker when the inner provider throws a domain exception', function (): void {
    $domainThrower = FakeDebtProvider::throwing(new UnknownDebtTypeException('LICENCIAMENTO'));
    $decorated = decorateWithBreaker($domainThrower, threshold: 2);

    // 5 calls, each throws a domain exception
    for ($i = 0; $i < 5; $i++) {
        try {
            $decorated->fetchDebts(Plate::fromString('ABC1234'));
        } catch (UnknownDebtTypeException) {
            // expected: domain exception propagates RAW
        }
    }

    // Domain exceptions do NOT count toward the threshold (threshold = 2, would
    // have tripped on the 2nd call). Inner was called all 5 times.
    expect($domainThrower->callCount)->toBe(5);

    // 6th call also reaches the inner provider — circuit still closed
    try {
        $decorated->fetchDebts(Plate::fromString('ABC1234'));
    } catch (UnknownDebtTypeException) {
    }
    expect($domainThrower->callCount)->toBe(6);
});
