<?php

namespace Paymenter\Extensions\Servers\Virtualmin;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

#[ExtensionMeta(
    name: 'Virtualmin',
    description: 'Provision, suspend and manage Virtualmin (GPL/Pro) virtual servers directly from Paymenter.',
    version: '1.0.1',
    author: 'Custom',
    url: 'https://www.virtualmin.com',
    icon: 'https://raw.githubusercontent.com/virtualmin/virtualmin-source/master/design/virtualmin-icon.png'
)]
class Virtualmin extends Server
{
    /**
     * Global extension settings (per Virtualmin server).
     * Filled in via Admin > Extensions > Virtualmin.
     */
    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'host',
                'label' => 'Virtualmin Hostname / IP',
                'type' => 'text',
                'description' => 'e.g. https://panel.example.com or https://123.123.123.123',
                'required' => true,
                'validation' => 'url',
            ],
            [
                'name' => 'port',
                'label' => 'Port',
                'type' => 'text',
                'default' => '10000',
                'required' => true,
            ],
            [
                'name' => 'username',
                'label' => 'Master Admin Username',
                'type' => 'text',
                'description' => 'A Webmin/Virtualmin user with Remote API access enabled (Webmin Users > Module Access Control > Remote API).',
                'required' => true,
            ],
            [
                'name' => 'password',
                'label' => 'Master Admin Password',
                'type' => 'password',
                'required' => true,
            ],
            [
                'name' => 'verify_ssl',
                'label' => 'Verify SSL Certificate',
                'type' => 'checkbox',
                'default' => true,
                'description' => 'Turn this off only if you use a self-signed certificate.',
            ],
        ];
    }

    /**
     * Per-product settings, e.g. quota/bandwidth/features for this hosting plan.
     */
    public function getProductConfig($values = [])
    {
        return [
            [
                'name' => 'plan',
                'label' => 'Virtualmin Account Plan (optional)',
                'type' => 'text',
                'description' => 'Name of an existing Account Plan in Virtualmin. Leave empty to use the fields below instead.',
            ],
            [
                'name' => 'quota',
                'label' => 'Disk Quota (MB)',
                'type' => 'text',
                'default' => '1024',
                'description' => 'Ignored if an Account Plan is set above.',
            ],
            [
                'name' => 'bandwidth',
                'label' => 'Bandwidth Limit (MB/month)',
                'type' => 'text',
                'default' => '10240',
                'description' => 'Ignored if an Account Plan is set above.',
            ],
            [
                'name' => 'features',
                'label' => 'Enabled Features',
                'type' => 'select',
                'multiple' => true,
                'default' => ['web', 'dns', 'mail', 'mysql', 'ssl'],
                'options' => [
                    'web' => 'Website (Apache/Nginx)',
                    'dns' => 'DNS Domain',
                    'mail' => 'Mail Domain',
                    'mysql' => 'MySQL Database',
                    'ssl' => 'SSL Website',
                    'webmin' => 'Webmin Login for this user',
                ],
            ],
        ];
    }

    /**
     * Fields the customer fills in at checkout.
     */
    public function getCheckoutConfig()
    {
        return [
            [
                'name' => 'domain',
                'type' => 'text',
                'label' => 'Domain',
                'required' => true,
                'placeholder' => 'domain.com',
            ],
        ];
    }

    /**
     * Create a new virtual server (domain) in Virtualmin.
     */
    public function createServer(Service $service, $settings, $properties)
    {
        $domain = $properties['domain'] ?? null;

        if (!$domain) {
            throw new \Exception('No domain was provided for this service.');
        }

        $username = $this->generateUsername($domain);
        $password = Str::password(16, symbols: false);

        $params = [
            'program' => 'create-domain',
            'domain' => $domain,
            'user' => $username,
            'pass' => $password,
            'json' => 1,
        ];

        if (!empty($settings['plan'])) {
            $params['plan'] = $settings['plan'];
        } else {
            // Virtualmin's create-domain API requires BOTH quota (domain/group
            // quota) and uquota (owning user's mail/home quota) to be sent
            // together — sending only "quota" makes Virtualmin respond with
            // "No quota specified" even though a quota value was provided.
            $quota = !empty($settings['quota']) ? $settings['quota'] : 1024;
            $params['quota'] = $quota;
            $params['uquota'] = $quota;
            $params['bandwidth'] = !empty($settings['bandwidth']) ? $settings['bandwidth'] : 10240;
        }

        $features = $this->normalizeFeatures($settings['features'] ?? null);
        // 'unix' (create the system user) and 'dir' (create the home directory)
        // are required for the domain to actually be usable, regardless of
        // which optional features are picked.
        $flags = array_unique(array_merge(['unix', 'dir'], $features));

        $response = $this->apiCall($params, $flags);

        if (($response['status'] ?? null) !== 'success') {
            throw new \Exception('Virtualmin: failed to create domain "' . $domain . '": ' . ($response['error'] ?? 'unknown error'));
        }

        // Persist generated credentials on the service so getActions()/customer can see them
        $service->properties()->updateOrCreate(['key' => 'domain'], ['value' => $domain]);
        $service->properties()->updateOrCreate(['key' => 'username'], ['value' => $username]);
        $service->properties()->updateOrCreate(['key' => 'password'], ['value' => $password]);

        return true;
    }

    /**
     * Suspend (disable) a domain.
     */
    public function suspendServer(Service $service, $settings, $properties)
    {
        $domain = $properties['domain'] ?? null;

        $response = $this->apiCall([
            'program' => 'disable-domain',
            'domain' => $domain,
            'json' => 1,
        ]);

        if (($response['status'] ?? null) !== 'success') {
            throw new \Exception('Virtualmin: failed to suspend domain "' . $domain . '": ' . ($response['error'] ?? 'unknown error'));
        }

        return true;
    }

    /**
     * Unsuspend (re-enable) a domain.
     */
    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $domain = $properties['domain'] ?? null;

        $response = $this->apiCall([
            'program' => 'enable-domain',
            'domain' => $domain,
            'json' => 1,
        ]);

        if (($response['status'] ?? null) !== 'success') {
            throw new \Exception('Virtualmin: failed to unsuspend domain "' . $domain . '": ' . ($response['error'] ?? 'unknown error'));
        }

        return true;
    }

    /**
     * Change plan / quota / bandwidth when the customer upgrades or downgrades.
     */
    public function upgradeServer(Service $service, $settings, $properties)
    {
        $domain = $properties['domain'] ?? null;

        $params = [
            'program' => 'modify-domain',
            'domain' => $domain,
            'json' => 1,
        ];

        if (!empty($settings['plan'])) {
            $params['plan'] = $settings['plan'];
        } else {
            // Same rule applies here as in createServer(): quota and uquota
            // must be sent together or Virtualmin rejects the request.
            $quota = !empty($settings['quota']) ? $settings['quota'] : 1024;
            $params['quota'] = $quota;
            $params['uquota'] = $quota;
            $params['bandwidth'] = !empty($settings['bandwidth']) ? $settings['bandwidth'] : 10240;
        }

        $response = $this->apiCall($params);

        if (($response['status'] ?? null) !== 'success') {
            throw new \Exception('Virtualmin: failed to upgrade domain "' . $domain . '": ' . ($response['error'] ?? 'unknown error'));
        }

        return true;
    }

    /**
     * Delete the domain entirely.
     */
    public function terminateServer(Service $service, $settings, $properties)
    {
        $domain = $properties['domain'] ?? null;

        if (!$domain) {
            // Nothing to terminate
            return true;
        }

        $response = $this->apiCall([
            'program' => 'delete-domain',
            'domain' => $domain,
            'json' => 1,
        ]);

        if (($response['status'] ?? null) !== 'success') {
            throw new \Exception('Virtualmin: failed to terminate domain "' . $domain . '": ' . ($response['error'] ?? 'unknown error'));
        }

        return true;
    }

    /**
     * Buttons/info shown on the service page in the client area.
     */
    public function getActions(Service $service, $settings, $properties)
    {
        return [
            [
                'text' => $properties['domain'] ?? '-',
                'label' => 'Domain',
                'type' => 'text',
            ],
            [
                'text' => $properties['username'] ?? '-',
                'label' => 'Username',
                'type' => 'text',
            ],
            [
                'text' => $properties['password'] ?? '-',
                'label' => 'Password',
                'type' => 'text',
            ],
            [
                'name' => 'control_panel',
                'label' => 'Login to Virtualmin',
                'url' => $this->getPanelUrl(),
                'type' => 'button',
            ],
        ];
    }

    /**
     * Paymenter can store multi-select product config values as a real PHP
     * array, a JSON-encoded string, or a comma-separated string depending on
     * version/DB layer — normalize all of those into a plain array here.
     */
    protected function normalizeFeatures($value): array
    {
        $default = ['web', 'dns', 'mail', 'mysql', 'ssl'];

        if (empty($value)) {
            return $default;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            return array_filter(array_map('trim', explode(',', $value)));
        }

        return $default;
    }

    /**
     * Build the panel login URL from the server config, without a redundant
     * ":443" for setups (e.g. Cloudflare Tunnel) that already serve on 443.
     */
    protected function getPanelUrl(): string
    {
        $config = $this->config;
        $host = rtrim($config['host'] ?? '', '/');
        $port = $config['port'] ?? 10000;

        return ((string) $port === '443') ? $host : $host . ':' . $port;
    }

    /**
     * Small helper to generate a valid, unique-ish unix username from a domain.
     */
    protected function generateUsername(string $domain): string
    {
        $base = Str::of($domain)
            ->before('.')
            ->lower()
            ->replaceMatches('/[^a-z0-9]/', '')
            ->substr(0, 8);

        if ($base === '' || is_numeric($base[0])) {
            $base = 'u' . $base;
        }

        return (string) $base . Str::lower(Str::random(3));
    }

    /**
     * Perform a call against the Virtualmin Remote API (remote.cgi).
     *
     * Docs: https://webmin.com/docs/api/remote/ (Virtualmin uses the same
     * remote.cgi mechanism as Webmin, one parameter per create-domain.pl option).
     */
    protected function apiCall(array $params, array $flags = []): array
    {
        $config = $this->config;
        $port = $config['port'] ?? 10000;
        $host = rtrim($config['host'], '/');

        // Don't append the port if it's already the standard HTTPS port,
        // since some hosts (e.g. behind Cloudflare Tunnel) route via 443
        // and the URL shouldn't have ":443" tacked on.
        $url = ((string) $port === '443') ? $host : $host . ':' . $port;
        $url .= '/virtual-server/remote.cgi';

        // Virtualmin's remote.cgi mirrors the underlying command-line scripts,
        // where boolean options (e.g. --dns, --mail, --web) are just present
        // or absent — they don't take a "=1" value. Passing them as key=1
        // makes Virtualmin choke with "Unknown parameter 1", so they're
        // appended to the query string as bare flags instead.
        $query = http_build_query($params);
        foreach ($flags as $flag) {
            $query .= '&' . rawurlencode($flag);
        }

        $url .= '?' . $query;

        try {
            $response = Http::withBasicAuth($config['username'], $config['password'])
                ->withOptions([
                    'verify' => (bool) ($config['verify_ssl'] ?? true),
                    // The Docker container has no IPv6 route out, and Guzzle's
                    // curl handler can hang for the full timeout trying an
                    // unreachable IPv6 address before falling back to IPv4
                    // (unlike the curl CLI, which fails over almost instantly).
                    // Forcing IPv4 resolution avoids that multi-second stall.
                    'curl' => [
                        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    ],
                ])
                ->timeout(30)
                ->get($url);
        } catch (\Exception $e) {
            Log::error('Virtualmin API connection error: ' . $e->getMessage());
            throw new \Exception('Could not connect to Virtualmin: ' . $e->getMessage());
        }

        if (!$response->successful()) {
            Log::error('Virtualmin API HTTP error: ' . $response->status() . ' - ' . $response->body());
            throw new \Exception('Virtualmin API returned HTTP ' . $response->status());
        }

        $data = $response->json();

        if (!is_array($data)) {
            // Some Virtualmin versions return a JSON array wrapped differently,
            // fall back to raw body for debugging.
            Log::error('Virtualmin API unexpected response: ' . $response->body());
            throw new \Exception('Unexpected response from Virtualmin API.');
        }

        return $data;
    }
}