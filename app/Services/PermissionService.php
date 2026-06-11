<?php

namespace App\Services;

class PermissionService
{
    protected static ?array $cache = null;

    /**
     * Charge rôles et permissions pour un utilisateur.
     */
    public function loadForUser(int $userId): array
    {
        $db = \Config\Database::connect();

        $roles = $db->table('user_roles ur')
            ->select('r.role_name')
            ->join('roles r', 'r.id = ur.role_id')
            ->where('ur.user_id', $userId)
            ->get()
            ->getResultArray();

        $roleNames = array_column($roles, 'role_name');

        $permissions = $db->table('user_roles ur')
            ->select('DISTINCT p.permission_name', false)
            ->join('role_permissions rp', 'rp.role_id = ur.role_id')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('ur.user_id', $userId)
            ->get()
            ->getResultArray();

        $permissionNames = array_values(array_unique(array_column($permissions, 'permission_name')));

        return [
            'roles' => $roleNames,
            'permissions' => $permissionNames,
        ];
    }

    public function isSuperAdmin(array $roles): bool
    {
        return in_array('super_admin', $roles, true);
    }

    public function hasPermission(array $roles, array $permissions, string $permission): bool
    {
        if ($this->isSuperAdmin($roles)) {
            return true;
        }

        if (in_array($permission, $permissions, true)) {
            return true;
        }

        // Wildcard module.* (ex: products.*)
        if (str_ends_with($permission, '.*')) {
            $prefix = substr($permission, 0, -1);
            foreach ($permissions as $p) {
                if (str_starts_with($p, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function hasAny(array $roles, array $permissions, array $required): bool
    {
        foreach ($required as $perm) {
            if ($this->hasPermission($roles, $permissions, $perm)) {
                return true;
            }
        }
        return false;
    }
}
