<?php

declare(strict_types=1);

use App\Application\Ports\ProviderUnavailableException;
use App\Domain\Debt\DebtType;
use App\Domain\Debt\UnknownDebtTypeException;
use App\Domain\Plate\Plate;
use App\Infrastructure\Providers\ProviderBXmlAdapter;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

const PROVIDER_B_URL = 'http://provider-b.test';

beforeEach(function (): void {
    $this->adapter = new ProviderBXmlAdapter(PROVIDER_B_URL);
    $this->plate = Plate::fromString('ABC1234');
});

function xmlResponse(string $body, int $status = 200): PromiseInterface
{
    return Http::response($body, $status, ['Content-Type' => 'application/xml']);
}

it('parses the canonical XML into a list of Debt VOs', function (): void {
    $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <response>
            <plate>ABC1234</plate>
            <debts>
                <debt><type>IPVA</type><amount>1500.33</amount><due_date>2024-01-10</due_date></debt>
                <debt><type>MULTA</type><amount>300.50</amount><due_date>2024-02-15</due_date></debt>
            </debts>
        </response>
        XML;

    Http::fake([PROVIDER_B_URL.'/debts/ABC1234' => xmlResponse($xml)]);

    $debts = $this->adapter->fetchDebts($this->plate);

    expect($debts)->toHaveCount(2);
    expect($debts[0]->type)->toBe(DebtType::IPVA)
        ->and($debts[0]->originalAmount->toString())->toBe('1500.33')
        ->and($debts[0]->dueDate->format('Y-m-d'))->toBe('2024-01-10');
    expect($debts[1]->type)->toBe(DebtType::MULTA)
        ->and($debts[1]->originalAmount->toString())->toBe('300.50')
        ->and($debts[1]->dueDate->format('Y-m-d'))->toBe('2024-02-15');
});

it('throws ProviderUnavailableException on HTTP 500', function (): void {
    Http::fake([PROVIDER_B_URL.'/debts/*' => xmlResponse('<?xml version="1.0"?><error/>', 500)]);

    expect(fn () => $this->adapter->fetchDebts($this->plate))
        ->toThrow(ProviderUnavailableException::class);
});

it('throws ProviderUnavailableException on connection errors', function (): void {
    Http::fake([
        PROVIDER_B_URL.'/debts/*' => fn () => throw new ConnectionException('refused'),
    ]);

    expect(fn () => $this->adapter->fetchDebts($this->plate))
        ->toThrow(ProviderUnavailableException::class);
});

it('throws ProviderUnavailableException on malformed XML', function (): void {
    Http::fake([PROVIDER_B_URL.'/debts/*' => xmlResponse('<not-closed>')]);

    expect(fn () => $this->adapter->fetchDebts($this->plate))
        ->toThrow(ProviderUnavailableException::class, 'malformed XML');
});

it('propagates UnknownDebtTypeException raw', function (): void {
    $xml = <<<'XML'
        <?xml version="1.0"?>
        <response>
            <debts>
                <debt><type>LICENCIAMENTO</type><amount>100.00</amount><due_date>2024-01-01</due_date></debt>
            </debts>
        </response>
        XML;

    Http::fake([PROVIDER_B_URL.'/debts/*' => xmlResponse($xml)]);

    expect(fn () => $this->adapter->fetchDebts($this->plate))
        ->toThrow(UnknownDebtTypeException::class, 'Unknown debt type: LICENCIAMENTO');
});

it('requests the exact URL using the uppercase plate', function (): void {
    Http::fake([
        PROVIDER_B_URL.'/debts/ABC1234' => xmlResponse('<?xml version="1.0"?><response><debts/></response>'),
    ]);

    $this->adapter->fetchDebts(Plate::fromString('abc1234'));

    Http::assertSent(fn ($request) => $request->url() === PROVIDER_B_URL.'/debts/ABC1234'
        && $request->hasHeader('Accept', 'application/xml'));
});
