<?php

namespace App\Controllers\Api;

use App\Models\UserModel;

class AuthController extends ApiController
{
    public function login()
    {
        $input      = $this->input();
        $employeeId = filter_var($input['employee_id'] ?? null, FILTER_VALIDATE_INT);
        $password   = $input['password'] ?? null;

        if ($employeeId === false || $employeeId < 1 || ! is_string($password) || $password === '') {
            return $this->error('validation_error', 'Employee ID and password are required.', 422);
        }

        $user = service('credentials')->attempt((int) $employeeId, $password);
        if ($user === null) {
            return $this->error('invalid_credentials', 'The supplied credentials are invalid.', 401);
        }

        $issued = service('tokenService')->issue((int) $employeeId, 'Greyhound API login');
        $actor  = service('tokenService')->publicUser($user);
        service('audit')->record($actor, $this->request, 'auth', 'LOGIN', 'user', (int) $employeeId, 'Authenticated through the API.');

        return $this->success([
            'access_token' => $issued['plainTextToken'],
            'token_type'   => 'Bearer',
            'expires_at'   => $issued['expiresAt'],
            'user'         => $actor,
        ]);
    }

    public function me()
    {
        return $this->success($this->actor());
    }

    public function logout()
    {
        $actor = $this->actor();
        service('tokenService')->revoke($this->request->getHeaderLine('Authorization'));
        service('audit')->record($actor, $this->request, 'auth', 'LOGOUT', 'user', (int) $actor['employee_id'], 'API token revoked.');

        return $this->success(['message' => 'The API token has been revoked.']);
    }
}
