<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Ports\DebtProvider;
use App\Application\Ports\ProviderUnavailableException;
use App\Domain\Debt\DebtType;
use App\Domain\Debt\InterestCalculator;
use App\Domain\Debt\IpvaInterestPolicy;
use App\Domain\Debt\MultaInterestPolicy;
use App\Domain\Payment\CreditCardSimulator;
use App\Domain\Payment\PaymentSimulator;
use App\Domain\Payment\PixSimulator;
use App\Infrastructure\Providers\ProviderAJsonAdapter;
use App\Infrastructure\Providers\ProviderBXmlAdapter;
use App\Infrastructure\Resilience\CircuitBreaker;
use App\Infrastructure\Resilience\CircuitBreakerDebtProvider;
use App\Infrastructure\Resilience\ProviderChain;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Domain policies — config-driven so demo-time `.env` edits propagate
        // without touching code. Defaults inside each class still reflect canon.
        $this->app->bind(IpvaInterestPolicy::class, fn (): IpvaInterestPolicy => new IpvaInterestPolicy(
            dailyRate: (string) config('business.interest.ipva.daily_rate'),
            capRate: (string) config('business.interest.ipva.cap_rate'),
        ));

        $this->app->bind(MultaInterestPolicy::class, fn (): MultaInterestPolicy => new MultaInterestPolicy(
            dailyRate: (string) config('business.interest.multa.daily_rate'),
        ));

        $this->app->bind(PixSimulator::class, fn (): PixSimulator => new PixSimulator(
            discountFactor: (string) config('business.payment.pix.discount_factor'),
        ));

        $this->app->bind(CreditCardSimulator::class, fn (): CreditCardSimulator => new CreditCardSimulator(
            monthlyRate: (string) config('business.payment.credit_card.monthly_rate'),
        ));

        $this->app->singleton(PaymentSimulator::class);

        $this->app->bind(InterestCalculator::class, fn (Application $app): InterestCalculator => new InterestCalculator(
            referenceDate: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            policies: [
                DebtType::IPVA->value => $app->make(IpvaInterestPolicy::class),
                DebtType::MULTA->value => $app->make(MultaInterestPolicy::class),
            ],
        ));

        // Provider chain wire-up. Each adapter pulls its URL + network knobs
        // from config; each gets an isolated CircuitBreaker so a slow Provider
        // B never trips Provider A's circuit.
        $this->app->singleton(DebtProvider::class, fn (): ProviderChain => new ProviderChain(
            providers: [
                new CircuitBreakerDebtProvider(
                    inner: new ProviderAJsonAdapter(
                        baseUrl: (string) config('business.providers.a.url'),
                        timeoutSeconds: (int) config('business.providers.a.timeout'),
                        retryAttempts: (int) config('business.providers.a.retries'),
                        retryBackoffMs: (int) config('business.providers.a.backoff_ms'),
                    ),
                    breaker: $this->makeBreaker(),
                ),
                new CircuitBreakerDebtProvider(
                    inner: new ProviderBXmlAdapter(
                        baseUrl: (string) config('business.providers.b.url'),
                        timeoutSeconds: (int) config('business.providers.b.timeout'),
                        retryAttempts: (int) config('business.providers.b.retries'),
                        retryBackoffMs: (int) config('business.providers.b.backoff_ms'),
                    ),
                    breaker: $this->makeBreaker(),
                ),
            ],
            budgetSeconds: (float) config('business.resilience.chain_budget_seconds'),
        ));
    }

    public function boot(): void
    {
        //
    }

    private function makeBreaker(): CircuitBreaker
    {
        return new CircuitBreaker(
            failureThreshold: (int) config('business.resilience.circuit_breaker.failure_threshold'),
            cooldownSeconds: (float) config('business.resilience.circuit_breaker.cooldown_seconds'),
            shouldRecordFailure: static fn (Throwable $e): bool => $e instanceof ProviderUnavailableException,
        );
    }
}
