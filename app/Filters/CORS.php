<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class CORS implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $response = service('response');

        // Autoriser toutes les origines (nécessaire pour Vercel)
        $response->setHeader('Access-Control-Allow-Origin', '*');
        $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
        $response->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, x-auth-token, X-CSRF-TOKEN');
        $response->setHeader('Access-Control-Allow-Credentials', 'true');
        $response->setHeader('Access-Control-Max-Age', '86400');

        // Gérer les requêtes OPTIONS
        if ($request->getMethod() === 'options') {
            $response->setStatusCode(200);
            $response->send();
            exit();
        }

        return $request;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return $response;
    }
}
