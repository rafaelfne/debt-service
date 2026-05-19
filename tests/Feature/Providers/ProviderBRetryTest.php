<?php

declare(strict_types=1);

use App\Application\Ports\ProviderUnavailableException;
use App\Domain\Plate\Plate;
use App\Infrastructure\Providers\ProviderBXmlAdapter;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

const RETRY_PROVIDER_B_URL = 'http://retry-b.test';

beforeEach(function (): void {
    $this->adapter = new ProviderBXmlAdapter(RETRY_PROVIDER_B_URL);
    $this->plate = Plate::fromString('ABC1234');
});

function retryXmlOk(): PromiseInterface
{
    return Http::response(
        '<?xml version="1.0"?><response><debts/></response>',
        200,
        ['Content-Type' => 'application/xml'],
    );
}

function retryXmlError(int $status): PromiseInterface
{
    return Http::response(
        '<?xml version="1.0"?><error/>',
        $status,
        ['Content-Type' => 'application/xml'],
    );
}

it('retries on 503 and succeeds on the third attempt (503, 503, 200)', function (): void {
    Http::fake([
        RETRY_PROVIDER_B_URL.'/debts/*' => Http::sequence()
            ->push('<?xml version="1.0"?><error/>', 503, ['Content-Type' => 'application/xml'])
            ->push('<?xml version="1.0"?><error/>', 503, ['Content-Type' => 'application/xml'])
            ->push('<?xml version="1.0"?><response><debts/></response>', 200, ['Content-Type' => 'application/xml']),
    ]);

    expect($this->adapter->fetchDebts($this->plate))->toBe([]);
    Http::assertSentCount(3);
});

it('does NOT retry on 400 — 4xx is deterministic (1 attempt only)', function (): void {
    Http::fake([
        RETRY_PROVIDER_B_URL.'/debts/*' => retryXmlError(400),
    ]);

    try {
        $this->adapter->fetchDebts($this->plate);
        $this->fail('Expected ProviderUnavailableException');
    } catch (ProviderUnavailableException $e) {
        Http::assertSentCount(1);
    }
});

it('retries up to 3 times then gives up with ProviderUnavailableException', function (): void {
    Http::fake([
        RETRY_PROVIDER_B_URL.'/debts/*' => retryXmlError(500),
    ]);

    expect(fn () => $this->adapter->fetchDebts($this->plate))
        ->toThrow(ProviderUnavailableException::class);

    Http::assertSentCount(3);
});

it('retries on ConnectionException', function (): void {
    $attempts = 0;
    Http::fake([
        RETRY_PROVIDER_B_URL.'/debts/*' => function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new ConnectionException('refused');
            }

            return retryXmlOk();
        },
    ]);

    expect($this->adapter->fetchDebts($this->plate))->toBe([]);
    expect($attempts)->toBe(3);
});
