<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\Logger;

/**
 * Laravel `tap` class that attaches the `PlateMaskingProcessor` to every
 * handler in a given Monolog logger. Wired in `config/logging.php` per
 * channel so every log line — single, daily, stderr, stack — gets plates
 * masked at the source.
 *
 * Implementation note: Laravel calls `__invoke($logger)` once per channel
 * resolution. The processor itself is stateless, so creating a fresh one
 * per handler is cheap and avoids cross-handler coupling.
 */
final class TapPlateMasking
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->pushProcessor(new PlateMaskingProcessor);
        }
    }
}
