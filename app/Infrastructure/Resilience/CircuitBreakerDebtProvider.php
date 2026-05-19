<?php

declare(strict_types=1);

namespace App\Infrastructure\Resilience;

use App\Application\Ports\DebtProvider;
use App\Domain\Plate\Plate;

/**
 * Decorator that wraps a `DebtProvider` with its own `CircuitBreaker`. Sits
 * between the `ProviderChain` and the concrete adapter so each provider gets
 * an isolated circuit (a slow Provider B never trips Provider A's breaker).
 *
 * Domain exceptions do NOT trip the breaker — the `CircuitBreaker` instance
 * passed in should be configured with `shouldRecordFailure` filtering for
 * `ProviderUnavailableException` only.
 */
final class CircuitBreakerDebtProvider implements DebtProvider
{
    public function __construct(
        private readonly DebtProvider $inner,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function fetchDebts(Plate $plate): array
    {
        return $this->breaker->execute(
            fn (): array => $this->inner->fetchDebts($plate),
        );
    }
}
