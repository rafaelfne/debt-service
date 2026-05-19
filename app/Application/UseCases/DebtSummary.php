<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Domain\Money\Money;

final class DebtSummary
{
    public function __construct(
        public readonly Money $totalOriginal,
        public readonly Money $totalUpdated,
    ) {}
}
