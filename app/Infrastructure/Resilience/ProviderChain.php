<?php

declare(strict_types=1);

namespace App\Infrastructure\Resilience;

use App\Application\Ports\DebtProvider;
use App\Application\Ports\ProviderUnavailableException;
use App\Domain\Plate\Plate;

final class ProviderChain implements DebtProvider
{
    /**
     * Sequential fail-over: the first provider to return wins. Only
     * `ProviderUnavailableException` triggers fallback — domain-level errors
     * (e.g. `UnknownDebtTypeException`) propagate unchanged because they are
     * deterministic responses, not transient failures (CLAUDE.md §4 #7).
     *
     * @param  list<DebtProvider>  $providers  ordered canonically (A → B → ...)
     */
    public function __construct(
        private readonly array $providers,
    ) {}

    public function fetchDebts(Plate $plate): array
    {
        $errors = [];

        foreach ($this->providers as $provider) {
            try {
                return $provider->fetchDebts($plate);
            } catch (ProviderUnavailableException $e) {
                $errors[] = $e;
            }
            // Any other exception (UnknownDebtTypeException, InvalidPlateException,
            // etc.) is NOT caught — it propagates to the caller.
        }

        throw new AllProvidersUnavailableException($errors);
    }
}
