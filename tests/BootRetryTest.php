<?php

use App\Actions\Server\InstallServer as CoreInstallServer;
use App\Enums\ServerStatus;
use App\Exceptions\SSHCommandError;
use App\Exceptions\SSHConnectionError;
use App\Facades\SSH;
use App\Jobs\Server\InstallJob;
use App\Models\ServerLog;
use App\Models\ServerProvider;
use App\Notifications\ServerInstallationFailed;
use App\Notifications\ServerInstallationSucceed;
use App\ServerProviders\Custom;
use App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\Jobs\ResumeInstallationJob;
use App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\Jobs\WaitForBootJob;
use App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\Plugin;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SyncQueue;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->freezeTime();
    Queue::fake();
    Notification::fake();
    SSH::fake('VITO_VPSBG_BOOT_PENDING');
    $profile = ServerProvider::factory()->create([
        'provider' => 'vpsbg', 'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id, 'credentials' => ['token' => 'private-token'],
    ]);
    $this->server->services()->delete();
    $this->server->update([
        'provider' => 'vpsbg', 'provider_id' => $profile->id, 'status' => ServerStatus::INSTALLING,
        'progress' => 0, 'progress_step' => 'Initializing', 'ip' => '',
        'provider_data' => ['vpsbg_id' => 77, 'region' => 'sofia', 'plan' => 'cloud-vps-4gb'],
    ]);
    $this->vpsbgActive = [
        'id' => 77, 'status' => 'active',
        'ip_addresses' => [['version' => 4, 'type' => 'public', 'main' => true, 'ip_address' => '203.0.113.10']],
    ];
});

test('core installation defers VPSBG boot checks to one delayed SSH job without provisioning', function () {
    (new InstallJob($this->server))->withFakeQueueInteractions()->handle();
    app(CoreInstallServer::class)->run($this->server->fresh());

    Queue::assertPushed(WaitForBootJob::class, 1);
    Queue::assertPushed(WaitForBootJob::class, fn (WaitForBootJob $job): bool => $job->connection === 'ssh'
        && $job->queue === 'ssh' && $job->delay->getTimestamp() === now()->addSeconds(10)->getTimestamp());
    Queue::assertNotPushed(ResumeInstallationJob::class);
    $this->assertDatabaseHas('servers', [
        'id' => $this->server->id, 'status' => ServerStatus::INSTALLING->value,
        'progress' => 0, 'progress_step' => 'waiting-for-boot',
    ]);
    expect(SSH::getExecutedCommands())->toBe([]);
    Http::assertNothingSent();
    Notification::assertNothingSent();
});

test('non VPSBG installations retain the stock provisioning flow', function () {
    $this->server->update(['provider' => Custom::id(), 'provider_id' => null]);

    app(CoreInstallServer::class)->run($this->server);

    SSH::assertExecutedContains('timeout 300 cloud-init status --wait');
    SSH::assertExecutedContains('sudo useradd');
    $this->assertDatabaseHas('servers', ['id' => $this->server->id, 'status' => ServerStatus::READY->value]);
    Queue::assertNotPushed(WaitForBootJob::class);
    Http::assertNothingSent();
    Notification::assertSentTo($this->notificationChannel, ServerInstallationSucceed::class);
});

test('plugin preserves an installer already registered by another extension', function () {
    $installer = Mockery::mock(CoreInstallServer::class);
    $this->server->update(['provider' => Custom::id()]);
    $installer->shouldReceive('run')->once()->with($this->server);
    app()->instance(CoreInstallServer::class, $installer);
    (new Plugin)->boot();

    app(CoreInstallServer::class)->run($this->server);

    Http::assertNothingSent();
    expect(SSH::getExecutedCommands())->toBe([]);
});

test('already ready VPSBG servers cannot restart provisioning', function () {
    $this->server->update(['status' => ServerStatus::READY]);

    app(CoreInstallServer::class)->run($this->server);

    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect(SSH::getExecutedCommands())->toBe([]);
    Notification::assertNothingSent();
});

test('pending boot checks release the same bounded job without creating another VPS', function () {
    Http::fake(['api.vpsbg.eu/v1/servers/77' => Http::response($this->vpsbgActive)]);
    $original = new WaitForBootJob($this->server);
    $deadline = $original->retryUntil()->getTimestamp();
    $job = unserialize(serialize($original));
    $this->travel(10)->minutes();
    $job->withFakeQueueInteractions()->handle();

    $job->assertReleased(30)->assertNotFailed();
    expect($deadline)->toBe(now()->subMinutes(10)->addMinutes(30)->getTimestamp())
        ->and($job->retryUntil()->getTimestamp())->toBe($deadline)
        ->and($original->retryUntil()->getTimestamp())->toBe($deadline);
    Queue::assertNotPushed(ResumeInstallationJob::class);
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    SSH::assertNotExecutedContains('sudo useradd');
    Notification::assertNothingSent();
    $this->assertDatabaseHas('servers', ['id' => $this->server->id, 'status' => ServerStatus::INSTALLING->value, 'progress' => 0]);
});

test('pending or interrupted probes then ready hand off once to stock installation', function (bool $interrupted) {
    Http::fake(['api.vpsbg.eu/v1/servers/77' => Http::response($this->vpsbgActive)]);
    if ($interrupted) {
        SSH::shouldReceive('init')->once()->andReturnSelf();
        SSH::shouldReceive('exec')->once()->andThrow(new SSHCommandError(message: 'SSH command timed out'));
    }
    $original = new WaitForBootJob($this->server);
    $deadline = $original->retryUntil()->getTimestamp();
    $job = unserialize(serialize($original))->withFakeQueueInteractions();
    $job->handle();
    $job->assertReleased(30)->assertNotFailed();
    expect($job->retryUntil()->getTimestamp())->toBe($deadline);
    Queue::assertNotPushed(ResumeInstallationJob::class);
    $this->assertDatabaseHas('servers', ['id' => $this->server->id, 'status' => ServerStatus::INSTALLING->value, 'progress' => 0]);

    $this->travel(31)->seconds();
    SSH::fake('VITO_VPSBG_BOOT_READY');
    $job->withFakeQueueInteractions()->handle();
    $job->assertNotReleased()->assertNotFailed();
    expect($job->retryUntil()->getTimestamp())->toBe($deadline);
    Queue::assertPushed(ResumeInstallationJob::class, 1);
    Queue::assertPushed(ResumeInstallationJob::class, fn (ResumeInstallationJob $resume): bool => $resume->connection === 'ssh'
        && $resume->queue === 'ssh' && $resume->delay->getTimestamp() === now()->addSecond()->getTimestamp());
    SSH::assertNotExecutedContains('sudo useradd');
    Notification::assertNothingSent();

    $resume = Queue::pushed(ResumeInstallationJob::class)->sole();
    $resume->withFakeQueueInteractions()->handle();
    $resume->assertNotFailed();
    SSH::assertExecutedContains('timeout 300 cloud-init status --wait');
    SSH::assertExecutedContains('sudo useradd');
    $this->assertDatabaseHas('servers', ['id' => $this->server->id, 'status' => ServerStatus::READY->value]);
    $commands = SSH::getExecutedCommands();
    $requests = Http::recorded()->count();

    (new ResumeInstallationJob($this->server))->withFakeQueueInteractions()->handle();

    expect(SSH::getExecutedCommands())->toBe($commands)->and(Http::recorded())->toHaveCount($requests);
    Notification::assertSentToTimes($this->notificationChannel, ServerInstallationSucceed::class, 1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->with([false, true]);

test('wait jobs skip servers that left the initial installation state', function (string $state) {
    $job = (new WaitForBootJob($this->server))->withFakeQueueInteractions();
    if ($state === 'deleted') {
        $this->server->deleteFromProvider = false;
        $this->server->delete();
    } else {
        $this->server->update(match ($state) {
            'ready' => ['status' => ServerStatus::READY],
            'failed' => ['status' => ServerStatus::INSTALLATION_FAILED],
            'other-provider' => ['provider' => Custom::id()],
            'partial' => ['progress' => 5],
        });
    }

    $job->handle();
    $job->failed(new RuntimeException('private-token'));

    $job->assertNotReleased()->assertNotFailed();
    Queue::assertNotPushed(ResumeInstallationJob::class);
    Http::assertNothingSent();
    Notification::assertNothingSent();
    expect(SSH::getExecutedCommands())->toBe([]);
    $this->assertDatabaseMissing('server_logs', ['server_id' => $this->server->id, 'type' => 'server-installation-failed']);
})->with(['ready', 'failed', 'other-provider', 'partial', 'deleted']);

test('expired boot checks fail with the same deadline even before another worker attempt', function (bool $attemptHandle) {
    $job = (new WaitForBootJob($this->server))->withFakeQueueInteractions();
    $this->travel(31)->minutes();
    if ($attemptHandle) {
        $job->handle();
        $job->assertFailedWith(SSHConnectionError::class)->assertNotReleased();
        $failure = $job->job->failedWith;
    } else {
        $failure = new MaxAttemptsExceededException('private-token worker details');
    }
    $job->failed($failure);

    $this->assertDatabaseHas('servers', ['id' => $this->server->id, 'status' => ServerStatus::INSTALLATION_FAILED->value]);
    $log = ServerLog::query()->where('server_id', $this->server->id)->where('type', 'server-installation-failed')->sole();
    expect($log->getContent())->toContain('did not become ready within 30 minutes')->not->toContain('private-token');
    Queue::assertNotPushed(ResumeInstallationJob::class);
    Http::assertNothingSent();
    Notification::assertSentTo($this->notificationChannel, ServerInstallationFailed::class);
})->with([true, false]);

test('actual API and cloud init failures stop boot retries and report safe errors', function (bool $cloudInitFailure) {
    Http::fake(['api.vpsbg.eu/v1/servers/77' => Http::response(
        $cloudInitFailure ? $this->vpsbgActive : ['message' => 'private-token upstream details'], $cloudInitFailure ? 200 : 500,
    )]);
    if ($cloudInitFailure) {
        SSH::fake('VITO_VPSBG_BOOT_ERROR');
    }
    $job = (new WaitForBootJob($this->server))->withFakeQueueInteractions();
    $job->handle();
    $job->assertFailed()->assertNotReleased();
    $job->failed($job->job->failedWith);

    $this->assertDatabaseHas('servers', ['id' => $this->server->id, 'status' => ServerStatus::INSTALLATION_FAILED->value]);
    $log = ServerLog::query()->where('server_id', $this->server->id)->where('type', 'server-installation-failed')->sole();
    expect($log->getContent())->toContain($cloudInitFailure ? 'VPSBG cloud-init reported an error.' : 'HTTP 500')->not->toContain('private-token', 'upstream details');
    Queue::assertNotPushed(ResumeInstallationJob::class);
    Http::assertSentCount(1);
})->with([false, true]);

test('resume jobs reject partially completed provisioning instead of creating users again', function (int $progress) {
    $this->server->update(['progress' => $progress]);
    $resume = (new ResumeInstallationJob($this->server))->withFakeQueueInteractions();

    $resume->handle();

    $resume->assertFailed()->assertNotReleased();
    expect($resume->job->failedWith->getMessage())->toContain('installation was interrupted after boot checks');
    $resume->failed($resume->job->failedWith);
    $this->assertDatabaseHas('servers', ['id' => $this->server->id, 'status' => ServerStatus::INSTALLATION_FAILED->value]);
    Http::assertNothingSent();
    expect(SSH::getExecutedCommands())->toBe([]);
})->with([5, 25]);

test('resume jobs and their failure callbacks cannot overwrite terminal or unrelated servers', function (string $state) {
    $resume = (new ResumeInstallationJob($this->server))->withFakeQueueInteractions();
    if ($state === 'deleted') {
        $this->server->deleteFromProvider = false;
        $this->server->delete();
    } else {
        $this->server->update(match ($state) {
            'ready' => ['status' => ServerStatus::READY],
            'failed' => ['status' => ServerStatus::INSTALLATION_FAILED],
            'other-provider' => ['provider' => Custom::id()],
        });
    }
    $resume->handle();
    $resume->failed(new RuntimeException('private-token'));

    $resume->assertNotFailed()->assertNotReleased();
    Http::assertNothingSent();
    expect(SSH::getExecutedCommands())->toBe([]);
    Notification::assertNothingSent();
    $this->assertDatabaseMissing('server_logs', ['server_id' => $this->server->id, 'type' => 'server-installation-failed']);
})->with(['ready', 'failed', 'other-provider', 'deleted']);

test('job lock contention releases work and the lock outlives its job timeout', function (bool $resume) {
    $job = ($resume ? new ResumeInstallationJob($this->server) : new WaitForBootJob($this->server))->withFakeQueueInteractions();
    $workerTimeout = (int) config('horizon.defaults.ssh.timeout');
    expect($job->timeout)->toBeLessThan($workerTimeout)->and($job->failOnTimeout)->toBeTrue()
        ->and((int) config('queue.connections.ssh.retry_after'))->toBeGreaterThan($workerTimeout);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturnFalse();
    Cache::shouldReceive('lock')->once()->with('unique-queue:server-'.$this->server->id, $job->timeout + 60)->andReturn($lock);

    $job->handle();

    $job->assertReleased(30)->assertNotFailed();
    Http::assertNothingSent();
    expect(SSH::getExecutedCommands())->toBe([]);
})->with([false, true]);

test('worker payload keeps the original boot deadline and bounded execution timeout', function () {
    Http::fake(['api.vpsbg.eu/v1/servers/77' => Http::response(['status' => 'processing'])]);
    $job = new WaitForBootJob($this->server);
    $deadline = $job->retryUntil()->getTimestamp();
    $this->travel(10)->minutes();
    $payload = null;
    Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$payload): void {
        $payload = $event->job->payload();
    });
    $queue = new SyncQueue;
    $queue->setContainer(app());
    $queue->setConnectionName('ssh');

    $queue->push($job, queue: 'ssh');

    expect($payload['retryUntil'])->toBe($deadline)->and($payload['timeout'])->toBe(90)
        ->and($payload['failOnTimeout'])->toBeTrue()->and($payload['data']['command'])->not->toContain('private-token')
        ->and($job->uniqueFor)->toBeGreaterThan(1800 + $job->timeout);
    $this->assertDatabaseHas('servers', ['id' => $this->server->id, 'status' => ServerStatus::INSTALLING->value]);
    Queue::assertNotPushed(ResumeInstallationJob::class);
    Http::assertSentCount(1);
});

test('real Redis worker retries boot until ready or its original deadline expires', function (bool $becomesReady, bool $interrupted) {
    $redis = (new ConfigurationUrlParser)->parseConfiguration(config('database.redis.default'));
    $redis['database'] = 15;
    $redis['options']['prefix'] = 'vpsbg-test-'.Str::uuid().':';
    config()->set([
        'database.redis.vpsbg_boot_test' => $redis,
        'queue.default' => 'ssh',
        'queue.connections.ssh.driver' => 'redis',
        'queue.connections.ssh.connection' => 'vpsbg_boot_test',
        'queue.connections.ssh.block_for' => null,
    ]);
    $fake = Queue::getFacadeRoot();
    app()->forgetInstance('queue');
    app()->forgetInstance('queue.worker');
    app()->forgetInstance('redis');
    Queue::clearResolvedInstance('queue');
    $connection = Queue::connection('ssh');
    $worker = app('queue.worker');
    $options = new WorkerOptions(sleep: 0, force: true);
    $processed = [];
    Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$processed): void {
        $processed[] = $event->job->payload();
    });
    Http::fake(['api.vpsbg.eu/v1/servers/77' => Http::response($this->vpsbgActive)]);

    try {
        InstallJob::dispatch($this->server)->onConnection('ssh')->onQueue('ssh');
        $worker->runNextJob('ssh', 'ssh', $options);
        expect($connection->delayedSize('ssh'))->toBe(1)->and(SSH::getExecutedCommands())->toBe([]);

        $this->travel(11)->seconds();
        $worker->runNextJob('ssh', 'ssh', $options);
        expect($connection->delayedSize('ssh'))->toBe(1);
        if ($interrupted) {
            SSH::fake()->execWillFail();
        }
        $this->travel(601)->seconds();
        $worker->runNextJob('ssh', 'ssh', $options);
        expect($connection->delayedSize('ssh'))->toBe(1);
        $this->assertDatabaseHas('servers', ['id' => $this->server->id, 'status' => ServerStatus::INSTALLING->value, 'progress' => 0]);
        Notification::assertNothingSent();

        if ($becomesReady) {
            SSH::fake('VITO_VPSBG_BOOT_READY');
            $this->travel(31)->seconds();
            $worker->runNextJob('ssh', 'ssh', $options);
            SSH::assertNotExecutedContains('sudo useradd');
            expect($connection->delayedSize('ssh'))->toBe(1);
            $this->travel(2)->seconds();
            $worker->runNextJob('ssh', 'ssh', $options);

            $this->assertDatabaseHas('servers', ['id' => $this->server->id, 'status' => ServerStatus::READY->value]);
            SSH::assertExecutedContains('sudo useradd');
            Notification::assertSentToTimes($this->notificationChannel, ServerInstallationSucceed::class, 1);
            expect(array_column($processed, 'displayName'))->toBe([
                InstallJob::class, WaitForBootJob::class, WaitForBootJob::class, WaitForBootJob::class, ResumeInstallationJob::class,
            ]);
        } else {
            $this->travel(20)->minutes();
            $worker->runNextJob('ssh', 'ssh', $options);

            $this->assertDatabaseHas('servers', ['id' => $this->server->id, 'status' => ServerStatus::INSTALLATION_FAILED->value]);
            $log = ServerLog::query()->where('server_id', $this->server->id)->where('type', 'server-installation-failed')->sole();
            expect($log->getContent())->toContain('did not become ready within 30 minutes');
            expect(array_column($processed, 'displayName'))->not->toContain(ResumeInstallationJob::class);
            expect($connection->size('ssh'))->toBe(0);
            SSH::assertNotExecutedContains('sudo useradd');
            Notification::assertNotSentTo($this->notificationChannel, ServerInstallationSucceed::class);
            Notification::assertSentToTimes($this->notificationChannel, ServerInstallationFailed::class, 1);
            Http::assertSentCount(2);
        }
        $deadlines = collect($processed)->where('displayName', WaitForBootJob::class)->pluck('retryUntil');
        expect($deadlines->unique())->toHaveCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        $unique = new UniqueLock(app('cache.store'));
        $next = new WaitForBootJob($this->server->fresh());
        expect($unique->acquire($next))->toBeTrue();
        $unique->release($next);
    } finally {
        $connection->clear('ssh');
        app('redis')->purge('vpsbg_boot_test');
        Queue::swap($fake);
    }
})->with([[true, false], [true, true], [false, false]])->skip(fn (): bool => getenv('VPSBG_TEST_REDIS') !== '1', 'Set VPSBG_TEST_REDIS=1 to run the isolated Redis worker integration.');
