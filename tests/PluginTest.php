<?php

use App\Enums\ServerStatus;
use App\Models\ServerProvider;
use App\Plugins\RegisterViews;
use App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\Plugin;
use App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\ServerProviders\VPSBG;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('plugin lifecycle registers its provider and views without replacing existing profiles', function () {
    $profile = ServerProvider::factory()->create(['provider' => 'vpsbg', 'credentials' => ['token' => 'private-token']]);
    $plugin = new Plugin;
    $plugin->install();
    $plugin->enable();
    $plugin->boot();

    expect($plugin->getName())->toBe('VPSBG')->and($plugin->getDescription())->toContain('VPSBG')
        ->and(config('server-provider.providers.vpsbg.handler'))->toBe(VPSBG::class)
        ->and(config('server-provider.providers.vpsbg.provision_timeout'))->toBe(600)
        ->and(RegisterViews::get()['vpsbg'])->toBe(dirname(__DIR__).'/resources/views')
        ->and($profile->fresh()->provider())->toBeInstanceOf(VPSBG::class)
        ->and($profile->fresh()->credentials)->toBe(['token' => 'private-token']);
    $this->assertDatabaseHas('server_providers', ['id' => $profile->id, 'provider' => 'vpsbg']);
});

test('disabling or uninstalling an unused plugin preserves saved credentials', function (string $hook) {
    $profile = ServerProvider::factory()->create(['provider' => 'vpsbg', 'credentials' => ['token' => 'private-token']]);

    (new Plugin)->{$hook}();

    expect($profile->fresh()->credentials)->toBe(['token' => 'private-token']);
    $this->assertDatabaseHas('server_providers', ['id' => $profile->id]);
})->with(['disable', 'uninstall']);

test('plugin cannot be disabled or uninstalled while any VPSBG server is managed', function (string $hook, ServerStatus $status) {
    $this->server->update(['provider' => 'vpsbg', 'status' => $status]);

    expect(fn () => (new Plugin)->{$hook}())->toThrow(RuntimeException::class, 'Remove VPSBG servers from Vito before disabling or uninstalling this plugin.');
    $this->assertDatabaseHas('servers', ['id' => $this->server->id, 'provider' => 'vpsbg']);
})->with(['disable', 'uninstall'])->with([ServerStatus::READY, ServerStatus::INSTALLATION_FAILED]);
