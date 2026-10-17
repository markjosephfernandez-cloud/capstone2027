<?php

namespace App\Libraries;

/** Central role/permission policy. Legacy staff accounts are Creative Staff. */
/** update the permission if there is new role */
final class RolePolicy
{   
    private const PERMISSIONS = [
        'super_admin' => ['*'],
        'admin' => ['files.view_all', 'files.download', 'files.upload', 'files.manage', 'files.delete_permanently', 'files.assign', 'ai.process', 'distribution.manage', 'raw.view', 'processed.view', 'published.view', 'notifications.view'],
        'staff' => ['files.view_all', 'files.download', 'files.upload', 'files.manage', 'files.assign', 'ai.process', 'distribution.manage', 'raw.view', 'processed.view', 'published.view', 'notifications.view'],
        'writer' => ['files.view_all', 'files.download', 'notifications.view'],
        'department' => ['files.view_assigned', 'files.download', 'requests.manage_own', 'notifications.view'],
    ];
     /* add some permission function here 

    /** @param array<string, mixed> $actor */
    public static function allows(array $actor, string $permission): bool
    {
        $permissions = self::PERMISSIONS[self::role($actor)] ?? [];
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    /** @param array<string, mixed> $actor */
    public static function role(array $actor): string
    {
        $role = strtolower(trim((string) ($actor['role'] ?? '')));
        return in_array($role, array_keys(self::PERMISSIONS), true) ? $role : 'department';
    }

    /** @param array<string, mixed> $actor */
    public static function isSuperAdmin(array $actor): bool
    {
        return self::role($actor) === 'super_admin';
    }
    
    /** @param array<string, mixed> $actor */
    public static function isCreativeAdmin(array $actor): bool
    {
        return in_array(self::role($actor), ['super_admin', 'admin', 'staff'], true);
    }
}
