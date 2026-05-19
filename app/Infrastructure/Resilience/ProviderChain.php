<?php

declare(strict_types=1);

namespace App\Infrastructure\Resilience;

use App\Application\Ports\DebtProvider;
use App\Application\Ports\ProviderUnavailableException;
use App\Domain\Plate\Plate;
use App\Infrastructure\Logging\DemoLogger;
use Closure;

final class ProviderChain implements DebtProvider
{
    public const DEFAULT_BUDGET_SECONDS = 5.0;

    /**
     * Sequential fail-over with a total time budget. The first provider that
     * returns wins. Only `ProviderUnavailableException` triggers fallback —
     * domain-level errors (e.g. `UnknownDebtTypeException`) propagate unchanged
     * because they are deterministic responses, not transient failures
     * (CLAUDE.md §4 #7).
     *
     * When the cumulative elapsed time exceeds `$budgetSeconds`, the chain
     * short-circuits with `AllProvidersUnavailableException` even if it still
     * had providers left to try — better to fail fast than blow the request's
     * wall-clock budget.
     *
     * The `$clock` callable is injectable to keep budget tests deterministic;
     * defaults to `microtime(true)`.
     *
     * @param  list<DebtProvider>  $providers  ordered canonically (A → B → ...)
     * @param  list<string>  $providerNames  parallel to $providers; used only for demo logs
     * @param  ?Closure(): float  $clock
     */
    public function __construct(
        private readonly array $providers,
        private readonly float $budgetSeconds = self::DEFAULT_BUDGET_SECONDS,
        private readonly ?Closure $clock = null,
        private readonly array $providerNames = [],
        private readonly ?DemoLogger $demoLog = null,
    ) {}

    public function fetchDebts(Plate $plate): array
    {
        $start = $this->now();
        $errors = [];

        $this->demoLog?->chainStarted(count($this->providers), $this->budgetSeconds);

        foreach ($this->providers as $i => $provider) {
            $name = $this->providerNames[$i] ?? "provider-{$i}";

            if ($this->now() - $start > $this->budgetSeconds) {
                $this->demoLog?->budgetExceeded($this->budgetSeconds, count($errors));

                throw new AllProvidersUnavailableException(
                    $errors,
                    "All providers unavailable: budget of {$this->budgetSeconds}s exceeded after ".count($errors).' attempts',
                );
            }

            $providerStart = $this->now();
            try {
                $debts = $provider->fetchDebts($plate);
                $this->demoLog?->providerSucceeded($name, count($debts), $this->now() - $providerStart);

                return $debts;
            } catch (CircuitOpenException $e) {
                $this->demoLog?->circuitOpen($name);
                $errors[] = $e;
            } catch (ProviderUnavailableException $e) {
                $this->demoLog?->providerFailed($name, $e->getMessage(), $this->now() - $providerStart);
                $errors[] = $e;
            }
            // Any other exception (UnknownDebtTypeException, InvalidPlateException,
            // etc.) is NOT caught — it propagates to the caller.
        }

        throw new AllProvidersUnavailableException($errors);
    }

    private function now(): float
    {
        return ($this->clock ?? static fn (): float => microtime(true))();
    }
}
