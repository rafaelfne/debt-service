<?php

declare(strict_types=1);

use App\Domain\Debt\IpvaInterestPolicy;
use App\Domain\Money\Money;

beforeEach(function (): void {
    $this->policy = new IpvaInterestPolicy;
});

it('returns the 20% cap when raw interest exceeds it (canon: 1500.33 × 121d → 300.07)', function (): void {
    // raw = 1500.33 × 0.0033 × 121 = 599.08… ; cap = 1500.33 × 0.20 = 300.066 → toString 300.07.
    $interest = $this->policy->calculate(Money::of('1500.33'), 121);

    expect($interest->toString())->toBe('300.07')
        ->and($interest->equals(Money::of('300.066')))->toBeTrue();
});

it('returns zero when daysOverdue is 0 (canon: 1500.33 × 0d → 0.00)', function (): void {
    expect($this->policy->calculate(Money::of('1500.33'), 0)->toString())->toBe('0.00');
});

it('returns the raw interest below the cap (canon: 1500.33 × 30d → 148.53)', function (): void {
    // raw = 1500.33 × 0.0033 × 30 = 148.53267 → toString HALF_UP 148.53; cap = 300.066, raw < cap.
    expect($this->policy->calculate(Money::of('1500.33'), 30)->toString())->toBe('148.53');
});

it('returns zero for negative daysOverdue', function (): void {
    expect($this->policy->calculate(Money::of('1500.33'), -5)->toString())->toBe('0.00');
});

it('exact boundary: 60 days × 0.33% = 19.8% — still raw, below the 20% cap', function (): void {
    // 1000 × 0.0033 × 60 = 198.00; cap = 200.00 → returns 198.00
    $interest = $this->policy->calculate(Money::of('1000.00'), 60);

    expect($interest->toString())->toBe('198.00');
});

it('exact boundary: ~61 days × 0.33% = 20.13% — cap kicks in', function (): void {
    // 1000 × 0.0033 × 61 = 201.30; cap = 200.00 → returns 200.00
    $interest = $this->policy->calculate(Money::of('1000.00'), 61);

    expect($interest->toString())->toBe('200.00');
});

it('does NOT apply the cap to the updated total, only to the interest', function (): void {
    // Regression fence: a buggy implementation that returns min(raw, original × 1.20)
    // would yield 1800.40 here, not 300.07.
    $original = Money::of('1500.33');
    $interest = $this->policy->calculate($original, 121);

    expect($interest->toString())->toBe('300.07')
        ->and($interest->lessThanOrEqual($original))->toBeTrue();
});
