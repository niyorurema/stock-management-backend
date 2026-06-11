<?php

namespace App\Controllers;

use App\Libraries\JwtAuth;
use CodeIgniter\API\ResponseTrait;

class ProfileController extends BaseController
{
    use ResponseTrait;

    protected $db;
    protected JwtAuth $jwtAuth;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
        $this->jwtAuth = new JwtAuth();
    }

    /**
     * GET /api/profile
     */
    public function index()
    {
        $userId = $this->jwtAuth->getUserIdFromRequest($this->request);
        if (!$userId) {
            return $this->respond(['success' => false, 'message' => 'Non authentifié'], 401);
        }

        $user = $this->db->table('users')->where('id', $userId)->get()->getRowArray();
        if (!$user) {
            return $this->respond(['success' => false, 'message' => 'Utilisateur non trouvé'], 404);
        }

        return $this->respond([
            'success' => true,
            'data' => $this->formatProfile($user, $userId),
        ]);
    }

    /**
     * PUT /api/profile
     */
    public function update()
    {
        $userId = $this->jwtAuth->getUserIdFromRequest($this->request);
        if (!$userId) {
            return $this->respond(['success' => false, 'message' => 'Non authentifié'], 401);
        }

        $user = $this->db->table('users')->where('id', $userId)->get()->getRowArray();
        if (!$user) {
            return $this->respond(['success' => false, 'message' => 'Utilisateur non trouvé'], 404);
        }

        $input = $this->request->getJSON(true) ?? [];

        $username = trim($input['username'] ?? $user['username']);
        $email = trim($input['email'] ?? $user['email']);
        $fullName = trim($input['full_name'] ?? $user['full_name']);
        $phone = trim($input['phone'] ?? ($user['phone'] ?? ''));

        if ($username === '' || $fullName === '') {
            return $this->respond([
                'success' => false,
                'message' => 'Le nom d\'utilisateur et le nom complet sont requis',
            ], 400);
        }

        if ($username !== $user['username']) {
            $exists = $this->db->table('users')
                ->where('username', $username)
                ->where('id !=', $userId)
                ->countAllResults();
            if ($exists > 0) {
                return $this->respond(['success' => false, 'message' => 'Ce nom d\'utilisateur existe déjà'], 400);
            }
        }

        if ($email !== $user['email']) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->respond(['success' => false, 'message' => 'Email invalide'], 400);
            }
            $exists = $this->db->table('users')
                ->where('email', $email)
                ->where('id !=', $userId)
                ->countAllResults();
            if ($exists > 0) {
                return $this->respond(['success' => false, 'message' => 'Cet email existe déjà'], 400);
            }
        }

        $this->db->table('users')->where('id', $userId)->update([
            'username' => $username,
            'email' => $email,
            'full_name' => $fullName,
            'phone' => $phone !== '' ? $phone : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $updated = $this->db->table('users')->where('id', $userId)->get()->getRowArray();

        return $this->respond([
            'success' => true,
            'message' => 'Profil mis à jour avec succès',
            'data' => $this->formatProfile($updated, $userId),
        ]);
    }

    private function formatProfile(array $user, int $userId): array
    {
        $roles = [];
        try {
            $rolesResult = $this->db->table('user_roles')
                ->select('r.role_name')
                ->join('roles r', 'r.id = user_roles.role_id')
                ->where('user_roles.user_id', $userId)
                ->get()
                ->getResultArray();
            $roles = array_column($rolesResult, 'role_name');
        } catch (\Exception $e) {
            log_message('warning', 'Profile roles: ' . $e->getMessage());
        }

        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'phone' => $user['phone'] ?? '',
            'avatar' => $user['avatar'] ?? null,
            'is_active' => (bool) ($user['is_active'] ?? true),
            'last_login' => $user['last_login'] ?? null,
            'last_ip' => $user['last_ip'] ?? null,
            'created_at' => $user['created_at'] ?? null,
            'updated_at' => $user['updated_at'] ?? null,
            'roles' => $roles,
        ];
    }
}
