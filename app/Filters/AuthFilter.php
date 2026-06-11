<?php

namespace App\Filters;

use App\Libraries\JwtAuth;
use App\Services\CurrentUser;
use App\Services\PermissionService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (strtolower($request->getMethod()) === 'options') {
            return;
        }

        $jwtAuth = new JwtAuth();
        $token = $jwtAuth->getTokenFromRequest($request);

        if (empty($token)) {
            return Services::response()->setJSON([
                'success' => false,
                'message' => 'Token d\'authentification requis',
            ])->setStatusCode(401);
        }

        $userId = null;
        $db = \Config\Database::connect();

        $session = $db->table('user_sessions')
            ->select('user_id')
            ->where('token', $token)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->get()
            ->getRowArray();

        if ($session) {
            $userId = (int) $session['user_id'];
        }

        if (!$userId) {
            $payload = $jwtAuth->verify($token);
            if ($payload && !empty($payload['user_id'])) {
                $userId = (int) $payload['user_id'];
            }
        }

        if (!$userId) {
            return Services::response()->setJSON([
                'success' => false,
                'message' => 'Session invalide ou token expiré',
            ])->setStatusCode(401);
        }

        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();
        if (!$user || (isset($user['is_active']) && !$user['is_active'])) {
            return Services::response()->setJSON([
                'success' => false,
                'message' => 'Compte utilisateur inactif ou introuvable',
            ])->setStatusCode(403);
        }

        $permService = new PermissionService();
        $loaded = $permService->loadForUser($userId);

        /** @var CurrentUser $currentUser */
        $currentUser = service('currentUser');
        $currentUser->set($userId, $loaded['roles'], $loaded['permissions']);

        $request->user_id = $userId;
        $request->user_roles = $loaded['roles'];
        $request->user_permissions = $loaded['permissions'];
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        service('currentUser')->clear();
    }
}
