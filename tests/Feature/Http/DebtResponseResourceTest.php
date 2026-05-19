<?php

declare(strict_types=1);

use App\Application\UseCases\QueryDebtsUseCase;
use App\Domain\Debt\DebtType;
use App\Domain\Debt\InterestCalculator;
use App\Domain\Debt\IpvaInterestPolicy;
use App\Domain\Debt\MultaInterestPolicy;
use App\Domain\Payment\CreditCardSimulator;
use App\Domain\Payment\PaymentSimulator;
use App\Domain\Payment\PixSimulator;
use App\Domain\Plate\Plate;
use App\Infrastructure\Http\Resources\DebtResponseResource;
use Tests\Support\Fakes\FakeDebtProvider;
use Tests\Support\Fixtures\DebtFixtures;

function makeCanonicalUseCase(): QueryDebtsUseCase
{
    return new QueryDebtsUseCase(
        provider: FakeDebtProvider::withDebts(DebtFixtures::combinedCanonical()),
        calculator: new InterestCalculator(
            referenceDate: DebtFixtures::utc(DebtFixtures::REFERENCE_DATE),
            policies: [
                DebtType::IPVA->value => new IpvaInterestPolicy,
                DebtType::MULTA->value => new MultaInterestPolicy,
            ],
        ),
        paymentSimulator: new PaymentSimulator(
            new PixSimulator,
            new CreditCardSimulator,
        ),
    );
}

it('serialises the enunciado canon BYTE-FOR-BYTE against the expected fixture', function (): void {
    $result = makeCanonicalUseCase()->execute(Plate::fromString('ABC1234'));

    $resource = new DebtResponseResource($result);
    $actual = $resource->toResponse(request())->getContent();
    $expected = (string) file_get_contents(__DIR__.'/fixtures/expected-ABC1234.json');

    // Normalise both via json_encode/decode so whitespace differences don't matter.
    expect(json_decode($actual, true))->toEqual(json_decode($expected, true));
});

it('emits every monetary field as a JSON string (never a number)', function (): void {
    $result = makeCanonicalUseCase()->execute(Plate::fromString('ABC1234'));
    $payload = json_decode((new DebtResponseResource($result))->toResponse(request())->getContent(), true);

    // Debit-level monetary fields
    foreach ($payload['debitos'] as $debt) {
        expect($debt['valor_original'])->toBeString();
        expect($debt['valor_atualizado'])->toBeString();
        expect($debt['dias_atraso'])->toBeInt();
    }

    // Summary
    expect($payload['resumo']['total_original'])->toBeString();
    expect($payload['resumo']['total_atualizado'])->toBeString();

    // Payment options
    foreach ($payload['pagamentos']['opcoes'] as $opcao) {
        expect($opcao['valor_base'])->toBeString();
        expect($opcao['pix']['total_com_desconto'])->toBeString();

        foreach ($opcao['cartao_credito']['parcelas'] as $parcela) {
            expect($parcela['quantidade'])->toBeInt();
            expect($parcela['valor_parcela'])->toBeString();
        }
    }
});

it('preserves the canonical order of debts and payment options', function (): void {
    $result = makeCanonicalUseCase()->execute(Plate::fromString('ABC1234'));
    $payload = json_decode((new DebtResponseResource($result))->toResponse(request())->getContent(), true);

    expect(array_column($payload['debitos'], 'tipo'))
        ->toBe(['IPVA', 'MULTA']);

    expect(array_column($payload['pagamentos']['opcoes'], 'tipo'))
        ->toBe(['TOTAL', 'SOMENTE_IPVA', 'SOMENTE_MULTA']);
});

it('handles an empty debts list: resumo zerado + single TOTAL=0.00 option', function (): void {
    $useCase = new QueryDebtsUseCase(
        provider: FakeDebtProvider::withDebts([]),
        calculator: new InterestCalculator(
            referenceDate: DebtFixtures::utc(DebtFixtures::REFERENCE_DATE),
            policies: [
                DebtType::IPVA->value => new IpvaInterestPolicy,
                DebtType::MULTA->value => new MultaInterestPolicy,
            ],
        ),
        paymentSimulator: new PaymentSimulator(new PixSimulator, new CreditCardSimulator),
    );

    $result = $useCase->execute(Plate::fromString('ABC1234'));
    $payload = json_decode((new DebtResponseResource($result))->toResponse(request())->getContent(), true);

    expect($payload['placa'])->toBe('ABC1234');
    expect($payload['debitos'])->toBe([]);
    expect($payload['resumo'])->toBe([
        'total_original' => '0.00',
        'total_atualizado' => '0.00',
    ]);
    expect($payload['pagamentos']['opcoes'])->toHaveCount(1);
    expect($payload['pagamentos']['opcoes'][0]['tipo'])->toBe('TOTAL');
    expect($payload['pagamentos']['opcoes'][0]['valor_base'])->toBe('0.00');
});
