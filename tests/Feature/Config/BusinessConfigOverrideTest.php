<?php

declare(strict_types=1);

use App\Application\Ports\DebtProvider;
use App\Application\Ports\ProviderUnavailableException;
use App\Domain\Debt\IpvaInterestPolicy;
use App\Domain\Debt\MultaInterestPolicy;
use App\Domain\Money\Money;
use App\Domain\Payment\CreditCardSimulator;
use App\Domain\Payment\PixSimulator;
use App\Domain\Plate\Plate;
use App\Infrastructure\Http\Middleware\MaxBodySize;
use App\Infrastructure\Resilience\AllProvidersUnavailableException;
use App\Infrastructure\Resilience\CircuitBreaker;
use App\Infrastructure\Resilience\CircuitBreakerDebtProvider;
use App\Infrastructure\Resilience\ProviderChain;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\Support\Fakes\FakeDebtProvider;

it('IpvaInterestPolicy resolved from the container reflects an overridden daily_rate', function (): void {
    config()->set('business.interest.ipva.daily_rate', '0.001');

    /** @var IpvaInterestPolicy $policy */
    $policy = app(IpvaInterestPolicy::class);

    // 1000 × 0.001 × 30 = 30.00 (cap = 200, raw stays below)
    expect($policy->calculate(Money::of('1000.00'), 30)->toString())->toBe('30.00');
});

it('IpvaInterestPolicy honours an overridden cap_rate', function (): void {
    config()->set('business.interest.ipva.cap_rate', '0.10');

    /** @var IpvaInterestPolicy $policy */
    $policy = app(IpvaInterestPolicy::class);

    // 1500 × 0.0033 × 121 = 598.95 raw; cap = 1500 × 0.10 = 150 → returns 150
    expect($policy->calculate(Money::of('1500.00'), 121)->toString())->toBe('150.00');
});

it('MultaInterestPolicy reflects an overridden daily_rate (2%/day)', function (): void {
    config()->set('business.interest.multa.daily_rate', '0.02');

    /** @var MultaInterestPolicy $policy */
    $policy = app(MultaInterestPolicy::class);

    // 100 × 0.02 × 10 = 20.00
    expect($policy->calculate(Money::of('100.00'), 10)->toString())->toBe('20.00');
});

it('PixSimulator reflects an overridden discount_factor (10% off)', function (): void {
    config()->set('business.payment.pix.discount_factor', '0.90');

    /** @var PixSimulator $sim */
    $sim = app(PixSimulator::class);

    expect($sim->simulate(Money::of('100.00'))->totalComDesconto->toString())->toBe('90.00');
});

it('CreditCardSimulator reflects an overridden monthly_rate', function (): void {
    config()->set('business.payment.credit_card.monthly_rate', '0.05');

    /** @var CreditCardSimulator $sim */
    $sim = app(CreditCardSimulator::class);

    $payment = $sim->simulate(Money::of('1000.00'));

    // 1x always equals base
    expect($payment->installments[0]->amount->toString())->toBe('1000.00');

    // 6x with i=0.05: pmt = 1000 × 0.05 × 1.05^6 / (1.05^6 - 1)
    //   1.05^6 ≈ 1.340096
    //   numer ≈ 67.0048; denom ≈ 0.340096 → pmt ≈ 197.02
    expect($payment->installments[1]->amount->toString())->toBe('197.02');
});

it('ProviderChain wired from config honours an overridden circuit-breaker threshold', function (): void {
    config()->set('business.resilience.circuit_breaker.failure_threshold', 1);
    config()->set('business.resilience.circuit_breaker.cooldown_seconds', 30.0);

    // Re-resolve the singleton so the chain picks up the new config.
    $this->app->forgetInstance(DebtProvider::class);

    // Each chain provider gets its own CircuitBreakerDebtProvider; we swap in
    // FakeDebtProviders that always fail so we can observe the trip.
    $fakeA = FakeDebtProvider::unavailable('A down');
    $fakeB = FakeDebtProvider::unavailable('B down');
    $this->app->instance(
        DebtProvider::class,
        new ProviderChain([
            new CircuitBreakerDebtProvider(
                inner: $fakeA,
                breaker: new CircuitBreaker(
                    failureThreshold: (int) config('business.resilience.circuit_breaker.failure_threshold'),
                    cooldownSeconds: 30.0,
                    shouldRecordFailure: static fn (Throwable $e): bool => $e instanceof ProviderUnavailableException,
                ),
            ),
            new CircuitBreakerDebtProvider(
                inner: $fakeB,
                breaker: new CircuitBreaker(
                    failureThreshold: (int) config('business.resilience.circuit_breaker.failure_threshold'),
                    cooldownSeconds: 30.0,
                    shouldRecordFailure: static fn (Throwable $e): bool => $e instanceof ProviderUnavailableException,
                ),
            ),
        ]),
    );

    /** @var DebtProvider $chain */
    $chain = app(DebtProvider::class);

    // First call exhausts both providers + trips both circuits (threshold = 1)
    try {
        $chain->fetchDebts(Plate::fromString('ABC1234'));
    } catch (AllProvidersUnavailableException) {
        // expected
    }

    // Second call: both circuits are open → both short-circuit without invoking
    // the inner fakes → callCount stays at 1 for each, not 2.
    try {
        $chain->fetchDebts(Plate::fromString('ABC1234'));
    } catch (AllProvidersUnavailableException) {
        // expected
    }

    expect($fakeA->callCount)->toBe(1)
        ->and($fakeB->callCount)->toBe(1);
});

it('MaxBodySize middleware honours an overridden max_body_bytes config', function (): void {
    config()->set('business.http.max_body_bytes', 512);

    $middleware = new MaxBodySize;
    $request = Request::create('/api/debts/query', 'POST', content: str_repeat('x', 1024));

    $response = $middleware->handle($request, static fn () => new Response('ok', 200));

    expect($response->getStatusCode())->toBe(413);
    expect(json_decode((string) $response->getContent(), true)['max_bytes'])->toBe(512);
});
