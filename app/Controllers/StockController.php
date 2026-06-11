<?php
// app/Controllers/StockController.php

namespace App\Controllers;

use App\Models\StockMovementModel;
use App\Models\ProductModel;
use App\Models\WarehouseModel;
use App\Libraries\StockCostingService;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;
use App\Services\ReservationService;

class StockController extends ResourceController
{
    use ResponseTrait;

    protected $stockMovementModel;
    protected $productModel;
    protected $warehouseModel;
    protected $db;
    protected $reservationService;

    public function __construct()
    {
        $this->stockMovementModel = new StockMovementModel();
        $this->productModel = new ProductModel();
        $this->warehouseModel = new WarehouseModel();
        $this->db = \Config\Database::connect();
        $this->reservationService = new ReservationService();
    }

    /**
     * GET /api/stock/warehouses - Liste des entrepôts
     */
    public function getWarehouses()
    {
        $warehouses = $this->warehouseModel->getActiveWarehouses();

        return $this->respond([
            'success' => true,
            'data' => $warehouses
        ]);
    }

    /**
     * GET /api/stock/product-stock/{productId}/{warehouseId} - Stock d'un produit dans un entrepôt
     */
    public function getProductStock($productId, $warehouseId)
    {
        $stock = $this->getCurrentStock($productId, $warehouseId);
        $product = $this->productModel->find($productId);

        return $this->respond([
            'success' => true,
            'data' => [
                'product_id' => $productId,
                'product_name' => $product['name'] ?? '',
                'warehouse_id' => $warehouseId,
                'current_stock' => $stock,
                'unit' => $product['unit'] ?? 'PIECE'
            ]
        ]);
    }

    /**
     * GET /api/stock/check-stock - Vérifier les stocks pour plusieurs produits
     */
    public function checkStock()
    {
        $items = $this->request->getJSON(true);
        $warehouseId = $this->request->getVar('warehouse_id');

        $results = [];
        foreach ($items as $item) {
            $currentStock = $this->getCurrentStock($item['product_id'], $warehouseId);
            $product = $this->productModel->find($item['product_id']);

            $results[] = [
                'product_id' => $item['product_id'],
                'product_name' => $product['name'] ?? '',
                'requested_quantity' => $item['quantity'],
                'available_quantity' => $currentStock,
                'unit' => $product['unit'] ?? 'PIECE',
                'is_available' => $currentStock >= $item['quantity']
            ];
        }

        return $this->respond([
            'success' => true,
            'data' => $results
        ]);
    }

    /**
     * POST /api/stock/movement - Ajouter un mouvement de stock (multi-produits avec pièces jointes)
     */
    public function addMovement()
    {
        try {
            $input = $this->request->getJSON(true);

            $movementType = $input['movement_type'] ?? null;
            $warehouseId = $input['warehouse_id'] ?? null;
            $movementDate = $input['movement_date'] ?? null;
            $description = $input['description'] ?? null;
            $reference = $input['reference'] ?? null;
            $referenceDoc = $input['reference_doc'] ?? null;

            // Décoder les items (attention: c'est une string JSON)
            $itemsJson = $input['items'] ?? null;
            $items = [];

            if ($itemsJson) {
                // Si items est une chaîne JSON, la décoder
                if (is_string($itemsJson)) {
                    $items = json_decode($itemsJson, true);
                } else {
                    $items = $itemsJson;
                }
            }

            // Validation
            if (empty($movementType)) {
                return $this->respond(['success' => false, 'message' => 'Le type de mouvement est requis'], 400);
            }

            if (empty($warehouseId)) {
                return $this->respond(['success' => false, 'message' => 'L\'entrepôt est requis'], 400);
            }

            if (empty($items) || !is_array($items)) {
                return $this->respond(['success' => false, 'message' => 'Au moins un produit est requis'], 400);
            }

            // Vérifier l'entrepôt
            $warehouse = $this->warehouseModel->find($warehouseId);
            if (!$warehouse) {
                return $this->respond(['success' => false, 'message' => 'Entrepôt non trouvé'], 404);
            }

            $inTypes = ['EN', 'ER', 'EI', 'EAJ', 'ET', 'EAU'];
            $outTypes = ['SN', 'SP', 'SV', 'SD', 'SC', 'SAJ', 'ST', 'SAU'];

            $movementGroup = 'MOV-' . date('YmdHis') . '-' . rand(1000, 9999);
            $createdItems = [];
            $totalValue = 0;
            $createdMovements = [];
            $costingService = new StockCostingService();
            $costingUpdates = [];

            $this->db->transStart();

            foreach ($items as $index => $item) {
                if (empty($item['product_id'])) {
                    $this->db->transRollback();
                    return $this->respond(['success' => false, 'message' => 'ID produit manquant pour l\'article ' . ($index + 1)], 400);
                }

                if (empty($item['quantity']) || $item['quantity'] <= 0) {
                    $this->db->transRollback();
                    return $this->respond(['success' => false, 'message' => 'La quantité doit être supérieure à zéro'], 400);
                }

                $product = $this->productModel->find($item['product_id']);
                if (!$product) {
                    $this->db->transRollback();
                    return $this->respond(['success' => false, 'message' => 'Produit non trouvé: ID ' . $item['product_id']], 404);
                }

                // Vérifier le stock
                $currentStock = $this->getCurrentStock($item['product_id'], $warehouseId);

                if (in_array($movementType, $outTypes) && $currentStock < $item['quantity']) {
                    $this->db->transRollback();
                    return $this->respond([
                        'success' => false,
                        'message' => "Stock insuffisant pour '{$product['name']}'. Disponible: {$currentStock} {$product['unit']}"
                    ], 400);
                }

                // Calculer nouveau stock
                $newStock = $currentStock;
                if (in_array($movementType, $inTypes)) {
                    $newStock += $item['quantity'];
                } elseif (in_array($movementType, $outTypes)) {
                    $newStock -= $item['quantity'];
                }

                // Determine incoming unit costs per currency (prefer explicit fields)
                $incomingUnitCostAed = (float) ($item['unit_cost_aed'] ?? $item['unit_cost'] ?? $product['avg_purchase_price_aed'] ?? $product['purchase_price_aed'] ?? $product['purchase_price'] ?? 0);
                $incomingUnitCostUsd = isset($item['unit_cost_usd']) ? (float)$item['unit_cost_usd'] : null;
                $incomingUnitCostBif = isset($item['unit_cost_bif']) ? (float)$item['unit_cost_bif'] : null;

                // Fallback derive USD/BIF from AED using existing ratios if not provided
                if ($incomingUnitCostUsd === null) {
                    if (!empty($product['purchase_price_aed']) && !empty($product['purchase_price_usd'])) {
                        $ratioUsd = $product['purchase_price_usd'] / max(1, $product['purchase_price_aed']);
                        $incomingUnitCostUsd = $incomingUnitCostAed * $ratioUsd;
                    } else {
                        $incomingUnitCostUsd = $product['avg_purchase_price_usd'] ?? $product['purchase_price_usd'] ?? 0;
                    }
                }
                if ($incomingUnitCostBif === null) {
                    if (!empty($product['purchase_price_usd']) && !empty($product['purchase_price_bif'])) {
                        $ratioBif = $product['purchase_price_bif'] / max(1, $product['purchase_price_usd']);
                        $incomingUnitCostBif = $incomingUnitCostUsd * $ratioBif;
                    } else {
                        $incomingUnitCostBif = $product['avg_purchase_price_bif'] ?? $product['purchase_price_bif'] ?? 0;
                    }
                }

                $previousAvgAed = (float) ($product['avg_purchase_price_aed'] ?? $product['purchase_price_aed'] ?? $product['purchase_price'] ?? 0);
                $previousAvgUsd = (float) ($product['avg_purchase_price_usd'] ?? $product['purchase_price_usd'] ?? 0);
                $previousAvgBif = (float) ($product['avg_purchase_price_bif'] ?? $product['purchase_price_bif'] ?? 0);

                $unitCost = $incomingUnitCostAed;

                if ($costingService->isInboundMovement($movementType)) {
                    // Recalculate weighted average per currency
                    $costingAed = $costingService->calculateWeightedAverage((float)$currentStock, $previousAvgAed, (float)$item['quantity'], $incomingUnitCostAed);
                    $costingUsd = $costingService->calculateWeightedAverage((float)$currentStock, $previousAvgUsd, (float)$item['quantity'], $incomingUnitCostUsd);
                    $costingBif = $costingService->calculateWeightedAverage((float)$currentStock, $previousAvgBif, (float)$item['quantity'], $incomingUnitCostBif);

                    $unitCost = $costingAed['new_average_cost'];

                    // Update product averaged purchase prices (and keep legacy fields in sync)
                    $this->productModel->update($item['product_id'], [
                        'avg_purchase_price_aed' => $costingAed['new_average_cost'],
                        'avg_purchase_price_usd' => $costingUsd['new_average_cost'],
                        'avg_purchase_price_bif' => $costingBif['new_average_cost'],
                        'purchase_price' => $costingAed['new_average_cost'],
                        'purchase_price_aed' => $costingAed['new_average_cost'],
                        'purchase_price_usd' => $costingUsd['new_average_cost'],
                        'purchase_price_bif' => $costingBif['new_average_cost'],
                    ]);

                    $costingUpdates[] = [
                        'product_id' => $item['product_id'],
                        'product_name' => $product['name'],
                        'previous_average_cost_aed' => $costingAed['previous_average_cost'],
                        'incoming_unit_cost_aed' => $costingAed['unit_cost'],
                        'new_average_cost_aed' => $costingAed['new_average_cost'],
                        'previous_average_cost_usd' => $costingUsd['previous_average_cost'],
                        'incoming_unit_cost_usd' => $costingUsd['unit_cost'],
                        'new_average_cost_usd' => $costingUsd['new_average_cost'],
                        'previous_average_cost_bif' => $costingBif['previous_average_cost'],
                        'incoming_unit_cost_bif' => $costingBif['unit_cost'],
                        'new_average_cost_bif' => $costingBif['new_average_cost'],
                    ];
                }

                $totalCost = $item['quantity'] * $unitCost;
                $totalValue += $totalCost;

                $movementNumber = $this->stockMovementModel->generateMovementNumber();

                $data = [
                    'movement_number' => $movementNumber,
                    'movement_group' => $movementGroup,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $item['product_id'],
                    'movement_type' => $movementType,
                    'quantity' => $item['quantity'],
                    'previous_quantity' => $currentStock,
                    'new_quantity' => $newStock,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'movement_value' => $totalCost,
                    'reference' => $reference,
                    'reference_doc' => $referenceDoc,
                    'description' => $description,
                    'movement_date' => $movementDate,
                    'created_by' => $this->request->user_id ?? session()->get('user_id')
                ];

                if ($this->stockMovementModel->insert($data)) {
                    $movementId = $this->stockMovementModel->insertID();
                    $createdMovements[] = ['id' => $movementId, 'number' => $movementNumber];
                    $createdItems[] = array_merge($data, ['id' => $movementId]);
                    $this->updateProductStock($item['product_id'], $newStock);
                } else {
                    $this->db->transRollback();
                    return $this->respond(['success' => false, 'message' => 'Erreur lors de l\'insertion'], 500);
                }
            }

            // Traitement des fichiers joints
            $files = $this->request->getFiles();
            $attachments = [];

            if (!empty($files['attachments'])) {
                $uploadPath = WRITEPATH . 'uploads/movements/';
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath, 0777, true);
                }

                foreach ($files['attachments'] as $file) {
                    if ($file->isValid() && !$file->hasMoved()) {
                        $newName = $file->getRandomName();
                        $file->move($uploadPath, $newName);

                        // Attacher le fichier au premier mouvement du groupe
                        $movementId = $createdMovements[0]['id'];

                        $attachmentData = [
                            'movement_id' => $movementId,
                            'filename' => $newName,
                            'original_name' => $file->getClientName(),
                            'file_path' => $uploadPath . $newName,
                            'file_size' => $file->getSize(),
                            'mime_type' => $file->getMimeType(),
                            'uploaded_by' => $this->request->user_id ?? session()->get('user_id'),
                            'created_at' => date('Y-m-d H:i:s')
                        ];

                        $this->db->table('movement_attachments')->insert($attachmentData);
                        $attachments[] = $attachmentData;
                    }
                }
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                return $this->respond(['success' => false, 'message' => 'Erreur lors de l\'enregistrement'], 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Mouvement de stock enregistré avec succès',
                'data' => [
                    'movement_group' => $movementGroup,
                    'items_count' => count($createdItems),
                    'total_value' => $totalValue,
                    'movements' => $createdMovements,
                    'attachments' => $attachments,
                    'costing_updates' => $costingUpdates,
                ]
            ], 201);
        } catch (\Exception $e) {
            log_message('error', 'Erreur addMovement: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Traiter un élément du mouvement (version simple sans transaction)
     */
    private function processMovementItemSimple($item, $index, $input, $movementType, $movementGroup)
    {
        log_message('info', 'Traitement item ' . $index . ': ' . json_encode($item));

        // Vérifier l'ID produit
        if (empty($item['product_id'])) {
            return $this->respond([
                'success' => false,
                'message' => 'ID produit manquant pour l\'article ' . ($index + 1)
            ], 400);
        }

        // Vérifier la quantité
        if (empty($item['quantity']) || $item['quantity'] <= 0) {
            return $this->respond([
                'success' => false,
                'message' => 'La quantité doit être supérieure à zéro pour l\'article ' . ($index + 1)
            ], 400);
        }

        // Récupérer le produit
        $product = $this->productModel->find($item['product_id']);
        if (!$product) {
            return $this->respond([
                'success' => false,
                'message' => 'Produit non trouvé: ID ' . $item['product_id']
            ], 404);
        }

        // Vérifier le stock pour les sorties
        $currentStock = $this->getCurrentStock($item['product_id'], $input['warehouse_id']);
        log_message('info', 'Stock actuel pour produit ' . $product['name'] . ': ' . $currentStock);

        $inTypes = ['EN', 'ER', 'EI', 'EAJ', 'ET', 'EAU'];
        $outTypes = ['SN', 'SP', 'SV', 'SD', 'SC', 'SAJ', 'ST', 'SAU'];

        if (in_array($movementType, $outTypes)) {
            if ($currentStock < $item['quantity']) {
                return $this->respond([
                    'success' => false,
                    'message' => "Stock insuffisant pour le produit '{$product['name']}'. Disponible: {$currentStock} {$product['unit']}, Demandé: {$item['quantity']}"
                ], 400);
            }
        }

        // Calculer le nouveau stock
        $newStock = $currentStock;
        if (in_array($movementType, $inTypes)) {
            $newStock += $item['quantity'];
        } elseif (in_array($movementType, $outTypes)) {
            $newStock -= $item['quantity'];
        }

        // Calculer les coûts
        $unitCost = isset($item['unit_cost']) ? $item['unit_cost'] : $product['purchase_price'];
        $totalCost = $item['quantity'] * $unitCost;

        // Générer le numéro de mouvement
        $movementNumber = $movementGroup . '-' . str_pad($index + 1, 3, '0', STR_PAD_LEFT);

        // Préparer les données
        $data = [
            'movement_number' => $movementNumber,
            'movement_group' => $movementGroup,
            'warehouse_id' => $input['warehouse_id'],
            'product_id' => $item['product_id'],
            'movement_type' => $movementType,
            'quantity' => $item['quantity'],
            'previous_quantity' => $currentStock,
            'new_quantity' => $newStock,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'reference' => $input['reference'] ?? null,
            'reference_doc' => $input['reference_doc'] ?? null,
            'description' => $input['description'] ?? null,
            'movement_date' => $input['movement_date'],
            'created_by' => $this->request->user_id ?? session()->get('user_id')
        ];

        log_message('info', 'Insertion mouvement: ' . json_encode($data));

        // Insérer dans la base de données (sans transaction)
        if (!$this->stockMovementModel->insert($data)) {
            $errors = $this->stockMovementModel->errors();
            log_message('error', 'Erreur insertion: ' . json_encode($errors));
            return $this->respond([
                'success' => false,
                'message' => 'Erreur insertion: ' . implode(', ', $errors)
            ], 500);
        }

        $insertId = $this->stockMovementModel->insertID();
        log_message('info', 'Mouvement inséré avec ID: ' . $insertId);

        // Mettre à jour le stock du produit
        $this->updateProductStock($item['product_id'], $newStock);

        // Vérifier les alertes de stock
        $stockAlerts = [];
        if ($newStock <= $product['min_stock_alert'] && $newStock > 0) {
            $stockAlerts[] = [
                'product' => $product['name'],
                'stock' => $newStock,
                'min_alert' => $product['min_stock_alert']
            ];
        } elseif ($newStock <= 0) {
            $stockAlerts[] = [
                'product' => $product['name'],
                'stock' => $newStock,
                'min_alert' => $product['min_stock_alert'],
                'out_of_stock' => true
            ];
        }

        return [
            'success' => true,
            'total_cost' => $totalCost,
            'item_data' => array_merge($data, ['id' => $insertId]),
            'stock_alerts' => $stockAlerts
        ];
    }

    /**
     * Valider les données d'entrée du mouvement
     */
    private function validateMovementInput($input)
    {
        if (empty($input['movement_type'])) {
            return $this->respond([
                'success' => false,
                'message' => 'Le type de mouvement est requis'
            ], 400);
        }

        $validTypes = ['EN', 'ER', 'EI', 'EAJ', 'ET', 'EAU', 'SN', 'SP', 'SV', 'SD', 'SC', 'SAJ', 'ST', 'SAU'];
        if (!in_array($input['movement_type'], $validTypes)) {
            return $this->respond([
                'success' => false,
                'message' => 'Type de mouvement invalide'
            ], 400);
        }

        if (empty($input['warehouse_id'])) {
            return $this->respond([
                'success' => false,
                'message' => 'L\'entrepôt est requis'
            ], 400);
        }

        if (empty($input['movement_date'])) {
            return $this->respond([
                'success' => false,
                'message' => 'La date du mouvement est requise'
            ], 400);
        }

        if (empty($input['items']) || !is_array($input['items'])) {
            return $this->respond([
                'success' => false,
                'message' => 'Au moins un produit est requis'
            ], 400);
        }

        return true;
    }

    /**
     * Traiter un élément du mouvement
     */
    private function processMovementItem($item, $index, $input, $movementType, $movementGroup, &$createdItems)
    {
        log_message('info', 'Traitement item ' . $index . ': ' . json_encode($item));

        // Vérifier l'ID produit
        if (empty($item['product_id'])) {
            return $this->respond([
                'success' => false,
                'message' => 'ID produit manquant pour l\'article ' . ($index + 1)
            ], 400);
        }

        // Vérifier la quantité
        if (empty($item['quantity']) || $item['quantity'] <= 0) {
            return $this->respond([
                'success' => false,
                'message' => 'La quantité doit être supérieure à zéro pour l\'article ' . ($index + 1)
            ], 400);
        }

        // Récupérer le produit
        $product = $this->productModel->find($item['product_id']);
        if (!$product) {
            return $this->respond([
                'success' => false,
                'message' => 'Produit non trouvé: ID ' . $item['product_id']
            ], 404);
        }

        // Vérifier le stock
        $currentStock = $this->getCurrentStock($item['product_id'], $input['warehouse_id']);
        log_message('info', 'Stock actuel pour produit ' . $product['name'] . ': ' . $currentStock);

        $inTypes = ['EN', 'ER', 'EI', 'EAJ', 'ET', 'EAU'];
        $outTypes = ['SN', 'SP', 'SV', 'SD', 'SC', 'SAJ', 'ST', 'SAU'];

        if (in_array($movementType, $outTypes)) {
            if ($currentStock < $item['quantity']) {
                return $this->respond([
                    'success' => false,
                    'message' => "Stock insuffisant pour le produit '{$product['name']}'. Disponible: {$currentStock} {$product['unit']}, Demandé: {$item['quantity']}"
                ], 400);
            }
        }

        // Calculer le nouveau stock
        $newStock = $currentStock;
        if (in_array($movementType, $inTypes)) {
            $newStock += $item['quantity'];
        } elseif (in_array($movementType, $outTypes)) {
            $newStock -= $item['quantity'];
        }

        // Calculer les coûts
        $unitCost = isset($item['unit_cost']) ? $item['unit_cost'] : $product['purchase_price'];
        $totalCost = $item['quantity'] * $unitCost;

        // Générer le numéro de mouvement
        $movementNumber = $movementGroup . '-' . str_pad(count($createdItems) + 1, 3, '0', STR_PAD_LEFT);

        // Préparer les données
        $data = [
            'movement_number' => $movementNumber,
            'movement_group' => $movementGroup,
            'warehouse_id' => $input['warehouse_id'],
            'product_id' => $item['product_id'],
            'movement_type' => $movementType,
            'quantity' => $item['quantity'],
            'previous_quantity' => $currentStock,
            'new_quantity' => $newStock,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'reference' => $input['reference'] ?? null,
            'reference_doc' => $input['reference_doc'] ?? null,
            'description' => $input['description'] ?? null,
            'movement_date' => $input['movement_date'],
            'created_by' => $this->request->user_id ?? session()->get('user_id')
        ];

        log_message('info', 'Insertion mouvement: ' . json_encode($data));

        // Valider les données avant insertion (utiliser la méthode du modèle)
        if (!$this->stockMovementModel->validate($data)) {
            $errors = $this->stockMovementModel->errors();
            log_message('error', 'Erreur validation: ' . json_encode($errors));
            return $this->respond([
                'success' => false,
                'message' => 'Données invalides: ' . implode(', ', $errors)
            ], 400);
        }

        // Insérer dans la base de données
        if (!$this->stockMovementModel->insert($data)) {
            log_message('error', 'Erreur insertion mouvement: ' . json_encode($this->stockMovementModel->errors()));
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors de l\'insertion du mouvement'
            ], 500);
        }

        // Mettre à jour le stock du produit
        $insertId = $this->stockMovementModel->insertID();
        $this->updateProductStock($item['product_id'], $newStock);

        // Vérifier les alertes de stock
        $stockAlerts = [];
        if ($newStock <= $product['min_stock_alert'] && $newStock > 0) {
            $stockAlerts[] = [
                'product' => $product['name'],
                'stock' => $newStock,
                'min_alert' => $product['min_stock_alert']
            ];
        } elseif ($newStock <= 0) {
            $stockAlerts[] = [
                'product' => $product['name'],
                'stock' => $newStock,
                'min_alert' => $product['min_stock_alert'],
                'out_of_stock' => true
            ];
        }

        return [
            'success' => true,
            'total_cost' => $totalCost,
            'item_data' => array_merge($data, ['id' => $insertId]),
            'stock_alerts' => $stockAlerts
        ];
    }
    /**
     * Gérer les erreurs de transaction
     */
    private function handleTransactionError()
    {
        // Récupérer l'erreur de la base de données
        $dbError = $this->db->error();
        $modelErrors = $this->stockMovementModel->errors();
        $lastQuery = $this->db->getLastQuery();

        // Log détaillé
        log_message('error', '=== TRANSACTION ÉCHOUÉE ===');
        log_message('error', 'DB Error Code: ' . ($dbError['code'] ?? 'unknown'));
        log_message('error', 'DB Error Message: ' . ($dbError['message'] ?? 'unknown'));
        log_message('error', 'Model Errors: ' . json_encode($modelErrors));
        log_message('error', 'Last Query: ' . $lastQuery);

        // Déterminer le message utilisateur
        $userMessage = 'Erreur lors de l\'enregistrement du mouvement';

        if (!empty($dbError['message'])) {
            if (strpos($dbError['message'], 'Duplicate entry') !== false) {
                $userMessage = 'Un mouvement avec ce numéro existe déjà';
            } elseif (strpos($dbError['message'], 'foreign key constraint') !== false) {
                $userMessage = 'Référence invalide (entrepôt ou produit non trouvé)';
            } elseif (strpos($dbError['message'], 'cannot be null') !== false) {
                $userMessage = 'Un champ obligatoire est vide';
            }
        }

        return $this->respond([
            'success' => false,
            'message' => $userMessage,
            'debug' => (getenv('CI_ENVIRONMENT') === 'development') ? [
                'db_error' => $dbError,
                'model_errors' => $modelErrors,
                'last_query' => $lastQuery
            ] : null
        ], 500);
    }

    /**
     * Créer des notifications pour les alertes de stock
     */
    private function createStockAlertNotifications($stockAlerts, $warehouse)
    {
        foreach ($stockAlerts as $alert) {
            $this->db->table('notifications')->insert([
                'user_id' => null,
                'title' => isset($alert['out_of_stock']) ? '❌ Rupture de stock' : '⚠️ Stock bas',
                'message' => isset($alert['out_of_stock'])
                    ? "Le produit '{$alert['product']}' est en rupture de stock dans l'entrepôt '{$warehouse['name']}'."
                    : "Le produit '{$alert['product']}' a un stock bas ({$alert['stock']}) dans l'entrepôt '{$warehouse['name']}'. Seuil minimum: {$alert['min_alert']}",
                'type' => isset($alert['out_of_stock']) ? 'danger' : 'warning',
                'link' => '/stock',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    }

    /**
     * POST /api/stock/transfer - Transfert multi-produits entre entrepôts
     */
    public function transferStock()
    {
        $input = $this->request->getJSON(true);

        // Log pour déboguer
        log_message('info', '=== transferStock appelé ===');
        log_message('info', 'Input: ' . json_encode($input));

        // Validation manuelle (sans utiliser 'array' comme règle)
        if (empty($input['from_warehouse_id'])) {
            return $this->respond([
                'success' => false,
                'message' => 'L\'entrepôt source est requis'
            ], 400);
        }

        if (empty($input['to_warehouse_id'])) {
            return $this->respond([
                'success' => false,
                'message' => 'L\'entrepôt destination est requis'
            ], 400);
        }

        if ($input['from_warehouse_id'] == $input['to_warehouse_id']) {
            return $this->respond([
                'success' => false,
                'message' => 'Les entrepôts source et destination doivent être différents'
            ], 400);
        }

        if (empty($input['transfer_date'])) {
            return $this->respond([
                'success' => false,
                'message' => 'La date du transfert est requise'
            ], 400);
        }

        if (empty($input['items']) || !is_array($input['items'])) {
            return $this->respond([
                'success' => false,
                'message' => 'Au moins un produit est requis'
            ], 400);
        }

        // Vérifier les entrepôts
        $fromWarehouse = $this->warehouseModel->find($input['from_warehouse_id']);
        $toWarehouse = $this->warehouseModel->find($input['to_warehouse_id']);

        if (!$fromWarehouse || !$toWarehouse) {
            return $this->respond([
                'success' => false,
                'message' => 'Entrepôt non trouvé'
            ], 404);
        }

        $transferGroup = 'TRF-' . date('YmdHis') . '-' . rand(1000, 9999);
        $transferResults = [];
        $totalValue = 0;
        $insufficientStockErrors = [];

        // Vérifier les stocks avant de commencer
        foreach ($input['items'] as $index => $item) {
            if (empty($item['product_id'])) {
                return $this->respond([
                    'success' => false,
                    'message' => 'ID produit manquant pour l\'article ' . ($index + 1)
                ], 400);
            }

            if (empty($item['quantity']) || $item['quantity'] <= 0) {
                return $this->respond([
                    'success' => false,
                    'message' => 'La quantité doit être supérieure à zéro pour l\'article ' . ($index + 1)
                ], 400);
            }

            $product = $this->productModel->find($item['product_id']);
            if (!$product) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Produit non trouvé: ID ' . $item['product_id']
                ], 404);
            }

            $currentStock = $this->getCurrentStock($item['product_id'], $input['from_warehouse_id']);
            if ($currentStock < $item['quantity']) {
                $insufficientStockErrors[] = "Produit '{$product['name']}': stock disponible {$currentStock} {$product['unit']}, demandé {$item['quantity']}";
            }
        }

        if (!empty($insufficientStockErrors)) {
            return $this->respond([
                'success' => false,
                'message' => 'Stock insuffisant pour certains produits',
                'errors' => $insufficientStockErrors
            ], 400);
        }
        $year = date('Y');
        // Démarrer la transaction
        $this->db->transStart();

        foreach ($input['items'] as $index => $item) {
            $product = $this->productModel->find($item['product_id']);
            $currentStockFrom = $this->getCurrentStock($item['product_id'], $input['from_warehouse_id']);
            $currentStockTo = $this->getCurrentStock($item['product_id'], $input['to_warehouse_id']);

            // 1. Sortie de l'entrepôt source (ST)
            $newStockFrom = $currentStockFrom - $item['quantity'];
            $movementNumberOut = $this->stockMovementModel->generateMovementNumber();

            $dataOut = [
                'movement_number' => $movementNumberOut,
                'movement_group' => $transferGroup,
                'warehouse_id' => $input['from_warehouse_id'],
                'product_id' => $item['product_id'],
                'movement_type' => 'ST',
                'quantity' => $item['quantity'],
                'previous_quantity' => $currentStockFrom,
                'new_quantity' => $newStockFrom,
                'unit_cost' => $product['purchase_price'],
                'total_cost' => $product['purchase_price'] * $item['quantity'],
                'reference_doc' => $input['reference_doc'] ?? null,
                'description' => "Transfert vers {$toWarehouse['name']} - " . ($input['description'] ?? ''),
                'movement_date' => date('Y-m-d H:i:s '), //$input['transfer_date'],
                'created_by' => $this->request->user_id ?? session()->get('user_id')
            ];

            if (!$this->stockMovementModel->insert($dataOut)) {
                $this->db->transRollback();
                return $this->respond([
                    'success' => false,
                    'message' => 'Erreur lors de l\'enregistrement de la sortie'
                ], 500);
            }

            $this->updateProductStock($item['product_id'], $newStockFrom);

            // 2. Entrée dans l'entrepôt destination (ET)
            $newStockTo = $currentStockTo + $item['quantity'];
            $movementNumberIn = $this->stockMovementModel->generateMovementNumber();
            $dataIn = [
                'movement_number' => $movementNumberIn,
                'movement_group' => $transferGroup,
                'warehouse_id' => $input['to_warehouse_id'],
                'product_id' => $item['product_id'],
                'movement_type' => 'ET',
                'quantity' => $item['quantity'],
                'previous_quantity' => $currentStockTo,
                'new_quantity' => $newStockTo,
                'unit_cost' => $product['purchase_price'],
                'total_cost' => $product['purchase_price'] * $item['quantity'],
                'reference_doc' => $input['reference_doc'] ?? null,
                'description' => "Transfert depuis {$fromWarehouse['name']} - " . ($input['description'] ?? ''),
                'movement_date' => date('Y-m-d H:i:s '),
                //$input['transfer_date'],
                'created_by' => $this->request->user_id ?? session()->get('user_id')
            ];

            if (!$this->stockMovementModel->insert($dataIn)) {
                $this->db->transRollback();
                return $this->respond([
                    'success' => false,
                    'message' => 'Erreur lors de l\'enregistrement de l\'entrée'
                ], 500);
            }

            $this->updateProductStock($item['product_id'], $newStockTo);

            $totalValue += $product['purchase_price'] * $item['quantity'];

            $transferResults[] = [
                'product_id' => $item['product_id'],
                'product_name' => $product['name'],
                'quantity' => $item['quantity'],
                'from_stock' => $newStockFrom,
                'to_stock' => $newStockTo
            ];
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors de la transaction'
            ], 500);
        }

        return $this->respond([
            'success' => true,
            'message' => 'Transfert effectué avec succès',
            'data' => [
                'transfer_group' => $transferGroup,
                'items_count' => count($transferResults),
                'total_value' => $totalValue,
                'items' => $transferResults
            ]
        ]);
    }

    /**
     * GET /api/stock/movements - Liste des mouvements avec pièces jointes
     */
    public function getMovements()
    {
        $warehouseId = $this->request->getVar('warehouse_id');
        $productId = $this->request->getVar('product_id');
        $movementType = $this->request->getVar('movement_type');
        $movementNumber = $this->request->getVar('movement_number');
        $dateFrom = $this->request->getVar('date_from');
        $dateTo = $this->request->getVar('date_to');
        $search = $this->request->getVar('search');
        $page = (int)($this->request->getVar('page') ?? 1);
        $limit = (int)($this->request->getVar('limit') ?? 10);
        $offset = ($page - 1) * $limit;

        $builder = $this->db->table('stock_movements sm')
            ->select('sm.*, u.full_name as user_name, p.name as product_name, p.code as product_code, p.unit,
                  w.name as warehouse_name, u.full_name as created_by_name')
            ->join('products p', 'p.id = sm.product_id')
            ->join('warehouses w', 'w.id = sm.warehouse_id')
            ->join('users u', 'u.id = sm.created_by', 'left');

        // Filtres
        if ($warehouseId) $builder->where('sm.warehouse_id', $warehouseId);
        if ($productId) $builder->where('sm.product_id', $productId);
        if ($movementType) $builder->where('sm.movement_type', $movementType);
        $direction = $this->request->getVar('direction');
        if ($direction === 'in') {
            $builder->whereIn('sm.movement_type', ['EN', 'ER', 'EI', 'EAJ', 'ET', 'EAU']);
        } elseif ($direction === 'out') {
            $builder->whereIn('sm.movement_type', ['SN', 'SP', 'SV', 'SD', 'SC', 'SAJ', 'ST', 'SAU']);
        } elseif ($direction === 'transfer') {
            $builder->whereIn('sm.movement_type', ['ST', 'ET']);
        }
        if ($movementNumber) $builder->like('sm.movement_number', $movementNumber);
        if ($dateFrom) $builder->where('sm.movement_date >=', $dateFrom);
        if ($dateTo) $builder->where('sm.movement_date <=', $dateTo . ' 23:59:59');
        if ($search) {
            $builder->groupStart()
                ->like('p.name', $search)
                ->orLike('p.code', $search)
                ->orLike('sm.movement_number', $search)
                ->groupEnd();
        }

        $total = $builder->countAllResults(false);
        $movements = $builder->orderBy('sm.movement_date', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        $groupedMovements = [];
        foreach ($movements as $movement) {
            if ($movement['movement_group']) {
                if (!isset($groupedMovements[$movement['movement_group']])) {
                    $groupedMovements[$movement['movement_group']] = [
                        'group_id' => $movement['movement_group'],
                        'date' => $movement['movement_date'],
                        'type' => $movement['movement_type'],
                        'warehouse' => $movement['warehouse_name'],
                        'reference' => $movement['reference'],
                        'description' => $movement['description'],
                        'items' => []
                    ];
                }
                $groupedMovements[$movement['movement_group']]['items'][] = [
                    'product_name' => $movement['product_name'],
                    'quantity' => $movement['quantity'],
                    'unit' => $movement['unit']
                ];
            }
        }

        // Ajouter les pièces jointes pour chaque mouvement
        foreach ($movements as &$movement) {
            $attachments = $this->db->table('movement_attachments')
                ->select('id, filename, original_name, file_size, mime_type, created_at')
                ->where('movement_id', $movement['id'])
                ->get()
                ->getResultArray();

            $movement['attachments'] = $attachments;

            // URL pour télécharger les fichiers
            foreach ($movement['attachments'] as &$att) {
                $att['download_url'] = site_url('api/stock/download-attachment/' . $att['id']);
            }
        }

        // Calculer le résumé du stock
        $stockSummary = $this->getStockSummary($warehouseId);

        return $this->respond([
            'success' => true,
            'data' => $movements,
            'grouped_movements' => array_values($groupedMovements),
            'stock_summary' => $stockSummary,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($total / $limit)
            ]
        ]);
    }

    /**
     * GET /api/stock/download-attachment/(:num) - Télécharger une pièce jointe
     */
    public function downloadAttachment($id)
    {
        $attachment = $this->db->table('movement_attachments')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$attachment) {
            return $this->respond(['success' => false, 'message' => 'Fichier non trouvé'], 404);
        }

        $filePath = $attachment['file_path'];

        if (!file_exists($filePath)) {
            return $this->respond(['success' => false, 'message' => 'Fichier introuvable sur le serveur'], 404);
        }

        return $this->response
            ->setHeader('Content-Type', $attachment['mime_type'])
            ->setHeader('Content-Disposition', 'attachment; filename="' . $attachment['original_name'] . '"')
            ->setBody(file_get_contents($filePath));
    }

    /**
     * GET /api/stock/summary - Résumé du stock avec valeurs
     */
    public function getStockSummary()
    {
        $warehouseId = $this->request->getVar('warehouse_id');

        // Utiliser directement le current_stock de la table products pour plus de fiabilité
        // Le current_stock est la source unique de vérité du stock réel
        $builder = $this->db->table('products p')
            ->select('p.id, p.code, p.name, p.unit, p.min_stock_alert, p.selling_price, p.current_stock as quantity');

        // Si un entrepôt spécifique est demandé, joindre avec les mouvements pour filtrer
        if ($warehouseId && $warehouseId !== 'all') {
            $builder->join('stock_movements sm', 'p.id = sm.product_id')
                ->where('sm.warehouse_id', $warehouseId)
                ->distinct();
        }

        $builder->where('p.is_active', 1);
        $summary = $builder->orderBy('p.name')->get()->getResultArray();

        // Calculer les totaux
        $totalProducts = count($summary);
        $lowStockCount = 0;
        $totalValue = 0;
        $totalQuantity = 0;

        foreach ($summary as &$item) {
            $quantity = (float)($item['quantity'] ?? 0);
            $sellingPrice = (float)($item['selling_price'] ?? 0);

            if ($quantity <= $item['min_stock_alert']) {
                $lowStockCount++;
            }

            $totalQuantity += $quantity;
            $totalValue += $quantity * $sellingPrice;

            $item['total_value'] = $quantity * $sellingPrice;
        }

        return $this->respond([
            'success' => true,
            'data' => $summary,
            'totals' => [
                'total_products' => $totalProducts,
                'low_stock' => $lowStockCount,
                'total_quantity' => $totalQuantity,
                'total_value' => $totalValue
            ]
        ]);
    }

    /**
     * GET /api/stock/warehouse-summary - Total stock par entrepôt
     */
    public function getWarehouseStockSummary()
    {
        $warehouseId = $this->request->getVar('warehouse_id');
        $stockData = $this->stockMovementModel->getStockByWarehouse($warehouseId && $warehouseId !== 'all' ? $warehouseId : null);

        $warehouseTotals = [];
        foreach ($stockData as $item) {
            $warehouseName = $item['warehouse_name'] ?? 'Unknown';
            $warehouseTotals[$warehouseName] = ($warehouseTotals[$warehouseName] ?? 0) + (float)($item['stock'] ?? 0);
        }

        $data = array_map(function ($name, $stock) {
            return ['name' => $name, 'stock' => $stock];
        }, array_keys($warehouseTotals), array_values($warehouseTotals));

        return $this->respond(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/stock/bulk-delete - Suppression groupée
     */
    public function bulkDelete()
    {
        $input = $this->request->getJSON(true);
        $ids = $input['ids'] ?? [];

        if (empty($ids)) {
            return $this->respond([
                'success' => false,
                'message' => 'Aucun mouvement sélectionné'
            ], 400);
        }

        $deleted = 0;
        foreach ($ids as $id) {
            if ($this->stockMovementModel->delete($id)) {
                $deleted++;
            }
        }

        return $this->respond([
            'success' => true,
            'message' => $deleted . ' mouvement(s) supprimé(s) avec succès'
        ]);
    }

    /**
     * Récupérer le stock actuel d'un produit dans un entrepôt
     */
    private function getCurrentStock($productId, $warehouseId)
    {
        $result = $this->db->table('stock_movements')
            ->select('new_quantity')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        $currentStock = $result ? (float)$result['new_quantity'] : 0;
        return $this->reservationService->getAvailableStock($currentStock, $productId);
    }

    /**
     * Mettre à jour le stock dans la table products
     */
    private function updateProductStock($productId, $newStock)
    {
        $this->db->table('products')
            ->where('id', $productId)
            ->update(['current_stock' => $newStock]);
    }

    /**
     * Créer une notification pour alerte de stock
     */
    private function createStockAlertNotification($productName, $stock, $warehouse, $minAlert)
    {
        $this->db->table('notifications')->insert([
            'user_id' => null,
            'title' => $stock <= 0 ? '❌ Rupture de stock' : '⚠️ Stock bas',
            'message' => $stock <= 0
                ? "Le produit '{$productName}' est en rupture de stock dans l'entrepôt '{$warehouse['name']}'."
                : "Le produit '{$productName}' a un stock bas ({$stock}) dans l'entrepôt '{$warehouse['name']}'. Seuil minimum: {$minAlert}",
            'type' => $stock <= 0 ? 'danger' : 'warning',
            'link' => '/stock',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function createWarehouse()
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

            $code = $this->warehouseModel->generateCode();

            $data = [
                'code' => $code,
                'name' => $input['name'],
                'location' => $input['location'] ?? null,
                'manager_name' => $input['manager_name'] ?? null,
                'phone' => $input['phone'] ?? null,
                'email' => $input['email'] ?? null,
                'description' => $input['description'] ?? null,
                'is_active' => isset($input['is_active']) ? (bool)$input['is_active'] : true,
                'created_by' => session()->get('user_id')
            ];

            $this->db->table('warehouses')->insert($data);
            $id = $this->db->insertID();

            if (!$id) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Erreur lors de la création de l\'entrepôt'
                ], 500);
            }

            $newWarehouse = $this->db->table('warehouses')->where('id', $id)->get()->getRowArray();

            return $this->respond([
                'success' => true,
                'message' => 'Entrepôt créé avec succès',
                'data' => $newWarehouse
            ], 201);
        } catch (\Exception $e) {
            log_message('error', 'Warehouse create error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors de la création de l\'entrepôt'
            ], 500);
        }
    }

    /**
     * PUT /api/stock/warehouses/(:num) - Modifier un entrepôt
     */
    public function updateWarehouse($id = null)
    {
        if (!$id) {
            return $this->respond([
                'success' => false,
                'message' => 'ID de l\'entrepôt requis'
            ], 400);
        }

        $input = $this->request->getJSON(true);

        $warehouse = $this->db->table('warehouses')->where('id', $id)->get()->getRowArray();
        if (!$warehouse) {
            return $this->respond([
                'success' => false,
                'message' => 'Entrepôt non trouvé'
            ], 404);
        }

        // Vérifier si le code existe déjà (sauf pour cet entrepôt)
        if (!empty($input['code']) && $input['code'] !== $warehouse['code']) {
            $existing = $this->db->table('warehouses')
                ->where('code', $input['code'])
                ->where('id !=', $id)
                ->get()
                ->getRowArray();

            if ($existing) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Ce code d\'entrepôt existe déjà'
                ], 400);
            }
        }

        $data = [
            'code' => !empty($input['code']) ? strtoupper($input['code']) : $warehouse['code'],
            'name' => $input['name'] ?? $warehouse['name'],
            'location' => $input['location'] ?? $warehouse['location'],
            'manager_name' => $input['manager_name'] ?? $warehouse['manager_name'],
            'phone' => $input['phone'] ?? $warehouse['phone'],
            'email' => $input['email'] ?? $warehouse['email'],
            'is_active' => isset($input['is_active']) ? (bool)$input['is_active'] : $warehouse['is_active'],
            'description' => $input['description'] ?? $warehouse['description'],
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->table('warehouses')->where('id', $id)->update($data);

        $updatedWarehouse = $this->db->table('warehouses')->where('id', $id)->get()->getRowArray();

        return $this->respond([
            'success' => true,
            'message' => 'Entrepôt modifié avec succès',
            'data' => $updatedWarehouse
        ]);
    }

    /**
     * DELETE /api/stock/warehouses/(:num) - Supprimer un entrepôt
     */
    public function deleteWarehouse($id = null)
    {
        if (!$id) {
            return $this->respond([
                'success' => false,
                'message' => 'ID de l\'entrepôt requis'
            ], 400);
        }

        $warehouse = $this->db->table('warehouses')->where('id', $id)->get()->getRowArray();
        if (!$warehouse) {
            return $this->respond([
                'success' => false,
                'message' => 'Entrepôt non trouvé'
            ], 404);
        }

        // Vérifier si l'entrepôt a des mouvements de stock
        $movements = $this->db->table('stock_movements')
            ->where('warehouse_id', $id)
            ->countAllResults();

        if ($movements > 0) {
            return $this->respond([
                'success' => false,
                'message' => 'Impossible de supprimer cet entrepôt car il contient des mouvements de stock'
            ], 400);
        }

        $this->db->table('warehouses')->where('id', $id)->delete();

        return $this->respond([
            'success' => true,
            'message' => 'Entrepôt supprimé avec succès'
        ]);
    }


    /**
     * POST /api/stock/reservation - Créer une réservation
     */
    public function createReservation()
    {
        try {
            $input = $this->request->getJSON(true);

            // Validation
            if (empty($input['customer_id'])) {
                return $this->respond(['success' => false, 'message' => 'Le client est requis'], 400);
            }

            if (empty($input['items']) || !is_array($input['items'])) {
                return $this->respond(['success' => false, 'message' => 'Au moins un produit est requis'], 400);
            }

            // Vérifier le client
            $customer = $this->db->table('users')->where('id', $input['customer_id'])->get()->getRowArray();
            if (!$customer) {
                return $this->respond(['success' => false, 'message' => 'Client non trouvé'], 404);
            }

            // Générer numéro de réservation
            $reservationNumber = 'RES-' . date('YmdHis') . '-' . rand(1000, 9999);

            $this->db->transStart();

            // Créer la réservation
            $reservationData = [
                'reservation_number' => $reservationNumber,
                'customer_id' => $input['customer_id'],
                'reservation_date' => $input['reservation_date'] ?? date('Y-m-d H:i:s'),
                'status' => 'pending',
                'notes' => $input['notes'] ?? null,
                'created_by' => session()->get('user_id'),
                'created_at' => date('Y-m-d H:i:s')
            ];

            $this->db->table('reservations')->insert($reservationData);
            $reservationId = $this->db->insertID();

            // Ajouter les produits
            foreach ($input['items'] as $item) {
                $product = $this->productModel->find($item['product_id']);
                if (!$product) {
                    $this->db->transRollback();
                    return $this->respond(['success' => false, 'message' => 'Produit non trouvé: ' . $item['product_id']], 404);
                }

                // Vérifier le stock disponible
                $currentStock = $this->getCurrentStock($item['product_id'], null);
                if ($currentStock < $item['quantity']) {
                    $this->db->transRollback();
                    return $this->respond([
                        'success' => false,
                        'message' => "Stock insuffisant pour le produit '{$product['name']}'. Disponible: {$currentStock}"
                    ], 400);
                }

                $this->db->table('reservation_items')->insert([
                    'reservation_id' => $reservationId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'reserved_quantity' => $item['quantity'],
                    'delivered_quantity' => 0,
                    'released_quantity' => 0,
                    'unit_price' => $product['selling_price'],
                    'discount_percent' => $item['discount_percent'] ?? 0,
                    'tax_rate' => $item['tax_rate'] ?? 0,
                    'notes' => $item['notes'] ?? null
                ]);
            }

            // Gérer les pièces jointes
            if (!empty($input['attachments'])) {
                foreach ($input['attachments'] as $attachment) {
                    // Logique de sauvegarde des fichiers
                }
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                return $this->respond(['success' => false, 'message' => 'Erreur lors de la création de la réservation'], 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Réservation créée avec succès',
                'data' => ['reservation_id' => $reservationId, 'reservation_number' => $reservationNumber]
            ], 201);
        } catch (\Exception $e) {
            log_message('error', 'Reservation create error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => 'Erreur lors de la création de la réservation'], 500);
        }
    }

    /**
     * POST /api/stock/inventory - Créer une session d'inventaire
     */
    public function createInventory()
    {
        $input = $this->request->getJSON(true);

        $sessionNumber = 'INV-' . date('YmdHis') . '-' . rand(1000, 9999);

        $sessionData = [
            'session_number' => $sessionNumber,
            'warehouse_id' => $input['warehouse_id'] ?? null,
            'started_at' => date('Y-m-d H:i:s'),
            'status' => 'in_progress',
            'created_by' => $this->request->user_id ?? session()->get('user_id'),
            'notes' => $input['notes'] ?? null
        ];

        $this->db->table('inventory_sessions')->insert($sessionData);
        $sessionId = $this->db->insertID();

        // Ajouter les lignes d'inventaire
        foreach ($input['items'] as $item) {
            $difference = $item['physical_quantity'] - $item['system_quantity'];

            $this->db->table('inventory_items')->insert([
                'inventory_session_id' => $sessionId,
                'product_id' => $item['product_id'],
                'system_quantity' => $item['system_quantity'],
                'physical_quantity' => $item['physical_quantity'],
                'difference' => $difference,
                'notes' => $item['notes'] ?? null
            ]);

            // Si différence, créer un mouvement d'ajustement
            if ($difference != 0) {
                $adjustmentType = $difference > 0 ? 'EAJ' : 'SAJ';
                $this->createAdjustmentMovement($item['product_id'], abs($difference), $adjustmentType, $input['warehouse_id'], "Ajustement inventaire #{$sessionNumber}");
            }
        }

        return $this->respond([
            'success' => true,
            'message' => 'Inventaire créé avec succès',
            'data' => ['session_id' => $sessionId, 'session_number' => $sessionNumber]
        ], 201);
    }

    /**
     * POST /api/stock/inventory/complete - Compléter une session d'inventaire
     */
    public function completeInventory($id)
    {
        $this->db->table('inventory_sessions')
            ->where('id', $id)
            ->update([
                'completed_at' => date('Y-m-d H:i:s'),
                'status' => 'completed'
            ]);

        return $this->respond([
            'success' => true,
            'message' => 'Inventaire complété avec succès'
        ]);
    }

    /**
     * GET /api/stock/reservations - Liste des réservations
     */
    public function getReservations()
    {
        $reservations = $this->db->table('reservations r')
            ->select('r.*, u.full_name as customer_name, u.email as customer_email')
            ->join('users u', 'u.id = r.customer_id')
            ->orderBy('r.created_at', 'DESC')
            ->get()
            ->getResultArray();

        foreach ($reservations as &$res) {
            $items = $this->db->table('reservation_items ri')
                ->select('ri.*, p.name as product_name, p.code as product_code, p.unit')
                ->join('products p', 'p.id = ri.product_id')
                ->where('ri.reservation_id', $res['id'])
                ->get()
                ->getResultArray();
            $res['items'] = $items;
        }

        return $this->respond([
            'success' => true,
            'data' => $reservations
        ]);
    }

    /**
     * GET /api/stock/inventories - Liste des sessions d'inventaire
     */
    public function getInventories()
    {
        $inventories = $this->db->table('inventory_sessions is')
            ->select('is.*, w.name as warehouse_name')
            ->join('warehouses w', 'w.id = is.warehouse_id', 'left')
            ->orderBy('is.created_at', 'DESC')
            ->get()
            ->getResultArray();

        return $this->respond([
            'success' => true,
            'data' => $inventories
        ]);
    }

    /**
     * GET /api/stock/movements/comparison - Statistiques de comparaison
     */
    public function getMovementComparison()
    {
        $stats = $this->db->table('stock_movements')
            ->select("
            movement_type,
            COUNT(*) as count,
            SUM(quantity) as total_quantity,
            SUM(total_cost) as total_value,
            DATE(movement_date) as date
        ")
            ->groupBy('movement_type, DATE(movement_date)')
            ->orderBy('date', 'DESC')
            ->limit(30)
            ->get()
            ->getResultArray();

        $byType = $this->db->table('stock_movements')
            ->select("
            movement_type,
            SUM(CASE WHEN movement_type IN ('EN','ER','EI','EAJ','ET','EAU') THEN quantity ELSE 0 END) as total_entries,
            SUM(CASE WHEN movement_type IN ('SN','SP','SV','SD','SC','SAJ','ST','SAU') THEN quantity ELSE 0 END) as total_exits
        ")
            ->groupBy('movement_type')
            ->get()
            ->getResultArray();

        return $this->respond([
            'success' => true,
            'data' => [
                'timeline' => $stats,
                'by_type' => $byType
            ]
        ]);
    }

    /**
     * Créer un mouvement d'ajustement pour l'inventaire
     */
    private function createAdjustmentMovement($productId, $quantity, $type, $warehouseId, $description)
    {
        $product = $this->productModel->find($productId);
        $currentStock = $this->getCurrentStock($productId, $warehouseId);
        $newStock = $type === 'EAJ' ? $currentStock + $quantity : $currentStock - $quantity;

        $movementNumber = 'ADJ-' . date('YmdHis') . '-' . rand(1000, 9999);

        $this->stockMovementModel->insert([
            'movement_number' => $movementNumber,
            'movement_group' => 'ADJ-' . date('YmdHis'),
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'movement_type' => $type,
            'quantity' => $quantity,
            'previous_quantity' => $currentStock,
            'new_quantity' => $newStock,
            'unit_cost' => $product['purchase_price'],
            'total_cost' => $product['purchase_price'] * $quantity,
            'description' => $description,
            'movement_date' => date('Y-m-d H:i:s'),
            'created_by' => $this->request->user_id ?? session()->get('user_id')
        ]);

        $this->updateProductStock($productId, $newStock);
    }

    /**
     * POST /api/stock/movement/:id/attachments - Ajouter des pièces jointes
     */
    public function addMovementAttachments($id = null)
    {
        if (!$id) {
            return $this->respond(['success' => false, 'message' => 'ID du mouvement requis'], 400);
        }

        // Vérifier si le mouvement existe
        $movement = $this->stockMovementModel->find($id);
        if (!$movement) {
            return $this->respond(['success' => false, 'message' => 'Mouvement non trouvé'], 404);
        }

        // Log pour déboguer
        log_message('info', '=== Upload attachments pour mouvement ID: ' . $id);
        log_message('info', 'POST data: ' . json_encode($_POST));
        log_message('info', 'FILES data: ' . json_encode(array_keys($_FILES)));

        // Vérifier si des fichiers ont été envoyés
        if (empty($_FILES)) {
            log_message('error', 'Aucun fichier dans $_FILES');
            return $this->respond(['success' => false, 'message' => 'Aucun fichier reçu'], 400);
        }

        // Récupérer les fichiers (différentes méthodes)
        $files = [];

        if (isset($_FILES['attachments'])) {
            $files = $_FILES['attachments'];
        } elseif (isset($_FILES['attachments[]'])) {
            $files = $_FILES['attachments[]'];
        } else {
            // Parcourir tous les fichiers reçus
            foreach ($_FILES as $key => $file) {
                if (isset($file['tmp_name']) && is_array($file['tmp_name'])) {
                    $files = $file;
                    break;
                } elseif (isset($file['tmp_name']) && !empty($file['tmp_name'])) {
                    $files = [$file];
                    break;
                }
            }
        }

        if (empty($files) || empty($files['tmp_name'])) {
            log_message('error', 'Aucun fichier valide trouvé');
            return $this->respond(['success' => false, 'message' => 'Aucun fichier valide à uploader'], 400);
        }

        $uploadPath = WRITEPATH . 'uploads/movements/';
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0777, true);
        }

        $attachments = [];
        $fileCount = is_array($files['tmp_name']) ? count($files['tmp_name']) : 1;

        for ($i = 0; $i < $fileCount; $i++) {
            $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
            $originalName = is_array($files['name']) ? $files['name'][$i] : $files['name'];
            $fileSize = is_array($files['size']) ? $files['size'][$i] : $files['size'];
            $fileType = is_array($files['type']) ? $files['type'][$i] : $files['type'];
            $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];

            if ($error !== UPLOAD_ERR_OK) {
                log_message('error', 'Erreur upload: ' . $error);
                continue;
            }

            if (!file_exists($tmpName)) {
                log_message('error', 'Fichier temporaire introuvable: ' . $tmpName);
                continue;
            }

            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
            $newName = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
            $destination = $uploadPath . $newName;

            if (move_uploaded_file($tmpName, $destination)) {
                $attachmentData = [
                    'movement_id' => $id,
                    'filename' => $newName,
                    'original_name' => $originalName,
                    'file_path' => $destination,
                    'file_size' => $fileSize,
                    'mime_type' => $fileType,
                    'uploaded_by' => $this->request->user_id ?? session()->get('user_id'),
                    'created_at' => date('Y-m-d H:i:s')
                ];

                $this->db->table('movement_attachments')->insert($attachmentData);
                $attachmentData['id'] = $this->db->insertID();
                $attachments[] = $attachmentData;

                log_message('info', 'Fichier uploadé: ' . $newName);
            } else {
                log_message('error', 'Erreur déplacement fichier: ' . $originalName);
            }
        }

        if (empty($attachments)) {
            return $this->respond(['success' => false, 'message' => 'Aucun fichier n\'a pu être uploadé'], 500);
        }

        return $this->respond([
            'success' => true,
            'message' => count($attachments) . ' fichier(s) ajouté(s) avec succès',
            'data' => $attachments
        ], 201);
    }
    /**
     * DELETE /api/stock/attachments/:id - Supprimer une pièce jointe
     */
    public function deleteAttachment($id = null)
    {
        if (!$id) {
            return $this->respond(['success' => false, 'message' => 'ID du fichier requis'], 400);
        }

        $attachment = $this->db->table('movement_attachments')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (!$attachment) {
            return $this->respond(['success' => false, 'message' => 'Fichier non trouvé'], 404);
        }

        // Supprimer le fichier du disque
        if (file_exists($attachment['file_path'])) {
            unlink($attachment['file_path']);
        }

        // Supprimer l'enregistrement en base
        $this->db->table('movement_attachments')->where('id', $id)->delete();

        return $this->respond([
            'success' => true,
            'message' => 'Fichier supprimé avec succès'
        ]);
    }

    /**
     * GET /api/stock/movement/:id/attachments - Récupérer les pièces jointes d'un mouvement
     */
    public function getMovementAttachments($id = null)
    {
        if (!$id) {
            return $this->respond(['success' => false, 'message' => 'ID du mouvement requis'], 400);
        }

        $attachments = $this->db->table('movement_attachments')
            ->select('id, filename, original_name, file_size, mime_type, created_at')
            ->where('movement_id', $id)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();

        foreach ($attachments as &$att) {
            $att['download_url'] = site_url('api/stock/download-attachment/' . $att['id']);
        }

        return $this->respond([
            'success' => true,
            'data' => $attachments
        ]);
    }
}
