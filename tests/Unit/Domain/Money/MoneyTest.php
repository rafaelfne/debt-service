<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;

it('builds from a decimal string and renders 2-scale HALF_UP', function (): void {
    expect(Money::of('1500.00')->toString())->toBe('1500.00');
});

it('rounds the third decimal place HALF_UP at toString', function (): void {
    expect(Money::of('255.425')->toString())->toBe('255.43');
});

it('keeps full precision internally and rounds only on output', function (): void {
    expect(
        Money::of('1500.00')->multiply('0.20')->equals(Money::of('300.00'))
    )->toBeTrue();
});

it('zero() returns 0.00', function (): void {
    expect(Money::zero()->toString())->toBe('0.00')
        ->and(Money::zero()->equals(Money::of('0')))->toBeTrue();
});

it('adds two money values', function (): void {
    expect(Money::of('100.10')->add(Money::of('200.20'))->toString())->toBe('300.30');
});

it('subtracts two money values', function (): void {
    expect(Money::of('500.00')->subtract(Money::of('199.99'))->toString())->toBe('300.01');
});

it('multiplies by a string factor', function (): void {
    expect(Money::of('1000.00')->multiply('0.075')->toString())->toBe('75.00');
});

it('multiplies by a BigDecimal factor', function (): void {
    expect(Money::of('1000.00')->multiply(BigDecimal::of('1.5'))->toString())->toBe('1500.00');
});

it('divides by a string divisor with HALF_UP rounding', function (): void {
    // 100 / 3 = 33.333… → toScale(2) HALF_UP = 33.33
    expect(Money::of('100.00')->divide('3')->toString())->toBe('33.33');
});

it('divides by a BigDecimal divisor', function (): void {
    expect(Money::of('100.00')->divide(BigDecimal::of('4'))->toString())->toBe('25.00');
});

it('equals returns false for different values', function (): void {
    expect(Money::of('10.00')->equals(Money::of('10.01')))->toBeFalse();
});

it('greaterThan returns true when strictly greater', function (): void {
    expect(Money::of('10.01')->greaterThan(Money::of('10.00')))->toBeTrue()
        ->and(Money::of('10.00')->greaterThan(Money::of('10.00')))->toBeFalse();
});

it('lessThanOrEqual returns true for less and equal', function (): void {
    expect(Money::of('9.99')->lessThanOrEqual(Money::of('10.00')))->toBeTrue()
        ->and(Money::of('10.00')->lessThanOrEqual(Money::of('10.00')))->toBeTrue()
        ->and(Money::of('10.01')->lessThanOrEqual(Money::of('10.00')))->toBeFalse();
});

it('min picks the smaller of two', function (): void {
    $a = Money::of('5.00');
    $b = Money::of('7.50');

    expect(Money::min($a, $b)->equals($a))->toBeTrue()
        ->and(Money::min($b, $a)->equals($a))->toBeTrue();
});

it('min returns either when they are equal', function (): void {
    $a = Money::of('1.00');
    $b = Money::of('1.00');

    expect(Money::min($a, $b)->equals($a))->toBeTrue();
});

it('serializes to JSON as the formatted string', function (): void {
    $money = Money::of('1234.567');

    expect(json_encode(['valor' => $money]))->toBe('{"valor":"1234.57"}')
        ->and($money->jsonSerialize())->toBe('1234.57');
});

it('rejects float construction', function (): void {
    Money::of(1500.00);
})->throws(InvalidArgumentException::class);

it('rejects int construction', function (): void {
    Money::of(1500);
})->throws(InvalidArgumentException::class);

it('rejects malformed numeric string', function (): void {
    Money::of('not-a-number');
})->throws(NumberFormatException::class);
