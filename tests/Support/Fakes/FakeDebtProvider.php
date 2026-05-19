<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Application\Ports\DebtProvider;
use App\Application\Ports\ProviderUnavailableException;
use App\Domain\Debt\Debt;
use App\Domain\Plate\Plate;
use Throwable;

/**
 * Reusable in-memory DebtProvider for use case tests (and, later, for
 * provider chain tests in #34).
 *
 * - `withDebts($debts)` makes the next `fetchDebts()` return that list.
 * - `throwing($exception)` makes the next `fetchDebts()` re-throw the given
 *   exception (typically a `ProviderUnavailableException`, but supports any
 *   Throwable so domain-level errors can be exercised too).
 */
final class FakeDebtProvider implements DebtProvider
{
    /** @var list<Debt> */
    private array $debts = [];

    private ?Throwable $exception = null;

    public int $callCount = 0;

    /** @var list<Plate> */
    public array $platesSeen = [];

    /**
     * @param  list<Debt>  $debts
     */
    public static function withDebts(array $debts): self
    {
        $instance = new self;
        $instance->debts = $debts;

        return $instance;
    }

    public static function throwing(Throwable $exception): self
    {
        $instance = new self;
        $instance->exception = $exception;

        return $instance;
    }

    public static function unavailable(string $message = 'Provider unavailable'): self
    {
        return self::throwing(new ProviderUnavailableException($message));
    }

    public function fetchDebts(Plate $plate): array
    {
        $this->callCount++;
        $this->platesSeen[] = $plate;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->debts;
    }
}
