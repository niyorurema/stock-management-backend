<?php
// app/Models/SupplierModel.php

namespace App\Models;

use CodeIgniter\Model;

class SupplierModel extends Model
{
    protected $table = 'suppliers';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;
    protected $protectFields = true;
    
    protected $allowedFields = [
        'code', 'name', 'contact_person', 'email', 'phone', 'address',
        'tin', 'bank_account', 'payment_terms', 'is_active', 'notes',
        'created_by'
    ];
    
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';
    
    protected $validationRules = [
        'code' => 'required|is_unique[suppliers.code,id,{id}]',
        'name' => 'required|min_length[2]|max_length[200]',
        'email' => 'valid_email|is_unique[suppliers.email,id,{id}]',
        'phone' => 'required'
    ];
    
    protected $validationMessages = [
        'code' => [
            'required' => 'Le code est requis',
            'is_unique' => 'Ce code existe déjà'
        ],
        'name' => [
            'required' => 'Le nom est requis',
            'min_length' => 'Le nom doit contenir au moins 2 caractères'
        ],
        'email' => [
            'valid_email' => 'L\'email n\'est pas valide',
            'is_unique' => 'Cet email est déjà utilisé'
        ],
        'phone' => [
            'required' => 'Le téléphone est requis'
        ]
    ];
    
    /**
     * Générer un code fournisseur unique
     */
    public function generateCode()
    {
        $lastSupplier = $this->orderBy('id', 'DESC')->first();
        
        if ($lastSupplier) {
            $lastCode = $lastSupplier['code'];
            $number = intval(substr($lastCode, -4)) + 1;
            $newNumber = str_pad($number, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }
        
        return 'SUP-' . $newNumber;
    }
    
    /**
     * Récupérer les fournisseurs actifs
     */
    public function getActiveSuppliers()
    {
        return $this->where('is_active', 1)
                    ->orderBy('name', 'ASC')
                    ->findAll();
    }
    
    /**
     * Récupérer les fournisseurs avec statistiques
     */
    public function getSuppliersWithStats()
    {
        $suppliers = $this->where('is_active', 1)->findAll();
        
        foreach ($suppliers as &$supplier) {
            // Nombre de commandes
            $supplier['order_count'] = $this->db->table('supplier_orders')
                ->where('supplier_id', $supplier['id'])
                ->countAllResults();
            
            // Montant total des commandes
            $totalAmount = $this->db->table('supplier_orders')
                ->selectSum('total_amount')
                ->where('supplier_id', $supplier['id'])
                ->where('status !=', 'cancelled')
                ->get()
                ->getRow();
            
            $supplier['total_orders_amount'] = $totalAmount->total_amount ?? 0;
        }
        
        return $suppliers;
    }
}