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
use pcrov\JsonReader\JsonReader;
use Throwable;

final class ProviderAJsonAdapter implements DebtProvider
{
    /**
     * Network parameters default to the canon `2s / 3 retries / 100ms` from the
     * project's resilience design. AppServiceProvider wires actual values from
     * `config('business.providers.a.*')` so demo-time edits in `.env` flow in
     * without touching this class.
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 2,
        private readonly int $retryAttempts = 3,
        private readonly int $retryBackoffMs = 100,
        private readonly string $simulateFailure = '',
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
                    // Only retry on transient failures; 4xx is deterministic.
                    when: fn (Throwable $e): bool => $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError()),
                    throw: false,
                )
                ->acceptJson()
                ->get(
                    "{$this->baseUrl}/debts/{$plate->toString()}",
                    $this->simulateFailure !== '' ? ['fail' => $this->simulateFailure] : [],
                );
        } catch (ConnectionException $e) {
            throw new ProviderUnavailableException(
                "Provider A unreachable: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->serverError()) {
            throw new ProviderUnavailableException(
                "Provider A returned {$response->status()}",
            );
        }

        if ($response->failed()) {
            throw new ProviderUnavailableException(
                "Provider A request failed with status {$response->status()}",
            );
        }

        return $response;
    }

    /**
     * Streams the response body via pcrov/jsonreader with FLOATS_AS_STRINGS so decimal
     * tokens never pass through PHP float (CLAUDE.md §4 #1/#2). Each debt entry is
     * mapped to a Debt VO using Money::of() on the raw string token.
     *
     * @return list<Debt>
     */
    private function parse(string $body): array
    {
        $reader = new JsonReader(JsonReader::FLOATS_AS_STRINGS);
        $debts = [];

        try {
            $reader->json($body);

            // Walk to the `debts` array (tokenization errors surface here, not in json()).
            while ($reader->read()) {
                if ($reader->type() === JsonReader::ARRAY && $reader->name() === 'debts') {
                    foreach ($this->readArrayOfObjects($reader) as $entry) {
                        $debts[] = $this->mapDebt($entry);
                    }
                    break;
                }
            }
        } catch (UnknownDebtTypeException $e) {
            // Domain exceptions propagate raw — chain MUST NOT fall back on them.
            throw $e;
        } catch (Throwable $e) {
            throw new ProviderUnavailableException(
                "Provider A returned malformed JSON: {$e->getMessage()}",
                previous: $e,
            );
        } finally {
            $reader->close();
        }

        return $debts;
    }

    /**
     * @return iterable<array{type: string, amount: string, due_date: string}>
     */
    private function readArrayOfObjects(JsonReader $reader): iterable
    {
        $entries = $reader->value();

        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            // Cast Money fields to string so amounts coming back as int/float (which
            // would only happen if FLOATS_AS_STRINGS were dropped) still arrive as
            // a string at Money::of — defence in depth on the precision invariant.
            yield [
                'type' => (string) ($entry['type'] ?? ''),
                'amount' => (string) ($entry['amount'] ?? ''),
                'due_date' => (string) ($entry['due_date'] ?? ''),
            ];
        }
    }

    /**
     * @param  array{type: string, amount: string, due_date: string}  $entry
     */
    private function mapDebt(array $entry): Debt
    {
        return new Debt(
            type: DebtType::fromString($entry['type']),
            originalAmount: Money::of($entry['amount']),
            dueDate: new DateTimeImmutable($entry['due_date'], new DateTimeZone('UTC')),
        );
    }
}
