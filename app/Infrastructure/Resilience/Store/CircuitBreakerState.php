<?php

declare(strict_types=1);

namespace App\Infrastructure\Resilience\Store;

final class CircuitBreakerState
{
    public function __construct(
        public readonly int $failureCount = 0,
        public readonly ?float $openedAt = null,
    ) {}

    public static function initial(): self
    {
        return new self;
    }
}
