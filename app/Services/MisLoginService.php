<?php

namespace App\Services;

use App\Exceptions\MisAuthenticationUnavailable;
use CodeIgniter\HTTP\CURLRequest;
use Config\Mis;
use Throwable;

/** Verifies credentials directly with MIS; no application token is required. */
class MisLoginService
{
    public function __construct(
        private readonly Mis $config = new Mis(),
        private readonly ?CURLRequest $client = null,
    ) {
    }

    public function verify(int $employeeId, #[\SensitiveParameter] string $password): bool
    {
        if ($employeeId < 1 || $password === '') {
            return false;
        }

        $url = rtrim($this->config->baseURL, '/');
        $parts = parse_url($url);
        if (trim($this->config->apiKey) === '' || preg_match('/[\r\n]/', $this->config->apiKey)
            || $parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new MisAuthenticationUnavailable();
        }

        try {
            $client = $this->client ?? \Config\Services::curlrequest([], null, null, false);
            $response = $client->post($url . '/v1/employees/login', [
                'headers' => ['API-Key' => $this->config->apiKey, 'Accept' => 'application/json'],
                'json' => ['employee_id' => (string) $employeeId, 'password' => $password],
                'http_errors' => false,
                'allow_redirects' => false,
                'verify' => true,
                'connect_timeout' => 5,
                'timeout' => 15,
            ]);
        } catch (Throwable) {
            // Do not retain an exception chain that may contain HTTP credentials.
            throw new MisAuthenticationUnavailable();
        }

        $status = $response->getStatusCode();
        $payload = json_decode((string) $response->getBody(), true);
        if (! is_array($payload)) {
            throw new MisAuthenticationUnavailable();
        }
        if (in_array($status, [400, 401, 403, 422], true) && ($payload['status'] ?? null) === 'error') {
            return false;
        }
        if ($status !== 200 || ($payload['status'] ?? null) !== 'success') {
            throw new MisAuthenticationUnavailable();
        }

        // Fail closed until the provider's authenticated identity is confirmed.
        $identity = $payload['data']['employee_id'] ?? null;
        if ((! is_string($identity) && ! is_int($identity)) || (string) $identity !== (string) $employeeId) {
            throw new MisAuthenticationUnavailable();
        }

        return true;
    }
}
