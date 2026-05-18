<?php

declare(strict_types=1);

use App\Domain\Debt\DebtType;
use App\Domain\Debt\UnknownDebtTypeException;

it('maps the IPVA string to the IPVA case', function (): void {
    expect(DebtType::fromString('IPVA'))->toBe(DebtType::IPVA);
});

it('maps the MULTA string to the MULTA case', function (): void {
    expect(DebtType::fromString('MULTA'))->toBe(DebtType::MULTA);
});

it('throws UnknownDebtTypeException for an unmapped type', function (): void {
    DebtType::fromString('LICENCIAMENTO');
})->throws(UnknownDebtTypeException::class, 'Unknown debt type: LICENCIAMENTO');

it('carries the offending type on the exception so callers can log it', function (): void {
    try {
        DebtType::fromString('LICENCIAMENTO');
        $this->fail('Expected UnknownDebtTypeException was not thrown');
    } catch (UnknownDebtTypeException $exception) {
        expect($exception->type)->toBe('LICENCIAMENTO');
    }
});

it('is case-sensitive — lowercase does not match', function (): void {
    DebtType::fromString('ipva');
})->throws(UnknownDebtTypeException::class);

it('rejects empty string', function (): void {
    DebtType::fromString('');
})->throws(UnknownDebtTypeException::class);
