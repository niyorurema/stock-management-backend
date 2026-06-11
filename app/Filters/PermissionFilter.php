<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Permissions as PermissionsConfig;
use Config\Services;

class PermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (strtolower($request->getMethod()) === 'options') {
            return;
        }

        $currentUser = service('currentUser');

        if (!$currentUser->isAuthenticated()) {
            return Services::response()->setJSON([
                'success' => false,
                'message' => 'Non authentifié',
            ])->setStatusCode(401);
        }

        // Permission explicite passée au filtre: permission:products.view
        if (!empty($arguments)) {
            $required = is_array($arguments) ? $arguments : explode(',', $arguments[0] ?? '');
            $required = array_map('trim', $required);
            if (!$currentUser->canAny($required)) {
                return $this->deny($required);
            }
            return;
        }

        $path = $this->normalizePath($request);
        $method = strtoupper($request->getMethod());

        foreach (PermissionsConfig::$authOnly as $pattern) {
            [$pMethod, $pPath] = explode(' ', $pattern, 2);
            if ($method === $pMethod && $this->pathMatches($path, $pPath)) {
                return;
            }
        }

        $permission = $this->resolvePermission($method, $path);
        if ($permission === null) {
            // Route non cartographiée: super_admin ou tout utilisateur avec au moins un rôle
            if ($currentUser->isSuperAdmin() || !empty($currentUser->roles)) {
                return;
            }
            return $this->deny(['(route non configurée)']);
        }

        $required = is_array($permission) ? $permission : [$permission];
        if (!$currentUser->canAny($required)) {
            return $this->deny($required);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }

    protected function normalizePath(RequestInterface $request): string
    {
        $uri = $request->getUri();
        $path = trim($uri->getPath(), '/');
        if (str_starts_with($path, 'api/')) {
            $path = substr($path, 4);
        } elseif ($path === 'api') {
            $path = '';
        }
        return $path;
    }

    protected function resolvePermission(string $method, string $path): string|array|null
    {
        $key = $method . ' ' . $path;
        if (isset(PermissionsConfig::$routes[$key])) {
            return PermissionsConfig::$routes[$key];
        }

        foreach (PermissionsConfig::$routes as $pattern => $perm) {
            [$pMethod, $pPath] = explode(' ', $pattern, 2);
            if ($pMethod !== $method) {
                continue;
            }
            if ($this->pathMatches($path, $pPath)) {
                return $perm;
            }
        }

        return null;
    }

    protected function pathMatches(string $path, string $pattern): bool
    {
        if ($pattern === $path) {
            return true;
        }
        $parts = explode('*', $pattern);
        $regex = '#^';
        foreach ($parts as $i => $part) {
            $regex .= preg_quote($part, '#');
            if ($i < count($parts) - 1) {
                $regex .= '[^/]+';
            }
        }
        $regex .= '$#';
        return (bool) preg_match($regex, $path);
    }

    protected function deny(array $required)
    {
        return Services::response()->setJSON([
            'success' => false,
            'message' => 'Accès refusé. Permission requise : ' . implode(' ou ', $required),
            'required_permissions' => $required,
        ])->setStatusCode(403);
    }
}
