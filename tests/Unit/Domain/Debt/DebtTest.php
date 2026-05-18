<?php

declare(strict_types=1);

use App\Domain\Debt\Debt;
use App\Domain\Debt\DebtType;
use App\Domain\Money\Money;

function utc(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date, new DateTimeZone('UTC'));
}

it('exposes type, originalAmount and dueDate as readonly properties', function (): void {
    $debt = new Debt(
        type: DebtType::IPVA,
        originalAmount: Money::of('1500.00'),
        dueDate: utc('2024-01-10T00:00:00Z'),
    );

    expect($debt->type)->toBe(DebtType::IPVA)
        ->and($debt->originalAmount->equals(Money::of('1500.00')))->toBeTrue()
        ->and($debt->dueDate->format('Y-m-d'))->toBe('2024-01-10');
});

it('returns 0 days overdue when the reference is before the due date', function (): void {
    $debt = new Debt(DebtType::IPVA, Money::of('100.00'), utc('2024-06-01T00:00:00Z'));

    expect($debt->daysOverdue(utc('2024-05-10T00:00:00Z')))->toBe(0);
});

it('returns 0 days overdue when the reference equals the due date', function (): void {
    $debt = new Debt(DebtType::IPVA, Money::of('100.00'), utc('2024-05-10T00:00:00Z'));

    expect($debt->daysOverdue(utc('2024-05-10T00:00:00Z')))->toBe(0);
});

it('returns the IPVA canon: 121 days overdue against 2024-05-10', function (): void {
    $debt = new Debt(DebtType::IPVA, Money::of('1500.00'), utc('2024-01-10T00:00:00Z'));

    expect($debt->daysOverdue(utc('2024-05-10T00:00:00Z')))->toBe(121);
});

it('returns the MULTA canon: 85 days overdue against 2024-05-10', function (): void {
    $debt = new Debt(DebtType::MULTA, Money::of('300.50'), utc('2024-02-15T00:00:00Z'));

    expect($debt->daysOverdue(utc('2024-05-10T00:00:00Z')))->toBe(85);
});

it('produces the same day count regardless of input timezone', function (): void {
    $debt = new Debt(
        DebtType::MULTA,
        Money::of('100.00'),
        new DateTimeImmutable('2024-01-01T22:00:00-03:00'),
    );
    $reference = new DateTimeImmutable('2024-01-11T22:00:00-03:00');

    expect($debt->daysOverdue($reference))->toBe(10);
});
