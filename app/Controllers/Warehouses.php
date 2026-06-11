<?php
// app/Controllers/Warehouses.php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class Warehouses extends ResourceController
{
    use ResponseTrait;
    
    protected $db;
    
    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }
    
    /**
     * GET /api/warehouses - Liste des entrepôts
     */
    public function index()
    {
        try {
            $warehouses = $this->db->table('warehouses')
                ->select('id, code, name, location, manager_name, phone, email, is_active')
                ->where('is_active', 1)
                ->orderBy('name', 'ASC')
                ->get()
                ->getResultArray();
            
            return $this->respond([
                'success' => true,
                'data' => $warehouses
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * GET /api/warehouses/(:num) - Détail d'un entrepôt
     */
    public function show($id = null)
    {
        try {
            $warehouse = $this->db->table('warehouses')
                ->where('id', $id)
                ->get()
                ->getRowArray();
            
            if (!$warehouse) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Entrepôt non trouvé'
                ], 404);
            }
            
            return $this->respond([
                'success' => true,
                'data' => $warehouse
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}