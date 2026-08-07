<?php

namespace App\Controllers\Api;

use App\Models\UserModel;
use CodeIgniter\I18n\Time;
use RuntimeException;

class MobileProfileController extends ApiController
{
    public function show()
    {
        return $this->success($this->payload((int) $this->actor()['employee_id']));
    }

    public function update()
    {
        $input = $this->input();
        $id = (int) $this->actor()['employee_id'];
        $users = new UserModel();
        $userChanges = [];
        foreach (['name', 'email_address'] as $field) {
            if (array_key_exists($field, $input)) $userChanges[$field] = trim((string) $input[$field]);
        }
        if (($userChanges['name'] ?? 'x') === '') return $this->error('invalid_name', 'Name cannot be empty.', 422);
        if (isset($userChanges['email_address']) && ! filter_var($userChanges['email_address'], FILTER_VALIDATE_EMAIL)) return $this->error('invalid_email', 'Enter a valid email address.', 422);
        if (! empty($input['password'])) {
            if (strlen((string) $input['password']) < 8) return $this->error('invalid_password', 'Password must contain at least 8 characters.', 422);
            $userChanges['password_hash'] = password_hash((string) $input['password'], PASSWORD_DEFAULT);
        }
        if ($userChanges) $users->update($id, $userChanges + ['updated_at' => Time::now('UTC')->toDateTimeString()]);

        $username = trim((string) ($input['username'] ?? ''));
        if ($username !== '' && ! preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) return $this->error('invalid_username', 'Username must be 3-100 letters, numbers, dots, dashes, or underscores.', 422);
        $profile = ['user_employee_id' => $id, 'updated_at' => Time::now('UTC')->toDateTimeString()];
        if (array_key_exists('username', $input)) $profile['username'] = $username ?: null;
        if (array_key_exists('bio', $input)) $profile['bio'] = mb_substr(trim((string) $input['bio']), 0, 500);
        if (count($profile) > 2) {
            try { db_connect()->table('mobile_profiles')->replace($profile + $this->existingProfile($id)); }
            catch (\Throwable) { return $this->error('username_taken', 'That username is already in use.', 409); }
        }
        return $this->success($this->payload($id));
    }

    public function uploadAvatar()
    {
        $upload = $this->request->getFile('avatar');
        if (! $upload || ! $upload->isValid() || $upload->hasMoved()) return $this->error('invalid_avatar', 'Choose a valid image.', 422);
        if (! in_array($upload->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true) || $upload->getSize() > 5 * 1024 * 1024) return $this->error('invalid_avatar', 'Avatar must be a JPEG, PNG, or WebP under 5 MB.', 422);
        $id = (int) $this->actor()['employee_id'];
        $directory = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'profiles';
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) throw new RuntimeException('Unable to create profile storage.');
        $name = $id . '-' . bin2hex(random_bytes(8)) . '.' . $upload->guessExtension();
        $upload->move($directory, $name);
        $old = $this->existingProfile($id);
        if (! empty($old['profile_image'])) @unlink($directory . DIRECTORY_SEPARATOR . basename((string) $old['profile_image']));
        db_connect()->table('mobile_profiles')->replace(array_merge($old, ['user_employee_id' => $id, 'profile_image' => $name, 'updated_at' => Time::now('UTC')->toDateTimeString()]));
        return $this->success($this->payload($id));
    }

    public function avatar(int $id)
    {
        $profile = $this->existingProfile($id);
        $name = basename((string) ($profile['profile_image'] ?? ''));
        $path = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'profiles' . DIRECTORY_SEPARATOR . $name;
        if ($name === '' || ! is_file($path)) return $this->error('not_found', 'Profile image not found.', 404);
        return $this->response->download($path, null, true)->inline()->noCache();
    }

    private function existingProfile(int $id): array
    {
        return db_connect()->table('mobile_profiles')->where('user_employee_id', $id)->get()->getRowArray() ?? ['user_employee_id' => $id];
    }

    private function payload(int $id): array
    {
        $user = (new UserModel())->find($id) ?? [];
        $profile = $this->existingProfile($id);
        return ['employee_id' => $id, 'name' => $user['name'] ?? '', 'username' => $profile['username'] ?? null, 'email' => $user['email_address'] ?? '', 'department' => $user['department'] ?? '', 'position' => $user['position'] ?? '', 'role' => $user['role'] ?? '', 'bio' => $profile['bio'] ?? '', 'profile_image_url' => ! empty($profile['profile_image']) ? site_url('api/v1/mobile/profile/avatar/' . $id) : null];
    }
}
