<?php
// app/Controllers/SupplierController.php

namespace App\Controllers;

use App\Models\SupplierModel;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class SupplierController extends ResourceController
{
    use ResponseTrait;
    
    protected $modelName = SupplierModel::class;
    protected $format = 'json';
    
    /**
     * GET /api/suppliers - Liste des fournisseurs
     */
    public function index()
    {
        try {
            $search = $this->request->getVar('search');
            $isActive = $this->request->getVar('is_active');
            
            $builder = $this->model->orderBy('name', 'ASC');
            
            if ($search) {
                $builder->groupStart()
                    ->like('name', $search)
                    ->orLike('code', $search)
                    ->orLike('contact_person', $search)
                    ->orLike('email', $search)
                    ->orLike('phone', $search)
                    ->groupEnd();
            }
            
            if ($isActive !== null) {
                $builder->where('is_active', $isActive);
            }
            
            $suppliers = $builder->findAll();
            
            return $this->respond([
                'success' => true,
                'data' => $suppliers
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Supplier index error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * GET /api/suppliers/(:num) - Détail d'un fournisseur
     */
    public function show($id = null)
    {
        try {
            $supplier = $this->model->find($id);
            
            if (!$supplier) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Fournisseur non trouvé'
                ], 404);
            }
            
            return $this->respond([
                'success' => true,
                'data' => $supplier
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * POST /api/suppliers - Créer un fournisseur
     */
    public function create()
    {
        try {
            $input = $this->request->getJSON(true);
            
            // Validation
            if (empty($input['name'])) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Le nom du fournisseur est requis'
                ], 400);
            }
            
            if (empty($input['phone'])) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Le téléphone est requis'
                ], 400);
            }
            
            // Générer le code
            $code = $this->generateCode();
            
            // Préparer les données - n'inclure que les colonnes qui existent
            $data = [
                'code' => $code,
                'name' => $input['name'],
                'contact_person' => $input['contact_person'] ?? null,
                'email' => $input['email'] ?? null,
                'phone' => $input['phone'],
                'address' => $input['address'] ?? null,
                'tin' => $input['tin'] ?? null,
                'bank_account' => $input['bank_account'] ?? null,
                'payment_terms' => $input['payment_terms'] ?? 30,
                'is_active' => isset($input['is_active']) ? (int)$input['is_active'] : 1,
                'notes' => $input['notes'] ?? null
            ];
            
            // Ajouter created_by si la colonne existe
            $db = \Config\Database::connect();
            $fields = $db->getFieldNames('suppliers');
            if (in_array('created_by', $fields)) {
                $data['created_by'] = session()->get('user_id');
            }
            
            log_message('debug', 'Tentative insertion fournisseur: ' . json_encode($data));
            
            $id = $this->model->insert($data);
            
            if (!$id) {
                $errors = $this->model->errors();
                log_message('error', 'Erreur insertion: ' . json_encode($errors));
                return $this->respond([
                    'success' => false,
                    'message' => 'Erreur lors de la création: ' . json_encode($errors)
                ], 500);
            }
            
            $supplier = $this->model->find($id);
            
            return $this->respond([
                'success' => true,
                'message' => 'Fournisseur créé avec succès',
                'data' => $supplier
            ], 201);
        } catch (\Exception $e) {
            log_message('error', 'Supplier create error: ' . $e->getMessage());
            log_message('error', 'Supplier create trace: ' . $e->getTraceAsString());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * PUT /api/suppliers/(:num) - Mettre à jour un fournisseur
     */
    public function update($id = null)
    {
        try {
            $supplier = $this->model->find($id);
            
            if (!$supplier) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Fournisseur non trouvé'
                ], 404);
            }
            
            $input = $this->request->getJSON(true);
            
            $data = [
                'name' => $input['name'] ?? $supplier['name'],
                'contact_person' => $input['contact_person'] ?? $supplier['contact_person'],
                'email' => $input['email'] ?? $supplier['email'],
                'phone' => $input['phone'] ?? $supplier['phone'],
                'address' => $input['address'] ?? $supplier['address'],
                'tin' => $input['tin'] ?? $supplier['tin'],
                'bank_account' => $input['bank_account'] ?? $supplier['bank_account'],
                'payment_terms' => $input['payment_terms'] ?? $supplier['payment_terms'],
                'is_active' => isset($input['is_active']) ? (int)$input['is_active'] : $supplier['is_active'],
                'notes' => $input['notes'] ?? $supplier['notes']
            ];
            
            $this->model->update($id, $data);
            
            return $this->respond([
                'success' => true,
                'message' => 'Fournisseur mis à jour avec succès'
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Supplier update error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * DELETE /api/suppliers/(:num) - Supprimer un fournisseur
     */
    public function delete($id = null)
    {
        try {
            $supplier = $this->model->find($id);
            
            if (!$supplier) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Fournisseur non trouvé'
                ], 404);
            }
            
            $this->model->delete($id);
            
            return $this->respond([
                'success' => true,
                'message' => 'Fournisseur supprimé avec succès'
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Supplier delete error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Générer un code fournisseur unique
     */
    private function generateCode()
    {
        $lastSupplier = $this->model->orderBy('id', 'DESC')->first();
        
        if ($lastSupplier && isset($lastSupplier['code'])) {
            $lastCode = $lastSupplier['code'];
            $number = intval(substr($lastCode, -4)) + 1;
            $newNumber = str_pad($number, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }
        
        $code = 'SUP-' . $newNumber;
        
        // Vérifier l'unicité
        $existing = $this->model->where('code', $code)->first();
        if ($existing) {
            return $this->generateCode();
        }
        
        return $code;
    }
}
