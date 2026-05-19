<?php

declare(strict_types=1);

use App\Application\Ports\ProviderUnavailableException;
use App\Domain\Debt\DebtType;
use App\Domain\Debt\UnknownDebtTypeException;
use App\Domain\Plate\Plate;
use App\Infrastructure\Providers\ProviderCCsvAdapter;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

const PROVIDER_C_URL = 'http://provider-c.test';

beforeEach(function (): void {
    $this->adapter = new ProviderCCsvAdapter(PROVIDER_C_URL);
    $this->plate = Plate::fromString('ABC1234');
});

function csvResponse(string $body, int $status = 200): PromiseInterface
{
    return Http::response($body, $status, ['Content-Type' => 'text/csv']);
}

it('parses the canonical CSV into a list of Debt VOs', function (): void {
    Http::fake([
        PROVIDER_C_URL.'/debts/ABC1234' => csvResponse(
            "type,amount,due_date\nIPVA,1500.00,2024-01-10\nMULTA,300.50,2024-02-15\n"
        ),
    ]);

    $debts = $this->adapter->fetchDebts($this->plate);

    expect($debts)->toHaveCount(2);
    expect($debts[0]->type)->toBe(DebtType::IPVA)
        ->and($debts[0]->originalAmount->toString())->toBe('1500.00')
        ->and($debts[0]->dueDate->format('Y-m-d'))->toBe('2024-01-10');
    expect($debts[1]->type)->toBe(DebtType::MULTA)
        ->and($debts[1]->originalAmount->toString())->toBe('300.50')
        ->and($debts[1]->dueDate->format('Y-m-d'))->toBe('2024-02-15');
});

it('returns an empty list when the body only has the header row', function (): void {
    Http::fake([
        PROVIDER_C_URL.'/debts/ABC1234' => csvResponse("type,amount,due_date\n"),
    ]);

    expect($this->adapter->fetchDebts($this->plate))->toBe([]);
});

it('returns an empty list when the body is empty', function (): void {
    Http::fake([
        PROVIDER_C_URL.'/debts/ABC1234' => csvResponse(''),
    ]);

    expect($this->adapter->fetchDebts($this->plate))->toBe([]);
});

it('tolerates column reordering thanks to header indexing', function (): void {
    Http::fake([
        PROVIDER_C_URL.'/debts/ABC1234' => csvResponse(
            "due_date,type,amount\n2024-01-10,IPVA,1500.00\n"
        ),
    ]);

    $debts = $this->adapter->fetchDebts($this->plate);

    expect($debts)->toHaveCount(1);
    expect($debts[0]->type)->toBe(DebtType::IPVA);
    expect($debts[0]->originalAmount->toString())->toBe('1500.00');
    expect($debts[0]->dueDate->format('Y-m-d'))->toBe('2024-01-10');
});

it('throws ProviderUnavailableException on HTTP 500', function (): void {
    Http::fake([
        PROVIDER_C_URL.'/debts/*' => csvResponse('error,boom', 500),
    ]);

    expect(fn () => $this->adapter->fetchDebts($this->plate))
        ->toThrow(ProviderUnavailableException::class);
});

it('throws ProviderUnavailableException on connection errors', function (): void {
    Http::fake([
        PROVIDER_C_URL.'/debts/*' => fn () => throw new ConnectionException('refused'),
    ]);

    expect(fn () => $this->adapter->fetchDebts($this->plate))
        ->toThrow(ProviderUnavailableException::class);
});

it('throws ProviderUnavailableException when the header lacks required columns', function (): void {
    Http::fake([
        PROVIDER_C_URL.'/debts/*' => csvResponse("type,amount\nIPVA,1500.00\n"),
    ]);

    expect(fn () => $this->adapter->fetchDebts($this->plate))
        ->toThrow(ProviderUnavailableException::class, 'missing `due_date` column');
});

it('propagates UnknownDebtTypeException raw — domain errors are NOT wrapped', function (): void {
    Http::fake([
        PROVIDER_C_URL.'/debts/*' => csvResponse(
            "type,amount,due_date\nLICENCIAMENTO,100.00,2024-01-01\n"
        ),
    ]);

    expect(fn () => $this->adapter->fetchDebts($this->plate))
        ->toThrow(UnknownDebtTypeException::class, 'Unknown debt type: LICENCIAMENTO');
});

it('requests the exact URL using the uppercase plate', function (): void {
    Http::fake([
        PROVIDER_C_URL.'/debts/ABC1234' => csvResponse("type,amount,due_date\n"),
    ]);

    $this->adapter->fetchDebts(Plate::fromString('abc1234'));

    Http::assertSent(fn ($request) => $request->url() === PROVIDER_C_URL.'/debts/ABC1234'
        && $request->hasHeader('Accept', 'text/csv'));
});
