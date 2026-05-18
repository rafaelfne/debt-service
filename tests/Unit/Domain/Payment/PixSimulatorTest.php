<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Domain\Payment\PixSimulator;

beforeEach(function (): void {
    $this->pix = new PixSimulator;
});

it('canon: 2355.93 → 2238.13 (5% discount)', function (): void {
    expect($this->pix->simulate(Money::of('2355.93'))->totalComDesconto->toString())
        ->toBe('2238.13');
});

it('canon: 1800.00 → 1710.00', function (): void {
    expect($this->pix->simulate(Money::of('1800.00'))->totalComDesconto->toString())
        ->toBe('1710.00');
});

it('canon: 555.93 → 528.13', function (): void {
    expect($this->pix->simulate(Money::of('555.93'))->totalComDesconto->toString())
        ->toBe('528.13');
});

it('returns zero when base is zero', function (): void {
    expect($this->pix->simulate(Money::zero())->totalComDesconto->toString())->toBe('0.00');
});

it('rounds HALF_UP on the discounted output', function (): void {
    // 100.05 × 0.95 = 95.0475 → HALF_UP at 2 places = 95.05
    expect($this->pix->simulate(Money::of('100.05'))->totalComDesconto->toString())->toBe('95.05');
});
