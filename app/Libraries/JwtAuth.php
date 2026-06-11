<?php

namespace App\Libraries;

use CodeIgniter\HTTP\IncomingRequest;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtAuth
{
    protected string $jwtKey;

    public function __construct()
    {
        $envKey = getenv('jwt.secret.key');
        $this->jwtKey = (!empty($envKey) && strlen($envKey) >= 32)
            ? $envKey
            : 'aB3dE5fG7hI9jK1lM2nO4pQ6rS8tU0vW2xY';
    }

    public function getTokenFromRequest(IncomingRequest $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if (!empty($header)) {
            return trim(str_ireplace('Bearer ', '', $header));
        }

        // En-tête alternatif (certains proxies Apache suppriment Authorization)
        $alt = $request->getHeaderLine('X-Auth-Token');
        if (!empty($alt)) {
            return trim($alt);
        }

        // Workarounds Apache / CGI
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return trim(str_ireplace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']));
        }
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return trim(str_ireplace('Bearer ', '', $_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
        }

        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'authorization') {
                    return trim(str_ireplace('Bearer ', '', $value));
                }
                if (strtolower($key) === 'x-auth-token') {
                    return trim($value);
                }
            }
        }

        return null;
    }

    public function verify(?string $token): ?array
    {
        if (empty($token)) {
            return null;
        }
        try {
            $decoded = JWT::decode($token, new Key($this->jwtKey, 'HS256'));
            return (array) $decoded;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getUserIdFromRequest(IncomingRequest $request): ?int
    {
        $token = $this->getTokenFromRequest($request);
        $data = $this->verify($token);
        return isset($data['user_id']) ? (int) $data['user_id'] : null;
    }
}
