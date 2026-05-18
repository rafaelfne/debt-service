<?php

declare(strict_types=1);

namespace App\Domain\Money;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;

final class Money implements JsonSerializable
{
    private function __construct(private readonly BigDecimal $amount) {}

    /**
     * Build a Money from a decimal string (e.g. "1500.00", "0.33").
     *
     * The union accepts int/float at the type level so float arguments reach the body
     * instead of triggering a TypeError; they are then explicitly rejected to
     * preserve the "Money is built only from strings" invariant (CLAUDE.md §4 #1).
     */
    public static function of(string|int|float $value): self
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException(
                'Money must be constructed from a string to preserve decimal precision.'
            );
        }

        return new self(BigDecimal::of($value));
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero());
    }

    public function add(self $other): self
    {
        return new self($this->amount->plus($other->amount));
    }

    public function subtract(self $other): self
    {
        return new self($this->amount->minus($other->amount));
    }

    public function multiply(string|BigDecimal $factor): self
    {
        return new self($this->amount->multipliedBy($factor));
    }

    public function divide(string|BigDecimal $divisor): self
    {
        return new self(
            $this->amount->dividedBy($divisor, 20, RoundingMode::HALF_UP)
        );
    }

    public function equals(self $other): bool
    {
        return $this->amount->isEqualTo($other->amount);
    }

    public function greaterThan(self $other): bool
    {
        return $this->amount->isGreaterThan($other->amount);
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->amount->isLessThanOrEqualTo($other->amount);
    }

    public static function min(self $a, self $b): self
    {
        return $a->lessThanOrEqual($b) ? $a : $b;
    }

    public function toString(): string
    {
        return (string) $this->amount->toScale(2, RoundingMode::HALF_UP);
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }
}
