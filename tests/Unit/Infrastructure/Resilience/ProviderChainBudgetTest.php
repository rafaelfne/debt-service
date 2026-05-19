<?php

declare(strict_types=1);

use App\Application\Ports\DebtProvider;
use App\Application\Ports\ProviderUnavailableException;
use App\Domain\Plate\Plate;
use App\Infrastructure\Resilience\AllProvidersUnavailableException;
use App\Infrastructure\Resilience\ProviderChain;

/**
 * A provider that advances a shared clock by `$elapses` seconds whenever it is
 * called, and then either throws (default) or returns the configured `$debts`.
 * Lets us write deterministic budget tests without real sleep().
 */
final class ClockAdvancingProvider implements DebtProvider
{
    /** @param array<int|string,mixed> $debts */
    public function __construct(
        private float &$clock,
        public readonly float $elapses,
        private readonly bool $throws = true,
        private readonly array $debts = [],
    ) {}

    public function fetchDebts(Plate $plate): array
    {
        $this->clock += $this->elapses;

        if ($this->throws) {
            throw new ProviderUnavailableException("after {$this->elapses}s");
        }

        return $this->debts;
    }
}

it('aborts before trying the next provider when the budget is exceeded', function (): void {
    $now = 0.0;
    $clock = function () use (&$now): float {
        return $now;
    };

    // Three providers; each one takes 3s. Budget = 5s.
    // - A consumes 3s, fails → elapsed = 3 (≤ 5), tries B
    // - B consumes 3s, fails → elapsed = 6 (> 5), MUST short-circuit before C
    $chain = new ProviderChain(
        providers: [
            new ClockAdvancingProvider($now, 3.0),
            new ClockAdvancingProvider($now, 3.0),
            new ClockAdvancingProvider($now, 3.0),
        ],
        budgetSeconds: 5.0,
        clock: $clock,
    );

    try {
        $chain->fetchDebts(Plate::fromString('ABC1234'));
        $this->fail('Expected AllProvidersUnavailableException to be thrown');
    } catch (AllProvidersUnavailableException $exception) {
        // A and B both tried (2 errors), C never tried (would have been 3 errors)
        expect($exception->errors)->toHaveCount(2);
        expect($exception->getMessage())->toContain('budget of 5s exceeded');
    }
});

it('does NOT abort when the cumulative elapsed time stays within budget', function (): void {
    $now = 0.0;
    $clock = function () use (&$now): float {
        return $now;
    };

    // Both providers take 2s; total 4s ≤ 5s budget.
    $chain = new ProviderChain(
        providers: [
            new ClockAdvancingProvider($now, 2.0),
            new ClockAdvancingProvider($now, 2.0),
        ],
        budgetSeconds: 5.0,
        clock: $clock,
    );

    try {
        $chain->fetchDebts(Plate::fromString('ABC1234'));
        $this->fail('Expected AllProvidersUnavailableException');
    } catch (AllProvidersUnavailableException $exception) {
        // Both tried; chain failed because all providers failed, NOT because of budget.
        expect($exception->errors)->toHaveCount(2);
        expect($exception->getMessage())->not->toContain('budget');
    }
});

it('returns success when the first provider beats the budget', function (): void {
    $now = 0.0;
    $clock = function () use (&$now): float {
        return $now;
    };

    $chain = new ProviderChain(
        providers: [
            new ClockAdvancingProvider($now, 1.0, throws: false, debts: ['ok']),
            new ClockAdvancingProvider($now, 10.0), // never reached
        ],
        budgetSeconds: 5.0,
        clock: $clock,
    );

    expect($chain->fetchDebts(Plate::fromString('ABC1234')))->toBe(['ok']);
    expect($now)->toBe(1.0);
});

it('honours a custom budgetSeconds value (3s)', function (): void {
    $now = 0.0;
    $clock = function () use (&$now): float {
        return $now;
    };

    $chain = new ProviderChain(
        providers: [
            new ClockAdvancingProvider($now, 2.0),
            new ClockAdvancingProvider($now, 2.0),
            new ClockAdvancingProvider($now, 2.0),
        ],
        budgetSeconds: 3.0,
        clock: $clock,
    );

    try {
        $chain->fetchDebts(Plate::fromString('ABC1234'));
        $this->fail('Expected AllProvidersUnavailableException');
    } catch (AllProvidersUnavailableException $exception) {
        // A consumes 2s, fails → elapsed = 2 (≤ 3), tries B
        // B consumes 2s, fails → elapsed = 4 (> 3), short-circuits before C
        expect($exception->errors)->toHaveCount(2);
        expect($exception->getMessage())->toContain('budget of 3s exceeded');
    }
});
