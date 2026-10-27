<?php

namespace App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\Jobs;

use App\Actions\Server\InstallServer;
use App\DTOs\SocketEventDTO;
use App\Enums\ServerStatus;
use App\Events\SocketEvent;
use App\Exceptions\ServerProviderError;
use App\Http\Resources\ServerResource;
use App\Jobs\Server\InstallJob;
use App\Models\Server;
use App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\ServerProviders\VPSBG;
use Exception;

class ResumeInstallationJob extends InstallJob
{
    public int $timeout;

    public bool $failOnTimeout = true;

    public bool $deleteWhenMissingModels = true;

    public function __construct(Server $server)
    {
        parent::__construct($server);

        $this->timeout = max(1, (int) config('horizon.defaults.ssh.timeout', 1200) - 60);
    }

    protected function lockSeconds(): int
    {
        return $this->timeout + 60;
    }

    public function handle(): void
    {
        $this->run('server-'.$this->server->id, function (): void {
            $server = $this->server->fresh();

            if ($server === null || $server->provider !== VPSBG::id() || $server->status !== ServerStatus::INSTALLING) {
                return;
            }

            if ($server->progress >= 5) {
                throw new ServerProviderError('VPSBG installation was interrupted after boot checks. Review the server logs before retrying provisioning.');
            }

            app(InstallServer::class)->run($server);

            SocketEvent::dispatch(new SocketEventDTO(
                projectId: $server->project_id,
                type: 'server.updated',
                data: new ServerResource($server->fresh()),
            ));
        });
    }

    public function failed(Exception $exception): void
    {
        $server = $this->server->fresh();

        if ($server !== null && $server->provider === VPSBG::id() && $server->status === ServerStatus::INSTALLING) {
            $this->server = $server;
            parent::failed($exception);
        }
    }
}
