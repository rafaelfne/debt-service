<?php

declare(strict_types=1);

use App\Application\Ports\ProviderUnavailableException;
use App\Infrastructure\Resilience\CircuitBreaker;
use App\Infrastructure\Resilience\CircuitOpenException;

function makeBreaker(int $threshold = 5, float $cooldown = 30.0, ?float &$now = null): CircuitBreaker
{
    $now ??= 0.0;

    return new CircuitBreaker(
        failureThreshold: $threshold,
        cooldownSeconds: $cooldown,
        clock: function () use (&$now): float {
            return $now;
        },
    );
}

it('passes through results when closed', function (): void {
    $breaker = makeBreaker();

    expect($breaker->execute(fn () => 'ok'))->toBe('ok');
});

it('opens after exactly failureThreshold consecutive failures', function (): void {
    $breaker = makeBreaker(threshold: 3);
    $throwUnavailable = fn () => throw new ProviderUnavailableException('boom');

    // 3 attempts → trips on the 3rd
    for ($i = 0; $i < 3; $i++) {
        try {
            $breaker->execute($throwUnavailable);
        } catch (ProviderUnavailableException) {
            // expected
        }
    }

    expect($breaker->isOpen())->toBeTrue();
});

it('rejects calls with CircuitOpenException while the circuit is open', function (): void {
    $now = 0.0;
    $breaker = makeBreaker(threshold: 1, cooldown: 30.0, now: $now);

    try {
        $breaker->execute(fn () => throw new ProviderUnavailableException('first'));
    } catch (ProviderUnavailableException) {
        // expected
    }

    // Should not even call the closure now — short-circuits with CircuitOpenException
    $called = false;
    try {
        $breaker->execute(function () use (&$called) {
            $called = true;

            return 'never reached';
        });
        $this->fail('Expected CircuitOpenException');
    } catch (CircuitOpenException $e) {
        expect($called)->toBeFalse();
        expect($e->getMessage())->toContain('30s');
    }
});

it('CircuitOpenException is itself a ProviderUnavailableException (chain treats it as fallback)', function (): void {
    expect(new CircuitOpenException('test'))->toBeInstanceOf(ProviderUnavailableException::class);
});

it('reopens to closed after the cooldown elapses', function (): void {
    $now = 0.0;
    $breaker = makeBreaker(threshold: 1, cooldown: 30.0, now: $now);

    try {
        $breaker->execute(fn () => throw new ProviderUnavailableException('trip'));
    } catch (ProviderUnavailableException) {
        // expected
    }

    expect($breaker->isOpen())->toBeTrue();

    // Advance past the cooldown
    $now = 31.0;
    expect($breaker->isOpen())->toBeFalse();

    // Next call goes through
    expect($breaker->execute(fn () => 'recovered'))->toBe('recovered');
});

it('any success resets the failure count', function (): void {
    $breaker = makeBreaker(threshold: 3);

    // 2 failures
    try {
        $breaker->execute(fn () => throw new ProviderUnavailableException('a'));
    } catch (ProviderUnavailableException) {
    }
    try {
        $breaker->execute(fn () => throw new ProviderUnavailableException('b'));
    } catch (ProviderUnavailableException) {
    }

    // Success → counter resets
    $breaker->execute(fn () => 'ok');

    // 2 more failures should not trip (would need 3 since reset)
    for ($i = 0; $i < 2; $i++) {
        try {
            $breaker->execute(fn () => throw new ProviderUnavailableException('c'));
        } catch (ProviderUnavailableException) {
        }
    }

    expect($breaker->isOpen())->toBeFalse();
});

it('does NOT trip when shouldRecordFailure rejects the exception type (domain exceptions ignored)', function (): void {
    $breaker = new CircuitBreaker(
        failureThreshold: 1,
        cooldownSeconds: 30.0,
        shouldRecordFailure: fn (Throwable $e): bool => $e instanceof ProviderUnavailableException,
    );

    // Domain-style exception — should NOT count.
    try {
        $breaker->execute(fn () => throw new RuntimeException('domain error'));
    } catch (RuntimeException) {
        // expected
    }

    expect($breaker->isOpen())->toBeFalse();

    // But a provider-unavailable DOES count and trips immediately (threshold = 1).
    try {
        $breaker->execute(fn () => throw new ProviderUnavailableException('real'));
    } catch (ProviderUnavailableException) {
    }

    expect($breaker->isOpen())->toBeTrue();
});

it('after cooldown, a single failure trips the circuit again immediately (no half-open)', function (): void {
    $now = 0.0;
    $breaker = makeBreaker(threshold: 1, cooldown: 10.0, now: $now);

    try {
        $breaker->execute(fn () => throw new ProviderUnavailableException('a'));
    } catch (ProviderUnavailableException) {
    }
    expect($breaker->isOpen())->toBeTrue();

    $now = 11.0;
    expect($breaker->isOpen())->toBeFalse();

    try {
        $breaker->execute(fn () => throw new ProviderUnavailableException('b'));
    } catch (ProviderUnavailableException) {
    }

    expect($breaker->isOpen())->toBeTrue();
});
