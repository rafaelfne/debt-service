<?php

declare(strict_types=1);

use App\Domain\Debt\DebtType;
use App\Domain\Debt\UpdatedDebt;
use App\Domain\Money\Money;
use App\Domain\Payment\CreditCardSimulator;
use App\Domain\Payment\PaymentSimulator;
use App\Domain\Payment\PixSimulator;

beforeEach(function (): void {
    $this->simulator = new PaymentSimulator(
        new PixSimulator,
        new CreditCardSimulator,
    );
});

function makeUpdatedDebt(DebtType $type, string $original, string $updated): UpdatedDebt
{
    return new UpdatedDebt(
        type: $type,
        dueDate: new DateTimeImmutable('2024-01-01T00:00:00Z', new DateTimeZone('UTC')),
        originalAmount: Money::of($original),
        updatedAmount: Money::of($updated),
        daysOverdue: 121,
    );
}

it('canon ENUNCIADO: [IPVA 1800.00, MULTA 555.93] → [TOTAL 2355.93, SOMENTE_IPVA 1800.00, SOMENTE_MULTA 555.93]', function (): void {
    $debts = [
        makeUpdatedDebt(DebtType::IPVA, '1500.00', '1800.00'),
        makeUpdatedDebt(DebtType::MULTA, '300.50', '555.93'),
    ];

    $options = $this->simulator->simulate($debts);

    expect($options)->toHaveCount(3);
    expect($options[0]->type)->toBe('TOTAL');
    expect($options[0]->baseAmount->toString())->toBe('2355.93');
    expect($options[1]->type)->toBe('SOMENTE_IPVA');
    expect($options[1]->baseAmount->toString())->toBe('1800.00');
    expect($options[2]->type)->toBe('SOMENTE_MULTA');
    expect($options[2]->baseAmount->toString())->toBe('555.93');
});

it('canon DOIS DO MESMO TIPO: [IPVA 1000, IPVA 500] → [TOTAL 1500, SOMENTE_IPVA 1500] (singular, sem duplicar)', function (): void {
    $debts = [
        makeUpdatedDebt(DebtType::IPVA, '1000.00', '1000.00'),
        makeUpdatedDebt(DebtType::IPVA, '500.00', '500.00'),
    ];

    $options = $this->simulator->simulate($debts);

    expect($options)->toHaveCount(2);
    expect($options[0]->type)->toBe('TOTAL');
    expect($options[0]->baseAmount->toString())->toBe('1500.00');
    expect($options[1]->type)->toBe('SOMENTE_IPVA');
    expect($options[1]->baseAmount->toString())->toBe('1500.00');
});

it('preserves first-seen order of distinct types in SOMENTE_ options', function (): void {
    $debts = [
        makeUpdatedDebt(DebtType::MULTA, '100.00', '111.00'),
        makeUpdatedDebt(DebtType::IPVA, '200.00', '240.00'),
    ];

    $options = $this->simulator->simulate($debts);

    // MULTA appears first in the list, so SOMENTE_MULTA comes before SOMENTE_IPVA.
    expect($options[0]->type)->toBe('TOTAL');
    expect($options[1]->type)->toBe('SOMENTE_MULTA');
    expect($options[2]->type)->toBe('SOMENTE_IPVA');
});

it('empty debts list yields a single TOTAL=0.00 option', function (): void {
    $options = $this->simulator->simulate([]);

    expect($options)->toHaveCount(1);
    expect($options[0]->type)->toBe('TOTAL');
    expect($options[0]->baseAmount->toString())->toBe('0.00');
});

it('builds each option with both Pix and Cartão simulations applied to the same base', function (): void {
    $debts = [makeUpdatedDebt(DebtType::IPVA, '2355.93', '2355.93')];

    $options = $this->simulator->simulate($debts);

    $total = $options[0];
    expect($total->pix->totalComDesconto->toString())->toBe('2238.13')
        ->and($total->creditCard->installments[0]->amount->toString())->toBe('2355.93')
        ->and($total->creditCard->installments[1]->amount->toString())->toBe('427.72')
        ->and($total->creditCard->installments[2]->amount->toString())->toBe('229.67');
});

it('SOMENTE_ naming is literal-singular (asserted strictly to defend against accidental plural)', function (): void {
    $debts = [
        makeUpdatedDebt(DebtType::IPVA, '1000.00', '1000.00'),
        makeUpdatedDebt(DebtType::MULTA, '500.00', '500.00'),
    ];

    $types = array_map(fn ($opt) => $opt->type, $this->simulator->simulate($debts));

    expect($types)->toBe(['TOTAL', 'SOMENTE_IPVA', 'SOMENTE_MULTA'])
        ->not->toContain('SOMENTE_IPVAS')
        ->not->toContain('SOMENTE_MULTAS');
});
