<?php

namespace App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin;

use App\Actions\Server\InstallServer as CoreInstallServer;
use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Plugins\AbstractPlugin;
use App\Plugins\RegisterServerProvider;
use App\Plugins\RegisterViews;
use App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\Actions\EnsureProviderUnused;
use App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\Actions\InstallServer;
use App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\ServerProviders\VPSBG;

class Plugin extends AbstractPlugin
{
    protected string $name = 'VPSBG';

    protected string $description = 'Provision and manage VPSBG servers from VitoDeploy.';

    public function boot(): void
    {
        app()->extend(CoreInstallServer::class, fn (CoreInstallServer $installer): InstallServer => new InstallServer($installer));

        RegisterViews::make('vpsbg')->path(__DIR__.'/resources/views')->register();

        RegisterServerProvider::make(VPSBG::id())
            ->label('VPSBG')
            ->handler(VPSBG::class)
            ->form(DynamicForm::make([
                DynamicField::make('token')
                    ->password()
                    ->label('API Token')
                    ->description('Create a token with read and write access in the VPSBG Console.')
                    ->link('API token setup', 'https://docs.vpsbg.eu/api/authentication'),
            ]))
            ->createForm(DynamicForm::make([
                DynamicField::make('billing_cycle')
                    ->select()
                    ->label('Billing cycle (months)')
                    ->options(['1', '3', '6', '12', '24'])
                    ->default('1'),
            ]))
            ->defaultUser('root')
            ->provisionTimeout(600)
            ->register();
    }

    public function disable(): void
    {
        app(EnsureProviderUnused::class)->handle();
    }

    public function uninstall(): void
    {
        app(EnsureProviderUnused::class)->handle();
    }
}
