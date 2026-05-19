<?php

declare(strict_types=1);

namespace App\Infrastructure\Resilience;

use Closure;
use Throwable;

/**
 * Minimal in-memory circuit breaker. After `$failureThreshold` consecutive
 * failures the circuit trips to `open` and rejects calls immediately with
 * `CircuitOpenException` for `$cooldownSeconds`. After the cooldown elapses
 * the next call is allowed through and resets the state on success (or
 * re-opens immediately on another failure — no formal half-open state).
 *
 * `$shouldRecordFailure` lets callers decide which Throwables count as a
 * provider failure (e.g. only `ProviderUnavailableException`, not domain
 * exceptions that signal bad input).
 *
 * `$clock` is injectable to keep tests deterministic; defaults to
 * `microtime(true)`.
 *
 * State is in-memory per-instance. Multiple app instances do not share
 * the count — README documents this trade-off and the Redis-backed
 * extension path.
 */
final class CircuitBreaker
{
    private int $failureCount = 0;

    private ?float $openedAt = null;

    /**
     * @param  ?Closure(Throwable): bool  $shouldRecordFailure  defaults to "every Throwable counts"
     * @param  ?Closure(): float  $clock
     */
    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly float $cooldownSeconds = 30.0,
        private readonly ?Closure $shouldRecordFailure = null,
        private readonly ?Closure $clock = null,
    ) {}

    /**
     * Runs `$callable`, tracking failures. Throws `CircuitOpenException` when
     * the circuit is open and still within cooldown. Re-throws every other
     * Throwable verbatim — but only those that pass `shouldRecordFailure`
     * advance the failure count.
     *
     * @template T
     *
     * @param  Closure(): T  $callable
     * @return T
     */
    public function execute(Closure $callable): mixed
    {
        if ($this->isOpen()) {
            throw new CircuitOpenException(
                "Circuit breaker is open (will recover after {$this->cooldownSeconds}s cooldown)"
            );
        }

        try {
            $result = $callable();
        } catch (Throwable $exception) {
            if ($this->shouldCountAsFailure($exception)) {
                $this->recordFailure();
            }

            throw $exception;
        }

        $this->recordSuccess();

        return $result;
    }

    /**
     * @internal exposed for tests / observability
     */
    public function isOpen(): bool
    {
        if ($this->openedAt === null) {
            return false;
        }

        if ($this->now() - $this->openedAt >= $this->cooldownSeconds) {
            // Cooldown elapsed: reset so the next call goes through.
            $this->openedAt = null;
            $this->failureCount = 0;

            return false;
        }

        return true;
    }

    private function shouldCountAsFailure(Throwable $exception): bool
    {
        return ($this->shouldRecordFailure ?? static fn (Throwable $e): bool => true)($exception);
    }

    private function recordFailure(): void
    {
        $this->failureCount++;

        if ($this->failureCount >= $this->failureThreshold) {
            $this->openedAt = $this->now();
        }
    }

    private function recordSuccess(): void
    {
        $this->failureCount = 0;
        $this->openedAt = null;
    }

    private function now(): float
    {
        return ($this->clock ?? static fn (): float => microtime(true))();
    }
}
