<?php
// E:\laragon\www\stock-management\backend\app\Controllers\Auth.php

namespace App\Controllers;

class Auth extends BaseController
{
    public function login()
    {
        // Headers CORS
        $this->response->setHeader('Access-Control-Allow-Origin', '*');
        $this->response->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $this->response->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        $this->response->setHeader('Content-Type', 'application/json');
        
        // Gérer OPTIONS (preflight)
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
        
        // Test simple - admin/password
        if ($username === 'admin' && $password === 'password') {
            $token = bin2hex(random_bytes(32));
            
            return $this->response->setJSON([
                'success' => true,
                'message' => 'Connexion réussie',
                'data' => [
                    'token' => $token,
                    'user' => [
                        'id' => 1,
                        'username' => 'admin',
                        'email' => 'admin@stockmanager.com',
                        'full_name' => 'Administrateur',
                        'roles' => ['super_admin'],
                        'permissions' => ['*']
                    ]
                ]
            ]);
        }
        
        return $this->response
            ->setStatusCode(401)
            ->setJSON([
                'success' => false,
                'message' => 'Nom d\'utilisateur ou mot de passe incorrect'
            ]);
    }
    
    public function me()
    {
        $this->response->setHeader('Access-Control-Allow-Origin', '*');
        $this->response->setHeader('Content-Type', 'application/json');
        
        return $this->response->setJSON([
            'success' => true,
            'data' => [
                'id' => 1,
                'username' => 'admin',
                'email' => 'admin@stockmanager.com',
                'full_name' => 'Administrateur'
            ]
        ]);
    }
    
    public function logout()
    {
        $this->response->setHeader('Access-Control-Allow-Origin', '*');
        $this->response->setHeader('Content-Type', 'application/json');
        
        return $this->response->setJSON([
            'success' => true,
            'message' => 'Déconnexion réussie'
        ]);
    }
}