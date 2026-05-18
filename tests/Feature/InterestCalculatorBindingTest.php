<?php

declare(strict_types=1);

use App\Domain\Debt\Debt;
use App\Domain\Debt\DebtType;
use App\Domain\Debt\InterestCalculator;
use App\Domain\Money\Money;

it('resolves InterestCalculator from the container with IPVA + MULTA policies wired', function (): void {
    $calculator = app(InterestCalculator::class);

    // Both registered types resolve cleanly through the container binding
    foreach ([DebtType::IPVA, DebtType::MULTA] as $type) {
        $result = $calculator->calculate(
            new Debt($type, Money::of('100.00'), new DateTimeImmutable('1990-01-01T00:00:00Z', new DateTimeZone('UTC'))),
        );

        expect($result->type)->toBe($type);
    }
});

it('binds fresh InterestCalculator instances per resolution (referenceDate is now-per-resolve)', function (): void {
    $first = app(InterestCalculator::class);
    $second = app(InterestCalculator::class);

    // Default Laravel bind() yields a new instance every make() call — the
    // calculator captures "now in UTC" at construction, so per-request resolution
    // gives a fresh time.
    expect($first)->not->toBe($second);
});
