# VPSBG for VitoDeploy

A [VitoDeploy](https://vitodeploy.com) server-provider plugin for [VPSBG](https://www.vpsbg.eu), compatible with Vito 4.2.1 and newer 4.x releases and PHP 8.4+.

## Install

1. In Vito, open **Admin → Plugins** and install from `https://github.com/dyanakiev/vito-vpsbg-plugin`.
2. Enable **VPSBG** in the Installed tab.
3. In **Settings → Server Providers**, connect VPSBG with a [VPSBG API token](https://docs.vpsbg.eu/api/authentication) that has read and write abilities.

The repository publishes GitHub releases for Vito's installer. Restart long-running queue workers after installing or updating the plugin.

## Create a server

Select the VPSBG connection, **Sofia, Bulgaria**, a plan, and an Ubuntu version. Available Ubuntu images and plans are loaded from the VPSBG API. Unavailable plans and end-of-life images cannot be provisioned.

Choose a billing cycle of **1, 3, 6, 12, or 24 months** on the server creation form. The default is one month, subject to the plan's minimum cycle. Creating a server charges the VPSBG account balance or saved card. If payment fails, check your balance, card, and unpaid orders in the VPSBG Console before retrying.

Vito imports a separate SSH key for each server. The hostname is derived from the server name and prefixed with the Vito server ID. Readiness checks wait for a public IPv4 address, SSH access, cloud-init, and package-manager locks before starting Vito's installation.

Boot readiness is checked through the SSH queue every 30 seconds for up to 30 minutes. Slow first-boot tasks and interrupted SSH checks are retried automatically without creating another VPS or order. The server stays **Installing** while waiting, and installation resumes once boot completes. Reported cloud-init errors and API authentication failures stop the checks; exhausting the window marks installation as failed. Automatic retries only cover initial boot and do not restart a partially completed installation.

## Disable or uninstall

Disabling or uninstalling is blocked while Vito still manages VPSBG servers. Remove those servers from Vito first; leave **delete from provider** disabled if you want to keep them at VPSBG. The plugin does not delete saved provider connections or credentials during its lifecycle.

## API

The plugin uses Vito's existing, project-scoped API endpoints. It adds no routes. Connect a provider using `provider: "vpsbg"` and `token`; create servers with `provider: "vpsbg"`, the saved `server_provider` ID, `region: "sofia"`, `plan`, `os`, and optional `billing_cycle`.

VPSBG API documentation: <https://docs.vpsbg.eu/api>.

## Development

The plugin follows Vito's [plugin template](https://github.com/vitodeploy/plugin-template). Its namespace and installation path are tied to the repository owner and name:

```text
App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin
app/Vito/Plugins/Dyanakiev/VitoVpsbgPlugin
```

For local development, copy the repository into that directory in a Vito checkout, then discover, install, and enable it through **Admin → Plugins**. Keep the plugin's Git checkout elsewhere if you plan to update it through Vito, because an update replaces the installed directory.

Run the plugin tests against a separate Vito checkout with its Composer dependencies installed:

```sh
VITO_PATH=/absolute/path/to/vito /absolute/path/to/vito/vendor/bin/pest --configuration phpunit.xml
```

The test harness uses an in-memory database and fakes HTTP and SSH. CI runs against Vito 4.2.1 and verifies delayed boot retries with an isolated Redis queue. Local runs skip the Redis integration check unless `VPSBG_TEST_REDIS=1` is set and Redis is available.

## License

AGPL-3.0, matching VitoDeploy. See [LICENSE](LICENSE).
