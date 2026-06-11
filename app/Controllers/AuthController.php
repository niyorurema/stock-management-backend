<?php

namespace App\Controllers;

use App\Models\UserModel;
use App\Models\ActivityLogModel;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class AuthController extends BaseController
{
    use ResponseTrait;
    
    protected $userModel;
    protected $session;
    protected $email;
    protected $db;
    protected $validation;
    protected $jwtKey;
    protected $jwtExpiry;
    
    public function __construct()
    {
        $this->userModel = new UserModel();
        $this->session = \Config\Services::session();
        $this->email = \Config\Services::email();
        $this->db = \Config\Database::connect();
        $this->validation = \Config\Services::validation();

        // Configuration JWT - Clé de 32 caractères minimum
        $envKey = getenv('jwt.secret.key');
        if (!empty($envKey) && strlen($envKey) >= 32) {
            $this->jwtKey = $envKey;
        } else {
            // Clé par défaut de 32 caractères
            $this->jwtKey = 'aB3dE5fG7hI9jK1lM2nO4pQ6rS8tU0vW2xY';
        }
        
        $this->jwtExpiry = (int)(getenv('jwt.expiry.time') ?: 28800); // 8 heures en secondes
    }
    
    /**
     * Connexion utilisateur
     * POST /api/auth/login
     */
    public function login()
    {
        // Headers CORS - IMPORTANT
        $this->response->setHeader('Access-Control-Allow-Origin', '*');
        $this->response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $this->response->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        $this->response->setHeader('Access-Control-Allow-Credentials', 'true');
        $this->response->setHeader('Content-Type', 'application/json');
        
        // Gérer la requête OPTIONS (preflight)
        if ($this->request->getMethod() === 'options') {
            return $this->response->setStatusCode(200);
        }
        
        // Récupérer les données
        $input = $this->request->getJSON(true);
        
        if (empty($input)) {
            $input = [
                'username' => $this->request->getPost('username'),
                'password' => $this->request->getPost('password')
            ];
        }
        
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        
        // Recherche dans la base de données
        $user = $this->userModel
            ->where('username', $username)
            ->orWhere('email', $username)
            ->first();
        
        // Vérifier l'utilisateur et le mot de passe
        if ($user && password_verify($password, $user['password_hash'])) {
            // Vérifier si le compte est actif
            if (isset($user['is_active']) && !$user['is_active']) {
                return $this->response
                    ->setStatusCode(403)
                    ->setJSON([
                        'success' => false,
                        'message' => 'Votre compte est désactivé'
                    ]);
            }
            
            // Générer token JWT
            $token = $this->generateJWT($user);
            $refreshToken = bin2hex(random_bytes(32));
            
            // Sauvegarder la session
            $this->saveUserSession($user['id'], $token, $refreshToken);
            
            // Mettre à jour la dernière connexion
            $this->userModel->update($user['id'], [
                'last_login' => date('Y-m-d H:i:s'),
                'last_ip' => $this->request->getIPAddress()
            ]);
            
            // Récupérer les rôles et permissions
            $roles = $this->getUserRoles($user['id']);
            $permissions = $this->getUserPermissions($user['id']);
            
            // Journaliser l'activité
            $this->logActivity($user['id'], 'login', 'Connexion réussie');
            
            return $this->response->setJSON([
                'success' => true,
                'message' => 'Connexion réussie',
                'data' => [
                    'token' => $token,
                    'refresh_token' => $refreshToken,
                    'expires_in' => $this->jwtExpiry,
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'email' => $user['email'],
                        'full_name' => $user['full_name'],
                        'phone' => $user['phone'] ?? '',
                        'avatar' => $user['avatar'] ?? null,
                        'last_login' => $user['last_login'],
                        'roles' => $roles,
                        'permissions' => $permissions
                    ]
                ]
            ]);
        }
        
        // Journaliser la tentative échouée
        $this->logFailedAttempt($username, $this->request->getIPAddress());
        
        // Échec de connexion
        return $this->response
            ->setStatusCode(401)
            ->setJSON([
                'success' => false,
                'message' => 'Nom d\'utilisateur ou mot de passe incorrect'
            ]);
    }
    
    /**
     * Déconnexion utilisateur
     * POST /api/auth/logout
     */
    public function logout()
    {
        $token = $this->getTokenFromRequest();
        
        if ($token) {
            // Supprimer la session
            $this->db->table('user_sessions')
                ->where('token', $token)
                ->delete();
            
            // Blacklister le token
            $this->blacklistToken($token);
        }
        
        return $this->respond([
            'success' => true,
            'message' => 'Déconnexion réussie'
        ]);
    }
    
    /**
     * Rafraîchir le token JWT
     * POST /api/auth/refresh
     */
    public function refreshToken()
    {
        $input = $this->request->getJSON(true);
        $refreshToken = $input['refresh_token'] ?? null;
        
        if (!$refreshToken) {
            return $this->respond([
                'success' => false,
                'message' => 'Refresh token requis'
            ], 400);
        }
        
        // Vérifier le refresh token
        $session = $this->db->table('user_sessions')
            ->where('refresh_token', $refreshToken)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->get()
            ->getRowArray();
        
        if (!$session) {
            return $this->respond([
                'success' => false,
                'message' => 'Refresh token invalide ou expiré'
            ], 401);
        }
        
        // Générer nouveau token
        $user = $this->userModel->find($session['user_id']);
        $newToken = $this->generateJWT($user);
        $newRefreshToken = bin2hex(random_bytes(32));
        
        // Mettre à jour la session
        $this->db->table('user_sessions')
            ->where('id', $session['id'])
            ->update([
                'token' => $newToken,
                'refresh_token' => $newRefreshToken,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+' . $this->jwtExpiry . ' seconds'))
            ]);
        
        return $this->respond([
            'success' => true,
            'data' => [
                'token' => $newToken,
                'refresh_token' => $newRefreshToken,
                'expires_in' => $this->jwtExpiry
            ]
        ]);
    }
    
    /**
     * Obtenir l'utilisateur courant
     * GET /api/auth/me
     */
    public function me()
    {
        // Headers CORS
        $this->response->setHeader('Access-Control-Allow-Origin', '*');
        $this->response->setHeader('Content-Type', 'application/json');
        
        // Récupérer le token
        $token = $this->getTokenFromRequest();
        
        if (empty($token)) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON([
                    'success' => false,
                    'message' => 'Token manquant'
                ]);
        }
        
        // Vérifier le token
        $userData = $this->verifyJWT($token);
        
        if (!$userData) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON([
                    'success' => false,
                    'message' => 'Token invalide ou expiré'
                ]);
        }
        
        $userId = $userData['user_id'] ?? null;
        
        if (!$userId) {
            return $this->respond([
                'success' => false,
                'message' => 'Non authentifié'
            ], 401);
        }
        
        $user = $this->userModel->find($userId);
        
        if (!$user) {
            return $this->respond([
                'success' => false,
                'message' => 'Utilisateur non trouvé'
            ], 404);
        }
        
        $roles = $this->getUserRoles($userId);
        $permissions = $this->getUserPermissions($userId);
        
        return $this->respond([
            'success' => true,
            'data' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'full_name' => $user['full_name'],
                'phone' => $user['phone'] ?? null,
                'avatar' => $user['avatar'] ?? null,
                'last_login' => $user['last_login'],
                'roles' => $roles,
                'permissions' => $permissions
            ]
        ]);
    }
    
    /**
     * Changer le mot de passe
     * POST /api/auth/change-password
     */
    public function changePassword()
    {
        $token = $this->getTokenFromRequest();
        $userData = $this->verifyJWT($token);
        
        if (!$userData) {
            return $this->respond([
                'success' => false,
                'message' => 'Non authentifié'
            ], 401);
        }
        
        $userId = $userData['user_id'];
        
        $input = $this->request->getJSON(true);
        $currentPassword = $input['current_password'] ?? null;
        $newPassword = $input['new_password'] ?? null;
        $confirmPassword = $input['confirm_password'] ?? null;
        
        // Validation
        if (!$currentPassword || !$newPassword || !$confirmPassword) {
            return $this->respond([
                'success' => false,
                'message' => 'Tous les champs sont requis'
            ], 400);
        }
        
        if ($newPassword !== $confirmPassword) {
            return $this->respond([
                'success' => false,
                'message' => 'Les nouveaux mots de passe ne correspondent pas'
            ], 400);
        }
        
        if (strlen($newPassword) < 6) {
            return $this->respond([
                'success' => false,
                'message' => 'Le mot de passe doit contenir au moins 6 caractères'
            ], 400);
        }
        
        $user = $this->userModel->find($userId);
        
        if (!password_verify($currentPassword, $user['password_hash'])) {
            return $this->respond([
                'success' => false,
                'message' => 'Mot de passe actuel incorrect'
            ], 401);
        }
        
        // Mettre à jour le mot de passe
        $this->userModel->update($userId, [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'password_changed_at' => date('Y-m-d H:i:s')
        ]);
        
        // Invalider toutes les sessions (sauf celle en cours)
        $this->db->table('user_sessions')
            ->where('user_id', $userId)
            ->where('token !=', $token)
            ->delete();
        
        $this->logActivity($userId, 'change_password', 'Mot de passe changé');
        
        return $this->respond([
            'success' => true,
            'message' => 'Mot de passe changé avec succès'
        ]);
    }
    
    /**
     * Réinitialiser le mot de passe (oublié)
     * POST /api/auth/reset-password
     */
    public function resetPassword()
    {
        $input = $this->request->getJSON(true);
        $email = $input['email'] ?? $this->request->getVar('email');
        
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->respond([
                'success' => false,
                'message' => 'Email valide requis'
            ], 400);
        }
        
        $user = $this->userModel->where('email', $email)->first();
        
        if (!$user) {
            // Ne pas révéler si l'email existe ou non pour des raisons de sécurité
            return $this->respond([
                'success' => true,
                'message' => 'Si un compte existe avec cet email, un lien de réinitialisation a été envoyé'
            ]);
        }
        
        // Générer token de réinitialisation
        $resetToken = bin2hex(random_bytes(32));
        $resetExpiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Sauvegarder le token
        $this->userModel->update($user['id'], [
            'reset_token' => $resetToken,
            'reset_token_expiry' => $resetExpiry
        ]);
        
        // Envoyer l'email
        $emailSent = $this->sendResetPasswordEmail($user['email'], $user['full_name'], $resetToken);
        
        if (!$emailSent) {
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi de l\'email'
            ], 500);
        }
        
        return $this->respond([
            'success' => true,
            'message' => 'Un lien de réinitialisation a été envoyé à votre adresse email'
        ]);
    }
    
    /**
     * Confirmer la réinitialisation du mot de passe
     * POST /api/auth/reset-password/confirm
     */
    public function confirmResetPassword()
    {
        $input = $this->request->getJSON(true);
        $token = $input['token'] ?? null;
        $newPassword = $input['new_password'] ?? null;
        $confirmPassword = $input['confirm_password'] ?? null;
        
        if (!$token || !$newPassword || !$confirmPassword) {
            return $this->respond([
                'success' => false,
                'message' => 'Token et nouveau mot de passe requis'
            ], 400);
        }
        
        if ($newPassword !== $confirmPassword) {
            return $this->respond([
                'success' => false,
                'message' => 'Les mots de passe ne correspondent pas'
            ], 400);
        }
        
        // Vérifier le token
        $user = $this->userModel
            ->where('reset_token', $token)
            ->where('reset_token_expiry >', date('Y-m-d H:i:s'))
            ->first();
        
        if (!$user) {
            return $this->respond([
                'success' => false,
                'message' => 'Token invalide ou expiré'
            ], 400);
        }
        
        // Mettre à jour le mot de passe
        $this->userModel->update($user['id'], [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'reset_token' => null,
            'reset_token_expiry' => null,
            'password_changed_at' => date('Y-m-d H:i:s')
        ]);
        
        // Invalider toutes les sessions
        $this->db->table('user_sessions')->where('user_id', $user['id'])->delete();
        
        return $this->respond([
            'success' => true,
            'message' => 'Mot de passe réinitialisé avec succès'
        ]);
    }
    
    /**
     * Vérifier le token (pour débogage)
     * GET /api/auth/verify
     */
    public function verifyToken()
    {
        $token = $this->getTokenFromRequest();
        
        if (!$token) {
            return $this->respond([
                'success' => false,
                'message' => 'Token manquant'
            ], 400);
        }
        
        try {
            $decoded = JWT::decode($token, new Key($this->jwtKey, 'HS256'));
            
            // Vérifier si le token est dans la liste noire
            if ($this->isTokenBlacklisted($token)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Token révoqué'
                ], 401);
            }
            
            return $this->respond([
                'success' => true,
                'message' => 'Token valide',
                'data' => [
                    'user_id' => $decoded->user_id,
                    'username' => $decoded->username,
                    'expires_at' => date('Y-m-d H:i:s', $decoded->exp)
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Token invalide: ' . $e->getMessage()
            ], 401);
        }
    }
    
    /**
     * Générer un token JWT
     */
    private function generateJWT($user)
    {
        $issuedAt = time();
        $expire = $issuedAt + $this->jwtExpiry;
        
        $payload = [
            'iat' => $issuedAt,
            'exp' => $expire,
            'user_id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email']
        ];
        
        return JWT::encode($payload, $this->jwtKey, 'HS256');
    }
    
    /**
     * Vérifier un token JWT
     */
    private function verifyJWT($token)
    {
        try {
            $decoded = JWT::decode($token, new Key($this->jwtKey, 'HS256'));
            return (array)$decoded;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Sauvegarder la session utilisateur
     */
    private function saveUserSession($userId, $token, $refreshToken)
    {
        // Créer la table si elle n'existe pas
        $this->createSessionTableIfNeeded();
        
        return $this->db->table('user_sessions')->insert([
            'user_id' => $userId,
            'token' => $token,
            'refresh_token' => $refreshToken,
            'ip_address' => $this->request->getIPAddress(),
            'user_agent' => $this->request->getUserAgent()->getAgentString(),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+' . $this->jwtExpiry . ' seconds')),
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Créer la table des sessions si nécessaire
     */
    private function createSessionTableIfNeeded()
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS user_sessions (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL,
                token VARCHAR(255) NOT NULL,
                refresh_token VARCHAR(255),
                ip_address VARCHAR(45),
                user_agent TEXT,
                expires_at DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_token (token),
                INDEX idx_expires (expires_at),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
    }
    
    /**
     * Récupérer le token depuis la requête
     */
    private function getTokenFromRequest()
    {
        $header = $this->request->getHeaderLine('Authorization');
        if (empty($header)) {
            return null;
        }
        
        return str_replace('Bearer ', '', $header);
    }
    
    /**
     * Récupérer les rôles de l'utilisateur
     */
    private function getUserRoles($userId)
    {
        try {
            $result = $this->db->table('user_roles')
                ->select('r.role_name')
                ->join('roles r', 'r.id = user_roles.role_id')
                ->where('user_roles.user_id', $userId)
                ->get()
                ->getResultArray();
            
            return array_column($result, 'role_name');
        } catch (\Exception $e) {
            return ['user'];
        }
    }
    
    /**
     * Récupérer les permissions de l'utilisateur
     */
    private function getUserPermissions($userId)
    {
        try {
            $result = $this->db->table('user_roles ur')
                ->select('p.permission_name')
                ->join('role_permissions rp', 'rp.role_id = ur.role_id')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('ur.user_id', $userId)
                ->get()
                ->getResultArray();
            
            return array_column($result, 'permission_name');
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Journaliser les tentatives échouées
     */
    private function logFailedAttempt($username, $ip, $userId = null)
    {
        try {
            $this->db->table('login_attempts')->insert([
                'username' => $username,
                'user_id' => $userId,
                'ip_address' => $ip,
                'attempt_time' => date('Y-m-d H:i:s'),
                'success' => 0
            ]);
        } catch (\Exception $e) {
            // Ignorer les erreurs de table manquante
        }
    }
    
    /**
     * Journaliser l'activité utilisateur
     */
    private function logActivity($userId, $action, $description)
    {
        try {
            $this->db->table('activity_logs')->insert([
                'user_id' => $userId,
                'action' => $action,
                'description' => $description,
                'ip_address' => $this->request->getIPAddress(),
                'user_agent' => $this->request->getUserAgent()->getAgentString(),
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            // Ignorer les erreurs de table manquante
        }
    }
    
    /**
     * Ajouter un token à la liste noire
     */
    private function blacklistToken($token)
    {
        try {
            $this->db->table('token_blacklist')->insert([
                'token' => $token,
                'blacklisted_at' => date('Y-m-d H:i:s'),
                'expires_at' => date('Y-m-d H:i:s', strtotime('+' . $this->jwtExpiry . ' seconds'))
            ]);
        } catch (\Exception $e) {
            // Ignorer les erreurs de table manquante
        }
    }
    
    /**
     * Vérifier si un token est blacklisté
     */
    private function isTokenBlacklisted($token)
    {
        try {
            $blacklisted = $this->db->table('token_blacklist')
                ->where('token', $token)
                ->where('expires_at >', date('Y-m-d H:i:s'))
                ->get()
                ->getRowArray();
            
            return !empty($blacklisted);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Envoyer l'email de réinitialisation
     */
    private function sendResetPasswordEmail($to, $name, $resetToken)
    {
        $resetLink = site_url('reset-password?token=' . $resetToken);
        
        $email = \Config\Services::email();
        $email->setTo($to);
        $email->setSubject('Réinitialisation de votre mot de passe - StockManager Pro');
        
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea, #764ba2); padding: 20px; text-align: center; color: white; }
                .content { padding: 20px; background: #f8f9fa; }
                .button { display: inline-block; padding: 12px 24px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; }
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
                    <p>Nous avons reçu une demande de réinitialisation de votre mot de passe.</p>
                    <p>Cliquez sur le bouton ci-dessous pour créer un nouveau mot de passe :</p>
                    <p style='text-align: center;'>
                        <a href='{$resetLink}' class='button'>Réinitialiser mon mot de passe</a>
                    </p>
                    <p>Ce lien expire dans 1 heure.</p>
                    <p>Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.</p>
                </div>
                <div class='footer'>
                    <p>© 2024 StockManager Pro. Tous droits réservés.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $email->setMessage($message);
        $email->setMailType('html');
        
        return $email->send();
    }
}