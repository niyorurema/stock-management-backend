<?php
// backend/app/Controllers/ProductController.php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;
use App\Models\ProductModel;
use App\Models\CategoryModel;
use App\Models\StockMovementModel;
use App\Models\WarehouseModel;
use App\Services\ReservationService;

class ProductController extends ResourceController
{
    use ResponseTrait;

    protected $db;
    protected $productModel;
    protected $categoryModel;
    protected $format = 'json';

    // Taux de change par défaut
    private $exchangeRates = [
        'AED_to_USD' => 3.6725,
        'USD_to_BIF' => 2830
    ];

    public function __construct()
    {
        $this->productModel = new ProductModel();
        $this->categoryModel = new CategoryModel();
        $this->db = \Config\Database::connect();
        $this->loadExchangeRates();
    }

    private function loadExchangeRates()
    {
        try {
            $rates = $this->db->table('exchange_rates')
                ->where('from_currency', 'AED')
                ->where('to_currency', 'USD')
                ->orderBy('effective_date', 'DESC')
                ->get()
                ->getRowArray();

            if ($rates) {
                $this->exchangeRates['AED_to_USD'] = $rates['rate'];
            }

            $rates = $this->db->table('exchange_rates')
                ->where('from_currency', 'USD')
                ->where('to_currency', 'BIF')
                ->orderBy('effective_date', 'DESC')
                ->get()
                ->getRowArray();

            if ($rates) {
                $this->exchangeRates['USD_to_BIF'] = $rates['rate'];
            }
        } catch (\Exception $e) {
            log_message('error', 'Erreur chargement taux de change: ' . $e->getMessage());
        }
    }

    // Convertir les prix entre devises
    private function convertPrices($priceAed)
    {
        $priceUsd = $priceAed / $this->exchangeRates['AED_to_USD'];
        $priceBif = $priceUsd * $this->exchangeRates['USD_to_BIF'];
        return [
            'aed' => $priceAed,
            'usd' => round($priceUsd, 2),
            'bif' => round($priceBif, 0)
        ];
    }

    /**
     * GET /api/products - Liste tous les produits avec filtres et pagination
     */
    public function index()
    {
        try {
            $page = max(1, (int)($this->request->getVar('page') ?? 1));
            $limit = max(1, min(100, (int)($this->request->getVar('limit') ?? 10)));
            $offset = ($page - 1) * $limit;

            // Récupérer les filtres
            $code = $this->request->getVar('code');
            $name = $this->request->getVar('name');
            $categoryId = $this->request->getVar('category_id');
            $minStock = $this->request->getVar('min_stock');
            $maxStock = $this->request->getVar('max_stock');
            $status = $this->request->getVar('status');

            // Construction de la requête avec calcul du stock en SQL
            $builder = $this->db->table('products p')
                ->select("
                p.*, 
                c.name as category_name,
                COALESCE((
                    SELECT SUM(
                        CASE 
                            WHEN sm.movement_type IN ('EN','ER','EI','EAJ','ET','EAU') THEN sm.quantity 
                            ELSE -sm.quantity 
                        END
                    ) 
                    FROM stock_movements sm 
                    WHERE sm.product_id = p.id AND sm.deleted_at IS NULL
                ), 0) as stock_quantity
            ")
                ->join('product_categories c', 'c.id = p.category_id', 'left')
                ->where('p.deleted_at', null);

            // Filtres textuels
            if (!empty($code)) {
                $builder->like('p.code', $code);
            }

            if (!empty($name)) {
                $builder->like('p.name', $name);
            }

            if (!empty($categoryId)) {
                $builder->where('p.category_id', $categoryId);
            }

            // Appliquer les filtres de stock (HAVING car c'est un calcul)
            $havingConditions = [];
            if ($minStock !== null && $minStock !== '' && $minStock > 0) {
                $havingConditions[] = "stock_quantity >= " . (float)$minStock;
            }

            if ($maxStock !== null && $maxStock !== '' && $maxStock > 0) {
                $havingConditions[] = "stock_quantity <= " . (float)$maxStock;
            }

            if (!empty($status)) {
                if ($status === 'out') {
                    $havingConditions[] = "stock_quantity <= 0";
                } elseif ($status === 'low') {
                    $havingConditions[] = "stock_quantity > 0 AND stock_quantity <= p.min_stock_alert";
                } elseif ($status === 'normal') {
                    $havingConditions[] = "stock_quantity > p.min_stock_alert";
                }
            }

            if (!empty($havingConditions)) {
                $builder->having(implode(' AND ', $havingConditions));
            }

            // Compter le total
            $countBuilder = clone $builder;
            $totalCount = $countBuilder->countAllResults(false);

            // Récupérer les produits paginés
            $products = $builder->orderBy('p.created_at', 'DESC')
                ->limit($limit, $offset)
                ->get()
                ->getResultArray();

            // Ajouter le statut du stock pour chaque produit et déduire les réservations bloquées
            $reservationService = new ReservationService();
            foreach ($products as &$product) {
                $stockQty = floatval($product['stock_quantity']);
                $reserved = $reservationService->getReservedQuantity($product['id']);
                $availableStock = max(0, $stockQty - $reserved);
                $minAlert = floatval($product['min_stock_alert'] ?? 0);

                if ($availableStock <= 0) {
                    $product['stock_status'] = 'out';
                } elseif ($availableStock <= $minAlert) {
                    $product['stock_status'] = 'low';
                } else {
                    $product['stock_status'] = 'normal';
                }

                $product['total_stock'] = $stockQty;
                $product['reserved_quantity'] = $reserved;
                $product['stock_quantity'] = $availableStock;
                $product['current_stock'] = $stockQty;
            }

            $totalPages = ceil($totalCount / $limit);
            $currentPage = min($page, max(1, $totalPages));

            return $this->respond([
                'success' => true,
                'data' => $products,
                'pagination' => [
                    'total' => (int)$totalCount,
                    'page' => (int)$currentPage,
                    'limit' => (int)$limit,
                    'total_pages' => (int)$totalPages
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Product index error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
                'pagination' => [
                    'total' => 0,
                    'page' => 1,
                    'limit' => 10,
                    'total_pages' => 1
                ]
            ], 500);
        }
    }


    // Ajoutez cette méthode dans votre AuthController
    public function debugSession()
    {
        // Vérifier si l'utilisateur est admin (sécurité)
        $session = session();

        $data = [
            'session_id' => $session->get('__ci_last_regenerate'),
            'user_id' => $session->get('id'),
            'username' => $session->get('name'),
            'role' => $session->get('role'),
            'is_logged_in' => $session->get('is_logged_in'),
            'user_data' => $session->get('user_data'),
            'last_activity' => $session->get('last_activity'),
            'toutes_les_variables' => $session->get()
        ];

        echo '<pre>';
        var_dump($data);
        echo '</pre>';
        die();
    }

    /**
     * GET /api/products/(:num) - Affiche un produit spécifique
     */
    public function show($id = null)
    {
        if (!$id) {
            return $this->respond([
                'success' => false,
                'message' => 'ID du produit requis'
            ], 400);
        }

        $product = $this->db->table('products p')
            ->select('p.*, c.name as category_name, u.full_name as created_by')
            ->join('product_categories c', 'c.id = p.category_id', 'left')
            ->join('users u', 'u.id = p.created_by_name', 'left')
            ->where('p.id', $id)
            ->get()
            ->getRowArray();

        if (!$product) {
            return $this->respond([
                'success' => false,
                'message' => 'Produit non trouvé'
            ], 404);
        }

        // Calculer le stock actuel
        $stockQuantity = $this->db->table('stock_movements')
            ->select("SUM(CASE WHEN movement_type IN ('EN','ER','EI','EAJ','ET','EAU') THEN quantity ELSE -quantity END) as total")
            ->where('product_id', $id)
            ->get()
            ->getRowArray();

        $currentStock = floatval($stockQuantity['total'] ?? 0);

        // Utiliser le service pour déduire les réservations
        $reservationService = new ReservationService();
        $reserved = $reservationService->getReservedQuantity($id);

        $product['stock_quantity'] = max(0, $currentStock - $reserved);
        $product['total_stock'] = $currentStock;
        $product['reserved_quantity'] = $reserved;

        // Déterminer le statut
        if ($product['stock_quantity'] <= 0) {
            $product['stock_status'] = 'out';
        } elseif ($product['stock_quantity'] <= $product['min_stock_alert']) {
            $product['stock_status'] = 'low';
        } else {
            $product['stock_status'] = 'normal';
        }

        return $this->respond([
            'success' => true,
            'data' => $product
        ]);
    }

    public function create()
    {
        try {
            $input = $this->request->getJSON(true);

            // Validation
            if (empty($input['code'])) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Le code du produit est requis'
                ], 400);
            }

            if (empty($input['name'])) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Le nom du produit est requis'
                ], 400);
            }

            if (empty($input['selling_price'])) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Le prix de vente est requis'
                ], 400);
            }

            // Vérifier si le code existe déjà
            $existing = $this->productModel->where('code', $input['code'])->first();
            if ($existing) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Un produit avec ce code existe déjà'
                ], 400);
            }

            // Calculer les prix dans les différentes devises
            $purchasePriceAed = floatval($input['purchase_price_aed'] ?? 0);
            $purchasePricebif = floatval($input['purchase_price_bif'] ?? 0);
            $convertedPrices = $this->convertPrices($purchasePriceAed);

            $data = [
                'code' => strtoupper($input['code']),
                'name' => $input['name'],
                'description' => $input['description'] ?? '',
                'category_id' => !empty($input['category_id']) ? $input['category_id'] : null,
                'unit' => $input['unit'] ?? 'PIECE',
                'purchase_price' => $purchasePricebif,
                'purchase_price_aed' => $purchasePriceAed,
                'purchase_price_usd' => floatval($input['purchase_price_usd'] ?? 0), //$convertedPrices['usd'],
                'purchase_price_bif' => floatval($input['purchase_price_bif'] ?? 0), //$convertedPrices['bif'],
                // New averaged purchase price fields (initialized at creation)
                'avg_purchase_price_aed' => $purchasePriceAed,
                'avg_purchase_price_usd' => floatval($input['purchase_price_usd'] ?? 0),
                'avg_purchase_price_bif' => floatval($input['purchase_price_bif'] ?? 0),
                // Allow initial stock to be set from form
                'current_stock' => isset($input['stock']) ? (float)$input['stock'] : 0,
                'selling_price' => floatval($input['selling_price'] ?? 0),
                'tax_rate' => floatval($input['tax_rate'] ?? 18),
                'ct_tax_rate' => floatval($input['ct_tax_rate'] ?? 0),
                'tl_tax_rate' => floatval($input['tl_tax_rate'] ?? 0),
                'tsce_tax' => floatval($input['tsce_tax'] ?? 0),
                'ott_tax' => floatval($input['ott_tax'] ?? 0),
                'min_stock_alert' => intval($input['min_stock_alert'] ?? 0),
                'is_active' => isset($input['is_active']) ? (bool)$input['is_active'] : true,
                'updated_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'created_by_name' => $this->request->user_id ?? session()->get('user_id')
            ];

            $id = $this->productModel->insert($data);

            if (!$id) {
                if ($existing) {
                    return $this->respond([
                        'success' => false,
                        'message' => 'Un produit avec ce code existe déjà!'
                    ], 500);
                } else {
                    return $this->respond([
                        'success' => false,
                        'message' => 'Erreur lors de la création du produit'
                    ], 500);
                }
            }

            $initialStock = isset($input['stock']) ? (float)$input['stock'] : 0;
            if ($initialStock > 0) {
                $warehouseModel = new WarehouseModel();
                $warehouseId = !empty($input['warehouse_id']) ? (int)$input['warehouse_id'] : null;
                $selectedWarehouse = $warehouseId
                    ? $warehouseModel->find($warehouseId)
                    : $warehouseModel->where('is_active', 1)->orderBy('id', 'ASC')->first();

                if ($selectedWarehouse) {
                    $stockMovementModel = new StockMovementModel();
                    $movementNumber = $stockMovementModel->generateMovementNumber();
                    $movementGroup = 'MOV-' . date('YmdHis') . '-' . rand(1000, 9999);
                    $movementData = [
                        'movement_number' => $movementNumber,
                        'movement_group' => $movementGroup,
                        'warehouse_id' => $selectedWarehouse['id'],
                        'product_id' => $id,
                        'movement_type' => 'EN',
                        'quantity' => $initialStock,
                        'previous_quantity' => 0,
                        'new_quantity' => $initialStock,
                        'unit_cost' => $purchasePricebif,
                        'total_cost' => $purchasePricebif * $initialStock,
                        'movement_value' => $purchasePricebif * $initialStock,
                        'reference' => 'INITIAL_STOCK',
                        'reference_doc' => null,
                        'description' => 'Stock initial produit créé',
                        'movement_date' => date('Y-m-d H:i:s'),
                        'created_by' => $this->request->user_id ?? session()->get('user_id')
                    ];

                    if (!$stockMovementModel->insert($movementData)) {
                        return $this->respond([
                            'success' => false,
                            'message' => 'Produit créé, mais impossible d\'enregistrer le mouvement initial'
                        ], 500);
                    }
                }
            }

            $newProduct = $this->productModel->getProductWithDetails($id);

            return $this->respond([
                'success' => true,
                'message' => 'Produit créé avec succès',
                'data' => $newProduct
            ], 201);
        } catch (\Exception $e) {
            log_message('error', 'Product create error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function update($id = null)
    {
        try {
            $product = $this->productModel->find($id);

            if (!$product) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Produit non trouvé'
                ], 404);
            }

            $input = $this->request->getJSON(true);

            // Calculer les prix dans les différentes devises
            $purchasePriceAed = floatval($input['purchase_price_aed'] ?? $product['purchase_price_aed']);
            $purchasePricebif = floatval($input['purchase_price_bif'] ?? $product['purchase_price_bif']);
            $convertedPrices = $this->convertPrices($purchasePriceAed);

            $data = [
                'code' => strtoupper($input['code'] ?? $product['code']),
                'name' => $input['name'] ?? $product['name'],
                'description' => $input['description'] ?? $product['description'],
                'category_id' => !empty($input['category_id']) ? $input['category_id'] : $product['category_id'],
                'unit' => $input['unit'] ?? $product['unit'],
                'purchase_price' => $purchasePricebif,
                'purchase_price_aed' => $purchasePriceAed,
                'purchase_price_usd' => $convertedPrices['usd'],
                'purchase_price_bif' => $convertedPrices['bif'],
                // If price is updated explicitly, update the averaged fields as well
                'avg_purchase_price_aed' => $purchasePriceAed,
                'avg_purchase_price_usd' => $convertedPrices['usd'],
                'avg_purchase_price_bif' => $convertedPrices['bif'],
                'selling_price' => floatval($input['selling_price'] ?? $product['selling_price']),
                'tax_rate' => floatval($input['tax_rate'] ?? $product['tax_rate']),
                'ct_tax_rate' => floatval($input['ct_tax_rate'] ?? $product['ct_tax_rate']),
                'tl_tax_rate' => floatval($input['tl_tax_rate'] ?? $product['tl_tax_rate']),
                'tsce_tax' => floatval($input['tsce_tax'] ?? $product['tsce_tax']),
                'ott_tax' => floatval($input['ott_tax'] ?? $product['ott_tax']),
                'min_stock_alert' => intval($input['min_stock_alert'] ?? $product['min_stock_alert']),
                'updated_at' => date('Y-m-d H:i:s'),
                'is_active' => isset($input['is_active']) ? (bool)$input['is_active'] : (bool)$product['is_active']
            ];

            if (!$this->productModel->update($id, $data)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Erreur lors de la mise à jour du produit'
                ], 500);
            }
            $updatedProduct = $this->productModel->getProductWithDetails($id);

            return $this->respond([
                'success' => true,
                'message' => 'Produit mis à jour avec succès',
                'data' => $updatedProduct
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Product update error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE /api/products/(:num) - Supprime un produit
     */
    public function delete($id = null)
    {
        try {
            if (!$id) {
                return $this->respond([
                    'success' => false,
                    'message' => 'ID du produit requis'
                ], 400);
            }

            $product = $this->db->table('products')->where('id', $id)->get()->getRowArray();
            if (!$product) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Produit non trouvé'
                ], 404);
            }

            // Vérifier si le produit a des mouvements de stock
            $movements = $this->db->table('stock_movements')
                ->where('product_id', $id)
                ->countAllResults();

            if ($movements > 0) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Impossible de supprimer ce produit car il a des mouvements de stock associés'
                ], 400);
            }

            // Vérifier si le produit est utilisé dans des commandes
            $usedInOrders = $this->db->table('supplier_order_items')
                ->where('product_id', $id)
                ->countAllResults();

            if ($usedInOrders > 0) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Ce produit ne peut pas être supprimé car il est utilisé dans des commandes'
                ], 400);
            }

            // Vérifier si le produit est utilisé dans des factures
            $usedInInvoices = $this->db->table('invoice_items')
                ->where('product_id', $id)
                ->countAllResults();

            if ($usedInInvoices > 0) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Ce produit ne peut pas être supprimé car il est utilisé dans des factures'
                ], 400);
            }

            $this->productModel->delete($id);

            //$this->db->table('products')->where('id', $id)->delete();

            return $this->respond([
                'success' => true,
                'message' => 'Produit supprimé avec succès'
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Product delete error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/products/categories - Liste toutes les catégories avec filtres
     */
    public function getCategories()
    {
        // Récupérer les paramètres de filtre
        $name = $this->request->getVar('name');
        $parentId = $this->request->getVar('parent_id');

        // Log pour déboguer
        log_message('info', '=== getCategories appelé ===');
        log_message('info', 'name: ' . ($name ?? 'null'));
        log_message('info', 'parent_id: ' . ($parentId ?? 'null'));
        log_message('info', 'parent_id type: ' . gettype($parentId));

        $builder = $this->db->table('product_categories')
            ->select('id, name, parent_id, description');

        // Filtre par nom
        if (!empty($name)) {
            $builder->like('name', $name);
            log_message('info', 'Filtre name appliqué: ' . $name);
        }

        // Filtre par catégorie parente - CORRECTION IMPORTANTE
        if ($parentId !== null && $parentId !== '' && $parentId !== 'null' && $parentId !== 0) {
            // Convertir en entier si nécessaire
            $parentIdInt = is_numeric($parentId) ? (int)$parentId : 0;
            if ($parentIdInt > 0) {
                $builder->where('parent_id', $parentIdInt);
                log_message('info', 'Filtre parent_id appliqué: ' . $parentIdInt);
            }
        }

        $categories = $builder->orderBy('name', 'ASC')
            ->get()
            ->getResultArray();

        log_message('info', 'Nombre de catégories trouvées: ' . count($categories));

        // Ajouter le nom du parent
        foreach ($categories as &$category) {
            if ($category['parent_id']) {
                $parent = $this->db->table('product_categories')
                    ->select('name')
                    ->where('id', $category['parent_id'])
                    ->get()
                    ->getRowArray();
                $category['parent_name'] = $parent['name'] ?? null;
            } else {
                $category['parent_name'] = null;
            }
        }

        $tree = $this->buildCategoryTree($categories);

        return $this->respond([
            'success' => true,
            'data' => $categories,
            'tree' => $tree,
            'debug' => [
                'received_parent_id' => $parentId,
                'applied_parent_id' => $parentIdInt ?? null,
                'count' => count($categories)
            ]
        ]);
    }

    /**
     * Filtrer l'arborescence des catégories pour ne garder que les catégories correspondant à la recherche
     */
    private function filterCategoryTree($categories, $searchTerm)
    {
        $result = [];
        foreach ($categories as $category) {
            // Vérifier si la catégorie correspond à la recherche
            $matches = stripos($category['name'], $searchTerm) !== false;

            // Filtrer récursivement les enfants
            $filteredChildren = [];
            if (!empty($category['children'])) {
                $filteredChildren = $this->filterCategoryTree($category['children'], $searchTerm);
            }

            // Garder la catégorie si elle correspond ou si elle a des enfants correspondants
            if ($matches || !empty($filteredChildren)) {
                $category['children'] = $filteredChildren;
                $result[] = $category;
            }
        }
        return $result;
    }

    /**
     * POST /api/products/categories - Crée une nouvelle catégorie
     */
    public function createCategory()
    {
        try {
            $input = $this->request->getJSON(true);

            if (empty($input['name'])) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Le nom de la catégorie est requis'
                ], 400);
            }

            if (!empty($input['parent_id'])) {
                $parent = $this->db->table('product_categories')
                    ->where('id', $input['parent_id'])
                    ->get()
                    ->getRowArray();

                if (!$parent) {
                    return $this->respond([
                        'success' => false,
                        'message' => 'La catégorie parente n\'existe pas'
                    ], 400);
                }
            }

            $data = [
                'name' => $input['name'],
                'parent_id' => !empty($input['parent_id']) ? $input['parent_id'] : null,
                'description' => $input['description'] ?? '',
                'created_at' => date('Y-m-d H:i:s')
            ];

            $this->db->table('product_categories')->insert($data);
            $id = $this->db->insertID();

            return $this->respond([
                'success' => true,
                'message' => 'Catégorie créée avec succès',
                'data' => ['id' => $id, 'name' => $input['name'], 'parent_id' => $input['parent_id'] ?? null]
            ], 201);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * PUT /api/products/categories/(:num) - Met à jour une catégorie
     */
    public function updateCategory($id = null)
    {
        $input = $this->request->getJSON(true);

        if (empty($input['name'])) {
            return $this->respond([
                'success' => false,
                'message' => 'Le nom de la catégorie est requis'
            ], 400);
        }

        $category = $this->db->table('product_categories')->where('id', $id)->get()->getRowArray();
        if (!$category) {
            return $this->respond([
                'success' => false,
                'message' => 'Catégorie non trouvée'
            ], 404);
        }

        if ($input['parent_id'] == $id) {
            return $this->respond([
                'success' => false,
                'message' => 'Une catégorie ne peut pas être sa propre parente'
            ], 400);
        }

        $data = [
            'name' => $input['name'],
            'parent_id' => !empty($input['parent_id']) ? $input['parent_id'] : null,
            'description' => $input['description'] ?? ''
        ];

        $this->db->table('product_categories')->where('id', $id)->update($data);

        return $this->respond([
            'success' => true,
            'message' => 'Catégorie modifiée avec succès'
        ]);
    }

    /**
     * DELETE /api/products/categories/(:num) - Supprime une catégorie
     */
    public function deleteCategory($id = null)
    {
        if (!$id) {
            return $this->respond([
                'success' => false,
                'message' => 'ID de la catégorie requis'
            ], 400);
        }

        $category = $this->db->table('product_categories')->where('id', $id)->get()->getRowArray();
        if (!$category) {
            return $this->respond([
                'success' => false,
                'message' => 'Catégorie non trouvée'
            ], 404);
        }

        // Vérifier les sous-catégories
        $children = $this->db->table('product_categories')
            ->where('parent_id', $id)
            ->countAllResults();

        if ($children > 0) {
            return $this->respond([
                'success' => false,
                'message' => 'Impossible de supprimer cette catégorie car elle contient des sous-catégories'
            ], 400);
        }

        // Vérifier les produits
        $products = $this->db->table('products')
            ->where('category_id', $id)
            ->countAllResults();

        if ($products > 0) {
            return $this->respond([
                'success' => false,
                'message' => 'Impossible de supprimer cette catégorie car elle contient des produits'
            ], 400);
        }

        $this->db->table('product_categories')->where('id', $id)->delete();

        return $this->respond([
            'success' => true,
            'message' => 'Catégorie supprimée avec succès'
        ]);
    }

    /**
     * Construire l'arborescence des catégories
     */
    private function buildCategoryTree($categories, $parentId = null, $level = 0)
    {
        $result = [];
        foreach ($categories as $category) {
            if ($category['parent_id'] == $parentId) {
                $category['level'] = $level;
                $category['children'] = $this->buildCategoryTree($categories, $category['id'], $level + 1);
                $result[] = $category;
            }
        }
        return $result;
    }

    public function bulkDelete()
    {
        try {
            $input = $this->request->getJSON(true);
            $ids = $input['ids'] ?? [];

            if (empty($ids)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Aucun produit sélectionné'
                ], 400);
            }

            $deleted = 0;
            foreach ($ids as $id) {
                // Vérifier si le produit est utilisé
                $usedInOrders = $this->db->table('supplier_order_items')
                    ->where('product_id', $id)
                    ->countAllResults();

                $usedInInvoices = $this->db->table('invoice_items')
                    ->where('product_id', $id)
                    ->countAllResults();

                if ($usedInOrders === 0 && $usedInInvoices === 0) {
                    $this->productModel->delete($id);
                    $deleted++;
                }
            }

            return $this->respond([
                'success' => true,
                'message' => $deleted . ' produit(s) supprimé(s) avec succès',
                'deleted_count' => $deleted
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Product bulkDelete error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/products/bulk-activate - Activation groupée
     */
    public function bulkActivate()
    {
        try {
            $input = $this->request->getJSON(true);
            $ids = $input['ids'] ?? [];

            if (empty($ids)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Aucun produit sélectionné'
                ], 400);
            }

            $activated = $this->productModel->bulkActivate($ids);

            return $this->respond([
                'success' => true,
                'message' => $activated . ' produit(s) activé(s) avec succès',
                'activated_count' => $activated
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Product bulkActivate error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/products/bulk-deactivate - Désactivation groupée
     */
    public function bulkDeactivate()
    {
        try {
            $input = $this->request->getJSON(true);
            $ids = $input['ids'] ?? [];

            if (empty($ids)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Aucun produit sélectionné'
                ], 400);
            }

            $deactivated = $this->productModel->bulkDeactivate($ids);

            return $this->respond([
                'success' => true,
                'message' => $deactivated . ' produit(s) désactivé(s) avec succès',
                'deactivated_count' => $deactivated
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Product bulkDeactivate error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // app/Controllers/ProductController.php - Ajouter ces méthodes

    /**
     * GET /api/products/code/(:any) - Récupérer un produit par son code
     */
    public function getByCode($code = null)
    {
        try {
            $product = $this->productModel->where('code', $code)->first();

            if (!$product) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Produit non trouvé'
                ], 404);
            }

            return $this->respond([
                'success' => true,
                'data' => $product
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/products/bulk-update-prices - Mise à jour massive des prix
     */
    public function bulkUpdatePrices()
    {
        try {
            $input = $this->request->getJSON(true);
            $productIds = $input['ids'] ?? [];
            $field = $input['field'] ?? 'selling_price';
            $value = floatval($input['value'] ?? 0);
            $operation = $input['operation'] ?? 'increase';
            $type = $input['type'] ?? 'percentage';

            if (empty($productIds)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Aucun produit sélectionné'
                ], 400);
            }

            $updated = 0;
            foreach ($productIds as $id) {
                $product = $this->productModel->find($id);
                if (!$product) continue;

                $currentValue = floatval($product[$field] ?? 0);
                $multiplier = $operation === 'increase' ? 1 : -1;

                if ($type === 'percentage') {
                    $newValue = $currentValue * (1 + ($multiplier * $value / 100));
                } else {
                    $newValue = $currentValue + ($multiplier * $value);
                }

                $newValue = max(0, $newValue);

                $updateData = [$field => $newValue];

                // Si on modifie le prix d'achat en AED, recalculer USD et BIF
                if ($field === 'purchase_price_aed') {
                    $rates = $this->getExchangeRates();
                    $priceUsd = $newValue * $rates['AED_to_USD'];
                    $priceBif = $priceUsd * $rates['USD_to_BIF'];
                    $updateData['purchase_price_usd'] = round($priceUsd, 2);
                    $updateData['purchase_price_bif'] = round($priceBif, 0);
                }

                $this->productModel->update($id, $updateData);
                $updated++;
            }

            return $this->respond([
                'success' => true,
                'message' => $updated . ' produit(s) mis à jour',
                'updated_count' => $updated
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Bulk update prices error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/products/recalc-prices - Recalculer les prix avec les nouveaux taux
     */
    public function recalcPrices()
    {
        try {
            $input = $this->request->getJSON(true);
            $rates = $input['rates'] ?? [];

            $products = $this->productModel->findAll();
            $updated = 0;

            foreach ($products as $product) {
                $priceAed = floatval($product['purchase_price_aed'] ?? 0);
                $priceUsd = $priceAed * ($rates['AED_to_USD'] ?? 3.6725);
                $priceBif = $priceUsd * ($rates['USD_to_BIF'] ?? 2830);

                $this->productModel->update($product['id'], [
                    'purchase_price_usd' => round($priceUsd, 2),
                    'purchase_price_bif' => round($priceBif, 0)
                ]);
                $updated++;
            }

            return $this->respond([
                'success' => true,
                'message' => $updated . ' produit(s) recalculés'
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function getExchangeRates()
    {
        $rates = [
            'AED_to_USD' => 3.6725,
            'USD_to_BIF' => 2830
        ];

        try {
            $result = $this->db->table('exchange_rates')
                ->where('from_currency', 'AED')
                ->where('to_currency', 'USD')
                ->orderBy('effective_date', 'DESC')
                ->get()
                ->getRowArray();

            if ($result) {
                $rates['AED_to_USD'] = $result['rate'];
            }

            $result = $this->db->table('exchange_rates')
                ->where('from_currency', 'USD')
                ->where('to_currency', 'BIF')
                ->orderBy('effective_date', 'DESC')
                ->get()
                ->getRowArray();

            if ($result) {
                $rates['USD_to_BIF'] = $result['rate'];
            }
        } catch (\Exception $e) {
            log_message('error', 'Error loading exchange rates: ' . $e->getMessage());
        }

        return $rates;
    }

    /**
     * Générer un code produit unique avec verrouillage concurrent
     */ public function generateCode()
    {
        $prefix = 'A';
        try {
            // Verrouiller la table pour éviter les doublons
            $this->db->transStart();
            $this->db->query("LOCK TABLES products WRITE");

            // Nettoyer le préfixe (une seule lettre majuscule)
            $prefix = strtoupper(substr(trim($prefix), 0, 1));

            // Chercher le dernier code avec ce préfixe
            $lastProduct = $this->db->table('products')
                ->select('code')
                ->like('code', $prefix, 'after')
                ->orderBy('code', 'DESC')
                ->limit(1)
                ->get()
                ->getRowArray();

            // Calculer le prochain numéro
            $nextNumber = 1;
            if ($lastProduct && !empty($lastProduct['code'])) {
                // Extraire le numéro (ex: "A0123" -> 123)
                $numberPart = (int)substr($lastProduct['code'], 1);
                $nextNumber = $numberPart + 1;
            }

            // Générer le nouveau code
            $newCode = $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

            // Vérifier l'unicité
            $exists = $this->db->table('products')
                ->where('code', $newCode)
                ->countAllResults();

            if ($exists > 0) {
                // En cas de collision, incrémenter
                while ($exists > 0) {
                    $nextNumber++;
                    $newCode = $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
                    $exists = $this->db->table('products')
                        ->where('code', $newCode)
                        ->countAllResults();
                }
            }

            // Déverrouiller
            $this->db->query("UNLOCK TABLES");
            $this->db->transComplete();
            // return $newCode;
            // $this->db->transComplete();

            return $this->respond([
                'success' => true,
                'code' => $newCode
            ]);
        } catch (\Exception $e) {
            // Déverrouiller en cas d'erreur
            $this->db->query("UNLOCK TABLES");
            $this->db->transRollback();

            log_message('error', 'Generate code error: ' . $e->getMessage());

            // Fallback: code avec timestamp
            $fallbackCode = $prefix . date('Ymd') . rand(100, 999);
            return $this->respond([
                'success' => true,
                'code' => $fallbackCode,
                'fallback' => true
            ]);
        }
    }
}
