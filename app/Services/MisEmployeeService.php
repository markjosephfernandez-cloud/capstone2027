<?php

namespace App\Services;

use CodeIgniter\HTTP\CURLRequest;
use Config\Mis;
use RuntimeException;
use Throwable;

/** Reads employee profiles; never verifies passwords or grants local permissions. */
class MisEmployeeService
{
    public function __construct(
        private readonly Mis $config = new Mis(),
        private readonly ?MisTokenService $tokens = null,
        private readonly ?CURLRequest $client = null,
    ) {
    }

    /** @return array<string, string>|null */
    public function find(int $employeeId): ?array
    {
        if ($employeeId < 1) {
            throw new RuntimeException('A valid employee ID is required.');
        }
        $url = rtrim($this->config->baseURL, '/');
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('MIS requires an HTTPS API base URL.');
        }
        $tokens = ($this->tokens ?? new MisTokenService($this->config))->issue();
        try {
            $client = $this->client ?? \Config\Services::curlrequest([], null, null, false);
            $response = $client->get($url . '/v1/employees/' . $employeeId, [
                'headers' => ['Authorization' => 'Bearer ' . $tokens['access_token'], 'Accept' => 'application/json'],
                'http_errors' => false,
                'allow_redirects' => false,
                'verify' => true,
                'connect_timeout' => 5,
                'timeout' => 15,
            ]);
        } catch (Throwable) {
            throw new RuntimeException('Unable to retrieve the MIS employee profile.');
        }
        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('MIS employee request failed (HTTP ' . $response->getStatusCode() . ').');
        }
        $payload = json_decode((string) $response->getBody(), true);
        $rows = $payload['data']['data'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('MIS returned an invalid employee response.');
        }
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1 || ! is_array($rows[0]) || (string) ($rows[0]['employee_id'] ?? '') !== (string) $employeeId) {
            throw new RuntimeException('MIS returned an unexpected employee identity.');
        }
        $employee = [];
        foreach (['employee_id', 'first_name', 'middle_name', 'last_name', 'position', 'department_code', 'department_name'] as $field) {
            $value = $rows[0][$field] ?? '';
            if (! is_string($value) && ! is_int($value)) {
                throw new RuntimeException('MIS returned an invalid employee field.');
            }
            $employee[$field] = trim((string) $value);
        }

        return $employee;
    }
}
