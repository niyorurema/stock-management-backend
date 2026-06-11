<?php
// app/Models/WarehouseModel.php

namespace App\Models;

use CodeIgniter\Model;

class WarehouseModel extends Model
{
    protected $table = 'warehouses';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'code',
        'name',
        'location',
        'manager_name',
        'phone',
        'email',
        'DESCRIPTION',
        'is_active',
        'created_by',
        'updated_by'
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    protected $validationRules = [
        'code' => 'required|min_length[2]|max_length[20]|is_unique[warehouses.code,id,{id}]',
        'name' => 'required|min_length[2]|max_length[100]',
        'email' => 'permit_empty|valid_email'
    ];

    protected $validationMessages = [
        'code' => [
            'required' => 'Le code de l\'entrepôt est requis',
            'min_length' => 'Le code doit contenir au moins 2 caractères',
            'is_unique' => 'Ce code d\'entrepôt existe déjà'
        ],
        'name' => [
            'required' => 'Le nom de l\'entrepôt est requis',
            'min_length' => 'Le nom doit contenir au moins 2 caractères'
        ],
        'email' => [
            'valid_email' => 'Veuillez entrer une adresse email valide'
        ]
    ];

    /**
     * Récupérer les entrepôts actifs
     */
    /*public function getActiveWarehouses()
    {
        return $this->where('is_active', 1)->orderBy('name')->findAll();
    }*/

    /**
     * Récupérer un entrepôt par son code
     */
    public function getByCode($code)
    {
        return $this->where('code', $code)->first();
    }

    public function getActiveWarehouses()
    {
        return $this->where('is_active', 1)->orderBy('name', 'ASC')->findAll();
    }

    /**
     * Générer un code d'entrepôt automatique
     */
    public function generateCode()
    {
        $lastWarehouse = $this->orderBy('id', 'DESC')->first();
        $lastNumber = $lastWarehouse ? intval(substr($lastWarehouse['code'], 2)) : 0;
        $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        return 'W' . $newNumber;
    }
}
