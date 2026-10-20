<?php

namespace ShookEagle\BetterPelicanSecrets\Node;

use App\Extensions\Features\FeatureService;
use App\Models\Server;
use App\Services\Servers\EnvironmentService;
use App\Services\Servers\ServerConfigurationStructureService;
use ShookEagle\BetterPelicanSecrets\Plans\PlanService;

final class ReferenceConfigurationService extends ServerConfigurationStructureService
{
    public function __construct(EnvironmentService $environment, FeatureService $features, private PlanService $plans)
    {
        parent::__construct($environment, $features);
    }

    public function handle(Server $server, array $override = []): array
    {
        $configuration = parent::handle($server, $override);
        $plan = $this->plans->current($server);
        foreach ($plan->startup as $binding) {
            unset($configuration['environment'][$binding->env]);
        }
        $configuration['secrets'] = $plan->toWire();

        return $configuration;
    }
}
