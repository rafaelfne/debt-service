<?php

declare(strict_types=1);

use App\Application\Ports\ProviderUnavailableException;
use App\Domain\Plate\Plate;
use App\Infrastructure\Providers\ProviderAJsonAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

const RETRY_PROVIDER_A_URL = 'http://retry-a.test';

beforeEach(function (): void {
    $this->adapter = new ProviderAJsonAdapter(RETRY_PROVIDER_A_URL);
    $this->plate = Plate::fromString('ABC1234');
});

it('retries on 503 and succeeds on the third attempt (503, 503, 200)', function (): void {
    Http::fake([
        RETRY_PROVIDER_A_URL.'/debts/*' => Http::sequence()
            ->push(['error' => 'maintenance'], 503)
            ->push(['error' => 'maintenance'], 503)
            ->push(['plate' => 'ABC1234', 'debts' => []], 200),
    ]);

    expect($this->adapter->fetchDebts($this->plate))->toBe([]);

    // 3 attempts total: 2 retries + final success
    Http::assertSentCount(3);
});

it('does NOT retry on 400 — 4xx is deterministic (1 attempt only)', function (): void {
    Http::fake([
        RETRY_PROVIDER_A_URL.'/debts/*' => Http::response(['error' => 'bad plate'], 400),
    ]);

    try {
        $this->adapter->fetchDebts($this->plate);
        $this->fail('Expected ProviderUnavailableException');
    } catch (ProviderUnavailableException $e) {
        // 400 is treated as provider failure (chain can fall back) but never retried.
        Http::assertSentCount(1);
    }
});

it('retries up to 3 times then gives up with ProviderUnavailableException', function (): void {
    Http::fake([
        RETRY_PROVIDER_A_URL.'/debts/*' => Http::response(['error' => 'down'], 500),
    ]);

    expect(fn () => $this->adapter->fetchDebts($this->plate))
        ->toThrow(ProviderUnavailableException::class);

    Http::assertSentCount(3);
});

it('retries on ConnectionException', function (): void {
    $attempts = 0;
    Http::fake([
        RETRY_PROVIDER_A_URL.'/debts/*' => function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new ConnectionException('dns');
            }

            return Http::response(['plate' => 'ABC1234', 'debts' => []], 200);
        },
    ]);

    expect($this->adapter->fetchDebts($this->plate))->toBe([]);
    expect($attempts)->toBe(3);
});
