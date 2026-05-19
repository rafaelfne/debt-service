<?php

declare(strict_types=1);

use App\Infrastructure\Http\Rules\PlateRule;

function runPlateRule(mixed $value): array
{
    $failures = [];
    $fail = function (string $message) use (&$failures): object {
        $failures[] = $message;

        // The rule chains ->translate(); we don't care about translation in unit tests,
        // so return an anonymous stub that no-ops.
        return new class
        {
            public function translate(): void {}
        };
    };

    (new PlateRule)->validate('placa', $value, $fail);

    return $failures;
}

it('accepts the old Brazilian format (ABC1234)', function (): void {
    expect(runPlateRule('ABC1234'))->toBe([]);
});

it('accepts the Mercosul format (ABC1D23)', function (): void {
    expect(runPlateRule('ABC1D23'))->toBe([]);
});

it('accepts lowercase plates (normalised by Plate VO)', function (): void {
    expect(runPlateRule('abc1234'))->toBe([]);
});

it('rejects malformed plates with the canonical error message', function (): void {
    $failures = runPlateRule('INVALID');

    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toContain('valid Brazilian plate');
});

it('rejects short input', function (): void {
    expect(runPlateRule('123'))->not->toBe([]);
});

it('rejects null with a type-specific message', function (): void {
    $failures = runPlateRule(null);

    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toContain('must be a string');
});

it('rejects non-string types (int)', function (): void {
    expect(runPlateRule(1234567))->not->toBe([]);
});
