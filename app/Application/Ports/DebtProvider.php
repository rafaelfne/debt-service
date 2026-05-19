<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Domain\Debt\Debt;
use App\Domain\Plate\Plate;

interface DebtProvider
{
    /**
     * Fetches the list of debts associated with a plate.
     *
     * @return list<Debt>
     *
     * @throws ProviderUnavailableException when the provider is unreachable
     *                                      (timeout, ConnectionException, 5xx after retries).
     *                                      Domain-level errors (e.g. UnknownDebtTypeException)
     *                                      propagate unwrapped and MUST NOT be converted
     *                                      to ProviderUnavailableException — only the
     *                                      chain in I7.1 distinguishes the two.
     */
    public function fetchDebts(Plate $plate): array;
}
