<?php

declare(strict_types=1);

namespace App\Infrastructure\Resilience\Store;

/**
 * Per-instance, in-process storage. Default store — preserves the historical
 * behavior of `CircuitBreaker` for unit tests and any deployment where
 * sharing state across workers isn't needed.
 */
final class InMemoryCircuitBreakerStore implements CircuitBreakerStore
{
    private CircuitBreakerState $state;

    public function __construct()
    {
        $this->state = CircuitBreakerState::initial();
    }

    public function load(): CircuitBreakerState
    {
        return $this->state;
    }

    public function save(CircuitBreakerState $state): void
    {
        $this->state = $state;
    }
}
