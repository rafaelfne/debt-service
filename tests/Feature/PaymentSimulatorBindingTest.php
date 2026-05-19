<?php

declare(strict_types=1);

use App\Domain\Payment\CreditCardSimulator;
use App\Domain\Payment\PaymentSimulator;
use App\Domain\Payment\PixSimulator;

it('resolves PaymentSimulator from the container with both simulators wired', function (): void {
    $simulator = app(PaymentSimulator::class);

    // Smoke: passes the empty-list path through the full graph without errors.
    expect($simulator->simulate([])[0]->baseAmount->toString())->toBe('0.00');
});

it('binds PixSimulator and CreditCardSimulator as singletons', function (): void {
    expect(app(PixSimulator::class))->toBe(app(PixSimulator::class))
        ->and(app(CreditCardSimulator::class))->toBe(app(CreditCardSimulator::class))
        ->and(app(PaymentSimulator::class))->toBe(app(PaymentSimulator::class));
});
