<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

it('preserves decimal precision when adding 0.1 + 0.2', function (): void {
    $result = BigDecimal::of('0.1')->plus('0.2');

    expect((string) $result)->toBe('0.3');
});

it('rounds HALF_UP to two scales on demand', function (): void {
    $result = BigDecimal::of('1234.567')->toScale(2, RoundingMode::HALF_UP);

    expect((string) $result)->toBe('1234.57');
});
