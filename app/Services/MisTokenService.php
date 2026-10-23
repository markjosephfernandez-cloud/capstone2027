<?php

namespace App\Services;

use CodeIgniter\HTTP\CURLRequest;
use Config\Mis;
use RuntimeException;
use Throwable;

/** Application credentials only: MIS tokens do not identify a Greyhound user. */
class MisTokenService
{
    public function __construct(
        private readonly Mis $config = new Mis(),
        private readonly ?CURLRequest $client = null,
    ) {
    }

    public function generate(): string
    {
        $data = $this->request('generate');

        return $this->token($data, 'refresh_token');
    }

    /** @return array{access_token: string, refresh_token: string, expires_at: string} */
    public function refresh(string $refreshToken): array
    {
        if (trim($refreshToken) === '' || preg_match('/[\r\n]/', $refreshToken)) {
            throw new RuntimeException('A valid MIS refresh token is required.');
        }

        $data = $this->request('refresh', $refreshToken);
        $expiresAt = $data['expires_at'] ?? null;
        if (! is_string($expiresAt) || strtotime($expiresAt) === false) {
            throw new RuntimeException('MIS returned an invalid token expiry.');
        }

        return [
            'access_token' => $this->token($data, 'access_token'),
            'refresh_token' => $this->token($data, 'refresh_token'),
            'expires_at' => $expiresAt,
        ];
    }

    /** Obtain the initial application token pair, without creating a user session.
     * @return array{access_token: string, refresh_token: string, expires_at: string}
     */
    public function issue(): array
    {
        return $this->refresh($this->generate());
    }

    private function request(string $operation, ?string $refreshToken = null): array
    {
        if (trim($this->config->apiKey) === '' || preg_match('/[\r\n]/', $this->config->apiKey)) {
            throw new RuntimeException('The MIS API key is not configured correctly.');
        }
        $url = rtrim($this->config->baseURL, '/');
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('MIS requires an HTTPS API base URL.');
        }

        $headers = ['API-Key' => $this->config->apiKey, 'Accept' => 'application/json'];
        if ($refreshToken !== null) {
            $headers['Authorization'] = 'Bearer ' . $refreshToken;
        }

        try {
            // Use a fresh client by default so Authorization cannot leak between requests.
            $client = $this->client ?? \Config\Services::curlrequest([], null, null, false);
            $response = $client->post($url . '/tokens/' . $operation, [
                'headers' => $headers,
                'body' => '',
                'http_errors' => false,
                'allow_redirects' => false,
                'verify' => true,
                'connect_timeout' => 5,
                'timeout' => 15,
            ]);
        } catch (Throwable) {
            // Never propagate provider exceptions containing credentials or response bodies.
            throw new RuntimeException('Unable to connect to MIS.');
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException('MIS token request failed (HTTP ' . $response->getStatusCode() . ').');
        }
        $data = json_decode((string) $response->getBody(), true);
        if (! is_array($data)) {
            throw new RuntimeException('MIS returned an invalid token response.');
        }

        // The sandbox wraps the documented token fields in a data object.
        if (array_key_exists('data', $data)) {
            if (! is_array($data['data'])) {
                throw new RuntimeException('MIS returned an invalid token response.');
            }
            return $data['data'];
        }

        return $data;
    }

    private function token(array $data, string $name): string
    {
        $value = $data[$name] ?? null;
        if (! is_string($value) || trim($value) === '' || preg_match('/[\r\n]/', $value)) {
            throw new RuntimeException('MIS returned an invalid ' . $name . '.');
        }

        return $value;
    }
}
