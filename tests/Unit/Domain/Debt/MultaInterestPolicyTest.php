<?php

declare(strict_types=1);

use App\Domain\Debt\MultaInterestPolicy;
use App\Domain\Money\Money;

beforeEach(function (): void {
    $this->policy = new MultaInterestPolicy;
});

it('returns the canon: 300.50 × 85d → 255.43 (HALF_UP from 255.425)', function (): void {
    $interest = $this->policy->calculate(Money::of('300.50'), 85);

    expect($interest->toString())->toBe('255.43');
});

it('preserves precision internally so the sum with original yields 555.93', function (): void {
    $original = Money::of('300.50');
    $interest = $this->policy->calculate($original, 85);
    $updated = $original->add($interest);

    // raw: 300.50 + 255.425 = 555.925 → toString HALF_UP = 555.93
    expect($updated->toString())->toBe('555.93');
});

it('returns zero when daysOverdue is 0', function (): void {
    expect($this->policy->calculate(Money::of('300.50'), 0)->toString())->toBe('0.00');
});

it('returns zero for negative daysOverdue', function (): void {
    expect($this->policy->calculate(Money::of('300.50'), -1)->toString())->toBe('0.00');
});

it('grows linearly without cap (1000 × 1% × 100d = 1000)', function (): void {
    expect($this->policy->calculate(Money::of('1000.00'), 100)->toString())->toBe('1000.00');
});

it('keeps growing past the value-of-original boundary (no cap)', function (): void {
    expect($this->policy->calculate(Money::of('1000.00'), 250)->toString())->toBe('2500.00');
});
