<?php

declare(strict_types=1);

/**
 * GOLDEN BOUNDARY REGRESSION TEST — DO NOT REPLACE WITH json_decode.
 *
 * These tests deliberately use "0.1" / "0.2" — values that are not exactly
 * representable in IEEE 754 binary floats. If anyone refactors the provider
 * adapters to use `json_decode` (which yields `float`), or drops the explicit
 * `(string)` cast in the XML adapter, or removes `FLOATS_AS_STRINGS` from the
 * JsonReader, these assertions WILL break because Money would either round
 * 0.1 + 0.2 to "0.30000000000000004" or refuse to construct from a non-string.
 *
 * Invariantes guardadas:
 *   • CLAUDE.md §4 #1 — Money construído só de string
 *   • CLAUDE.md §4 #2 — `(float)` proibido em adapters
 *   • CLAUDE.md §10 #2 — não cair de novo no cast para float
 */

use App\Domain\Money\Money;
use App\Domain\Plate\Plate;
use App\Infrastructure\Providers\ProviderAJsonAdapter;
use App\Infrastructure\Providers\ProviderBXmlAdapter;
use Illuminate\Support\Facades\Http;

const PRECISION_PROVIDER_A_URL = 'http://precision-a.test';
const PRECISION_PROVIDER_B_URL = 'http://precision-b.test';

it('keeps Money::of("0.1") + Money::of("0.2") == Money::of("0.3") — the canary', function (): void {
    expect(
        Money::of('0.1')->add(Money::of('0.2'))->equals(Money::of('0.3'))
    )->toBeTrue();
});

it('Provider A: a JSON amount of 0.1 round-trips through pcrov as Money::of("0.1")', function (): void {
    // Note: the JSON value is a NUMERIC token (not quoted). FLOATS_AS_STRINGS
    // in the adapter is what preserves the raw "0.1" — json_decode would yield
    // a PHP float that corrupts the value.
    Http::fake([
        PRECISION_PROVIDER_A_URL.'/debts/ABC1234' => Http::response(
            '{"plate":"ABC1234","debts":[{"type":"IPVA","amount":0.1,"due_date":"2024-01-01"}]}',
            200,
        ),
    ]);

    $adapter = new ProviderAJsonAdapter(PRECISION_PROVIDER_A_URL);
    $debts = $adapter->fetchDebts(Plate::fromString('ABC1234'));

    expect($debts)->toHaveCount(1)
        ->and($debts[0]->originalAmount->equals(Money::of('0.1')))->toBeTrue()
        ->and($debts[0]->originalAmount->toString())->toBe('0.10');
});

it('Provider A: 0.1 + 0.2 read separately can still be summed as exact 0.30', function (): void {
    Http::fake([
        PRECISION_PROVIDER_A_URL.'/debts/ABC1234' => Http::response(
            '{"plate":"ABC1234","debts":['
            .'{"type":"IPVA","amount":0.1,"due_date":"2024-01-01"},'
            .'{"type":"MULTA","amount":0.2,"due_date":"2024-01-01"}'
            .']}',
            200,
        ),
    ]);

    $adapter = new ProviderAJsonAdapter(PRECISION_PROVIDER_A_URL);
    $debts = $adapter->fetchDebts(Plate::fromString('ABC1234'));

    $sum = $debts[0]->originalAmount->add($debts[1]->originalAmount);
    expect($sum->equals(Money::of('0.3')))->toBeTrue()
        ->and($sum->toString())->toBe('0.30');
});

it('Provider B: an XML amount of 0.1 round-trips through SimpleXML as Money::of("0.1")', function (): void {
    Http::fake([
        PRECISION_PROVIDER_B_URL.'/debts/ABC1234' => Http::response(
            '<?xml version="1.0"?>'
            .'<response><debts><debt>'
            .'<type>IPVA</type><amount>0.1</amount><due_date>2024-01-01</due_date>'
            .'</debt></debts></response>',
            200,
            ['Content-Type' => 'application/xml'],
        ),
    ]);

    $adapter = new ProviderBXmlAdapter(PRECISION_PROVIDER_B_URL);
    $debts = $adapter->fetchDebts(Plate::fromString('ABC1234'));

    expect($debts)->toHaveCount(1)
        ->and($debts[0]->originalAmount->equals(Money::of('0.1')))->toBeTrue()
        ->and($debts[0]->originalAmount->toString())->toBe('0.10');
});

it('Provider B: 0.1 + 0.2 read separately can still be summed as exact 0.30', function (): void {
    Http::fake([
        PRECISION_PROVIDER_B_URL.'/debts/ABC1234' => Http::response(
            '<?xml version="1.0"?>'
            .'<response><debts>'
            .'<debt><type>IPVA</type><amount>0.1</amount><due_date>2024-01-01</due_date></debt>'
            .'<debt><type>MULTA</type><amount>0.2</amount><due_date>2024-01-01</due_date></debt>'
            .'</debts></response>',
            200,
            ['Content-Type' => 'application/xml'],
        ),
    ]);

    $adapter = new ProviderBXmlAdapter(PRECISION_PROVIDER_B_URL);
    $debts = $adapter->fetchDebts(Plate::fromString('ABC1234'));

    $sum = $debts[0]->originalAmount->add($debts[1]->originalAmount);
    expect($sum->equals(Money::of('0.3')))->toBeTrue()
        ->and($sum->toString())->toBe('0.30');
});
