<?php

declare(strict_types=1);

namespace App\Infrastructure\Resilience\Store;

use RuntimeException;

/**
 * File-backed store. Persists `{failure_count, opened_at}` as JSON so the
 * state survives the shared-nothing PHP request lifecycle and is visible
 * across PHP-FPM / `php artisan serve` workers in the same machine.
 *
 * Concurrency: each operation acquires an `flock` (shared for reads,
 * exclusive for writes) on the state file. This serializes concurrent
 * workers but does NOT span the breaker's read-then-write cycle inside
 * `execute()` — two workers can still race the failure-count increment
 * by one. That's acceptable for the demo / single-instance use case;
 * fully transactional behavior would need a real coordinator (Redis,
 * APCu CAS).
 *
 * NOT intended for multi-host deployments — file locks don't reach across
 * machines, and `storage/` is local. See CLAUDE.md §11 #7 for the
 * Redis-backed evolution path.
 */
final class FileCircuitBreakerStore implements CircuitBreakerStore
{
    public function __construct(
        private readonly string $path,
    ) {}

    public function load(): CircuitBreakerState
    {
        if (! is_file($this->path)) {
            return CircuitBreakerState::initial();
        }

        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            return CircuitBreakerState::initial();
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                return CircuitBreakerState::initial();
            }

            $contents = stream_get_contents($handle) ?: '';
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            return CircuitBreakerState::initial();
        }

        $count = isset($decoded['failure_count']) ? (int) $decoded['failure_count'] : 0;
        $openedAt = isset($decoded['opened_at']) && $decoded['opened_at'] !== null
            ? (float) $decoded['opened_at']
            : null;

        return new CircuitBreakerState($count, $openedAt);
    }

    public function save(CircuitBreakerState $state): void
    {
        $directory = dirname($this->path);
        if (! is_dir($directory) && ! @mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create circuit breaker state directory: {$directory}");
        }

        $handle = @fopen($this->path, 'cb+');
        if ($handle === false) {
            throw new RuntimeException("Unable to open circuit breaker state file: {$this->path}");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException("Unable to lock circuit breaker state file: {$this->path}");
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode([
                'failure_count' => $state->failureCount,
                'opened_at' => $state->openedAt,
            ], JSON_THROW_ON_ERROR));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }
}
