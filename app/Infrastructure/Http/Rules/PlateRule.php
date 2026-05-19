<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Rules;

use App\Domain\Plate\InvalidPlateException;
use App\Domain\Plate\Plate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Delegates plate validation to the domain VO so the rule never duplicates the
 * regex. If `Plate::fromString` accepts the value, it is valid; otherwise the
 * exception message becomes the validation failure.
 */
final class PlateRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.')->translate();

            return;
        }

        try {
            Plate::fromString($value);
        } catch (InvalidPlateException) {
            $fail('The :attribute must be a valid Brazilian plate (ABC1234 or ABC1D23).')->translate();
        }
    }
}
