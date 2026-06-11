<?php

namespace App\Services;

/**
 * Contexte utilisateur pour la requête courante (rempli par AuthFilter).
 */
class CurrentUser
{
    public ?int $id = null;
    public array $roles = [];
    public array $permissions = [];

    protected PermissionService $permissionService;

    public function __construct()
    {
        $this->permissionService = new PermissionService();
    }

    public function set(int $id, array $roles, array $permissions): void
    {
        $this->id = $id;
        $this->roles = $roles;
        $this->permissions = $permissions;
    }

    public function isAuthenticated(): bool
    {
        return $this->id !== null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->permissionService->isSuperAdmin($this->roles);
    }

    public function can(string $permission): bool
    {
        if (!$this->isAuthenticated()) {
            return false;
        }
        return $this->permissionService->hasPermission($this->roles, $this->permissions, $permission);
    }

    public function canAny(array $permissions): bool
    {
        return $this->permissionService->hasAny($this->roles, $this->permissions, $permissions);
    }

    public function clear(): void
    {
        $this->id = null;
        $this->roles = [];
        $this->permissions = [];
    }
}
