<?php

namespace App\Providers;

use App\Domain\Contracts\DebtProviderInterface;
use App\Domain\Contracts\JurosStrategyResolverInterface;
use App\Domain\Contracts\PaymentSimulatorInterface;
use App\Domain\Contracts\ReferenceDateProviderInterface;
use App\Domain\Services\Juros\IpvaJurosStrategy;
use App\Domain\Services\Juros\JurosStrategyResolver;
use App\Domain\Services\Juros\MultaJurosStrategy;
use App\Domain\Services\Pagamento\PagamentoSimulator;
use App\Integrations\Clock\ConfigReferenceDateProvider;
use App\Integrations\Providers\FixtureProviderAClient;
use App\Integrations\Providers\FixtureProviderBClient;
use App\Integrations\Providers\ProviderAJsonAdapter;
use App\Integrations\Providers\ProviderBXmlAdapter;
use App\Integrations\Resilience\CircuitBreakerProviderClientDecorator;
use App\Integrations\Resilience\ProviderFallbackOrchestrator;
use App\Integrations\Resilience\RetryingProviderClientDecorator;
use Illuminate\Support\ServiceProvider;

final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(JurosStrategyResolverInterface::class, fn ($app) =>
            new JurosStrategyResolver([
                $app->make(IpvaJurosStrategy::class),
                $app->make(MultaJurosStrategy::class),
            ])
        );

        $this->app->bind(PaymentSimulatorInterface::class, PagamentoSimulator::class);
        $this->app->bind(ReferenceDateProviderInterface::class, ConfigReferenceDateProvider::class);

        $this->app->singleton('cb_client_a', fn () =>
            new CircuitBreakerProviderClientDecorator(
                new RetryingProviderClientDecorator(new FixtureProviderAClient(), retries: 3, waitMs: 200),
                threshold: 5,
                resetAfterSeconds: 60,
            )
        );

        $this->app->singleton('cb_client_b', fn () =>
            new CircuitBreakerProviderClientDecorator(
                new RetryingProviderClientDecorator(new FixtureProviderBClient(), retries: 3, waitMs: 200),
                threshold: 5,
                resetAfterSeconds: 60,
            )
        );

        $this->app->bind(DebtProviderInterface::class, fn ($app) =>
            new ProviderFallbackOrchestrator([
                new ProviderAJsonAdapter($app->make('cb_client_a')),
                new ProviderBXmlAdapter($app->make('cb_client_b')),
            ])
        );
    }
}
