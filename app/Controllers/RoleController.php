<?php

namespace App\Controllers;

use App\Services\CurrentUser;
use CodeIgniter\API\ResponseTrait;

class RoleController extends BaseController
{
    use ResponseTrait;

    protected $db;
    protected CurrentUser $currentUser;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
        $this->currentUser = service('currentUser');
    }

    /**
     * GET /api/roles
     */
    public function index()
    {
        if (!$this->currentUser->can('roles.view')) {
            return $this->forbidden();
        }

        $roles = $this->db->table('roles r')
            ->select('r.id, r.role_name, r.description, r.created_at, COUNT(rp.permission_id) as permission_count')
            ->join('role_permissions rp', 'rp.role_id = r.id', 'left')
            ->groupBy('r.id, r.role_name, r.description, r.created_at')
            ->orderBy('r.role_name')
            ->get()
            ->getResultArray();

        return $this->respond(['success' => true, 'data' => $roles]);
    }

    /**
     * GET /api/roles/{id}
     */
    public function show($id = null)
    {
        if (!$this->currentUser->can('roles.view')) {
            return $this->forbidden();
        }

        $role = $this->db->table('roles')->where('id', $id)->get()->getRowArray();
        if (!$role) {
            return $this->respond(['success' => false, 'message' => 'Rôle introuvable'], 404);
        }

        $permissions = $this->db->table('role_permissions rp')
            ->select('p.id, p.permission_name, p.module, p.description')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('rp.role_id', $id)
            ->orderBy('p.module, p.permission_name')
            ->get()
            ->getResultArray();

        $role['permissions'] = $permissions;
        $role['permission_ids'] = array_column($permissions, 'id');

        return $this->respond(['success' => true, 'data' => $role]);
    }

    /**
     * POST /api/roles
     */
    public function create()
    {
        if (!$this->currentUser->can('roles.manage')) {
            return $this->forbidden();
        }

        $input = $this->request->getJSON(true) ?? [];
        $roleName = trim($input['role_name'] ?? '');
        $description = trim($input['description'] ?? '');

        if ($roleName === '') {
            return $this->respond(['success' => false, 'message' => 'Le nom du rôle est requis'], 400);
        }

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $roleName)) {
            return $this->respond([
                'success' => false,
                'message' => 'Nom invalide (minuscules, chiffres et underscore uniquement)',
            ], 400);
        }

        $exists = $this->db->table('roles')->where('role_name', $roleName)->countAllResults();
        if ($exists > 0) {
            return $this->respond(['success' => false, 'message' => 'Ce rôle existe déjà'], 400);
        }

        $this->db->table('roles')->insert([
            'role_name' => $roleName,
            'description' => $description,
        ]);
        $roleId = $this->db->insertID();

        if (!empty($input['permission_ids']) && is_array($input['permission_ids'])) {
            $this->syncPermissions((int) $roleId, $input['permission_ids']);
        }

        return $this->respond([
            'success' => true,
            'message' => 'Rôle créé',
            'data' => ['id' => $roleId],
        ], 201);
    }

    /**
     * PUT /api/roles/{id}
     */
    public function update($id = null)
    {
        if (!$this->currentUser->can('roles.manage')) {
            return $this->forbidden();
        }

        $role = $this->db->table('roles')->where('id', $id)->get()->getRowArray();
        if (!$role) {
            return $this->respond(['success' => false, 'message' => 'Rôle introuvable'], 404);
        }

        if ($role['role_name'] === 'super_admin') {
            return $this->respond(['success' => false, 'message' => 'Le rôle super_admin ne peut pas être modifié'], 403);
        }

        $input = $this->request->getJSON(true) ?? [];
        $update = [];
        if (isset($input['description'])) {
            $update['description'] = trim($input['description']);
        }
        if (!empty($input['role_name']) && $input['role_name'] !== $role['role_name']) {
            $update['role_name'] = trim($input['role_name']);
        }
        if (!empty($update)) {
            $this->db->table('roles')->where('id', $id)->update($update);
        }

        return $this->respond(['success' => true, 'message' => 'Rôle mis à jour']);
    }

    /**
     * DELETE /api/roles/{id}
     */
    public function delete($id = null)
    {
        if (!$this->currentUser->can('roles.manage')) {
            return $this->forbidden();
        }

        $role = $this->db->table('roles')->where('id', $id)->get()->getRowArray();
        if (!$role) {
            return $this->respond(['success' => false, 'message' => 'Rôle introuvable'], 404);
        }

        $protected = ['super_admin', 'admin', 'manager', 'cashier', 'viewer'];
        if (in_array($role['role_name'], $protected, true)) {
            return $this->respond([
                'success' => false,
                'message' => 'Les rôles système par défaut ne peuvent pas être supprimés',
            ], 403);
        }

        $this->db->table('roles')->where('id', $id)->delete();

        return $this->respond(['success' => true, 'message' => 'Rôle supprimé']);
    }

    /**
     * PUT /api/roles/{id}/permissions
     */
    public function updatePermissions($id = null)
    {
        if (!$this->currentUser->can('roles.manage')) {
            return $this->forbidden();
        }

        $role = $this->db->table('roles')->where('id', $id)->get()->getRowArray();
        if (!$role) {
            return $this->respond(['success' => false, 'message' => 'Rôle introuvable'], 404);
        }

        if ($role['role_name'] === 'super_admin') {
            return $this->respond([
                'success' => false,
                'message' => 'Les permissions de super_admin sont complètes et fixes',
            ], 403);
        }

        $input = $this->request->getJSON(true) ?? [];
        $permissionIds = $input['permission_ids'] ?? [];
        if (!is_array($permissionIds)) {
            return $this->respond(['success' => false, 'message' => 'permission_ids doit être un tableau'], 400);
        }

        $this->syncPermissions((int) $id, $permissionIds);

        return $this->respond(['success' => true, 'message' => 'Permissions du rôle mises à jour']);
    }

    protected function syncPermissions(int $roleId, array $permissionIds): void
    {
        $permissionIds = array_map('intval', array_filter($permissionIds));
        $this->db->table('role_permissions')->where('role_id', $roleId)->delete();
        foreach ($permissionIds as $pid) {
            $exists = $this->db->table('permissions')->where('id', $pid)->countAllResults();
            if ($exists > 0) {
                $this->db->table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $pid,
                ]);
            }
        }
    }

    protected function forbidden()
    {
        return $this->respond([
            'success' => false,
            'message' => 'Accès refusé',
        ], 403);
    }
}
