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
use SimpleXMLElement;
use Throwable;

final class ProviderBXmlAdapter implements DebtProvider
{
    /**
     * Same canon defaults as Provider A; AppServiceProvider wires actual
     * values from `config('business.providers.b.*')`.
     */
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
                ->withHeaders(['Accept' => 'application/xml'])
                ->get("{$this->baseUrl}/debts/{$plate->toString()}");
        } catch (ConnectionException $e) {
            throw new ProviderUnavailableException(
                "Provider B unreachable: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->serverError()) {
            throw new ProviderUnavailableException(
                "Provider B returned {$response->status()}",
            );
        }

        if ($response->failed()) {
            throw new ProviderUnavailableException(
                "Provider B request failed with status {$response->status()}",
            );
        }

        return $response;
    }

    /**
     * Parses the XML response via SimpleXML, taking leaf values with explicit
     * `(string)` casts. NEVER use `(float)` here — that would corrupt decimals.
     *
     * @return list<Debt>
     */
    private function parse(string $body): array
    {
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        if ($xml === false) {
            throw new ProviderUnavailableException('Provider B returned malformed XML');
        }

        $debtsNode = $xml->debts ?? null;
        if ($debtsNode === null || $debtsNode->count() === 0) {
            return [];
        }

        $debts = [];
        foreach ($debtsNode->debt as $node) {
            try {
                $debts[] = new Debt(
                    type: DebtType::fromString((string) $node->type),                 // never (float)
                    originalAmount: Money::of((string) $node->amount),                // never (float)
                    dueDate: new DateTimeImmutable((string) $node->due_date, new DateTimeZone('UTC')),
                );
            } catch (UnknownDebtTypeException $e) {
                // Domain exception propagates raw — chain MUST NOT fall back on it.
                throw $e;
            }
        }

        return $debts;
    }
}
