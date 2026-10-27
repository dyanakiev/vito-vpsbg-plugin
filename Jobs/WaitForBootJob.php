<?php

namespace App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\Jobs;

use App\Enums\ServerStatus;
use App\Exceptions\ServerProviderError;
use App\Exceptions\SSHConnectionError;
use App\Jobs\Server\InstallJob;
use App\Models\Server;
use App\Traits\UniqueQueue;
use App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\ServerProviders\VPSBG;
use DateTime;
use Exception;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class WaitForBootJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
    use UniqueQueue;

    public int $timeout = 90;

    public int $uniqueFor = 1950;

    public bool $failOnTimeout = true;

    public bool $deleteWhenMissingModels = true;

    private DateTime $deadline;

    public function __construct(protected Server $server)
    {
        $this->deadline = now()->addMinutes(30);
    }

    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    public function retryUntil(): DateTime
    {
        return clone $this->deadline;
    }

    protected function lockSeconds(): int
    {
        return $this->timeout + 60;
    }

    public function handle(): void
    {
        $this->run('server-'.$this->server->id, function (): void {
            $server = $this->waitingServer();

            if ($server === null) {
                return;
            }

            if (now()->getTimestamp() >= $this->deadline->getTimestamp()) {
                throw $this->timeoutError();
            }

            if (! $server->provider()->isRunning()) {
                $this->release(30);

                return;
            }

            ResumeInstallationJob::dispatch($server)->onConnection('ssh')->onQueue('ssh')->delay(now()->addSecond());
        });
    }

    public function failed(Exception $exception): void
    {
        $server = $this->waitingServer();

        if ($server === null) {
            return;
        }

        $failure = now()->getTimestamp() >= $this->deadline->getTimestamp()
            ? $this->timeoutError()
            : ($exception instanceof ServerProviderError ? $exception : new SSHConnectionError('VPSBG boot readiness checks failed. Check cloud-init and SSH access in the VPSBG Console.'));

        (new InstallJob($server))->failed($failure);
    }

    private function waitingServer(): ?Server
    {
        $server = $this->server->fresh();

        return $server !== null && $server->provider === VPSBG::id()
            && $server->status === ServerStatus::INSTALLING && $server->progress < 5 ? $server : null;
    }

    private function timeoutError(): SSHConnectionError
    {
        return new SSHConnectionError('VPSBG did not become ready within 30 minutes. Check its boot status in the VPSBG Console before retrying installation.');
    }
}
