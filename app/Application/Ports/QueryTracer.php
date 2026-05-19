<?php

declare(strict_types=1);

namespace App\Application\Ports;

/**
 * Outbound port for observing use-case-level steps. Implemented by an
 * Infrastructure adapter (demo tracer, metrics emitter, etc.); the use
 * case stays unaware of the destination.
 */
interface QueryTracer
{
    public function interestCalculated(int $debtCount): void;

    /**
     * @param  list<string>  $optionNames
     */
    public function paymentsSimulated(array $optionNames): void;
}
