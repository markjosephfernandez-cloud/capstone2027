<?php

namespace App\Services;

use App\Models\ApiTokenModel;
use App\Models\UserModel;
use CodeIgniter\I18n\Time;

/** Issues hashed bearer tokens and resolves them to active accounts. */
class TokenService
{
    public function __construct(
        private readonly ApiTokenModel $tokens = new ApiTokenModel(),
        private readonly UserModel $users = new UserModel(),
    ) {
    }

    /** @return array{plainTextToken: string, expiresAt: string} */
    public function issue(int $employeeId, string $name = 'API login'): array
    {
        $plainTextToken = bin2hex(random_bytes(32));
        $expiresAt      = Time::now('UTC')->addHours(max(1, (int) env('greyhound.tokenHours', 12)));

        $this->tokens->insert([
            'user_employee_id' => $employeeId,
            'token_hash'       => hash('sha256', $plainTextToken),
            'token_name'       => mb_substr($name, 0, 100),
            'expires_at'       => $expiresAt->toDateTimeString(),
            'created_at'       => Time::now('UTC')->toDateTimeString(),
        ]);

        return [
            'plainTextToken' => $plainTextToken,
            'expiresAt'      => $expiresAt->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function authenticateBearer(string $authorization): ?array
    {
        if (preg_match('/^Bearer\\s+(.+)$/i', trim($authorization), $matches) !== 1) {
            return null;
        }

        $token = trim($matches[1]);
        if ($token === '' || strlen($token) > 512) {
            return null;
        }

        $record = $this->tokens
            ->where('token_hash', hash('sha256', $token))
            ->where('revoked_at', null)
            ->first();

        if ($record === null || ($record['expires_at'] !== null && strtotime((string) $record['expires_at']) <= time())) {
            return null;
        }

        $user = $this->users->find((int) $record['user_employee_id']);
        if ($user === null || (isset($user['is_active']) && (int) $user['is_active'] !== 1)) {
            return null;
        }

        $this->tokens->update($record['id'], ['last_used_at' => Time::now('UTC')->toDateTimeString()]);

        return $this->publicUser($user);
    }

    public function revoke(string $authorization): void
    {
        if (preg_match('/^Bearer\\s+(.+)$/i', trim($authorization), $matches) !== 1) {
            return;
        }

        $record = $this->tokens->where('token_hash', hash('sha256', trim($matches[1])))->first();
        if ($record !== null) {
            $this->tokens->update($record['id'], ['revoked_at' => Time::now('UTC')->toDateTimeString()]);
        }
    }

    /**
     * Whitelists identity fields so credential data never enters API responses.
     *
     * @param array<string, mixed> $user
     * @return array{employee_id: int, name: mixed, department: mixed, position: mixed, email_address: mixed, role: mixed}
     */
    public function publicUser(array $user): array
    {
        return [
            'employee_id'   => (int) $user['employee_id'],
            'name'          => $user['name'],
            'department'    => $user['department'],
            'position'      => $user['position'],
            'email_address' => $user['email_address'],
            'role'          => $user['role'] ?? 'guest',
        ];
    }
}
