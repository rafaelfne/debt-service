<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Domain\Payment\CreditCardSimulator;
use App\Domain\Payment\Installment;

beforeEach(function (): void {
    $this->card = new CreditCardSimulator;
});

function pmtValues(CreditCardSimulator $card, string $base): array
{
    $payment = $card->simulate(Money::of($base));

    return array_map(
        fn (Installment $i) => [$i->count, $i->amount->toString()],
        $payment->installments,
    );
}

it('canon 2355.93 → 1x 2355.93, 6x 427.72, 12x 229.67', function (): void {
    expect(pmtValues($this->card, '2355.93'))->toBe([
        [1, '2355.93'],
        [6, '427.72'],
        [12, '229.67'],
    ]);
});

it('canon 1800.00 → 1x 1800.00, 6x 326.79, 12x 175.48', function (): void {
    expect(pmtValues($this->card, '1800.00'))->toBe([
        [1, '1800.00'],
        [6, '326.79'],
        [12, '175.48'],
    ]);
});

it('canon 555.93 → 1x 555.93, 6x 100.93, 12x 54.20', function (): void {
    expect(pmtValues($this->card, '555.93'))->toBe([
        [1, '555.93'],
        [6, '100.93'],
        [12, '54.20'],
    ]);
});

it('returns three installments in the fixed 1/6/12 order', function (): void {
    $payment = $this->card->simulate(Money::of('1000.00'));

    expect($payment->installments)->toHaveCount(3)
        ->and(array_column(
            array_map(fn (Installment $i) => ['count' => $i->count], $payment->installments),
            'count',
        ))->toBe([1, 6, 12]);
});

it('1x equals base without rounding artefacts (no PMT applied)', function (): void {
    expect($this->card->simulate(Money::of('12345.67'))->installments[0]->amount->equals(Money::of('12345.67')))
        ->toBeTrue();
});

it('returns Installments with zero amount when base is zero', function (): void {
    $payment = $this->card->simulate(Money::zero());

    foreach ($payment->installments as $installment) {
        expect($installment->amount->toString())->toBe('0.00');
    }
});
