<?php

namespace App\Vito\Plugins\Dyanakiev\VitoVpsbgPlugin\ServerProviders;

use App\Exceptions\ServerProviderError;
use App\Exceptions\SSHCommandError;
use App\Exceptions\SSHConnectionError;
use App\ServerProviders\AbstractProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use SensitiveParameter;

class VPSBG extends AbstractProvider
{
    private const API_URL = 'https://api.vpsbg.eu/v1';

    public static function id(): string
    {
        return 'vpsbg';
    }

    public function createRules(array $input): array
    {
        return [
            'plan' => ['required', 'string'],
            'region' => ['required', Rule::in(array_keys($this->regions()))],
            'billing_cycle' => ['sometimes', 'required', Rule::in(['1', '3', '6', '12', '24'])],
        ];
    }

    public function credentialValidationRules(array $input): array
    {
        return ['token' => ['required', 'string']];
    }

    public function credentialData(#[SensitiveParameter] array $input): array
    {
        return ['token' => $input['token']];
    }

    public function data(array $input): array
    {
        return [
            'plan' => $input['plan'],
            'region' => $input['region'],
            'billing_cycle' => (string) ($input['billing_cycle'] ?? '1'),
        ];
    }

    public function connect(#[SensitiveParameter] array $credentials): bool
    {
        $this->request('GET', '/user', credentials: $credentials);

        return true;
    }

    public function regions(): array
    {
        return ['sofia' => 'Sofia, Bulgaria'];
    }

    public function plans(?string $region): array
    {
        if ($region !== 'sofia') {
            return [];
        }

        return collect($this->catalogue())
            ->mapWithKeys(fn (array $plan): array => [
                $plan['key'] => ['label' => $plan['name'], 'available' => $plan['available']],
            ])
            ->all();
    }

    public function create(): void
    {
        $plan = collect($this->catalogue())->firstWhere('key', $this->server->provider_data['plan']);
        $cycle = $this->server->provider_data['billing_cycle'] ?? '1';

        if (! ($plan['available'] ?? false)) {
            throw new ServerProviderError('The selected VPSBG plan is unavailable.');
        }

        if ((int) $cycle < (int) $plan['minimum_billing_cycle']) {
            throw new ServerProviderError('The selected VPSBG plan requires a longer billing cycle.');
        }

        $image = $this->image();
        $this->generateKeyPair();
        $key = $this->request('POST', '/ssh-keys', [
            'name' => 'vito-'.$this->server->id.'-key',
            'key' => $this->server->sshKey()['public_key'],
        ])->json('id');

        if (! is_int($key) || $key < 1) {
            throw new ServerProviderError('VPSBG did not return an SSH key ID.');
        }

        $this->server->jsonUpdate('provider_data', 'ssh_key_id', $key);
        $id = $this->request('POST', '/servers', [
            'name' => rtrim(Str::limit(Str::slug('vito-'.$this->server->id.'-'.$this->server->name), 50, ''), '-'),
            'plan' => $this->server->provider_data['plan'],
            'billing_cycle' => (string) $cycle,
            'image_id' => $image,
            'ssh_keys' => [$key],
        ])->json('data.0.id');

        if (! is_int($id) || $id < 1) {
            throw new ServerProviderError('VPSBG did not return a server ID.');
        }

        $this->server->jsonUpdate('provider_data', 'vpsbg_id', $id);
    }

    public function isRunning(): bool
    {
        if (! isset($this->server->provider_data['vpsbg_id'])) {
            return false;
        }

        $response = $this->request('GET', '/servers/'.$this->server->provider_data['vpsbg_id'], allowedStatuses: [404]);

        if ($response->notFound() || $response->json('status') !== 'active') {
            return false;
        }

        $address = collect($response->json('ip_addresses', []))
            ->filter(fn (array $ip): bool => ($ip['version'] ?? null) === 4
                && ($ip['type'] ?? null) === 'public'
                && filter_var($ip['ip_address'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
            ->sortByDesc('main')
            ->first();

        if ($address === null) {
            return false;
        }

        $this->server->update(['ip' => $address['ip_address']]);

        if (! ($this->server->provider_data['boot_ready'] ?? false)) {
            try {
                $state = $this->server->ssh()->exec(view('vpsbg::ssh.boot-ready'), timeout: 15);
            } catch (SSHConnectionError|SSHCommandError) {
                return false;
            }

            if (trim($state) === 'VITO_VPSBG_BOOT_ERROR') {
                throw new ServerProviderError('VPSBG cloud-init reported an error. Check its status in the VPSBG Console.');
            }

            if (trim($state) !== 'VITO_VPSBG_BOOT_READY') {
                return false;
            }

            $this->server->jsonUpdate('provider_data', 'boot_ready', true);
        }

        return true;
    }

    public function delete(): void
    {
        if (isset($this->server->provider_data['vpsbg_id'])) {
            $path = '/servers/'.$this->server->provider_data['vpsbg_id'];
            $response = $this->request('GET', $path, allowedStatuses: [404]);

            if (! $response->notFound()) {
                $hostname = $response->json('hostname');

                if (! is_string($hostname) || $hostname === '') {
                    throw new ServerProviderError('VPSBG did not return the hostname required to delete the server.');
                }

                $this->request('DELETE', $path, [
                    'hostname' => $hostname,
                    'reason' => 'Removed from Vito',
                ], allowedStatuses: [404, 409]);
            }
        }

        if (isset($this->server->provider_data['ssh_key_id'])) {
            $this->request('DELETE', '/ssh-keys/'.$this->server->provider_data['ssh_key_id'], allowedStatuses: [404]);
        }
    }

    /**
     * @return array<int, array{key: string, name: string, available: bool, minimum_billing_cycle: string}>
     */
    private function catalogue(): array
    {
        $plans = $this->request('GET', '/servers/plans')->json('data');

        if (! is_array($plans)) {
            throw new ServerProviderError('VPSBG did not return its server plans.');
        }

        return $plans;
    }

    private function image(): int
    {
        $path = '/server-images';
        $parameters = ['per_page' => 50, 'filter' => ['group' => 'Ubuntu', 'virtualization' => 'kvm', 'adapter' => '2']];
        $seen = [];

        for ($page = 0; $page < 100; $page++) {
            $response = $this->request('GET', $path, $parameters);
            $images = $response->json('data');

            if (! is_array($images)) {
                throw new ServerProviderError('VPSBG did not return its server images.');
            }

            foreach ($images as $image) {
                if (($image['name'] ?? '') === 'Ubuntu '.$this->server->os->getVersion()
                    && ($image['eol'] ?? true) === false
                    && ($image['ssh_keys_support'] ?? false) === true
                    && is_int($image['id'] ?? null) && $image['id'] > 0) {
                    return $image['id'];
                }
            }

            $next = $response->json('links.next');

            if ($next === null) {
                throw new ServerProviderError('The selected Ubuntu version is unavailable on VPSBG.');
            }

            if (! is_string($next) || ! str_starts_with($next, self::API_URL.'/server-images?') || isset($seen[$next])) {
                throw new ServerProviderError('VPSBG returned an invalid image pagination link.');
            }

            $seen[$next] = true;
            $path = substr($next, strlen(self::API_URL));
            $parameters = [];
        }

        throw new ServerProviderError('VPSBG returned too many image pages.');
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>|null  $credentials
     * @param  array<int, int>  $allowedStatuses
     */
    private function request(string $method, string $path, #[SensitiveParameter] array $parameters = [], #[SensitiveParameter] ?array $credentials = null, array $allowedStatuses = []): Response
    {
        try {
            $response = Http::acceptJson()
                ->withToken(($credentials ?? $this->serverProvider->getCredentials())['token'])
                ->withoutRedirecting()
                ->timeout(30)
                ->send($method, self::API_URL.$path, $parameters === [] ? [] : [
                    in_array($method, ['GET', 'DELETE'], true) ? 'query' : 'json' => $parameters,
                ]);
        } catch (ConnectionException) {
            throw new ServerProviderError('Could not connect to VPSBG.');
        }

        if (! $response->successful() && ! in_array($response->status(), $allowedStatuses, true)) {
            if ($method === 'POST' && $path === '/servers' && $response->status() === 400) {
                throw new ServerProviderError('VPSBG could not complete payment for this server. Check your account balance, saved card and unpaid orders in the VPSBG Console.');
            }

            throw new ServerProviderError('VPSBG could not complete the request (HTTP '.$response->status().').');
        }

        return $response;
    }
}
