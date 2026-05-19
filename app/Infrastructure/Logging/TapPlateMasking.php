<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\Logger;

/**
 * Laravel `tap` class that attaches the `PlateMaskingProcessor` to every
 * handler in a given Monolog logger. Wired in `config/logging.php` per
 * channel so every log line — single, daily, stderr, stack — gets plates
 * masked at the source.
 *
 * Laravel's `LogManager::tap` passes an `Illuminate\Log\Logger` wrapper;
 * direct callers (e.g. integration tests) hand in a raw `Monolog\Logger`.
 * The union type plus the unwrap below supports both.
 *
 * Implementation note: the processor is stateless, so creating a fresh one
 * per handler is cheap and avoids cross-handler coupling.
 */
final class TapPlateMasking
{
    public function __invoke(Logger|IlluminateLogger $logger): void
    {
        $monolog = $logger instanceof Logger ? $logger : $logger->getLogger();

        foreach ($monolog->getHandlers() as $handler) {
            $handler->pushProcessor(new PlateMaskingProcessor);
        }
    }
}
