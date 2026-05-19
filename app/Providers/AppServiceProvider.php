<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Debt\DebtType;
use App\Domain\Debt\InterestCalculator;
use App\Domain\Debt\IpvaInterestPolicy;
use App\Domain\Debt\MultaInterestPolicy;
use App\Domain\Payment\CreditCardSimulator;
use App\Domain\Payment\PaymentSimulator;
use App\Domain\Payment\PixSimulator;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\ServiceProvider;

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
    }

    public function boot(): void
    {
        //
    }
}
