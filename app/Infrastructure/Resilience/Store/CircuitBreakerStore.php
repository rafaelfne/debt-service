<?php

declare(strict_types=1);

namespace App\Infrastructure\Resilience\Store;

/**
 * Backing storage for a `CircuitBreaker`'s state. Implementations decide
 * whether state is per-process (in-memory) or shared across workers (file,
 * cache, etc.). Methods are intentionally minimal — atomicity of read/write
 * across concurrent callers is each implementation's responsibility.
 */
interface CircuitBreakerStore
{
    public function load(): CircuitBreakerState;

    public function save(CircuitBreakerState $state): void;
}
