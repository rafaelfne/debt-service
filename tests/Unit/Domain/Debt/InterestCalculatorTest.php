<?php

declare(strict_types=1);

use App\Domain\Debt\Debt;
use App\Domain\Debt\DebtType;
use App\Domain\Debt\InterestCalculator;
use App\Domain\Debt\IpvaInterestPolicy;
use App\Domain\Debt\MultaInterestPolicy;
use App\Domain\Debt\UnknownDebtTypeException;
use App\Domain\Debt\UpdatedDebt;
use App\Domain\Money\Money;

const CANON_REFERENCE = '2024-05-10T00:00:00Z';

function calculatorWithFullPolicies(): InterestCalculator
{
    return new InterestCalculator(
        referenceDate: new DateTimeImmutable(CANON_REFERENCE, new DateTimeZone('UTC')),
        policies: [
            DebtType::IPVA->value => new IpvaInterestPolicy,
            DebtType::MULTA->value => new MultaInterestPolicy,
        ],
    );
}

it('returns an UpdatedDebt VO with the right shape', function (): void {
    $debt = new Debt(
        DebtType::IPVA,
        Money::of('1500.00'),
        new DateTimeImmutable('2024-01-10T00:00:00Z', new DateTimeZone('UTC')),
    );

    $result = calculatorWithFullPolicies()->calculate($debt);

    expect($result)->toBeInstanceOf(UpdatedDebt::class)
        ->and($result->type)->toBe(DebtType::IPVA)
        ->and($result->daysOverdue)->toBe(121)
        ->and($result->originalAmount->toString())->toBe('1500.00');
});

it('canon IPVA: 1500.00 due 2024-01-10, ref 2024-05-10 → updated 1800.00', function (): void {
    $debt = new Debt(
        DebtType::IPVA,
        Money::of('1500.00'),
        new DateTimeImmutable('2024-01-10T00:00:00Z', new DateTimeZone('UTC')),
    );

    $result = calculatorWithFullPolicies()->calculate($debt);

    expect($result->daysOverdue)->toBe(121)
        ->and($result->updatedAmount->toString())->toBe('1800.00');
});

it('canon MULTA: 300.50 due 2024-02-15, ref 2024-05-10 → updated 555.93', function (): void {
    $debt = new Debt(
        DebtType::MULTA,
        Money::of('300.50'),
        new DateTimeImmutable('2024-02-15T00:00:00Z', new DateTimeZone('UTC')),
    );

    $result = calculatorWithFullPolicies()->calculate($debt);

    expect($result->daysOverdue)->toBe(85)
        ->and($result->updatedAmount->toString())->toBe('555.93');
});

it('canon COMBINED: IPVA + MULTA → 1800.00 + 555.93 = 2355.93', function (): void {
    $calculator = calculatorWithFullPolicies();
    $debts = [
        new Debt(
            DebtType::IPVA,
            Money::of('1500.00'),
            new DateTimeImmutable('2024-01-10T00:00:00Z', new DateTimeZone('UTC')),
        ),
        new Debt(
            DebtType::MULTA,
            Money::of('300.50'),
            new DateTimeImmutable('2024-02-15T00:00:00Z', new DateTimeZone('UTC')),
        ),
    ];

    $updated = array_map(fn (Debt $d) => $calculator->calculate($d), $debts);
    $total = array_reduce(
        $updated,
        fn (Money $sum, UpdatedDebt $u) => $sum->add($u->updatedAmount),
        Money::zero(),
    );

    expect($updated[0]->updatedAmount->toString())->toBe('1800.00')
        ->and($updated[1]->updatedAmount->toString())->toBe('555.93')
        ->and($total->toString())->toBe('2355.93');
});

it('returns daysOverdue=0 and updated==original when reference is before due', function (): void {
    $debt = new Debt(
        DebtType::IPVA,
        Money::of('1000.00'),
        new DateTimeImmutable('2024-06-01T00:00:00Z', new DateTimeZone('UTC')),
    );

    $result = calculatorWithFullPolicies()->calculate($debt);

    expect($result->daysOverdue)->toBe(0)
        ->and($result->updatedAmount->equals(Money::of('1000.00')))->toBeTrue();
});

it('throws UnknownDebtTypeException defensively when a type has no policy', function (): void {
    $calculator = new InterestCalculator(
        referenceDate: new DateTimeImmutable(CANON_REFERENCE, new DateTimeZone('UTC')),
        policies: [
            // MULTA registered, IPVA missing on purpose
            DebtType::MULTA->value => new MultaInterestPolicy,
        ],
    );

    $debt = new Debt(
        DebtType::IPVA,
        Money::of('1000.00'),
        new DateTimeImmutable('2024-01-01T00:00:00Z', new DateTimeZone('UTC')),
    );

    $calculator->calculate($debt);
})->throws(UnknownDebtTypeException::class, 'Unknown debt type: IPVA');
