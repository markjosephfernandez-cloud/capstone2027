<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

abstract class ApiController extends BaseController
{
    /** @return array<string, mixed> */
    protected function actor(): array
    {
        return service('authContext')->actor() ?? [];
    }

    /** @param mixed $data @param array<string, mixed> $meta */
    protected function success(mixed $data = null, int $status = 200, array $meta = []): ResponseInterface
    {
        $payload = ['status' => $status, 'data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return $this->response->setStatusCode($status)->setJSON($payload);
    }

    /** @param array<string, mixed> $details */
    protected function error(string $code, string $message, int $status = 400, array $details = []): ResponseInterface
    {
        $payload = ['status' => $status, 'error' => $code, 'message' => $message];
        if ($details !== []) {
            $payload['details'] = $details;
        }

        return $this->response->setStatusCode($status)->setJSON($payload);
    }

    /** @return array<string, mixed> */
    protected function input(): array
    {
        $json = $this->request->getJSON(true);
        if (is_array($json)) {
            return $json;
        }

        $raw = $this->request->getRawInput();

        return is_array($raw) ? $raw : [];
    }

    protected function page(): int
    {
        return max(1, min(100000, (int) ($this->request->getGet('page') ?? 1)));
    }

    protected function perPage(): int
    {
        return max(1, min(100, (int) ($this->request->getGet('per_page') ?? 25)));
    }

    /** @param list<string> $roles */
    protected function requireRole(array $roles): ?ResponseInterface
    {
        if (! in_array((string) ($this->actor()['role'] ?? ''), $roles, true)) {
            return $this->error('forbidden', 'Your role is not allowed to perform this action.', 403);
        }

        return null;
    }
}
