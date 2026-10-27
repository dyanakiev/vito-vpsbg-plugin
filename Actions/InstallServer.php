<?php

namespace App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\Actions;

use App\Actions\Server\InstallServer as CoreInstallServer;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\Jobs\WaitForBootJob;
use App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\ServerProviders\VPSBG;

class InstallServer extends CoreInstallServer
{
    public function __construct(private CoreInstallServer $installer) {}

    public function run(Server $server): void
    {
        if ($server->provider !== VPSBG::id()) {
            $this->installer->run($server);

            return;
        }

        $server->refresh();

        if ($server->status === ServerStatus::READY) {
            return;
        }

        if (($server->provider_data['boot_ready'] ?? false) || $server->progress >= 5) {
            $this->installer->run($server);

            return;
        }

        $server->update(['status' => ServerStatus::INSTALLING, 'progress_step' => 'waiting-for-boot']);

        WaitForBootJob::dispatch($server)->onConnection('ssh')->onQueue('ssh')->delay(now()->addSeconds(10));
    }
}
