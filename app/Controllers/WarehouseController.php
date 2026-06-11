<?php
// app/Controllers/WarehouseController.php

namespace App\Controllers;

use App\Models\WarehouseModel;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class WarehouseController extends ResourceController
{
    use ResponseTrait;

    protected $modelName = WarehouseModel::class;
    protected $format = 'json';

    // Dans WarehouseController.php, méthode index()
    public function index()
    {
        try {
            $search = $this->request->getVar('search');
            $isActive = $this->request->getVar('is_active');

            $builder = $this->model->orderBy('name', 'ASC');

            if ($search) {
                $builder->groupStart()
                    ->like('code', $search)
                    ->orLike('name', $search)
                    ->orLike('location', $search)
                    ->orLike('manager_name', $search)
                    ->groupEnd();
            }

            if ($isActive !== null) {
                $builder->where('is_active', $isActive);
            }

            $warehouses = $builder->findAll();
            $db = \Config\Database::connect();

            foreach ($warehouses as &$warehouse) {
                // Nombre de produits distincts dans l'entrepôt
                $productCount = $db->table('stock_movements')
                    ->select('COUNT(DISTINCT product_id) as count')
                    ->where('warehouse_id', $warehouse['id'])
                    ->get()
                    ->getRow();

                // Valeur totale du stock (derniers mouvements)
                $subquery = $db->table('stock_movements')
                    ->select('product_id, MAX(id) as last_id')
                    ->where('warehouse_id', $warehouse['id'])
                    ->groupBy('product_id')
                    ->getCompiledSelect();

                $stockValue = $db->table('stock_movements sm')
                    ->select('SUM(sm.new_quantity * sm.unit_cost) as total_value')
                    ->join("($subquery) latest", 'latest.last_id = sm.id')
                    ->get()
                    ->getRow();

                // Nombre de mouvements récents
                $recentMovements = $db->table('stock_movements')
                    ->where('warehouse_id', $warehouse['id'])
                    ->where('movement_date >=', date('Y-m-d', strtotime('-30 days')))
                    ->countAllResults();

                $warehouse['product_count'] = $productCount->count ?? 0;
                $warehouse['stock_value'] = $stockValue->total_value ?? 0;
                $warehouse['recent_movements'] = $recentMovements;
            }


            // echo '<pre>';

            // print_r($warehouses);

            // echo '</pre>';

            return $this->respond([
                'success' => true,
                'data' => $warehouses
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Warehouse index error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors du chargement des entrepôts'
            ], 500);
        }
    }

    private function generateCode()
    {
        $lastWarehouse = $this->model->orderBy('id', 'DESC')->first();
        $lastNumber = $lastWarehouse ? intval(substr($lastWarehouse['code'], 2)) : 0;
        $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        return 'W' . $newNumber;
    }

    /**
     * GET /api/warehouses/(:num) - Détail d'un entrepôt
     */
    public function show($id = null)
    {

        try {
            $warehouse = $this->model->find($id);

            if (!$warehouse) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Entrepôt non trouvé'
                ], 404);
            }

            // Récupérer les produits de l'entrepôt
            $db = \Config\Database::connect();
            $products = $this->getWarehouseProducts($id); /*$db->table('stock-movements')
                ->select('id, code, name, current_stock, min_stock_alert, unit, selling_price, purchase_price')
                ->where('warehouse_id', $id)
                ->orderBy('name', 'ASC')
                ->limit(20)
                ->get()
                ->getResultArray();*/

            // Récupérer les mouvements récents
            $movements = $db->table('stock_movements sm')
                ->select('sm.*, p.name as product_name')
                ->join('products p', 'p.id = sm.product_id')
                ->where('sm.warehouse_id', $id)
                ->orderBy('sm.movement_date', 'DESC')
                ->limit(20)
                ->get()
                ->getResultArray();


            $subquery = $db->table('stock_movements')
                ->select('product_id, MAX(id) as last_id')
                ->where('warehouse_id', $warehouse['id'])
                ->groupBy('product_id')
                ->getCompiledSelect();

            $stockValue = $db->table('stock_movements sm1')
                ->select('SUM(sm1.new_quantity * sm1.unit_cost) as total_value')
                ->join("($subquery) sm2", 'sm2.last_id = sm1.id')
                ->get()
                ->getRow();


            $productCount = $db->table('stock_movements')
                ->select('COUNT(DISTINCT product_id) as count')
                ->where('warehouse_id', $warehouse['id'])
                ->get()
                ->getRow();

            $warehouse['stock_value'] = $stockValue->total_value;
            $warehouse['product_count'] =  $productCount->count;
            $warehouse['products'] = $products;
            $warehouse['recent_movements'] = $movements;

            return $this->respond([
                'success' => true,
                'data' => $warehouse
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Warehouse show error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors du chargement des détails'
            ], 500);
        }
    }


    /**
     * Récupérer les produits d'un entrepôt avec leur stock actuel
     */
    private function getWarehouseProducts($warehouseId, $limit = 9999)
    {
        $db = \Config\Database::connect();

        // Obtenir le dernier mouvement pour chaque produit dans cet entrepôt
        $subquery = $db->table('stock_movements')
            ->select('product_id, MAX(id) as last_id')
            ->where('warehouse_id', $warehouseId)
            ->groupBy('product_id')
            ->getCompiledSelect();

        $products = $db->table('stock_movements sm')
            ->select('
            p.id, 
            p.code, 
            p.name, 
            COALESCE(sm.new_quantity, 0) as current_stock,
            p.min_stock_alert, 
            p.unit, 
            p.selling_price, 
            p.purchase_price,
            CASE 
                WHEN COALESCE(sm.new_quantity, 0) <= p.min_stock_alert AND p.min_stock_alert > 0 
                THEN 1 
                ELSE 0 
            END as is_critical
        ')
            ->join("($subquery) latest", 'latest.last_id = sm.id')
            ->join('products p', 'p.id = sm.product_id')
            ->orderBy('p.name', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        // Ajouter le stock critique
        foreach ($products as &$product) {
            $product['stock_status'] = $product['is_critical'] ? 'critical' : 'normal';
            if ($product['current_stock'] == 0) {
                $product['stock_status'] = 'out_of_stock';
            }
        }

        return $products;
    }


    public function create()
    {
        try {
            $input = $this->request->getJSON(true);

            // Validation
            if (empty($input['name'])) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Le nom de l\'entrepôt est requis'
                ], 400);
            }

            // Générer le code automatiquement
            $code = $this->generateCode();

            $data = [
                'code' => $code,  // Code généré automatiquement
                'name' => $input['name'],
                'location' => $input['location'] ?? null,
                'manager_name' => $input['manager_name'] ?? null,
                'phone' => $input['phone'] ?? null,
                'email' => $input['email'] ?? null,
                'description' => $input['description'] ?? null,
                'is_active' => isset($input['is_active']) ? (int)$input['is_active'] : 1,
                'created_by' => $this->request->user_id ?? session()->get('user_id')
            ];

            $db = \Config\Database::connect();

            $id = $db->table('warehouses')
                ->insert($data); //$this->model->insert($data);

            if (!$id) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Erreur lors de la création11'
                ], 500);
            }

            $warehouse = $this->model->find($id);

            return $this->respond([
                'success' => true,
                'message' => 'Entrepôt créé avec succès',
                'data' => $warehouse
            ], 201);
        } catch (\Exception $e) {
            log_message('error', 'Warehouse create error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors de la création ' . $e->getMessage()
            ], 500);
        }
    }



    /**
     * PUT /api/warehouses/(:num) - Mettre à jour un entrepôt
     */
    public function update($id = null)
    {
        try {
            $warehouse = $this->model->find($id);
            if (!$warehouse) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Entrepôt non trouvé'
                ], 404);
            }

            $input = $this->request->getJSON(true);

            // Vérifier l'unicité du code (sauf pour l'entrepôt actuel)
            /* if (!empty($input['code']) && $input['code'] !== $warehouse['code']) {
                $existing = $this->model->where('code', $input['code'])->first();
                if ($existing) {
                    return $this->respond([
                        'success' => false,
                        'message' => 'Ce code d\'entrepôt existe déjà'
                    ], 400);
                }
            }*/

            $data = [
                'name' => $input['name'] ?? $warehouse['name'],
                'location' => $input['location'] ?? $warehouse['location'],
                'manager_name' => $input['manager_name'] ?? $warehouse['manager_name'],
                'phone' => $input['phone'] ?? $warehouse['phone'],
                'email' => $input['email'] ?? $warehouse['email'],
                'DESCRIPTION' => $input['description'] ?? $warehouse['DESCRIPTION'],
                'is_active' => isset($input['is_active']) ? (int)$input['is_active'] : $warehouse['is_active'],
                'updated_by' => session()->get('user_id')
            ];

            // $this->model->update($id, $data);


            $db = \Config\Database::connect();
            $db->table('warehouses')
                ->where('id', $id)
                ->update($data);

            return $this->respond([
                'success' => true,
                'message' => 'Entrepôt mis à jour avec succès'
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Warehouse update error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE /api/warehouses/(:num) - Supprimer un entrepôt
     */
    public function delete($id = null)
    {
        try {
            $warehouse = $this->model->find($id);

            if (!$warehouse) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Entrepôt non trouvé'
                ], 404);
            }

            // Vérifier si l'entrepôt contient des produits
            $db = \Config\Database::connect();
            $hasProducts = $db->table('stock_movements')
                ->where('warehouse_id', $id)
                ->countAllResults() > 0;

            if ($hasProducts) {
                // Désactiver uniquement
                $this->model->update($id, ['is_active' => 0]);
                return $this->respond([
                    'success' => true,
                    'message' => 'Entrepôt désactivé avec succès (il contient des mouvements de stock)'
                ]);
            }

            $this->model->delete($id);

            return $this->respond([
                'success' => true,
                'message' => 'Entrepôt supprimé avec succès'
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Warehouse delete error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors de la suppression'
            ], 500);
        }
    }

    /**
     * PATCH /api/warehouses/(:num)/toggle-status - Activer/Désactiver
     */
    public function toggleStatus($id = null)
    {
        try {
            $warehouse = $this->model->find($id);

            if (!$warehouse) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Entrepôt non trouvé'
                ], 404);
            }

            $newStatus = $warehouse['is_active'] ? 0 : 1;
            $this->model->update($id, ['is_active' => $newStatus]);

            return $this->respond([
                'success' => true,
                'message' => $newStatus ? 'Entrepôt activé' : 'Entrepôt désactivé',
                'data' => ['is_active' => $newStatus]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors du changement de statut'
            ], 500);
        }
    }

    /**
     * GET /api/warehouses/(:num)/stock-value - Valeur du stock
     */
    public function getStockValue($id = null)
    {
        try {
            $warehouse = $this->model->find($id);

            if (!$warehouse) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Entrepôt non trouvé'
                ], 404);
            }

            $db = \Config\Database::connect();

            // Valeur totale du stock
            $stockValue = $db->table('products')
                ->selectSum('current_stock * purchase_price', 'total_value')
                ->where('warehouse_id', $id)
                ->get()
                ->getRow();

            // Valeur de vente potentielle
            $salesValue = $db->table('products')
                ->selectSum('current_stock * selling_price', 'total_value')
                ->where('warehouse_id', $id)
                ->get()
                ->getRow();

            // Produits en stock critique
            $criticalProducts = $db->table('products')
                ->where('warehouse_id', $id)
                ->where('current_stock <=', 'min_stock_alert', false)
                ->where('min_stock_alert >', 0)
                ->countAllResults();

            return $this->respond([
                'success' => true,
                'data' => [
                    'stock_value' => $stockValue->total_value ?? 0,
                    'sales_value' => $salesValue->total_value ?? 0,
                    'critical_products' => $criticalProducts
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors du calcul de la valeur du stock'
            ], 500);
        }
    }
}
