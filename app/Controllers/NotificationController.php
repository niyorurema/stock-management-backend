<?php

namespace App\Controllers;

use App\Libraries\JwtAuth;
use CodeIgniter\API\ResponseTrait;

class NotificationController extends BaseController
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
     * GET /api/notifications
     */
    public function index()
    {
        $userId = $this->jwtAuth->getUserIdFromRequest($this->request);
        if (!$userId) {
            return $this->respond(['success' => false, 'message' => 'Non authentifié'], 401);
        }

        $limit = min(100, max(1, (int) ($this->request->getVar('limit') ?: 20)));
        $unreadOnly = filter_var($this->request->getVar('unread_only'), FILTER_VALIDATE_BOOLEAN);

        $builder = $this->db->table('notifications')
            ->groupStart()
                ->where('user_id', $userId)
                ->orWhere('user_id', null)
            ->groupEnd()
            ->orderBy('created_at', 'DESC');

        if ($unreadOnly) {
            $builder->where('is_read', 0);
        }

        $notifications = $builder->limit($limit)->get()->getResultArray();

        return $this->respond([
            'success' => true,
            'data' => array_map([$this, 'formatNotification'], $notifications),
        ]);
    }

    /**
     * GET /api/notifications/unread-count
     */
    public function unreadCount()
    {
        $userId = $this->jwtAuth->getUserIdFromRequest($this->request);
        if (!$userId) {
            return $this->respond(['success' => false, 'message' => 'Non authentifié'], 401);
        }

        $count = $this->db->table('notifications')
            ->groupStart()
                ->where('user_id', $userId)
                ->orWhere('user_id', null)
            ->groupEnd()
            ->where('is_read', 0)
            ->countAllResults();

        return $this->respond([
            'success' => true,
            'data' => ['count' => (int) $count],
        ]);
    }

    /**
     * PATCH /api/notifications/(:num)/read
     */
    public function markRead($id = null)
    {
        $userId = $this->jwtAuth->getUserIdFromRequest($this->request);
        if (!$userId) {
            return $this->respond(['success' => false, 'message' => 'Non authentifié'], 401);
        }

        if (!$this->canAccessNotification($id, $userId)) {
            return $this->respond(['success' => false, 'message' => 'Notification introuvable'], 404);
        }

        $this->db->table('notifications')->where('id', $id)->update(['is_read' => 1]);

        return $this->respond(['success' => true, 'message' => 'Notification marquée comme lue']);
    }

    /**
     * PATCH /api/notifications/read-all
     */
    public function markAllRead()
    {
        $userId = $this->jwtAuth->getUserIdFromRequest($this->request);
        if (!$userId) {
            return $this->respond(['success' => false, 'message' => 'Non authentifié'], 401);
        }

        $this->db->table('notifications')
            ->groupStart()
                ->where('user_id', $userId)
                ->orWhere('user_id', null)
            ->groupEnd()
            ->where('is_read', 0)
            ->update(['is_read' => 1]);

        return $this->respond(['success' => true, 'message' => 'Toutes les notifications ont été marquées comme lues']);
    }

    /**
     * DELETE /api/notifications/(:num)
     */
    public function delete($id = null)
    {
        $userId = $this->jwtAuth->getUserIdFromRequest($this->request);
        if (!$userId) {
            return $this->respond(['success' => false, 'message' => 'Non authentifié'], 401);
        }

        if (!$this->canAccessNotification($id, $userId)) {
            return $this->respond(['success' => false, 'message' => 'Notification introuvable'], 404);
        }

        $this->db->table('notifications')->where('id', $id)->delete();

        return $this->respond(['success' => true, 'message' => 'Notification supprimée']);
    }

    private function canAccessNotification($id, int $userId): bool
    {
        if (!$id) {
            return false;
        }

        $row = $this->db->table('notifications')
            ->where('id', $id)
            ->groupStart()
                ->where('user_id', $userId)
                ->orWhere('user_id', null)
            ->groupEnd()
            ->get()
            ->getRowArray();

        return !empty($row);
    }

    private function formatNotification(array $row): array
    {
        $type = $row['type'] ?? 'info';
        $icons = [
            'danger' => '❌',
            'warning' => '⚠️',
            'success' => '✅',
            'info' => 'ℹ️',
            'invoice' => '📄',
            'stock' => '📦',
        ];

        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'message' => $row['message'],
            'type' => $type,
            'icon' => $icons[$type] ?? '🔔',
            'is_read' => (bool) ($row['is_read'] ?? false),
            'link' => $row['link'] ?? null,
            'created_at' => $row['created_at'],
        ];
    }
}
