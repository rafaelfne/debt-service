<?php

declare(strict_types=1);

use App\Application\Ports\ProviderUnavailableException;
use App\Infrastructure\Resilience\CircuitBreaker;
use App\Infrastructure\Resilience\CircuitOpenException;
use App\Infrastructure\Resilience\Store\FileCircuitBreakerStore;

function freshFileBreaker(string $path, int $threshold, float &$now, float $cooldown = 30.0): CircuitBreaker
{
    return new CircuitBreaker(
        failureThreshold: $threshold,
        cooldownSeconds: $cooldown,
        shouldRecordFailure: static fn (Throwable $e): bool => $e instanceof ProviderUnavailableException,
        clock: function () use (&$now): float {
            return $now;
        },
        store: new FileCircuitBreakerStore($path),
    );
}

function sharedStorePath(): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'cb-shared-'.uniqid('', true).DIRECTORY_SEPARATOR.'provider.json';
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'cb-shared-*', GLOB_ONLYDIR) ?: [] as $dir) {
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
});

it('trips the circuit when failures accumulate across separate breaker instances', function (): void {
    $path = sharedStorePath();
    $now = 0.0;

    // Each instance simulates a fresh PHP worker — same file, different objects.
    for ($i = 0; $i < 3; $i++) {
        $breaker = freshFileBreaker($path, threshold: 3, now: $now);
        try {
            $breaker->execute(fn () => throw new ProviderUnavailableException("hit {$i}"));
        } catch (ProviderUnavailableException) {
            // expected
        }
    }

    // A brand new instance should already see the circuit as open.
    $observer = freshFileBreaker($path, threshold: 3, now: $now);
    expect($observer->isOpen())->toBeTrue();

    expect(fn () => $observer->execute(fn () => 'never'))
        ->toThrow(CircuitOpenException::class);
});

it('resets shared state after the cooldown elapses', function (): void {
    $path = sharedStorePath();
    $now = 0.0;

    $tripper = freshFileBreaker($path, threshold: 1, now: $now);
    try {
        $tripper->execute(fn () => throw new ProviderUnavailableException('trip'));
    } catch (ProviderUnavailableException) {
    }
    expect($tripper->isOpen())->toBeTrue();

    // Different instance (different worker), cooldown elapsed.
    $now = 31.0;
    $recovered = freshFileBreaker($path, threshold: 1, now: $now);

    expect($recovered->isOpen())->toBeFalse();
    expect($recovered->execute(fn () => 'ok'))->toBe('ok');
});

it('a success in any worker resets the shared failure count', function (): void {
    $path = sharedStorePath();
    $now = 0.0;

    // Two failures across two "workers".
    for ($i = 0; $i < 2; $i++) {
        $b = freshFileBreaker($path, threshold: 3, now: $now);
        try {
            $b->execute(fn () => throw new ProviderUnavailableException("hit {$i}"));
        } catch (ProviderUnavailableException) {
        }
    }

    // A third worker observes a success → count resets.
    $success = freshFileBreaker($path, threshold: 3, now: $now);
    $success->execute(fn () => 'recovered');

    // Two more failures should NOT trip (would need 3 since reset).
    for ($i = 0; $i < 2; $i++) {
        $b = freshFileBreaker($path, threshold: 3, now: $now);
        try {
            $b->execute(fn () => throw new ProviderUnavailableException("post-reset {$i}"));
        } catch (ProviderUnavailableException) {
        }
    }

    $observer = freshFileBreaker($path, threshold: 3, now: $now);
    expect($observer->isOpen())->toBeFalse();
});
