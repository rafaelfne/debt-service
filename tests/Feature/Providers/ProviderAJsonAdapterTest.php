<?php

declare(strict_types=1);

use App\Application\Ports\ProviderUnavailableException;
use App\Domain\Debt\DebtType;
use App\Domain\Debt\UnknownDebtTypeException;
use App\Domain\Plate\Plate;
use App\Infrastructure\Providers\ProviderAJsonAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

const PROVIDER_A_URL = 'http://provider-a.test';

beforeEach(function (): void {
    $this->adapter = new ProviderAJsonAdapter(PROVIDER_A_URL);
    $this->plate = Plate::fromString('ABC1234');
});

it('parses the canonical JSON response into a list of Debt VOs', function (): void {
    Http::fake([
        PROVIDER_A_URL.'/debts/ABC1234' => Http::response([
            'plate' => 'ABC1234',
            'debts' => [
                ['type' => 'IPVA',  'amount' => '1500.33', 'due_date' => '2024-01-10'],
                ['type' => 'MULTA', 'amount' => '300.50',  'due_date' => '2024-02-15'],
            ],
        ]),
    ]);

    $debts = $this->adapter->fetchDebts($this->plate);

    expect($debts)->toHaveCount(2);
    expect($debts[0]->type)->toBe(DebtType::IPVA)
        ->and($debts[0]->originalAmount->toString())->toBe('1500.33')
        ->and($debts[0]->dueDate->format('Y-m-d'))->toBe('2024-01-10');
    expect($debts[1]->type)->toBe(DebtType::MULTA)
        ->and($debts[1]->originalAmount->toString())->toBe('300.50')
        ->and($debts[1]->dueDate->format('Y-m-d'))->toBe('2024-02-15');
});

it('returns an empty list when the provider responds with debts: []', function (): void {
    Http::fake([
        PROVIDER_A_URL.'/debts/ABC1234' => Http::response([
            'plate' => 'ABC1234',
            'debts' => [],
        ]),
    ]);

    expect($this->adapter->fetchDebts($this->plate))->toBe([]);
});

it('throws ProviderUnavailableException on HTTP 500', function (): void {
    Http::fake([
        PROVIDER_A_URL.'/debts/*' => Http::response(['error' => 'boom'], 500),
    ]);

    expect(fn () => $this->adapter->fetchDebts($this->plate))
        ->toThrow(ProviderUnavailableException::class);
});

it('throws ProviderUnavailableException on connection errors', function (): void {
    Http::fake([
        PROVIDER_A_URL.'/debts/*' => fn () => throw new ConnectionException('dns'),
    ]);

    expect(fn () => $this->adapter->fetchDebts($this->plate))
        ->toThrow(ProviderUnavailableException::class);
});

it('throws ProviderUnavailableException on malformed JSON', function (): void {
    Http::fake([
        PROVIDER_A_URL.'/debts/*' => Http::response('not-a-json{{', 200),
    ]);

    expect(fn () => $this->adapter->fetchDebts($this->plate))
        ->toThrow(ProviderUnavailableException::class);
});

it('propagates UnknownDebtTypeException raw — domain errors are NOT wrapped as provider failures', function (): void {
    Http::fake([
        PROVIDER_A_URL.'/debts/*' => Http::response([
            'plate' => 'ABC1234',
            'debts' => [
                ['type' => 'LICENCIAMENTO', 'amount' => '100.00', 'due_date' => '2024-01-01'],
            ],
        ]),
    ]);

    expect(fn () => $this->adapter->fetchDebts($this->plate))
        ->toThrow(UnknownDebtTypeException::class, 'Unknown debt type: LICENCIAMENTO');
});

it('requests the exact URL using the uppercase plate', function (): void {
    Http::fake([
        PROVIDER_A_URL.'/debts/ABC1234' => Http::response([
            'plate' => 'ABC1234',
            'debts' => [],
        ]),
    ]);

    $this->adapter->fetchDebts(Plate::fromString('abc1234'));

    Http::assertSent(fn ($request) => $request->url() === PROVIDER_A_URL.'/debts/ABC1234'
        && $request->hasHeader('Accept', 'application/json'));
});
