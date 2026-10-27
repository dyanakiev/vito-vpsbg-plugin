<?php

namespace App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\Actions;

use App\Models\Server;
use App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\ServerProviders\VPSBG;
use RuntimeException;

class EnsureProviderUnused
{
    public function handle(): void
    {
        if (Server::query()->where('provider', VPSBG::id())->exists()) {
            throw new RuntimeException('Remove VPSBG servers from Vito before disabling or uninstalling this plugin.');
        }
    }
}
