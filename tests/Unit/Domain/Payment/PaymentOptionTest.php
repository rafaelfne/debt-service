<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Domain\Payment\CreditCardSimulator;
use App\Domain\Payment\PaymentOption;
use App\Domain\Payment\PixSimulator;

function makeOption(string $type, string $base): PaymentOption
{
    $baseMoney = Money::of($base);

    return new PaymentOption(
        type: $type,
        baseAmount: $baseMoney,
        pix: (new PixSimulator)->simulate($baseMoney),
        creditCard: (new CreditCardSimulator)->simulate($baseMoney),
    );
}

it('exposes the four readonly properties', function (): void {
    $option = makeOption('TOTAL', '2355.93');

    expect($option->type)->toBe('TOTAL')
        ->and($option->baseAmount->toString())->toBe('2355.93');
});

it('serializes a TOTAL option matching the enunciado shape byte-for-byte', function (): void {
    $option = makeOption('TOTAL', '2355.93');

    $json = json_encode($option, JSON_THROW_ON_ERROR);

    expect($json)->toBe(
        '{"tipo":"TOTAL","valor_base":"2355.93",'
        .'"pix":{"total_com_desconto":"2238.13"},'
        .'"cartao_credito":{"parcelas":['
        .'{"quantidade":1,"valor_parcela":"2355.93"},'
        .'{"quantidade":6,"valor_parcela":"427.72"},'
        .'{"quantidade":12,"valor_parcela":"229.67"}'
        .']}}'
    );
});

it('serializes SOMENTE_IPVA with the same shape', function (): void {
    $option = makeOption('SOMENTE_IPVA', '1800.00');

    $json = json_encode($option, JSON_THROW_ON_ERROR);

    expect($json)->toBe(
        '{"tipo":"SOMENTE_IPVA","valor_base":"1800.00",'
        .'"pix":{"total_com_desconto":"1710.00"},'
        .'"cartao_credito":{"parcelas":['
        .'{"quantidade":1,"valor_parcela":"1800.00"},'
        .'{"quantidade":6,"valor_parcela":"326.79"},'
        .'{"quantidade":12,"valor_parcela":"175.48"}'
        .']}}'
    );
});

it('serializes SOMENTE_MULTA with the same shape', function (): void {
    $option = makeOption('SOMENTE_MULTA', '555.93');

    $json = json_encode($option, JSON_THROW_ON_ERROR);

    expect($json)->toBe(
        '{"tipo":"SOMENTE_MULTA","valor_base":"555.93",'
        .'"pix":{"total_com_desconto":"528.13"},'
        .'"cartao_credito":{"parcelas":['
        .'{"quantidade":1,"valor_parcela":"555.93"},'
        .'{"quantidade":6,"valor_parcela":"100.93"},'
        .'{"quantidade":12,"valor_parcela":"54.20"}'
        .']}}'
    );
});

it('emits monetary values as JSON strings (not numbers)', function (): void {
    $option = makeOption('TOTAL', '1000.00');

    $decoded = json_decode(json_encode($option), associative: true);

    expect($decoded['valor_base'])->toBeString()
        ->and($decoded['pix']['total_com_desconto'])->toBeString()
        ->and($decoded['cartao_credito']['parcelas'][0]['valor_parcela'])->toBeString();
});

it('emits installment count as an integer (not a string)', function (): void {
    $option = makeOption('TOTAL', '1000.00');

    $decoded = json_decode(json_encode($option), associative: true);

    expect($decoded['cartao_credito']['parcelas'][0]['quantidade'])->toBeInt()->toBe(1);
});
