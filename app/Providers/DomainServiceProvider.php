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
use App\Integrations\Providers\ProviderClientInterface;
use App\Integrations\Resilience\ProviderFallbackOrchestrator;
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

        $this->app->when(ProviderAJsonAdapter::class)
            ->needs(ProviderClientInterface::class)
            ->give(FixtureProviderAClient::class);

        $this->app->when(ProviderBXmlAdapter::class)
            ->needs(ProviderClientInterface::class)
            ->give(FixtureProviderBClient::class);

        $this->app->bind(DebtProviderInterface::class, fn ($app) =>
            new ProviderFallbackOrchestrator([
                $app->make(ProviderAJsonAdapter::class),
                $app->make(ProviderBXmlAdapter::class),
            ])
        );
    }
}
