<?php

declare(strict_types=1);

use App\Application\Ports\DebtProvider;
use App\Domain\Debt\DebtType;
use App\Domain\Debt\InterestCalculator;
use App\Domain\Debt\IpvaInterestPolicy;
use App\Domain\Debt\MultaInterestPolicy;
use Tests\Support\Fakes\FakeDebtProvider;
use Tests\Support\Fixtures\DebtFixtures;

beforeEach(function (): void {
    // Override the chain wired in AppServiceProvider with a FakeDebtProvider so
    // the controller is tested without hitting real HTTP. The reference date in
    // InterestCalculator is "now"; we override that too by binding a calculator
    // pinned to the canon reference date.
    $this->app->instance(
        DebtProvider::class,
        FakeDebtProvider::withDebts(DebtFixtures::combinedCanonical()),
    );

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

it('POST /api/debts/query with canon plate returns the byte-for-byte enunciado envelope', function (): void {
    $response = $this->postJson('/api/debts/query', ['placa' => 'ABC1234']);

    $response->assertOk();

    $expected = json_decode((string) file_get_contents(__DIR__.'/fixtures/expected-ABC1234.json'), true);

    expect($response->json())->toEqual($expected);
});

it('forwards lowercase plates to the provider in uppercase', function (): void {
    $fake = FakeDebtProvider::withDebts([]);
    $this->app->instance(DebtProvider::class, $fake);

    $this->postJson('/api/debts/query', ['placa' => 'abc1d23'])->assertOk();

    expect($fake->callCount)->toBe(1)
        ->and($fake->platesSeen[0]->toString())->toBe('ABC1D23');
});

it('empty debts list yields a well-formed envelope (status 200)', function (): void {
    $this->app->instance(DebtProvider::class, FakeDebtProvider::withDebts([]));

    $response = $this->postJson('/api/debts/query', ['placa' => 'ABC1234']);

    $response->assertOk();
    $response->assertExactJson([
        'placa' => 'ABC1234',
        'debitos' => [],
        'resumo' => [
            'total_original' => '0.00',
            'total_atualizado' => '0.00',
        ],
        'pagamentos' => [
            'opcoes' => [
                [
                    'tipo' => 'TOTAL',
                    'valor_base' => '0.00',
                    'pix' => ['total_com_desconto' => '0.00'],
                    'cartao_credito' => [
                        'parcelas' => [
                            ['quantidade' => 1,  'valor_parcela' => '0.00'],
                            ['quantidade' => 6,  'valor_parcela' => '0.00'],
                            ['quantidade' => 12, 'valor_parcela' => '0.00'],
                        ],
                    ],
                ],
            ],
        ],
    ]);
});
