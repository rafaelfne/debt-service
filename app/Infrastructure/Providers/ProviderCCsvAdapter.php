<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Application\Ports\DebtProvider;
use App\Application\Ports\ProviderUnavailableException;
use App\Domain\Debt\Debt;
use App\Domain\Debt\DebtType;
use App\Domain\Debt\UnknownDebtTypeException;
use App\Domain\Money\Money;
use App\Domain\Plate\Plate;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * CSV provider — third adapter, added as a live demonstration of how the
 * hexagonal seam keeps the cost of "new provider" linear (CLAUDE.md §7.1).
 * Same network knobs as A and B; values are read as raw strings via
 * `str_getcsv` and fed straight into `Money::of()` (never (float)).
 */
final class ProviderCCsvAdapter implements DebtProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 2,
        private readonly int $retryAttempts = 3,
        private readonly int $retryBackoffMs = 100,
    ) {}

    public function fetchDebts(Plate $plate): array
    {
        $response = $this->request($plate);

        return $this->parse($response->body());
    }

    private function request(Plate $plate): Response
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->retry(
                    times: $this->retryAttempts,
                    sleepMilliseconds: $this->retryBackoffMs,
                    when: fn (Throwable $e): bool => $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError()),
                    throw: false,
                )
                ->withHeaders(['Accept' => 'text/csv'])
                ->get("{$this->baseUrl}/debts/{$plate->toString()}");
        } catch (ConnectionException $e) {
            throw new ProviderUnavailableException(
                "Provider C unreachable: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->serverError()) {
            throw new ProviderUnavailableException("Provider C returned {$response->status()}");
        }

        if ($response->failed()) {
            throw new ProviderUnavailableException("Provider C request failed with status {$response->status()}");
        }

        return $response;
    }

    /**
     * Parses a CSV body whose first row is a header `type,amount,due_date`.
     * Empty body or header-only body returns an empty list. Missing required
     * columns surface as `ProviderUnavailableException` (treated as malformed).
     *
     * Every cell is taken as the raw `str_getcsv` string and passed to
     * `Money::of`/`DateTimeImmutable` directly — never cast to float.
     *
     * @return list<Debt>
     */
    private function parse(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body)) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));

        if ($lines === []) {
            return [];
        }

        $header = str_getcsv(array_shift($lines), ',', '"', '\\');
        $indices = $this->indexHeader($header);

        $debts = [];
        foreach ($lines as $line) {
            $cells = str_getcsv($line, ',', '"', '\\');

            $debts[] = new Debt(
                type: DebtType::fromString((string) ($cells[$indices['type']] ?? '')),
                originalAmount: Money::of((string) ($cells[$indices['amount']] ?? '')),
                dueDate: new DateTimeImmutable(
                    (string) ($cells[$indices['due_date']] ?? ''),
                    new DateTimeZone('UTC'),
                ),
            );
            // UnknownDebtTypeException propagates raw — chain treats it as a
            // domain error, not a provider failure (CLAUDE.md §4 #7).
        }

        return $debts;
    }

    /**
     * @param  list<string>  $header
     * @return array{type: int, amount: int, due_date: int}
     */
    private function indexHeader(array $header): array
    {
        $map = [];
        foreach ($header as $i => $name) {
            $map[strtolower(trim($name))] = $i;
        }

        foreach (['type', 'amount', 'due_date'] as $required) {
            if (! array_key_exists($required, $map)) {
                throw new ProviderUnavailableException(
                    "Provider C returned malformed CSV: missing `{$required}` column",
                );
            }
        }

        return [
            'type' => $map['type'],
            'amount' => $map['amount'],
            'due_date' => $map['due_date'],
        ];
    }
}
