<?php

use App\Actions\Bootstrap\GetBootstrap;
use App\Actions\Server\CreateServer;
use App\Actions\Server\DeleteServer;
use App\Enums\OperatingSystem;
use App\Exceptions\ServerProviderError;
use App\Exceptions\SSHCommandError;
use App\Exceptions\SSHConnectionError;
use App\Facades\SSH;
use App\Jobs\Server\InstallJob;
use App\Models\ServerProvider;
use App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\ServerProviders\VPSBG;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    SSH::fake('VITO_VPSBG_BOOT_READY');
    Queue::fake();
    $this->vpsbgProfile = ServerProvider::factory()->create([
        'provider' => VPSBG::id(), 'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id, 'credentials' => ['token' => 'test-token'],
    ]);
    $this->server->update([
        'provider' => VPSBG::id(), 'provider_id' => $this->vpsbgProfile->id,
        'provider_data' => ['region' => 'sofia', 'plan' => 'cloud-vps-4gb', 'billing_cycle' => '1'],
        'os' => OperatingSystem::UBUNTU24, 'ip' => '',
    ]);
    $this->server->refresh();
    $this->vpsbgPlan = [
        'id' => 'plan-4', 'key' => 'cloud-vps-4gb', 'name' => 'Cloud VPS 4GB',
        'available' => true, 'minimum_billing_cycle' => '1', 'price' => '10.00',
    ];
    $this->vpsbgImage = [
        'id' => 24, 'name' => 'Ubuntu 24.04', 'eol' => false, 'ssh_keys_support' => true,
        'group' => ['name' => 'Ubuntu', 'key' => 'ubuntu'],
    ];
    $this->vpsbgInput = [
        'provider' => 'vpsbg', 'server_provider' => $this->vpsbgProfile->id,
        'name' => 'Production VPSBG', 'os' => 'ubuntu_24', 'region' => 'sofia', 'plan' => 'cloud-vps-4gb',
    ];
});

test('vpsbg bootstrap registers masked credentials and billing cycle selection', function () {
    $provider = app(GetBootstrap::class)->handle()['configs']['server_provider']['providers']['vpsbg'];

    expect($provider['label'])->toBe('VPSBG')->and($provider['default_user'])->toBe('root')
        ->and(array_column($provider['form'], 'name'))->toBe(['token'])
        ->and(collect($provider['form'])->firstWhere('name', 'token')['type'])->toBe('password');
    $cycle = collect($provider['create_form'])->firstWhere('name', 'billing_cycle');
    expect($cycle['type'])->toBe('select')->and((string) $cycle['default'])->toBe('1');
});

test('vpsbg connects through web and api without exposing its stored token', function (bool $api) {
    Http::fake(['api.vpsbg.eu/v1/user' => Http::response(['id' => 1])]);
    $input = ['provider' => 'vpsbg', 'name' => 'My VPSBG', 'token' => 'new-token'];
    if ($api) {
        Sanctum::actingAs($this->user, ['read', 'write']);
        $this->postJson(route('api.user.server-providers.create'), $input)
            ->assertSuccessful()->assertJsonFragment(['provider' => 'vpsbg'])->assertDontSee('new-token');
    } else {
        $this->actingAs($this->user)->post(route('server-providers.store'), $input)->assertSessionDoesntHaveErrors();
    }

    $this->assertDatabaseHas('server_providers', ['profile' => 'My VPSBG', 'project_id' => $this->user->current_project_id]);
    $profile = ServerProvider::query()->where('profile', 'My VPSBG')->firstOrFail();
    expect($profile->credentials)->toBe(['token' => 'new-token'])
        ->and($profile->getRawOriginal('credentials'))->not->toContain('new-token');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.vpsbg.eu/v1/user'
        && $request->hasHeader('Authorization', 'Bearer new-token'));
})->with([false, true]);

test('vpsbg validates token input before calling upstream', function (array $credentials) {
    Sanctum::actingAs($this->user, ['write']);
    $this->postJson(route('api.user.server-providers.create'), array_merge([
        'provider' => 'vpsbg', 'name' => 'Invalid VPSBG',
    ], $credentials))->assertUnprocessable()->assertJsonValidationErrors('token');
    Http::assertNothingSent();
})->with([[[]], [['token' => []]]]);

test('vpsbg rejects invalid credentials without showing upstream secrets', function () {
    Http::fake(['api.vpsbg.eu/v1/user' => Http::response(['message' => 'test-token confidential'], 401)]);
    Sanctum::actingAs($this->user, ['write']);
    $this->postJson(route('api.user.server-providers.create'), [
        'provider' => 'vpsbg', 'name' => 'Rejected VPSBG', 'token' => 'test-token',
    ])->assertUnprocessable()->assertJsonValidationErrors('provider')->assertDontSee('test-token');
    $this->assertDatabaseMissing('server_providers', ['profile' => 'Rejected VPSBG']);
});

test('vpsbg exposes Sofia and lists plans by deployable keys with stock information', function () {
    Http::fake(['api.vpsbg.eu/v1/servers/plans' => Http::response(['data' => [
        $this->vpsbgPlan,
        array_merge($this->vpsbgPlan, ['key' => 'sold-out', 'name' => 'Sold out', 'available' => false]),
    ]])]);
    $this->actingAs($this->user)->getJson(route('server-providers.regions', $this->vpsbgProfile))
        ->assertExactJson(['sofia' => 'Sofia, Bulgaria']);
    $plans = $this->getJson(route('server-providers.plans', [
        'serverProvider' => $this->vpsbgProfile, 'region' => 'sofia',
    ]))->assertOk()->json();
    expect($plans['cloud-vps-4gb']['label'])->toContain('Cloud VPS 4GB')
        ->and($plans['cloud-vps-4gb']['available'])->toBeTrue()->and($plans['sold-out']['available'])->toBeFalse();
    Sanctum::actingAs($this->user, ['read']);
    $this->getJson(route('api.user.server-providers.plans', [
        'serverProvider' => $this->vpsbgProfile, 'region' => 'sofia',
    ]))->assertOk()->assertJsonCount(1)->assertJsonStructure(['cloud-vps-4gb']);
});

test('vpsbg creates with a paginated Ubuntu image and queues installation', function (string $os, string $version, ?string $cycle) {
    Http::fake([
        'api.vpsbg.eu/v1/servers/plans' => Http::response(['data' => [$this->vpsbgPlan]]),
        'api.vpsbg.eu/v1/server-images*' => Http::sequence()
            ->push(['data' => [], 'links' => ['next' => 'https://api.vpsbg.eu/v1/server-images?page=2']])
            ->push(['data' => [array_merge($this->vpsbgImage, ['name' => 'Ubuntu '.$version])], 'links' => ['next' => null]]),
        'api.vpsbg.eu/v1/ssh-keys' => Http::response(['id' => 42], 201),
        'api.vpsbg.eu/v1/servers' => Http::response(['data' => [['id' => 77, 'status' => 'processing']]], 201),
    ]);
    $input = array_merge($this->vpsbgInput, ['os' => $os, 'name' => str_repeat('Production / ', 8)]);
    if ($cycle !== null) {
        $input['billing_cycle'] = $cycle;
    }
    $server = app(CreateServer::class)->create($this->user, $this->user->currentProject, $input);

    $this->assertDatabaseHas('servers', ['id' => $server->id, 'provider' => 'vpsbg', 'ssh_user' => 'root']);
    expect($server->provider_data['vpsbg_id'])->toBe(77)->and($server->provider_data['ssh_key_id'])->toBe(42)
        ->and($server->provider_data['billing_cycle'])->toBe($cycle ?? '1')
        ->and($server->sshKey()['public_key'])->toStartWith('ssh-ed25519 ');
    Http::assertSent(function (Request $request) use ($cycle): bool {
        if ($request->url() !== 'https://api.vpsbg.eu/v1/servers') {
            return false;
        }
        expect($request['name'])->toMatch('/^[a-z0-9-]{3,50}$/')->and($request['plan'])->toBe('cloud-vps-4gb')
            ->and($request['billing_cycle'])->toBe($cycle ?? '1')->and($request['image_id'])->toBe(24)
            ->and($request['ssh_keys'])->toBe([42]);

        return true;
    });
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.vpsbg.eu/v1/ssh-keys'
        && $request['key'] === $server->sshKey()['public_key']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.vpsbg.eu/v1/server-images?page=2');
    Queue::assertPushed(InstallJob::class);
})->with([['ubuntu_22', '22.04', null], ['ubuntu_24', '24.04', '3'], ['ubuntu_26', '26.04', '12']]);

test('vpsbg validates location plan and billing cycle before upstream requests', function () {
    $this->actingAs($this->user)->postJson(route('servers.store'), array_merge($this->vpsbgInput, [
        'region' => 'other', 'plan' => [], 'billing_cycle' => '2',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['region', 'plan', 'billing_cycle']);
    Http::assertNothingSent();
});

test('vpsbg refuses unavailable plans or insufficient billing cycles before creating resources', function (array $changes) {
    Http::fake(['api.vpsbg.eu/v1/servers/plans' => Http::response(['data' => [array_merge($this->vpsbgPlan, $changes)]])]);
    expect(fn () => $this->server->provider()->create())->toThrow(ServerProviderError::class);
    Http::assertSentCount(1);
})->with([[['key' => 'another-plan']], [['available' => false]], [['minimum_billing_cycle' => '3']]]);

test('vpsbg refuses unsuitable Ubuntu images before creating resources', function (array $changes) {
    Http::fake([
        'api.vpsbg.eu/v1/servers/plans' => Http::response(['data' => [$this->vpsbgPlan]]),
        'api.vpsbg.eu/v1/server-images*' => Http::response(['data' => [array_merge($this->vpsbgImage, $changes)], 'links' => ['next' => null]]),
    ]);
    expect(fn () => $this->server->provider()->create())->toThrow(ServerProviderError::class);
    Http::assertSentCount(2);
})->with([[['name' => 'Ubuntu 22.04']], [['eol' => true]], [['ssh_keys_support' => false]]]);

test('vpsbg refuses external and cyclic image pagination links', function (string $next) {
    Http::fake([
        'api.vpsbg.eu/v1/servers/plans' => Http::response(['data' => [$this->vpsbgPlan]]),
        'api.vpsbg.eu/v1/server-images*' => Http::response(['data' => [], 'links' => ['next' => $next]]),
    ]);
    expect(fn () => $this->server->provider()->create())->toThrow(ServerProviderError::class);
    Http::assertNotSent(fn (Request $request): bool => ! str_starts_with($request->url(), 'https://api.vpsbg.eu/v1/'));
    expect(Http::recorded())->toHaveCount($next === 'https://api.vpsbg.eu/v1/server-images?page=2' ? 3 : 2);
})->with(['https://attacker.example/server-images?page=2', 'https://api.vpsbg.eu/v1/server-images?page=2']);

test('vpsbg cleans up its imported key when server creation fails', function (array $body, int $status) {
    Http::fake([
        'api.vpsbg.eu/v1/servers/plans' => Http::response(['data' => [$this->vpsbgPlan]]),
        'api.vpsbg.eu/v1/server-images*' => Http::response(['data' => [$this->vpsbgImage], 'links' => ['next' => null]]),
        'api.vpsbg.eu/v1/ssh-keys' => Http::response(['id' => 42], 201),
        'api.vpsbg.eu/v1/servers' => Http::response($body, $status),
        'api.vpsbg.eu/v1/ssh-keys/42' => Http::response(null, 204),
    ]);
    $response = $this->actingAs($this->user)->postJson(route('servers.store'), $this->vpsbgInput)
        ->assertUnprocessable()->assertJsonValidationErrors('provider')
        ->assertDontSee('test-token')->assertDontSee('confidential')->assertDontSee('87654321');
    if ($status === 400) {
        $response->assertJsonPath('errors.provider.0', 'VPSBG could not complete payment for this server. Check your account balance, saved card and unpaid orders in the VPSBG Console.');
    }
    $this->assertDatabaseMissing('servers', ['name' => $this->vpsbgInput['name']]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.vpsbg.eu/v1/ssh-keys/42');
    Http::assertSentCount(5);
    Queue::assertNotPushed(InstallJob::class);
})->with([
    [['message' => 'test-token confidential'], 400],
    [['message' => 'test-token confidential', 'order_id' => 87654321], 400],
    [['message' => 'test-token confidential'], 422],
    [['data' => []], 201],
]);

test('vpsbg waits for an active server with a public IPv4', function (array $instance, int $status) {
    expect($this->server->provider()->isRunning())->toBeFalse();
    $this->server->jsonUpdate('provider_data', 'vpsbg_id', 77);
    Http::fake(['api.vpsbg.eu/v1/servers/77' => Http::response($instance, $status)]);
    expect($this->server->provider()->isRunning())->toBeFalse()->and($this->server->fresh()->ip)->toBe('');
})->with([
    [['status' => 'processing'], 200], [['status' => 'active', 'ip_addresses' => []], 200],
    [['status' => 'active', 'ip_addresses' => [['version' => 6, 'type' => 'public', 'ip_address' => '2001:db8::1', 'main' => true]]], 200],
    [['status' => 'active', 'ip_addresses' => [['version' => 4, 'type' => 'public', 'ip_address' => 'invalid', 'main' => true]]], 200],
    [[], 404],
]);

test('vpsbg saves the main public IPv4 after provisioning', function () {
    $this->server->jsonUpdate('provider_data', 'vpsbg_id', 77);
    Http::fake(['api.vpsbg.eu/v1/servers/77' => Http::response([
        'status' => 'active', 'ip_addresses' => [
            ['version' => 4, 'type' => 'public', 'ip_address' => '203.0.113.20', 'main' => false],
            ['version' => 4, 'type' => 'public', 'ip_address' => '203.0.113.10', 'main' => true],
        ],
    ])]);
    expect($this->server->provider()->isRunning())->toBeTrue()
        ->and($this->server->fresh()->provider_data['boot_ready'])->toBeTrue()
        ->and($this->server->fresh()->provider()->isRunning())->toBeTrue()
        ->and(SSH::getExecutedCommands())->toHaveCount(1);
    $this->assertDatabaseHas('servers', ['id' => $this->server->id, 'ip' => '203.0.113.10']);
});

test('vpsbg waits for first boot completion before starting core provisioning', function (string $output) {
    SSH::fake($output);
    $this->server->jsonUpdate('provider_data', 'vpsbg_id', 77);
    Http::fake(['api.vpsbg.eu/v1/servers/77' => Http::response([
        'status' => 'active',
        'ip_addresses' => [['version' => 4, 'type' => 'public', 'ip_address' => '203.0.113.10', 'main' => true]],
    ])]);

    expect($this->server->provider()->isRunning())->toBeFalse()
        ->and($this->server->fresh()->provider_data['boot_ready'] ?? false)->toBeFalse()
        ->and(SSH::getExecutedCommands())->toHaveCount(1);
    $this->assertDatabaseHas('servers', ['id' => $this->server->id, 'ip' => '203.0.113.10']);
})->with(['VITO_VPSBG_BOOT_PENDING', '', 'unexpected output']);

test('vpsbg retries boot probes interrupted by SSH transport failures', function (bool $connectionFailure) {
    $this->server->jsonUpdate('provider_data', 'vpsbg_id', 77);
    Http::fake(['api.vpsbg.eu/v1/servers/77' => Http::response([
        'status' => 'active',
        'ip_addresses' => [['version' => 4, 'type' => 'public', 'ip_address' => '203.0.113.10', 'main' => true]],
    ])]);
    SSH::shouldReceive('init')->once()->andReturnSelf();
    SSH::shouldReceive('exec')->once()->withArgs(function (...$arguments): bool {
        return str_contains((string) $arguments[0], 'VITO_VPSBG_BOOT_READY') && end($arguments) === 15;
    })->andThrow($connectionFailure ? new SSHConnectionError('Connection refused') : new SSHCommandError(message: 'SSH command timed out'));

    expect($this->server->provider()->isRunning())->toBeFalse()
        ->and($this->server->fresh()->provider_data['boot_ready'] ?? false)->toBeFalse();
})->with([true, false]);

test('vpsbg stops boot checks when cloud init explicitly reports an error', function () {
    $this->server->jsonUpdate('provider_data', 'vpsbg_id', 77);
    Http::fake(['api.vpsbg.eu/v1/servers/77' => Http::response([
        'status' => 'active',
        'ip_addresses' => [['version' => 4, 'type' => 'public', 'ip_address' => '203.0.113.10', 'main' => true]],
    ])]);
    SSH::fake("\nVITO_VPSBG_BOOT_ERROR\n");

    expect(fn () => $this->server->provider()->isRunning())->toThrow(
        ServerProviderError::class, 'VPSBG cloud-init reported an error. Check its status in the VPSBG Console.',
    );
    expect($this->server->fresh()->provider_data['boot_ready'] ?? false)->toBeFalse();
});

test('vpsbg deletes with the current remote hostname and respects keeping the remote server', function (bool $delete, int $status) {
    $this->server->jsonUpdate('provider_data', 'vpsbg_id', 77);
    $this->server->jsonUpdate('provider_data', 'ssh_key_id', 42);
    Http::fake([
        'api.vpsbg.eu/v1/servers/77*' => fn (Request $request) => $request->method() === 'GET'
            ? Http::response(['id' => 77, 'hostname' => 'renamed.example.com']) : Http::response(null, $status),
        'api.vpsbg.eu/v1/ssh-keys/42' => Http::response(null, 204),
    ]);
    app(DeleteServer::class)->delete($this->server, ['name' => $this->server->name, 'delete_from_provider' => $delete]);
    $this->assertDatabaseMissing('servers', ['id' => $this->server->id]);
    if ($delete) {
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'DELETE' && str_contains($request->url(), '/servers/77')
                && ($query['hostname'] ?? null) === 'renamed.example.com' && ! empty($query['reason']);
        });
        Http::assertSentCount(3);
    } else {
        Http::assertNothingSent();
    }
})->with([[true, 204], [true, 409], [false, 204]]);

test('vpsbg deletion tolerates missing remote resources', function () {
    $this->server->jsonUpdate('provider_data', 'vpsbg_id', 77);
    $this->server->jsonUpdate('provider_data', 'ssh_key_id', 42);
    Http::fake(['api.vpsbg.eu/v1/*' => Http::response([], 404)]);
    app(DeleteServer::class)->delete($this->server, ['name' => $this->server->name, 'delete_from_provider' => true]);
    $this->assertDatabaseMissing('servers', ['id' => $this->server->id]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.vpsbg.eu/v1/ssh-keys/42');
});

test('vpsbg propagates failed deletions and retains local server data', function () {
    $this->server->jsonUpdate('provider_data', 'vpsbg_id', 77);
    Http::fake(['api.vpsbg.eu/v1/servers/77*' => fn (Request $request) => $request->method() === 'GET'
        ? Http::response(['id' => 77, 'hostname' => 'server.example.com'])
        : Http::response(['message' => 'test-token private response'], 423)]);
    expect(fn () => app(DeleteServer::class)->delete($this->server, [
        'name' => $this->server->name, 'delete_from_provider' => true,
    ]))->toThrow(ServerProviderError::class);
    $this->assertDatabaseHas('servers', ['id' => $this->server->id]);
});

test('vpsbg propagates catalog errors without exposing upstream details', function (int $status) {
    Http::fake(['api.vpsbg.eu/v1/servers/plans' => Http::response(['message' => 'test-token confidential'], $status)]);
    try {
        $this->vpsbgProfile->provider()->plans('sofia');
        $this->fail('Expected a provider exception.');
    } catch (ServerProviderError $exception) {
        expect($exception->getMessage())->toContain((string) $status)->not->toContain('test-token', 'confidential')
            ->and($exception->getPrevious())->toBeNull();
    }
})->with([400, 409, 429]);

test('vpsbg sanitizes connection failures without chaining the upstream exception', function () {
    Http::fake(['api.vpsbg.eu/v1/servers/plans' => Http::failedConnection('test-token confidential')]);
    try {
        $this->vpsbgProfile->provider()->plans('sofia');
        $this->fail('Expected a provider exception.');
    } catch (ServerProviderError $exception) {
        expect($exception->getMessage())->toBe('Could not connect to VPSBG.')
            ->and($exception->getPrevious())->toBeNull();
    }
});

test('vpsbg rejects malformed catalog and image responses', function (bool $images) {
    Http::fake([
        'api.vpsbg.eu/v1/servers/plans' => Http::response($images ? ['data' => [$this->vpsbgPlan]] : []),
        'api.vpsbg.eu/v1/server-images*' => Http::response([]),
    ]);
    expect(fn () => $this->server->provider()->create())->toThrow(ServerProviderError::class);
    Http::assertSentCount($images ? 2 : 1);
})->with([false, true]);

test('vpsbg bounds image pagination even when the API keeps returning more pages', function () {
    $sequence = Http::sequence();
    for ($page = 1; $page <= 100; $page++) {
        $sequence->push(['data' => [], 'links' => ['next' => 'https://api.vpsbg.eu/v1/server-images?page='.($page + 1)]]);
    }
    Http::fake([
        'api.vpsbg.eu/v1/servers/plans' => Http::response(['data' => [$this->vpsbgPlan]]),
        'api.vpsbg.eu/v1/server-images*' => $sequence,
    ]);
    expect(fn () => $this->server->provider()->create())->toThrow(ServerProviderError::class, 'too many image pages');
    Http::assertSentCount(101);
});

test('vpsbg will not delete without the remote hostname confirmation', function () {
    $this->server->jsonUpdate('provider_data', 'vpsbg_id', 77);
    Http::fake(['api.vpsbg.eu/v1/servers/77' => Http::response(['id' => 77, 'hostname' => null])]);
    expect(fn () => app(DeleteServer::class)->delete($this->server, [
        'name' => $this->server->name, 'delete_from_provider' => true,
    ]))->toThrow(ServerProviderError::class, 'hostname');
    $this->assertDatabaseHas('servers', ['id' => $this->server->id]);
    Http::assertSentCount(1);
});
