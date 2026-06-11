<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;
use App\Models\UserModel;

class UserController extends ResourceController
{
    use ResponseTrait;
    
    protected $db;
    protected $userModel;
    
    public function __construct()
    {
        $this->db = \Config\Database::connect();
        $this->userModel = new UserModel();
    }

    /**
     * Convertit une valeur API (bool, int, string) en booléen fiable.
     * Évite (bool)"false" === true en PHP.
     */
    protected function normalizeBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            return in_array($v, ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    /**
     * is_active pour la base (0 ou 1), en conservant l'existant si absent du payload.
     */
    protected function resolveIsActive(array $input, array $existingUser, bool $defaultActive = true): int
    {
        if (!array_key_exists('is_active', $input)) {
            return (int) ($existingUser['is_active'] ?? ($defaultActive ? 1 : 0));
        }
        return $this->normalizeBoolean($input['is_active']) ? 1 : 0;
    }
    
    /**
     * GET /api/users - Liste tous les utilisateurs
     */
    public function index()
    {
        $search = $this->request->getVar('search');
        $role = $this->request->getVar('role');
        $status = $this->request->getVar('status');
        
        // Utiliser le modèle UserModel
        $users = $this->userModel
            ->select('users.*, roles.role_name, roles.id as role_id')
            ->join('user_roles', 'user_roles.user_id = users.id', 'left')
            ->join('roles', 'roles.id = user_roles.role_id', 'left');
        
        // Filtre par recherche
        if ($search) {
            $users->groupStart()
                  ->like('users.username', $search)
                  ->orLike('users.email', $search)
                  ->orLike('users.full_name', $search)
                  ->groupEnd();
        }
        
        // Filtre par rôle
        if ($role) {
            $users->where('roles.id', $role);
        }
        
        // Filtre par statut
        if ($status === 'active') {
            $users->where('users.is_active', 1);
        } elseif ($status === 'inactive') {
            $users->where('users.is_active', 0);
        }
        
        $result = $users->orderBy('users.created_at', 'DESC')
            ->findAll();
        
        // Ajouter les permissions pour chaque utilisateur
        foreach ($result as &$user) {
            $permissions = $this->db->table('user_roles ur')
                ->select('p.permission_name')
                ->join('role_permissions rp', 'rp.role_id = ur.role_id')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('ur.user_id', $user['id'])
                ->get()
                ->getResultArray();
            
            $user['permissions'] = array_column($permissions, 'permission_name');
        }
        
        return $this->respond([
            'success' => true,
            'data' => $result
        ]);
    }
    
    /**
     * GET /api/users/(:num) - Affiche un utilisateur spécifique
     */
    public function show($id = null)
    {
        if (!$id) {
            return $this->respond([
                'success' => false,
                'message' => 'ID utilisateur requis'
            ], 400);
        }
        
        $user = $this->userModel
            ->select('users.*, roles.role_name, roles.id as role_id')
            ->join('user_roles', 'user_roles.user_id = users.id', 'left')
            ->join('roles', 'roles.id = user_roles.role_id', 'left')
            ->where('users.id', $id)
            ->first();
        
        if (!$user) {
            return $this->respond([
                'success' => false,
                'message' => 'Utilisateur non trouvé'
            ], 404);
        }
        
        // Récupérer les permissions
        $permissions = $this->db->table('user_roles ur')
            ->select('p.permission_name')
            ->join('role_permissions rp', 'rp.role_id = ur.role_id')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('ur.user_id', $id)
            ->get()
            ->getResultArray();
        
        $user['permissions'] = array_column($permissions, 'permission_name');
        
        return $this->respond([
            'success' => true,
            'data' => $user
        ]);
    }
    
    /**
     * GET /api/users/roles - Liste tous les rôles
     */
    public function getRoles()
    {
        $roles = $this->db->table('roles')
            ->select('id, role_name, description')
            ->orderBy('role_name')
            ->get()
            ->getResultArray();
        
        return $this->respond([
            'success' => true,
            'data' => $roles
        ]);
    }
    
    /**
     * GET /api/users/permissions - Liste toutes les permissions
     */
    public function getPermissions()
    {
        $permissions = $this->db->table('permissions')
            ->select('id, permission_name, module, description')
            ->orderBy('module')
            ->orderBy('permission_name')
            ->get()
            ->getResultArray();
        
        // Grouper par module
        $grouped = [];
        foreach ($permissions as $perm) {
            $module = $perm['module'] ?? 'general';
            if (!isset($grouped[$module])) {
                $grouped[$module] = [];
            }
            $grouped[$module][] = $perm;
        }
        
        return $this->respond([
            'success' => true,
            'data' => $permissions,
            'grouped' => $grouped
        ]);
    }
    
    /**
     * POST /api/users - Crée un nouvel utilisateur
     */
    public function create()
    {
        $input = $this->request->getJSON(true);
        
        // Validation
        if (empty($input['username'])) {
            return $this->respond([
                'success' => false,
                'message' => 'Le nom d\'utilisateur est requis'
            ], 400);
        }
        
        if (empty($input['email'])) {
            return $this->respond([
                'success' => false,
                'message' => 'L\'email est requis'
            ], 400);
        }
        
        if (empty($input['full_name'])) {
            return $this->respond([
                'success' => false,
                'message' => 'Le nom complet est requis'
            ], 400);
        }
        
        // Vérifier si le nom d'utilisateur existe déjà
        $existing = $this->db->table('users')
            ->where('username', $input['username'])
            ->get()
            ->getRowArray();
        
        if ($existing) {
            return $this->respond([
                'success' => false,
                'message' => 'Ce nom d\'utilisateur existe déjà'
            ], 400);
        }
        
        // Vérifier si l'email existe déjà
        $existing = $this->db->table('users')
            ->where('email', $input['email'])
            ->get()
            ->getRowArray();
        
        if ($existing) {
            return $this->respond([
                'success' => false,
                'message' => 'Cet email existe déjà'
            ], 400);
        }
        
        // Générer un mot de passe temporaire
        $tempPassword = $this->generateRandomPassword();
        $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
        
        $roleId = !empty($input['role_id']) ? (int) $input['role_id'] : null;

        $data = [
            'username' => trim($input['username']),
            'email' => trim($input['email']),
            'password_hash' => $hashedPassword,
            'full_name' => trim($input['full_name']),
            'phone' => !empty($input['phone']) ? trim($input['phone']) : null,
            'is_active' => $this->resolveIsActive($input, [], true),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $this->db->table('users')->insert($data);
        $userId = $this->db->insertID();

        // Assigner le rôle (défaut: viewer si non précisé)
        if (!$roleId) {
            $viewer = $this->db->table('roles')->where('role_name', 'viewer')->get()->getRowArray();
            $roleId = $viewer ? (int) $viewer['id'] : null;
        }
        if ($roleId) {
            $this->db->table('user_roles')->insert([
                'user_id' => $userId,
                'role_id' => $roleId,
                'assigned_at' => date('Y-m-d H:i:s'),
            ]);
        }
        
        // Envoyer l'email avec le mot de passe temporaire
        $this->sendWelcomeEmail($input['email'], $input['full_name'], $input['username'], $tempPassword);
        
        $newUser = $this->db->table('users')->where('id', $userId)->get()->getRowArray();
        
        return $this->respond([
            'success' => true,
            'message' => 'Utilisateur créé avec succès',
            'data' => $newUser
        ], 201);
    }
    
    /**
     * PUT /api/users/(:num) - Met à jour un utilisateur
     */
    public function update($id = null)
    {
        if (!$id) {
            return $this->respond([
                'success' => false,
                'message' => 'ID utilisateur requis'
            ], 400);
        }
        
        $input = $this->request->getJSON(true);
        
        $user = $this->db->table('users')->where('id', $id)->get()->getRowArray();
        if (!$user) {
            return $this->respond([
                'success' => false,
                'message' => 'Utilisateur non trouvé'
            ], 404);
        }
        
        // Vérifier si le nom d'utilisateur existe déjà (sauf pour cet utilisateur)
        if (!empty($input['username']) && $input['username'] !== $user['username']) {
            $existing = $this->db->table('users')
                ->where('username', $input['username'])
                ->where('id !=', $id)
                ->get()
                ->getRowArray();
            
            if ($existing) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Ce nom d\'utilisateur existe déjà'
                ], 400);
            }
        }
        
        // Vérifier si l'email existe déjà (sauf pour cet utilisateur)
        if (!empty($input['email']) && $input['email'] !== $user['email']) {
            $existing = $this->db->table('users')
                ->where('email', $input['email'])
                ->where('id !=', $id)
                ->get()
                ->getRowArray();
            
            if ($existing) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Cet email existe déjà'
                ], 400);
            }
        }
        
        $data = [
            'username' => trim($input['username'] ?? $user['username']),
            'email' => trim($input['email'] ?? $user['email']),
            'full_name' => trim($input['full_name'] ?? $user['full_name']),
            'phone' => array_key_exists('phone', $input)
                ? (!empty($input['phone']) ? trim($input['phone']) : null)
                : $user['phone'],
            'is_active' => $this->resolveIsActive($input, $user),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $this->db->table('users')->where('id', $id)->update($data);

        // Mettre à jour le rôle uniquement si role_id est fourni explicitement
        if (array_key_exists('role_id', $input)) {
            $roleId = !empty($input['role_id']) ? (int) $input['role_id'] : null;
            $this->db->table('user_roles')->where('user_id', $id)->delete();
            if ($roleId) {
                $this->db->table('user_roles')->insert([
                    'user_id' => $id,
                    'role_id' => $roleId,
                    'assigned_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        $updatedUser = $this->userModel
            ->select('users.*, roles.role_name, roles.id as role_id')
            ->join('user_roles', 'user_roles.user_id = users.id', 'left')
            ->join('roles', 'roles.id = user_roles.role_id', 'left')
            ->where('users.id', $id)
            ->first();

        if ($updatedUser) {
            $perms = $this->db->table('user_roles ur')
                ->select('p.permission_name')
                ->join('role_permissions rp', 'rp.role_id = ur.role_id')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('ur.user_id', $id)
                ->get()
                ->getResultArray();
            $updatedUser['permissions'] = array_column($perms, 'permission_name');
        }

        return $this->respond([
            'success' => true,
            'message' => 'Utilisateur modifié avec succès',
            'data' => $updatedUser,
        ]);
    }
    
    /**
     * DELETE /api/users/(:num) - Supprime un utilisateur
     */
    public function delete($id = null)
    {
        if (!$id) {
            return $this->respond([
                'success' => false,
                'message' => 'ID utilisateur requis'
            ], 400);
        }
        
        $user = $this->db->table('users')->where('id', $id)->get()->getRowArray();
        if (!$user) {
            return $this->respond([
                'success' => false,
                'message' => 'Utilisateur non trouvé'
            ], 404);
        }
        
        // Supprimer les sessions de l'utilisateur
        $this->db->table('user_sessions')->where('user_id', $id)->delete();
        
        // Supprimer les rôles de l'utilisateur
        $this->db->table('user_roles')->where('user_id', $id)->delete();
        
        // Supprimer l'utilisateur
        $this->db->table('users')->where('id', $id)->delete();
        
        return $this->respond([
            'success' => true,
            'message' => 'Utilisateur supprimé avec succès'
        ]);
    }
    
    /**
     * POST /api/users/(:num)/reset-password - Réinitialise le mot de passe
     */
    public function resetPassword($id = null)
    {
        if (!$id) {
            return $this->respond([
                'success' => false,
                'message' => 'ID utilisateur requis'
            ], 400);
        }
        
        $user = $this->db->table('users')->where('id', $id)->get()->getRowArray();
        if (!$user) {
            return $this->respond([
                'success' => false,
                'message' => 'Utilisateur non trouvé'
            ], 404);
        }
        
        // Générer un nouveau mot de passe
        $newPassword = $this->generateRandomPassword();
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $this->db->table('users')
            ->where('id', $id)
            ->update([
                'password_hash' => $hashedPassword,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        
        // Invalider toutes les sessions de l'utilisateur
        $this->db->table('user_sessions')->where('user_id', $id)->delete();
        
        // Envoyer l'email avec le nouveau mot de passe
        $this->sendPasswordResetEmail($user['email'], $user['full_name'], $user['username'], $newPassword);
        
        return $this->respond([
            'success' => true,
            'message' => 'Mot de passe réinitialisé avec succès. Un email a été envoyé.'
        ]);
    }
    
    /**
     * Générer un mot de passe aléatoire
     */
    private function generateRandomPassword($length = 10)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        return substr(str_shuffle($chars), 0, $length);
    }
    
/**
 * Envoyer l'email de bienvenue
 */
private function sendWelcomeEmail($to, $name, $username, $password)
{
    $email = \Config\Services::email();
    
    $smtpPort = getenv('email.SMTPPort');
    if (is_string($smtpPort)) {
        $smtpPort = (int)$smtpPort;
    }
    if (empty($smtpPort)) {
        $smtpPort = 587;
    }
    
    $config = [
        'protocol' => getenv('email.protocol') ?: 'smtp',
        'SMTPHost' => getenv('email.SMTPHost') ?: 'smtp.gmail.com',
        'SMTPUser' => getenv('email.SMTPUser') ?: '',
        'SMTPPass' => getenv('email.SMTPPass') ?: '',
        'SMTPPort' => $smtpPort,
        'SMTPCrypto' => getenv('email.SMTPCrypto') ?: 'tls',
        'mailType' => getenv('email.mailType') ?: 'html',
        'charset' => 'utf-8',
        'wordWrap' => true,
        'newline' => "\r\n",
        'CRLF' => "\r\n"
    ];
    
    $email->initialize($config);
    $email->setFrom($config['SMTPUser'], 'StockManager Pro');
    $email->setTo($to);
    $email->setSubject('Bienvenue sur StockManager Pro - Vos identifiants de connexion');
    
    // URL du frontend pour la connexion
    $frontendUrl = $this->getFrontendUrl();
    $loginUrl = $frontendUrl . '/login';
    
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #667eea, #764ba2); padding: 20px; text-align: center; color: white; }
            .content { padding: 20px; background: #f8f9fa; }
            .credentials { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; }
            .button { display: inline-block; padding: 12px 24px; background: #667eea; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; }
            .button:hover { background: #5a67d8; }
            .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>StockManager Pro</h2>
            </div>
            <div class='content'>
                <p>Bonjour <strong>{$name}</strong>,</p>
                <p>Votre compte a été créé sur la plateforme StockManager Pro.</p>
                <div class='credentials'>
                    <p><strong>👤 Nom d'utilisateur :</strong> {$username}</p>
                    <p><strong>🔑 Mot de passe temporaire :</strong> <span style='background: #f1f5f9; padding: 4px 8px; font-family: monospace;'>{$password}</span></p>
                </div>
                <p>Veuillez changer votre mot de passe lors de votre première connexion.</p>
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='{$loginUrl}' class='button'>🔐 Se connecter</a>
                </p>
                <p style='font-size: 12px; color: #666;'>
                    Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br>
                    <a href='{$loginUrl}' style='color: #667eea;'>{$loginUrl}</a>
                </p>
            </div>
            <div class='footer'>
                <p>© " . date('Y') . " StockManager Pro. Tous droits réservés.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $email->setMessage($message);
    
    if ($email->send()) {
        log_message('info', 'Email de bienvenue envoyé à: ' . $to);
        return true;
    } else {
        log_message('error', 'Erreur envoi email: ' . $email->printDebugger(['headers']));
        return false;
    }
}

/**
 * Obtenir l'URL du frontend
 */
private function getFrontendUrl()
{
    $frontendUrl = getenv('frontend.url');
    if (empty($frontendUrl)) {
        $frontendUrl = 'http://localhost:3000'; // URL par défaut
    }
    return rtrim($frontendUrl, '/');
}
 /**
 * Envoyer l'email de réinitialisation de mot de passe
 */
private function sendPasswordResetEmail($to, $name, $username, $newPassword)
{
    $email = \Config\Services::email();
    
    $smtpPort = getenv('email.SMTPPort');
    if (is_string($smtpPort)) {
        $smtpPort = (int)$smtpPort;
    }
    if (empty($smtpPort)) {
        $smtpPort = 587;
    }
    
    $config = [
        'protocol' => getenv('email.protocol') ?: 'smtp',
        'SMTPHost' => getenv('email.SMTPHost') ?: 'smtp.gmail.com',
        'SMTPUser' => getenv('email.SMTPUser') ?: '',
        'SMTPPass' => getenv('email.SMTPPass') ?: '',
        'SMTPPort' => $smtpPort,
        'SMTPCrypto' => getenv('email.SMTPCrypto') ?: 'tls',
        'mailType' => getenv('email.mailType') ?: 'html',
        'charset' => 'utf-8',
        'wordWrap' => true,
        'newline' => "\r\n",
        'CRLF' => "\r\n"
    ];
    
    $email->initialize($config);
    $email->setFrom($config['SMTPUser'], 'StockManager Pro');
    $email->setTo($to);
    $email->setSubject('StockManager Pro - Réinitialisation de votre mot de passe');
    
    // URL du frontend pour la connexion
    $frontendUrl = $this->getFrontendUrl();
    $loginUrl = $frontendUrl . '/login';
    
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #667eea, #764ba2); padding: 20px; text-align: center; color: white; }
            .content { padding: 20px; background: #f8f9fa; }
            .credentials { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; }
            .button { display: inline-block; padding: 12px 24px; background: #667eea; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; }
            .button:hover { background: #5a67d8; }
            .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>StockManager Pro</h2>
            </div>
            <div class='content'>
                <p>Bonjour <strong>{$name}</strong>,</p>
                <p>Une demande de réinitialisation de mot de passe a été effectuée pour votre compte.</p>
                <div class='credentials'>
                    <p><strong>👤 Nom d'utilisateur :</strong> {$username}</p>
                    <p><strong>🔑 Nouveau mot de passe :</strong> <span style='background: #f1f5f9; padding: 4px 8px; font-family: monospace;'>{$newPassword}</span></p>
                </div>
                <p>Veuillez changer votre mot de passe lors de votre prochaine connexion.</p>
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='{$loginUrl}' class='button'>🔐 Se connecter</a>
                </p>
                <p style='font-size: 12px; color: #666;'>
                    Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br>
                    <a href='{$loginUrl}' style='color: #667eea;'>{$loginUrl}</a>
                </p>
                <p style='margin-top: 20px; font-size: 12px; color: #666;'>
                    Si vous n'êtes pas à l'origine de cette demande, veuillez contacter immédiatement l'administrateur.
                </p>
            </div>
            <div class='footer'>
                <p>© " . date('Y') . " StockManager Pro. Tous droits réservés.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $email->setMessage($message);
    
    if ($email->send()) {
        log_message('info', 'Email de réinitialisation envoyé à: ' . $to);
        return true;
    } else {
        log_message('error', 'Erreur envoi email: ' . $email->printDebugger(['headers']));
        return false;
    }
}

}