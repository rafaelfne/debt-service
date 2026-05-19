<?php

declare(strict_types=1);

namespace App\Infrastructure\Resilience;

use App\Application\Ports\ProviderUnavailableException;

/**
 * Signalled when a circuit is in the `open` state. Extends
 * `ProviderUnavailableException` so the `ProviderChain` treats it as a
 * "try the next provider" outcome without needing a special case.
 */
final class CircuitOpenException extends ProviderUnavailableException {}
