<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table = 'users';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'username', 'email', 'password_hash', 'full_name', 'phone',
        'is_active', 'last_login', 'last_ip', 'password_reset_token',
        'reset_token_expiry', 'avatar',
    ];
    
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    
    protected $validationRules = [
        'username' => 'required|min_length[3]|max_length[50]|is_unique[users.username,id,{id}]',
        'email'    => 'required|valid_email|is_unique[users.email,id,{id}]',
        'full_name' => 'required|min_length[3]|max_length[100]',
    ];
    
    protected $validationMessages = [];
    protected $skipValidation = false;
    
    public function getUserRoles($userId)
    {
        return $this->db->table('user_roles ur')
            ->select('r.role_name, r.id')
            ->join('roles r', 'r.id = ur.role_id')
            ->where('ur.user_id', $userId)
            ->get()
            ->getResultArray();
    }
    
    public function getUserPermissions($userId)
    {
        return $this->db->table('user_roles ur')
            ->select('p.permission_name')
            ->join('role_permissions rp', 'rp.role_id = ur.role_id')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('ur.user_id', $userId)
            ->get()
            ->getResultArray();
    }
}