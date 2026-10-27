<?php

namespace Vpsbg\Tests;

use App\Facades\SSH;
use App\Plugins\RegisterViews;
use App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\Plugin;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends \Tests\TestCase
{
    public function createApplication(): Application
    {
        $app = require getenv('VITO_PATH').'/bootstrap/app.php';
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app->detectEnvironment(fn (): string => 'testing');
            $app['config']->set([
                'app.env' => 'testing',
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => ':memory:',
                'cache.default' => 'array',
                'session.driver' => 'array',
                'queue.default' => 'sync',
                'mail.default' => 'array',
            ]);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        SSH::fake();
        (new Plugin)->boot();

        foreach (RegisterViews::get() as $name => $path) {
            app('view')->addNamespace($name, $path);
        }
    }
}
