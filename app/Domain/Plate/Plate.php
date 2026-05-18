<?php

declare(strict_types=1);

namespace App\Domain\Plate;

use Stringable;

final class Plate implements Stringable
{
    private const PATTERN = '/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/';

    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));

        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw new InvalidPlateException("Invalid plate format: {$value}");
        }

        return new self($normalized);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->masked();
    }

    public function masked(): string
    {
        return substr($this->value, 0, 3).'****';
    }
}
