<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Ports\DebtProvider;
use App\Application\Ports\ProviderUnavailableException;
use App\Application\Ports\QueryTracer;
use App\Domain\Debt\DebtType;
use App\Domain\Debt\InterestCalculator;
use App\Domain\Debt\IpvaInterestPolicy;
use App\Domain\Debt\MultaInterestPolicy;
use App\Domain\Payment\CreditCardSimulator;
use App\Domain\Payment\PaymentSimulator;
use App\Domain\Payment\PixSimulator;
use App\Infrastructure\Logging\DemoLogger;
use App\Infrastructure\Providers\ProviderAJsonAdapter;
use App\Infrastructure\Providers\ProviderBXmlAdapter;
use App\Infrastructure\Resilience\CircuitBreaker;
use App\Infrastructure\Resilience\CircuitBreakerDebtProvider;
use App\Infrastructure\Resilience\ProviderChain;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InterestCalculator::class, fn (): InterestCalculator => new InterestCalculator(
            referenceDate: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            policies: [
                DebtType::IPVA->value => new IpvaInterestPolicy,
                DebtType::MULTA->value => new MultaInterestPolicy,
            ],
        ));

        $this->app->singleton(PixSimulator::class);
        $this->app->singleton(CreditCardSimulator::class);
        $this->app->singleton(PaymentSimulator::class);

        // Demo tracer — enabled outside production. When disabled, every
        // call site is a no-op (constructor short-circuits the channel).
        $this->app->singleton(DemoLogger::class, fn (): DemoLogger => new DemoLogger(
            enabled: ! $this->app->isProduction(),
            manager: $this->app->make(LogManager::class),
        ));
        // Application depends on the QueryTracer port; Infrastructure
        // provides DemoLogger as the implementation.
        $this->app->bind(QueryTracer::class, DemoLogger::class);

        // Provider chain wire-up. Each adapter is wrapped in its own CircuitBreaker
        // so a slow Provider B never trips Provider A's circuit, and vice versa.
        // The chain is bound as the application's DebtProvider implementation, so
        // QueryDebtsUseCase resolves the full resilience stack without knowing
        // about it.
        $this->app->singleton(DebtProvider::class, function (): ProviderChain {
            $demoLog = $this->app->make(DemoLogger::class);

            return new ProviderChain(
                providers: [
                    new CircuitBreakerDebtProvider(
                        inner: new ProviderAJsonAdapter(
                            baseUrl: (string) config('providers.a.url', env('PROVIDER_A_URL', '')),
                        ),
                        breaker: $this->makeBreaker(),
                    ),
                    new CircuitBreakerDebtProvider(
                        inner: new ProviderBXmlAdapter(
                            baseUrl: (string) config('providers.b.url', env('PROVIDER_B_URL', '')),
                        ),
                        breaker: $this->makeBreaker(),
                    ),
                ],
                providerNames: ['Provider A', 'Provider B'],
                demoLog: $demoLog,
            );
        });
    }

    public function boot(): void
    {
        //
    }

    private function makeBreaker(): CircuitBreaker
    {
        return new CircuitBreaker(
            failureThreshold: 5,
            cooldownSeconds: 30.0,
            shouldRecordFailure: static fn (Throwable $e): bool => $e instanceof ProviderUnavailableException,
        );
    }
}
