<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use App\Domain\Debt\Debt;
use App\Domain\Debt\DebtType;
use App\Domain\Money\Money;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Canonical debts from the enunciado, anchored to the reference date 2024-05-10:
 *   - IPVA 1500.00 due 2024-01-10 (121 days overdue → updated 1800.00)
 *   - MULTA 300.50 due 2024-02-15 (85 days overdue → updated 555.93)
 */
final class DebtFixtures
{
    public const REFERENCE_DATE = '2024-05-10T00:00:00Z';

    public static function ipvaCanonical(): Debt
    {
        return new Debt(
            type: DebtType::IPVA,
            originalAmount: Money::of('1500.00'),
            dueDate: self::utc('2024-01-10T00:00:00Z'),
        );
    }

    public static function multaCanonical(): Debt
    {
        return new Debt(
            type: DebtType::MULTA,
            originalAmount: Money::of('300.50'),
            dueDate: self::utc('2024-02-15T00:00:00Z'),
        );
    }

    /**
     * @return list<Debt>
     */
    public static function combinedCanonical(): array
    {
        return [self::ipvaCanonical(), self::multaCanonical()];
    }

    public static function utc(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }
}
