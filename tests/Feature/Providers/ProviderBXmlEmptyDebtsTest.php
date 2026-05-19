<?php

declare(strict_types=1);

use App\Domain\Plate\Plate;
use App\Infrastructure\Providers\ProviderBXmlAdapter;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

const EMPTY_PROVIDER_B_URL = 'http://provider-b-empty.test';

function emptyXmlResponse(string $body): PromiseInterface
{
    return Http::response($body, 200, ['Content-Type' => 'application/xml']);
}

beforeEach(function (): void {
    $this->adapter = new ProviderBXmlAdapter(EMPTY_PROVIDER_B_URL);
    $this->plate = Plate::fromString('ABC1234');
});

it('returns [] when the provider responds with self-closed <debts/>', function (): void {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><response><plate>ABC1234</plate><debts/></response>';

    Http::fake([EMPTY_PROVIDER_B_URL.'/debts/ABC1234' => emptyXmlResponse($xml)]);

    expect($this->adapter->fetchDebts($this->plate))->toBe([]);
});

it('returns [] when the provider responds with explicit <debts></debts>', function (): void {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><response><plate>ABC1234</plate><debts></debts></response>';

    Http::fake([EMPTY_PROVIDER_B_URL.'/debts/ABC1234' => emptyXmlResponse($xml)]);

    expect($this->adapter->fetchDebts($this->plate))->toBe([]);
});

it('treats <debts/> and <debts></debts> identically (no exception, both empty)', function (): void {
    $selfClosed = '<?xml version="1.0"?><response><debts/></response>';
    $explicit = '<?xml version="1.0"?><response><debts></debts></response>';

    Http::fake([EMPTY_PROVIDER_B_URL.'/debts/ABC1234' => emptyXmlResponse($selfClosed)]);
    $resultSelfClosed = $this->adapter->fetchDebts($this->plate);

    Http::fake([EMPTY_PROVIDER_B_URL.'/debts/ABC1234' => emptyXmlResponse($explicit)]);
    $resultExplicit = $this->adapter->fetchDebts($this->plate);

    expect($resultSelfClosed)->toBe($resultExplicit)->toBe([]);
});

it('returns [] also when the <debts> node is missing entirely', function (): void {
    $xml = '<?xml version="1.0"?><response><plate>ABC1234</plate></response>';

    Http::fake([EMPTY_PROVIDER_B_URL.'/debts/ABC1234' => emptyXmlResponse($xml)]);

    expect($this->adapter->fetchDebts($this->plate))->toBe([]);
});
