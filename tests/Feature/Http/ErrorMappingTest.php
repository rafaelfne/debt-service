<?php

declare(strict_types=1);

use App\Application\Ports\DebtProvider;
use App\Application\Ports\ProviderUnavailableException;
use App\Domain\Debt\Debt;
use App\Domain\Debt\DebtType;
use App\Domain\Debt\InterestCalculator;
use App\Domain\Debt\IpvaInterestPolicy;
use App\Domain\Debt\MultaInterestPolicy;
use App\Domain\Debt\UnknownDebtTypeException;
use App\Domain\Exceptions\DomainException;
use App\Domain\Money\Money;
use App\Domain\Plate\InvalidPlateException;
use App\Domain\Plate\Plate;
use App\Infrastructure\Resilience\AllProvidersUnavailableException;
use App\Infrastructure\Resilience\ProviderChain;
use Illuminate\Support\Facades\Route;
use Tests\Support\Fakes\FakeDebtProvider;
use Tests\Support\Fixtures\DebtFixtures;

beforeEach(function (): void {
    $this->app->instance(
        InterestCalculator::class,
        new InterestCalculator(
            referenceDate: DebtFixtures::utc(DebtFixtures::REFERENCE_DATE),
            policies: [
                DebtType::IPVA->value => new IpvaInterestPolicy,
                DebtType::MULTA->value => new MultaInterestPolicy,
            ],
        ),
    );
});

it('returns 400 invalid_plate when InvalidPlateException bubbles up to the handler', function (): void {
    // FormRequest validation catches 'INVALID' first → 422 validation_failed.
    // To exercise the handler we register a test-only API route that throws
    // directly, simulating a defensive path where Plate::fromString fails
    // outside FormRequest (e.g. future internal callers).
    $this->app['router']->prefix('api')->group(function (): void {
        Route::get('/__test/invalid-plate', static function (): void {
            Plate::fromString('INVALID');
        });
    });

    $this->getJson('/api/__test/invalid-plate')
        ->assertStatus(400)
        ->assertExactJson(['error' => 'invalid_plate']);
});

it('returns 422 unknown_debt_type with the offending type when provider returns LICENCIAMENTO', function (): void {
    // Domain exception escapes from inside the use case (interest calculator)
    // — the chain MUST NOT swallow it, and the handler picks it up.
    $this->app->instance(DebtProvider::class, FakeDebtProvider::throwing(
        new UnknownDebtTypeException('LICENCIAMENTO'),
    ));

    $this->postJson('/api/debts/query', ['placa' => 'ABC1234'])
        ->assertStatus(422)
        ->assertExactJson([
            'error' => 'unknown_debt_type',
            'type' => 'LICENCIAMENTO',
        ]);
});

it('returns 503 all_providers_unavailable when every provider in the chain fails', function (): void {
    $this->app->instance(DebtProvider::class, new ProviderChain([
        FakeDebtProvider::unavailable('A down'),
        FakeDebtProvider::unavailable('B down'),
    ]));

    $this->postJson('/api/debts/query', ['placa' => 'ABC1234'])
        ->assertStatus(503)
        ->assertExactJson(['error' => 'all_providers_unavailable']);
});

it('chain is not triggered for FormRequest validation errors (422 wins before reaching the chain)', function (): void {
    // Double-check that the FormRequest path 422 doesn't accidentally also fire
    // the InvalidPlateException handler (which would also be 400).
    $this->app->instance(DebtProvider::class, FakeDebtProvider::withDebts([]));

    $response = $this->postJson('/api/debts/query', ['placa' => 'INVALID']);

    $response->assertStatus(422);
    $response->assertJsonPath('error', 'validation_failed');
});

it('canonical happy path: ABC1234 → byte-for-byte envelope, status 200', function (): void {
    $this->app->instance(
        DebtProvider::class,
        FakeDebtProvider::withDebts(DebtFixtures::combinedCanonical()),
    );

    $response = $this->postJson('/api/debts/query', ['placa' => 'ABC1234']);
    $response->assertOk();
    expect($response->json())->toEqual(
        json_decode((string) file_get_contents(__DIR__.'/fixtures/expected-ABC1234.json'), true),
    );
});

it('DomainException hierarchy is consistent — all 3 mapped types extend DomainException', function (): void {
    $invalidPlate = new InvalidPlateException('test');
    $unknownDebt = new UnknownDebtTypeException('TEST');
    $allUnavailable = new AllProvidersUnavailableException([
        new ProviderUnavailableException('A'),
    ]);

    expect($invalidPlate)->toBeInstanceOf(DomainException::class);
    expect($unknownDebt)->toBeInstanceOf(DomainException::class);
    expect($allUnavailable)->toBeInstanceOf(DomainException::class);
});

// Helper: build a Debt that won't actually be calculated (use case will call
// the provider's throw before it reaches this).
function unusedDebt(): Debt
{
    return new Debt(
        DebtType::IPVA,
        Money::of('1.00'),
        DebtFixtures::utc('2024-01-01T00:00:00Z'),
    );
}
