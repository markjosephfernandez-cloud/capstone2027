<?php

namespace App\Services;

use App\Models\UserModel;
use Config\Mis;

/** Uses the configured identity provider, retaining local account permissions. */
class CredentialsService
{
    public function __construct(
        private readonly UserModel $users = new UserModel(),
        private readonly Mis $mis = new Mis(),
        private readonly ?MisLoginService $misLogin = null,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function attempt(int $employeeId, #[\SensitiveParameter] string $password): ?array
    {
        if ($employeeId < 1 || $password === '') {
            return null;
        }
        $user = $this->users->find($employeeId);
        if ($user === null || (isset($user['is_active']) && (int) $user['is_active'] !== 1)) {
            return null;
        }

        if ($this->mis->loginEnabled) {
            // Never fall back to a local password when MIS rejects or is unavailable.
            return ($this->misLogin ?? new MisLoginService($this->mis))->verify($employeeId, $password)
                ? $user : null;
        }

        if (! $this->matches($password, $user)) {
            return null;
        }

        if (empty($user['password_hash']) && ! empty($user['password'])) {
            $this->users->update($employeeId, ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            $user['password_hash'] = 'upgraded';
        }

        return $user;
    }

    /** @param array<string, mixed> $user */
    private function matches(string $password, array $user): bool
    {
        $hash = (string) ($user['password_hash'] ?? '');
        if ($hash !== '') {
            return password_verify($password, $hash);
        }

        $legacy = (string) ($user['password'] ?? '');
        if (str_starts_with($legacy, '$')) {
            return password_verify($password, $legacy);
        }

        return $legacy !== '' && hash_equals($legacy, $password);
    }
}
