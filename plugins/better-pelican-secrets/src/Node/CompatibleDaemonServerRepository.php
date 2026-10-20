<?php

namespace ShookEagle\BetterPelicanSecrets\Node;

use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Http\Client\Response;

final class CompatibleDaemonServerRepository extends DaemonServerRepository
{
    public function power(string $action): Response
    {
        if (in_array($action, ['start', 'restart'], true)) {
            app(NodeCapabilityService::class)->assertServerCompatible($this->server);
        }

        return parent::power($action);
    }
}
