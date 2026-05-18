<?php

declare(strict_types=1);

use App\Domain\Plate\InvalidPlateException;
use App\Domain\Plate\Plate;

it('accepts the old Brazilian format (ABC1234)', function (): void {
    expect(Plate::fromString('ABC1234')->toString())->toBe('ABC1234');
});

it('accepts the Mercosul format (ABC1D23)', function (): void {
    expect(Plate::fromString('ABC1D23')->toString())->toBe('ABC1D23');
});

it('is case-insensitive and normalises to uppercase', function (): void {
    expect(Plate::fromString('abc1234')->toString())->toBe('ABC1234')
        ->and(Plate::fromString('abc1d23')->toString())->toBe('ABC1D23');
});

it('trims surrounding whitespace before validating', function (): void {
    expect(Plate::fromString('  abc1234  ')->toString())->toBe('ABC1234');
});

it('throws InvalidPlateException for invalid format', function (): void {
    Plate::fromString('INVALID');
})->throws(InvalidPlateException::class);

it('throws InvalidPlateException for empty string', function (): void {
    Plate::fromString('');
})->throws(InvalidPlateException::class);

it('throws InvalidPlateException for plate with special characters', function (): void {
    Plate::fromString('ABC-1234');
})->throws(InvalidPlateException::class);

it('throws InvalidPlateException for plate too short', function (): void {
    Plate::fromString('ABC123');
})->throws(InvalidPlateException::class);

it('throws InvalidPlateException for plate too long', function (): void {
    Plate::fromString('ABC12345');
})->throws(InvalidPlateException::class);

it('throws InvalidPlateException when the 4th char is a letter (old-format rule)', function (): void {
    Plate::fromString('ABCD234');
})->throws(InvalidPlateException::class);

it('masks for LGPD logs as the first 3 chars + ****', function (): void {
    expect(Plate::fromString('ABC1234')->masked())->toBe('ABC****')
        ->and(Plate::fromString('xyz9z88')->masked())->toBe('XYZ****');
});

it('__toString returns the masked plate so log interpolation never leaks', function (): void {
    $plate = Plate::fromString('ABC1234');

    expect((string) $plate)->toBe('ABC****')
        ->and("placa={$plate}")->toBe('placa=ABC****');
});
