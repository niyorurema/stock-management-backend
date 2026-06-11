<?php
// app/Controllers/PurchaseOrderController.php

namespace App\Controllers;

use Dompdf\Dompdf;
use Dompdf\Options;

use App\Models\PurchaseOrderModel;
use App\Models\PurchaseOrderItemModel;
use App\Models\SupplierModel;
use App\Models\ProductModel;
use App\Models\StockMovementModel;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class PurchaseOrderController extends ResourceController
{
    use ResponseTrait;

    protected $purchaseOrderModel;
    protected $purchaseOrderItemModel;
    protected $supplierModel;
    protected $productModel;
    protected $stockMovementModel;
    protected $db;
    protected $format = 'json';

    public function __construct()
    {
        $this->purchaseOrderModel = new PurchaseOrderModel();
        $this->purchaseOrderItemModel = new PurchaseOrderItemModel();
        $this->supplierModel = new SupplierModel();
        $this->productModel = new ProductModel();
        $this->stockMovementModel = new StockMovementModel();
        $this->db = \Config\Database::connect();
    }


    /**
     * GET /api/purchase-orders - Liste des commandes
     */
    public function index()
    {
        try {
            $filters = [
                'order_number' => $this->request->getVar('order_number'),
                'supplier_id' => $this->request->getVar('supplier_id'),
                'status' => $this->request->getVar('status'),
                'date_from' => $this->request->getVar('date_from'),
                'date_to' => $this->request->getVar('date_to')
            ];

            // Filtrer les valeurs vides
            $filters = array_filter($filters, function ($value) {
                return !empty($value) && $value !== '';
            });

            $page = (int)($this->request->getVar('page') ?? 1);
            $limit = (int)($this->request->getVar('limit') ?? 10);

            $result = $this->purchaseOrderModel->getOrdersWithFilters($filters, $page, $limit);

            return $this->respond([
                'success' => true,
                'data' => $result['data'],
                'pagination' => $result['pagination']
            ]);
        } catch (\Exception $e) {
            log_message('error', 'PurchaseOrder index error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/purchase-orders/(:num) - Détail d'une commande
     */
    public function show($id = null)
    {
        try {
            $order = $this->purchaseOrderModel->getOrderWithDetails($id);
            if (!$order) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Commande non trouvée'
                ], 404);
            }

            if (!isset($order['items'])) {
                $order['items'] = [];
            }

            if (!isset($order['supplier_name']) && isset($order['supplier_id'])) {
                $supplier = $this->supplierModel->find($order['supplier_id']);
                $order['supplier_name'] = $supplier['name'] ?? '-';
                $order['supplier_phone'] = $supplier['phone'] ?? '-';
                $order['supplier_email'] = $supplier['email'] ?? '-';
                $order['supplier_code'] = $supplier['code'] ?? '-';
                $order['contact_person'] = $supplier['contact_person'] ?? '-';
            }


            return $this->respond([
                'success' => true,
                'data' => $order
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function create()
    {
        try {
            $input = $this->request->getJSON(true);
            // ========== VALIDATION ==========
            if (empty($input['supplier_id'])) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Le fournisseur est requis'
                ], 400);
            }

            if (empty($input['items']) || !is_array($input['items'])) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Au moins un produit est requis'
                ], 400);
            }

            // Validation des items
            foreach ($input['items'] as $item) {
                if (empty($item['product_id'])) {
                    return $this->respond([
                        'success' => false,
                        'message' => 'Chaque produit doit avoir un ID valide'
                    ], 400);
                }
                if (empty($item['quantity']) || $item['quantity'] <= 0) {
                    return $this->respond([
                        'success' => false,
                        'message' => 'La quantité doit être supérieure à 0'
                    ], 400);
                }
            }

            // ========== GÉNÉRATION DU NUMÉRO DE COMMANDE ==========
            $orderNumber = $this->purchaseOrderModel->generateOrderNumber();

            // ========== TAUX DE CHANGE ==========
            $exchangeRateAedToUsd = $input['exchange_rate_aed_to_usd'] ?? 3.6725;
            $exchangeRateUsdToBif = $input['exchange_rate_usd_to_bif'] ?? 2830;

            // ========== CALCUL DES TOTAUX ==========
            $subtotalAed = 0;
            $subtotalUsd = 0;
            $subtotalBif = 0;
            $totalExpectedProfit = 0;

            foreach ($input['items'] as $item) {
                // Ensure totals fallback when unit costs provided; do not change items here
                $subtotalAed += $item['total_cost_aed'] ?? (($item['unit_cost_aed'] ?? 0) * ($item['quantity'] ?? 0));
                $subtotalUsd += $item['total_cost_usd'] ?? (($item['unit_cost_usd'] ?? 0) * ($item['quantity'] ?? 0));
                $subtotalBif += $item['total_cost_bif'] ?? (($item['unit_cost_bif'] ?? 0) * ($item['quantity'] ?? 0));
                $totalExpectedProfit += $item['expected_profit'] ?? 0;
            }

            // ========== PRÉPARATION DES DONNÉES DE LA COMMANDE ==========
            $orderData = [
                'order_number' => $orderNumber,
                'supplier_id' => $input['supplier_id'],
                'order_date' => $input['order_date'] ?? date('Y-m-d H:i:s'),
                'expected_delivery_date' => $input['expected_delivery_date'] ?? null,
                'priority' => $input['priority'] ?? 'normal',
                'currency' => 'AED', // Devise de base
                'exchange_rate_aed_to_usd' => $exchangeRateAedToUsd,
                'exchange_rate_usd_to_bif' => $exchangeRateUsdToBif,
                'subtotal_aed' => $subtotalAed,
                'subtotal_usd' => $subtotalUsd,
                'subtotal_bif' => $subtotalBif,
                'total_amount_aed' => $subtotalAed,
                'total_amount_usd' => $subtotalUsd,
                'total_amount_bif' => $subtotalBif,
                'subtotal' => $subtotalBif,
                'total_amount' => $subtotalBif,
                'total_expected_profit' => $totalExpectedProfit,
                'notes' => $input['notes'] ?? null,
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'created_by' => session()->get('user_id')
            ];

            // ========== TRANSACTION ==========
            $this->db->transStart();

            // Insérer la commande
            $orderId = $this->purchaseOrderModel->insert($orderData);

            if (!$orderId) {
                $this->db->transRollback();
                return $this->respond([
                    'success' => false,
                    'message' => 'Erreur lors de la création de la commande'
                ], 500);
            }

            // ========== INSÉRER LES ITEMS ==========
            foreach ($input['items'] as $item) {
                // Récupérer le produit pour le prix de vente et les prix moyens
                $product = $this->productModel->find($item['product_id']);
                $sellingPrice = $product['selling_price'] ?? 0;

                // Récupérer/inférer les prix unitaires; si non fournis, utiliser la moyenne stockée
                $quantity = $item['quantity'] ?? 0;
                $unitCostAed = isset($item['unit_cost_aed']) ? (float)$item['unit_cost_aed'] : (float)($product['avg_purchase_price_aed'] ?? $product['purchase_price_aed'] ?? $product['purchase_price'] ?? 0);

                // Derive USD/BIF using exchange rates provided for the order (fallback to product ratios if present)
                $unitCostUsd = isset($item['unit_cost_usd']) ? (float)$item['unit_cost_usd'] : (float)($item['unit_cost_usd'] ?? ($unitCostAed / max(1, $exchangeRateAedToUsd)));
                $unitCostBif = isset($item['unit_cost_bif']) ? (float)$item['unit_cost_bif'] : (float)($unitCostUsd * $exchangeRateUsdToBif);

                // Totaux
                $totalCostAed = $item['total_cost_aed'] ?? ($unitCostAed * $quantity);
                $totalCostUsd = $item['total_cost_usd'] ?? ($unitCostUsd * $quantity);
                $totalCostBif = $item['total_cost_bif'] ?? ($unitCostBif * $quantity);

                // Profit et marge
                $expectedProfit = ($sellingPrice - $unitCostBif) * $quantity;
                $profitMargin = $unitCostBif > 0 ? (($sellingPrice - $unitCostBif) / $unitCostBif) * 100 : 0;

                $itemData = [
                    'order_id' => $orderId,
                    'product_id' => $item['product_id'],
                    'quantity' => $quantity,
                    'unit_cost_aed' => round($unitCostAed, 4),
                    'unit_cost_usd' => round($unitCostUsd, 4),
                    'unit_cost_bif' => round($unitCostBif, 2),
                    'total_cost_aed' => round($totalCostAed, 2),
                    'total_cost_usd' => round($totalCostUsd, 2),
                    'total_cost_bif' => round($totalCostBif, 2),
                    'expected_profit' => round($expectedProfit, 2),
                    'profit_margin' => round($profitMargin, 2),
                    'received_quantity' => 0,
                    'unit_cost' => round($unitCostAed, 2)
                ];

                if (!$this->purchaseOrderItemModel->insert($itemData)) {
                    $this->db->transRollback();
                    return $this->respond([
                        'success' => false,
                        'message' => 'Erreur lors de l\'ajout des produits'
                    ], 500);
                }
            }

            $this->db->transComplete();

            // ========== RÉCUPÉRER LA COMMANDE COMPLÈTE ==========
            $order = $this->purchaseOrderModel->getOrderWithDetails($orderId);

            return $this->respond([
                'success' => true,
                'message' => 'Commande créée avec succès',
                'data' => $order
            ], 201);
        } catch (\Exception $e) {
            $this->db->transRollback();
            log_message('error', 'PurchaseOrder create error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function update($id = null)
    {
        try {
            // Vérifier si la commande existe
            $existingOrder = $this->purchaseOrderModel->find($id);
            if (!$existingOrder) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Commande non trouvée'
                ], 404);
            }

            $input = $this->request->getJSON(true);

            // ========== VALIDATION ==========
            if (empty($input['supplier_id'])) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Le fournisseur est requis'
                ], 400);
            }

            if (empty($input['items']) || !is_array($input['items'])) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Au moins un produit est requis'
                ], 400);
            }

            // ========== TAUX DE CHANGE ==========
            $exchangeRateAedToUsd = $input['exchange_rate_aed_to_usd'] ?? 3.6725;
            $exchangeRateUsdToBif = $input['exchange_rate_usd_to_bif'] ?? 2830;

            // ========== CALCUL DES TOTAUX ==========
            $subtotalAed = 0;
            $subtotalUsd = 0;
            $subtotalBif = 0;
            $totalExpectedProfit = 0;

            foreach ($input['items'] as $item) {
                $subtotalAed += $item['total_cost_aed'] ?? ($item['unit_cost_aed'] * $item['quantity']);
                $subtotalUsd += $item['total_cost_usd'] ?? ($item['unit_cost_usd'] * $item['quantity']);
                $subtotalBif += $item['total_cost_bif'] ?? ($item['unit_cost_bif'] * $item['quantity']);
                $totalExpectedProfit += $item['expected_profit'] ?? 0;
            }

            // ========== PRÉPARATION DES DONNÉES DE LA COMMANDE ==========
            $orderData = [
                'supplier_id' => $input['supplier_id'],
                'order_date' => $input['order_date'] ?? $existingOrder['order_date'],
                'expected_delivery_date' => $input['expected_delivery_date'] ?? $existingOrder['expected_delivery_date'],
                'priority' => $input['priority'] ?? $existingOrder['priority'],
                'exchange_rate_aed_to_usd' => $exchangeRateAedToUsd,
                'exchange_rate_usd_to_bif' => $exchangeRateUsdToBif,
                'subtotal_aed' => $subtotalAed,
                'subtotal_usd' => $subtotalUsd,
                'subtotal_bif' => $subtotalBif,
                'subtotal' => $subtotalBif,
                'total_amount' => $subtotalBif,
                'total_amount_aed' => $subtotalAed,
                'total_amount_usd' => $subtotalUsd,
                'total_amount_bif' => $subtotalBif,
                'total_expected_profit' => $totalExpectedProfit,
                'notes' => $input['notes'] ?? $existingOrder['notes'],
                'updated_at' => date('Y-m-d H:i:s')
            ];

            // ========== TRANSACTION ==========
            $this->db->transStart();

            // Mettre à jour la commande
            if (!$this->purchaseOrderModel->update($id, $orderData)) {
                $this->db->transRollback();
                return $this->respond([
                    'success' => false,
                    'message' => 'Erreur lors de la mise à jour de la commande'
                ], 500);
            }

            // Supprimer les anciens items
            $this->db->table('supplier_order_items')
                ->where('order_id', $id)
                ->delete();
            $items = $input['items'];

            // echo '<pre>';

            // print_r($items);
            // echo '</pre>';
            // ========== INSÉRER LES NOUVEAUX ITEMS ==========
            foreach ($items as $item) {
                // Récupérer le produit pour le prix de vente
                $product = $this->productModel->find($item['product_id']);
                $sellingPrice = $product['selling_price'] ?? 0;

                $quantity = $item['quantity'] ?? 0;
                $unitCostAed = isset($item['unit_cost_aed']) ? (float)$item['unit_cost_aed'] : (float)($product['avg_purchase_price_aed'] ?? $product['purchase_price_aed'] ?? $product['purchase_price'] ?? 0);
                $unitCostUsd = isset($item['unit_cost_usd']) ? (float)$item['unit_cost_usd'] : (float)($item['unit_cost_usd'] ?? ($unitCostAed / max(1, $exchangeRateAedToUsd)));
                $unitCostBif = isset($item['unit_cost_bif']) ? (float)$item['unit_cost_bif'] : (float)($unitCostUsd * $exchangeRateUsdToBif);

                $totalCostAed = round($item['total_cost_aed'] ?? ($unitCostAed * $quantity), 2);
                $totalCostUsd = round($item['total_cost_usd'] ?? ($unitCostUsd * $quantity), 2);
                $totalCostBif = round($item['total_cost_bif'] ?? ($unitCostBif * $quantity), 2);

                $expectedProfit = round($item['expected_profit'], 2); //($sellingPrice - $unitCostBif) * $quantity;
                // $profitMargin = $unitCostBif > 0 ? (($sellingPrice - $unitCostBif) / $unitCostBif) * 100 : 0;
                $profitMargin = $unitCostBif > 0 ? round((($sellingPrice - $unitCostBif) / $unitCostBif) * 100, 2) : 0;
                $itemData = [
                    'order_id' => $id,
                    'product_id' => $item['product_id'],
                    'quantity' => round($quantity, 3),
                    'unit_cost_aed' => round($unitCostAed, 2),
                    'unit_cost_usd' => round($unitCostUsd, 2),
                    'unit_cost_bif' => round($unitCostBif, 2),
                    'total_cost_aed' => $totalCostAed,
                    'total_cost_usd' => $totalCostUsd,
                    'total_cost_bif' => $totalCostBif,
                    'expected_profit' => $expectedProfit,
                    'profit_margin' => $profitMargin,
                    'unit_cost' => round($unitCostAed, 2)
                ];

                if (!$this->purchaseOrderItemModel->insert($itemData)) {
                    $this->db->transRollback();
                    return $this->respond([
                        'success' => false,
                        'message' => 'Erreur lors de l\'ajout des produits'
                    ], 500);
                }
            }

            $this->db->transComplete();

            // ========== RÉCUPÉRER LA COMMANDE COMPLÈTE ==========
            $order = $this->purchaseOrderModel->getOrderWithDetails($id);

            return $this->respond([
                'success' => true,
                'message' => 'Commande mise à jour avec succès',
                'data' => $order
            ]);
        } catch (\Exception $e) {
            $this->db->transRollback();
            log_message('error', 'PurchaseOrder update error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function approve($id = null)
    {
        try {
            // Vérifier si la commande existe
            $order = $this->purchaseOrderModel->find($id);

            if (!$order) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Commande non trouvée'
                ], 404);
            }

            // Vérifier si la commande est déjà approuvée
            if ($order['status'] === 'approved') {
                return $this->respond([
                    'success' => false,
                    'message' => 'Cette commande est déjà approuvée'
                ], 400);
            }

            // Vérifier si la commande peut être approuvée (status pending)
            if ($order['status'] !== 'pending') {
                return $this->respond([
                    'success' => false,
                    'message' => 'Seules les commandes en attente peuvent être approuvées'
                ], 400);
            }

            $input = $this->request->getJSON(true);
            $approvedBy = $input['approved_by'] ?? session()->get('user_id');

            // Mettre à jour le statut
            $updateData = [
                'status' => 'approved',
                'approved_at' => date('Y-m-d H:i:s'),
                'approved_by' => $approvedBy
            ];

            if (!$this->purchaseOrderModel->update($id, $updateData)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Erreur lors de l\'approbation de la commande'
                ], 500);
            }

            // Récupérer la commande mise à jour
            $updatedOrder = $this->purchaseOrderModel->getOrderWithDetails($id);

            return $this->respond([
                'success' => true,
                'message' => 'Commande approuvée avec succès',
                'data' => $updatedOrder
            ]);
        } catch (\Exception $e) {
            log_message('error', 'PurchaseOrder approve error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus($id = null)
    {
        try {
            $order = $this->purchaseOrderModel->find($id);

            if (!$order) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Commande non trouvée'
                ], 404);
            }

            $input = $this->request->getJSON(true);
            $newStatus = $input['status'] ?? null;

            $allowedStatuses = ['draft', 'pending', 'approved', 'processing', 'partial', 'completed', 'cancelled', 'rejected'];

            if (!$newStatus || !in_array($newStatus, $allowedStatuses)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Statut invalide'
                ], 400);
            }

            // Vérifier si le changement est autorisé
            $currentStatus = $order['status'];
            $allowedTransitions = [
                'draft' => ['pending', 'cancelled'],
                'pending' => ['approved', 'cancelled'],
                'approved' => ['processing', 'cancelled'],
                'processing' => ['partial', 'completed', 'cancelled'],
                'partial' => ['completed', 'cancelled'],
                'completed' => [],
                'cancelled' => []
            ];

            if (!in_array($newStatus, $allowedTransitions[$currentStatus] ?? [])) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Transition de statut non autorisée'
                ], 400);
            }

            $updateData = [
                'status' => $newStatus,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if ($newStatus === 'confirmed') {
                $updateData['confirmed_at'] = date('Y-m-d H:i:s');
            }

            if (!$this->purchaseOrderModel->update($id, $updateData)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Erreur lors de la mise à jour du statut'
                ], 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Statut mis à jour avec succès',
                'data' => $this->purchaseOrderModel->getOrderWithDetails($id)
            ]);
        } catch (\Exception $e) {
            log_message('error', 'PurchaseOrder updateStatus error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function receive($id = null)
    {
        try {
            $order = $this->purchaseOrderModel->find($id);

            if (!$order) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Commande non trouvée'
                ], 404);
            }

            // Récupérer les données du formulaire
            $input = $this->request->getPost();
            $items = json_decode($input['items'] ?? '[]', true);

            if (empty($items)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Aucun article à réceptionner'
                ], 400);
            }

            $this->db->transStart();

            // Générer le numéro de réception
            $receptionNumber = $this->generateReceptionNumber();

            // Créer la réception
            $receptionData = [
                'reception_number' => $receptionNumber,
                'order_id' => $id,
                'reception_date' => $input['reception_date'] ?? date('Y-m-d H:i:s'),
                'received_by' => session()->get('user_id'),
                'notes' => $input['notes'] ?? null
            ];

            $this->db->table('order_receptions')->insert($receptionData);
            $receptionId = $this->db->insertID();

            // ========== TRAITEMENT DES FICHIERS CORRIGÉ ==========
            $attachmentPaths = [];

            // Créer le dossier d'upload s'il n'existe pas

            $uploadPath = FCPATH . 'uploads/receptions/';
            if (!is_dir($uploadPath)) {
                mkdir($uploadPath, 0777, true);
            }

            // Récupérer les fichiers via $_FILES directement (plus fiable)
            if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
                $filesCount = count($_FILES['attachments']['name']);
                for ($i = 0; $i < $filesCount; $i++) {
                    if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                        $tmpName = $_FILES['attachments']['tmp_name'][$i];
                        $originalName = $_FILES['attachments']['name'][$i];
                        $fileSize = $_FILES['attachments']['size'][$i];
                        $fileType = $_FILES['attachments']['type'][$i];

                        // Vérifier la taille (max 5MB)
                        if ($fileSize > 5 * 1024 * 1024) {
                            continue;
                        }

                        // Générer un nom unique
                        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                        $newName = $receptionNumber . '_' . time() . '_' . uniqid() . '.' . $extension;
                        $destination = $uploadPath . $newName;

                        // Déplacer le fichier
                        if (move_uploaded_file($tmpName, $destination)) {
                            $attachmentPaths[] = [
                                'reception_id' => $receptionId,
                                'file_name' => $originalName,
                                'file_path' => 'uploads/receptions/' . $newName,
                                'file_size' => $fileSize,
                                'file_type' => $fileType,
                                'uploaded_by' => session()->get('user_id'),
                                'created_at' => date('Y-m-d H:i:s')
                            ];
                        }
                    }
                }
            }

            // Insérer les pièces jointes
            if (!empty($attachmentPaths)) {
                $this->db->table('reception_attachments')->insertBatch($attachmentPaths);
            }

            // ========== TRAITEMENT DES ARTICLES ==========
            $totalReceived = 0;
            $totalQuantity = 0;

            foreach ($items as $item) {
                $orderItem = $this->purchaseOrderItemModel->find($item['order_item_id']);

                if (!$orderItem) continue;

                $receivedQty = floatval($item['received_quantity']);
                if ($receivedQty <= 0) continue;

                // Vérifier la quantité maximale
                $maxQty = $orderItem['quantity'] - $orderItem['received_quantity'];
                if ($receivedQty > $maxQty) {
                    $this->db->transRollback();
                    return $this->respond([
                        'success' => false,
                        'message' => 'La quantité reçue dépasse la quantité restante'
                    ], 400);
                }

                // Mettre à jour la quantité reçue
                $newReceived = $orderItem['received_quantity'] + $receivedQty;
                $this->purchaseOrderItemModel->update($item['order_item_id'], [
                    'received_quantity' => $newReceived
                ]);

                // Enregistrer le détail de réception
                $receptionItemData = [
                    'reception_id' => $receptionId,
                    'order_item_id' => $item['order_item_id'],
                    'product_id' => $orderItem['product_id'],
                    'received_quantity' => $receivedQty,
                    'unit_cost_aed' => $orderItem['unit_cost_aed'],
                    'unit_cost_usd' => $orderItem['unit_cost_usd'],
                    'unit_cost_bif' => $orderItem['unit_cost_bif'],
                    'total_cost_aed' => $orderItem['unit_cost_aed'] * $receivedQty,
                    'total_cost_usd' => $orderItem['unit_cost_usd'] * $receivedQty,
                    'total_cost_bif' => $orderItem['unit_cost_bif'] * $receivedQty
                ];

                $this->db->table('reception_items')->insert($receptionItemData);

                // Mettre à jour le stock
                $product = $this->productModel->find($orderItem['product_id']);
                $oldStock = floatval($product['current_stock'] ?? 0);
                $newStock = $oldStock + $receivedQty;
                $this->productModel->update($orderItem['product_id'], [
                    'current_stock' => $newStock
                ]);

                // Créer un mouvement de stock
                $movementNumber = $this->stockMovementModel->generateMovementNumber();
                $movementData = [
                    'movement_number' => $movementNumber,
                    'movement_group' => 'PURCHASE_ORDER_' . $id,
                    'warehouse_id' => 1,
                    'product_id' => $orderItem['product_id'],
                    'movement_type' => 'EN',
                    'quantity' => $receivedQty,
                    'previous_quantity' => $oldStock,
                    'new_quantity' => $newStock,
                    'unit_cost' => $orderItem['unit_cost_aed'] ?? $orderItem['unit_cost'] ?? ($orderItem['unit_cost_bif'] ?? 0),
                    'total_cost' => ($orderItem['unit_cost_aed'] ?? $orderItem['unit_cost'] ?? ($orderItem['unit_cost_bif'] ?? 0)) * $receivedQty,
                    'invoice_ref' => $order['order_number'],
                    'description' => 'Réception commande fournisseur ' . $order['order_number'],
                    'movement_date' => date('Y-m-d H:i:s'),
                    'created_by' => session()->get('user_id'),
                    'reference' => $order['order_number']
                ];
                $this->stockMovementModel->insert($movementData);
                $totalReceived += $newReceived;
                $totalQuantity += $orderItem['quantity'];
            }

            // Mettre à jour le statut de la commande
            $newStatus = 'processing';
            if ($totalReceived >= $totalQuantity) {
                $newStatus = 'completed';
            } elseif ($totalReceived > 0) {
                $newStatus = 'partial';
            }

            $this->purchaseOrderModel->update($id, [
                'status' => $newStatus,
                'actual_delivery_date' => $newStatus === 'completed' ? date('Y-m-d H:i:s') : null
            ]);

            $this->db->transComplete();

            return $this->respond([
                'success' => true,
                'message' => 'Réception enregistrée avec succès',
                'data' => [
                    'reception_number' => $receptionNumber,
                    'status' => $newStatus,
                    'total_received' => $totalReceived,
                    'attachments_count' => count($attachmentPaths)
                ]
            ]);
        } catch (\Exception $e) {
            $this->db->transRollback();
            log_message('error', 'Receive error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Générer un numéro de réception unique
     */
    private function generateReceptionNumber()
    {
        $date = date('Ymd');
        $prefix = 'REC-' . $date . '-';

        $lastReception = $this->db->table('order_receptions')
            ->select('reception_number')
            ->like('reception_number', $prefix, 'after')
            ->orderBy('reception_number', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        if ($lastReception) {
            $lastNumber = (int)substr($lastReception['reception_number'], -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return $prefix . $newNumber;
    }


// app/Controllers/PurchaseOrderController.php

    /**
     * GET /api/purchase-orders/receptions/(:num)/attachments
     */
    public function getReceptionAttachments($receptionId = null)
    {
        try {
            $attachments = $this->db->table('reception_attachments')
                ->where('reception_id', $receptionId)
                ->get()
                ->getResultArray();

            return $this->respond([
                'success' => true,
                'data' => $attachments
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // app/Controllers/PurchaseOrderController.php

    public function getSignatures($id = null)
    {
        try {
            $signatures = $this->db->table('reception_signatures rs')
                ->select('rs.*, or.reception_number, or.reception_date, u.username as signed_by_name')
                ->join('order_receptions or', 'or.id = rs.reception_id')
                ->join('users u', 'u.id = rs.signed_by', 'left')
                ->where('rs.order_id', $id)
                ->orderBy('rs.signed_at', 'DESC')
                ->get()
                ->getResultArray();

            // Debug: loguer les résultats
            log_message('info', 'Signatures found: ' . json_encode($signatures));

            return $this->respond([
                'success' => true,
                'data' => $signatures
            ]);
        } catch (\Exception $e) {
            log_message('error', 'getSignatures error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * GET /api/purchase-orders/receptions/(:num)/print
     */
    public function printReception($receptionId = null)
    {
        try {
            $reception = $this->db->table('order_receptions or')
                ->select('or.*, u.username as received_by_name, so.order_number, so.supplier_id, so.order_date, so.expected_delivery_date, s.name as supplier_name')
                ->join('users u', 'u.id = or.received_by', 'left')
                ->join('supplier_orders so', 'so.id = or.order_id')
                ->join('suppliers s', 's.id = so.supplier_id')
                ->where('or.id', $receptionId)
                ->get()
                ->getRowArray();

            if (!$reception) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Réception non trouvée'
                ], 404);
            }

            // Récupérer les items de la réception
            $items = $this->db->table('reception_items ri')
                ->select('ri.*, p.name as product_name, p.unit')
                ->join('products p', 'p.id = ri.product_id')
                ->where('ri.reception_id', $receptionId)
                ->get()
                ->getResultArray();

            $reception['items'] = $items;

            return $this->respond([
                'success' => true,
                'data' => $reception
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Print reception error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * POST /api/purchase-orders/(:num)/share-email
     * Envoyer la commande par email avec PDF joint
     */
    public function shareEmail($id = null)
    {
        try {
            // Vérifier si la commande existe
            $order = $this->purchaseOrderModel->getOrderWithDetails($id);

            if (!$order) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Commande non trouvée'
                ], 404);
            }

            // Récupérer l'email du destinataire
            $input = $this->request->getJSON(true);
            $recipientEmail = $input['email'] ?? null;

            if (empty($recipientEmail)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Email du destinataire requis'
                ], 400);
            }

            // Vérifier si la commande a été approuvée partial
            if ($order['status'] !== 'approved' && $order['status'] !== 'processing' && $order['status'] !== 'partial' && $order['status'] !== 'completed') {
                return $this->respond([
                    'success' => false,
                    'message' => 'La commande doit être approuvée avant d\'être envoyée'
                ], 400);
            }

            // Générer le PDF de la commande
            $pdfContent = $this->generateOrderPDF($order);

            // Sauvegarder temporairement le PDF
            $tempDir = WRITEPATH . 'temp/';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }
            $pdfPath = $tempDir . 'commande_' . $order['order_number'] . '.pdf';
            file_put_contents($pdfPath, $pdfContent);

            // Configurer et envoyer l'email
            $email = \Config\Services::email();

            $email->setFrom(getenv('email.fromEmail') ?: 'niyoruremaezechiel1993@gmail.com', getenv('email.fromName') ?: 'SM-PRO');
            $email->setTo($recipientEmail);
            $email->setSubject('Commande Fournisseur N° ' . $order['order_number']);
            $email->setMessage($this->getEmailBody($order));
            $email->attach($pdfPath);

            if ($email->send()) {
                // Supprimer le fichier temporaire
                @unlink($pdfPath);

                // Enregistrer l'envoi dans l'historique
                $this->db->table('order_shares')->insert([
                    'order_id' => $id,
                    'share_type' => 'email',
                    'shared_to' => $recipientEmail,
                    'shared_by' => session()->get('user_id'),
                    'shared_at' => date('Y-m-d H:i:s')
                ]);

                return $this->respond([
                    'success' => true,
                    'message' => 'Email envoyé avec succès à ' . $recipientEmail
                ]);
            } else {
                @unlink($pdfPath);
                return $this->respond([
                    'success' => false,
                    'message' => 'Erreur lors de l\'envoi de l\'email'
                ], 500);
            }
        } catch (\Exception $e) {
            log_message('error', 'shareEmail error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Générer le corps de l'email
     */
    private function getEmailBody($order)
    {
        $companyName = getenv('email.fromName') ?: 'SM-PRO';

        return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { text-align: center; padding: 20px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border-radius: 10px 10px 0 0; }
            .content { padding: 20px; background: #f8fafc; }
            .order-details { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; border: 1px solid #e2e8f0; }
            .order-summary { display: flex; justify-content: space-between; margin: 10px 0; padding: 8px; background: #f1f5f9; border-radius: 6px; }
            .footer { text-align: center; padding: 15px; font-size: 11px; color: #666; border-top: 1px solid #e2e8f0; margin-top: 20px; }
            .btn { display: inline-block; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 6px; margin-top: 15px; }
            h2 { color: #667eea; margin-bottom: 10px; }
            .amount { font-size: 18px; font-weight: bold; color: #10b981; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>📦 Commande Fournisseur</h2>
                <p>N° ' . $order['order_number'] . '</p>
            </div>
            <div class="content">
                <p>Bonjour,</p>
                <p>Veuillez trouver ci-joint le bon de commande N° <strong>' . $order['order_number'] . '</strong>.</p>
                
                <div class="order-details">
                    <h3>📋 Récapitulatif</h3>
                    <div class="order-summary">
                        <span><strong>Date de commande:</strong></span>
                        <span>' . date('d/m/Y H:i', strtotime($order['order_date'])) . '</span>
                    </div>
                    <div class="order-summary">
                        <span><strong>Fournisseur:</strong></span>
                        <span>' . ($order['supplier_name'] ?? '-') . '</span>
                    </div>
                    <div class="order-summary">
                        <span><strong>Nombre de produits:</strong></span>
                        <span>' . count($order['items'] ?? []) . '</span>
                    </div>
                    <div class="order-summary">
                        <span><strong>Quantité totale:</strong></span>
                        <span>' . array_sum(array_column($order['items'] ?? [], 'quantity')) . '</span>
                    </div>
                    <div class="order-summary">
                        <span><strong>Montant total:</strong></span>
                        <span class="amount">' . number_format($order['total_amount'] ?? 0, 2) . ' AED</span>
                    </div>
                </div>
                
                <p>Vous pouvez consulter les détails complets dans le fichier PDF joint à cet email.</p>
                
                <div style="text-align: center;">
                    <a href="#" class="btn">📄 Voir la commande</a>
                </div>
                
                <p style="margin-top: 20px;">Cordialement,<br><strong>' . $companyName . '</strong></p>
            </div>
            <div class="footer">
                <p>Ceci est un message automatique. Merci de ne pas répondre à cet email.</p>
                <p>&copy; ' . date('Y') . ' ' . $companyName . ' - Tous droits réservés</p>
            </div>
        </div>
    </body>
    </html>';
    }


    private function generateOrderPDF(array $order): string
    {
        $this->validateOrderData($order);

        // Récupération des settings avec cache
        $settings = $this->getCompanySettings();

        // Extraction des données
        $companyData = $this->extractCompanyData($settings);
        $supplierData = $this->extractSupplierData($order);
        $financialData = $this->calculateFinancialData($order);

        // Génération du PDF
        $html = $this->generateModernOrderHTML($order, $companyData, $supplierData, $financialData);

        return $this->generatePDF($html, $order['order_number']);
    }

    /**
     * Génère le HTML moderne et élégant du document
     */
    private function generateModernOrderHTML(
        array $order,
        array $companyData,
        array $supplierData,
        array $financialData
    ): string {
        $itemsHtml = $this->generateModernItemsHTML($order['items'] ?? []);
        $rateBifPerAed = $financialData['rate_aed_to_usd'] * $financialData['rate_usd_to_bif'];

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>BON DE COMMANDE {$order['order_number']}</title>
    <style>
        {$this->getModernPDFStyles()}
    </style>
</head>
<body>
    <!-- En-tête avec logo et titre -->
    <div class="header">
        <div class="header-left">
            <div class="company-logo">
                <div class="logo-placeholder">
                    <svg width="50" height="50" viewBox="0 0 50 50" style="background: #1e40af; border-radius: 12px;">
                        <text x="25" y="32" text-anchor="middle" fill="white" font-size="20" font-weight="bold">M</text>
                    </svg>
                </div>
                <div class="company-mini">
                    <div class="company-mini-name">{$this->sanitizeValue($companyData['name'])}</div>
                    <div class="company-mini-details">NIF: {$this->sanitizeValue($companyData['nif'])} | RC: {$this->sanitizeValue($companyData['rc'])}</div>
                </div>
            </div>
        </div>
        <div class="header-center">
            <h1 class="doc-title">BON DE COMMANDE</h1>
            <p class="doc-subtitle">PURCHASE ORDER</p>
        </div>
        <div class="header-right">
            <div class="ref-badge">
                <div class="ref-label">N° COMMANDE</div>
                <div class="ref-number">{$this->sanitizeValue($order['order_number'])}</div>
            </div>
        </div>
    </div>
    
    <!-- Informations de l'entreprise (compact) -->
    <div class="company-info-bar">
        <div class="info-item">
            <span class="info-icon">📍</span>
            <span>{$this->sanitizeValue($companyData['address'])}, {$this->sanitizeValue($companyData['commune'])}</span>
        </div>
        <div class="info-item">
            <span class="info-icon">📞</span>
            <span>{$this->sanitizeValue($companyData['phone'])}</span>
        </div>
        <div class="info-item">
            <span class="info-icon">✉️</span>
            <span>{$this->sanitizeValue($companyData['email'])}</span>
        </div>
    </div>
    
    <!-- Cartes d'informations (style moderne) -->
    <div class="cards-grid">
        <div class="info-card">
            <div class="card-header">
                <span class="card-icon">📅</span>
                <span class="card-title">Détails commande</span>
            </div>
            <div class="card-content">
                <div class="card-row">
                    <span class="card-label">Date :</span>
                    <span class="card-value">{$this->formatDate($order['order_date'])}</span>
                </div>
                <div class="card-row">
                    <span class="card-label">Livraison :</span>
                    <span class="card-value">{$this->sanitizeValue($order['expected_delivery_date'] ?? '-')}</span>
                </div>
                <div class="card-row">
                    <span class="card-label">Priorité :</span>
                    <span class="card-value priority-{$this->getPriorityClass($order['priority'] ?? 'normal')}">{$this->formatPriority($order['priority'] ?? 'normal')}</span>
                </div>
                <div class="card-row">
                    <span class="card-label">Statut :</span>
                    <span class="card-value status-badge">{$this->formatStatus($order['status'] ?? 'draft')}</span>
                </div>
            </div>
        </div>
        
        <div class="info-card">
            <div class="card-header">
                <span class="card-icon">🏢</span>
                <span class="card-title">Fournisseur</span>
            </div>
            <div class="card-content">
                <div class="card-row">
                    <span class="card-label">Société :</span>
                    <span class="card-value"><strong>{$this->sanitizeValue($supplierData['name'])}</strong></span>
                </div>
                <div class="card-row">
                    <span class="card-label">Téléphone :</span>
                    <span class="card-value">{$this->sanitizeValue($supplierData['phone'])}</span>
                </div>
                <div class="card-row">
                    <span class="card-label">Email :</span>
                    <span class="card-value">{$this->sanitizeValue($supplierData['email'])}</span>
                </div>
            </div>
        </div>
        
        <div class="info-card">
            <div class="card-header">
                <span class="card-icon">💱</span>
                <span class="card-title">Taux de change</span>
            </div>
            <div class="card-content">
                <div class="card-row-small">
                    <span>1 AED = {$this->formatNumber($financialData['rate_aed_to_usd'], 4)} USD</span>
                </div>
                <div class="card-row-small">
                    <span>1 USD = {$this->formatNumber($financialData['rate_usd_to_bif'], 2)} BIF</span>
                </div>
                <div class="card-row-small highlight">
                    <span>1 AED = {$this->formatNumber($rateBifPerAed, 0)} BIF</span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tableau des produits (design élégant) -->
    <div class="table-container">
        <table class="elegant-table">
            <thead>
                <tr>
                    <th class="col-num">#</th>
                    <th class="col-product">Désignation</th>
                    <th class="col-qty">Qté</th>
                    <th class="col-price">P.U. (AED)</th>
                    <th class="col-price">P.U. (BIF)</th>
                    <th class="col-total">Total (AED)</th>
                    <th class="col-total">Total (BIF)</th>
                    <th class="col-profit">Marge (BIF)</th>
                </tr>
            </thead>
            <tbody>
                {$itemsHtml}
            </tbody>
        </table>
    </div>
    
    <!-- Pied du tableau avec récapitulatif -->
    <div class="table-footer">
        <div class="footer-left">
            <div class="summary-badge">
                <span>📦 {$financialData['items_count']} produit(s)</span>
                <span class="separator">|</span>
                <span>🔢 {$this->formatNumber($financialData['total_quantity'])} unité(s)</span>
            </div>
        </div>
        <div class="footer-right">
            <div class="totals-compact">
                <div class="total-line">
                    <span class="total-label">Sous-total AED :</span>
                    <span class="total-value">{$this->formatNumber($financialData['subtotal_aed'], 2)} AED</span>
                </div>
                <div class="total-line">
                    <span class="total-label">Sous-total BIF :</span>
                    <span class="total-value">{$this->formatNumber($financialData['subtotal_bif'], 0)} BIF</span>
                </div>
                <div class="total-line grand">
                    <span class="total-label">TOTAL AED :</span>
                    <span class="total-value">{$this->formatNumber($financialData['total_aed'], 2)} AED</span>
                </div>
                <div class="total-line grand">
                    <span class="total-label">TOTAL BIF :</span>
                    <span class="total-value">{$this->formatNumber($financialData['total_bif'], 0)} BIF</span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Profit attendu -->
    <div class="profit-section">
        <div class="profit-box">
            <span class="profit-icon">📈</span>
            <span class="profit-text">Bénéfice attendu :</span>
            <span class="profit-amount">{$this->formatNumber($financialData['total_profit'], 0)} FBu</span>
        </div>
    </div>
    
    <!-- Notes -->
    {$this->generateModernNotesHTML($order['notes'] ?? '')}
    
    <!-- Signatures -->
    <div class="signatures-modern">
        <div class="signature-modern">
            <div class="signature-line-modern"></div>
            <div class="signature-text-modern">Signature du fournisseur</div>
            <div class="signature-date-modern">Lu et approuvé</div>
        </div>
        <div class="signature-modern">
            <div class="signature-line-modern"></div>
            <div class="signature-text-modern">Signature et cachet</div>
            <div class="signature-date-modern">{$this->sanitizeValue($companyData['name'])}</div>
        </div>
    </div>
    
    <!-- Footer -->
    <div class="footer-modern">
        <div class="footer-text">Document généré le {$this->getCurrentDateTime()}</div>
        <div class="footer-thanks">Merci de votre confiance</div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Génère le HTML moderne des articles
     */
    private function generateModernItemsHTML(array $items): string
    {
        if (empty($items)) {
            return '<tr><td colspan="8" class="empty-row">Aucun produit dans cette commande</td></tr>';
        }

        $html = '';
        foreach ($items as $index => $item) {
            $profit = (float)($item['expected_profit'] ?? 0);
            $profitClass = $profit >= 0 ? 'profit-positive' : 'profit-negative';
            $profitColor = $profit >= 0 ? 'style="color: #059669;"' : 'style="color: #dc2626;"';

            $html .= <<<ROW
        <tr>
            <td class="text-center">{$this->sanitizeValue($index + 1)}</td>
            <td>
                <div class="product-name">{$this->sanitizeValue($item['product_name'] ?? '-')}</div>
                <div class="product-code">Code: {$this->sanitizeValue($item['product_code'] ?? '-')}</div>
            </td>
            <td class="text-center">{$this->formatNumber($item['quantity'] ?? 0)} <span class="unit">{$this->sanitizeValue($item['unit'] ?? '')}</span></td>
            <td class="text-right">{$this->formatNumber($item['unit_cost_aed'] ?? 0, 2)}</td>
            <td class="text-right">{$this->formatNumber($item['unit_cost_bif'] ?? 0, 0)}</td>
            <td class="text-right">{$this->formatNumber($item['total_cost_aed'] ?? 0, 2)}</td>
            <td class="text-right">{$this->formatNumber($item['total_cost_bif'] ?? 0, 0)}</td>
            <td class="text-right {$profitClass}" {$profitColor}>{$this->formatNumber($profit, 0)}</td>
        </tr>
ROW;
        }

        return $html;
    }

    /**
     * Génère les notes modernes
     */
    private function generateModernNotesHTML(string $notes): string
    {
        if (empty($notes)) {
            return '';
        }

        return <<<HTML
    <div class="notes-modern">
        <div class="notes-header-modern">
            <span class="notes-icon">📝</span>
            <span class="notes-title">Notes</span>
        </div>
        <div class="notes-content-modern">
            {$this->sanitizeValue(nl2br($notes))}
        </div>
    </div>
HTML;
    }

    /**
     * Retourne les styles CSS modernes
     */
    private function getModernPDFStyles(): string
    {
        return <<<CSS
        @page {
            size: A4;
            margin: 1.8cm 1.5cm;
        }
        
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 9pt;
            line-height: 1.4;
            color: #1f2937;
            background: #ffffff;
            margin: 0;
            padding: 0;
        }
        
        /* En-tête */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .header-left {
            flex: 1;
        }
        
        .company-logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .logo-placeholder {
            width: 45px;
            height: 45px;
        }
        
        .company-mini-name {
            font-size: 10pt;
            font-weight: bold;
            color: #111827;
        }
        
        .company-mini-details {
            font-size: 7pt;
            color: #6b7280;
        }
        
        .header-center {
            flex: 2;
            text-align: center;
        }
        
        .doc-title {
            font-size: 18pt;
            font-weight: bold;
            color: #1e40af;
            margin: 0;
            letter-spacing: 2px;
        }
        
        .doc-subtitle {
            font-size: 8pt;
            color: #6b7280;
            margin: 3px 0 0 0;
        }
        
        .header-right {
            flex: 1;
            text-align: right;
        }
        
        .ref-badge {
            background: #eff6ff;
            padding: 6px 12px;
            border-radius: 8px;
            display: inline-block;
        }
        
        .ref-label {
            font-size: 7pt;
            color: #3b82f6;
            font-weight: 600;
        }
        
        .ref-number {
            font-size: 11pt;
            font-weight: bold;
            color: #1e40af;
        }
        
        /* Barre d'informations */
        .company-info-bar {
            display: flex;
            justify-content: center;
            gap: 25px;
            padding: 10px 0;
            margin-bottom: 20px;
            background: #f9fafb;
            border-radius: 8px;
            font-size: 8pt;
            color: #4b5563;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .info-icon {
            font-size: 10pt;
        }
        
        /* Grille de cartes */
        .cards-grid {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .info-card {
            flex: 1;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .card-header {
            background: #f9fafb;
            padding: 8px 12px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .card-icon {
            font-size: 12pt;
        }
        
        .card-title {
            font-weight: 600;
            font-size: 9pt;
            color: #374151;
        }
        
        .card-content {
            padding: 10px 12px;
        }
        
        .card-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
            font-size: 8.5pt;
        }
        
        .card-row-small {
            margin-bottom: 5px;
            font-size: 8pt;
            color: #4b5563;
        }
        
        .card-row-small.highlight {
            color: #059669;
            font-weight: 600;
            margin-top: 5px;
            padding-top: 5px;
            border-top: 1px dashed #d1fae5;
        }
        
        .card-label {
            color: #6b7280;
        }
        
        .card-value {
            color: #111827;
            font-weight: 500;
        }
        
        .priority-high {
            color: #dc2626;
            font-weight: 600;
        }
        
        .priority-medium {
            color: #f59e0b;
            font-weight: 600;
        }
        
        .priority-low {
            color: #10b981;
            font-weight: 600;
        }
        
        .status-badge {
            background: #e5e7eb;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 7.5pt;
        }
        
        /* Tableau élégant */
        .table-container {
            margin: 20px 0 15px 0;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .elegant-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .elegant-table th {
            background: #f8fafc;
            padding: 10px 8px;
            font-size: 8pt;
            font-weight: 600;
            text-align: center;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .elegant-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 8.5pt;
        }
        
        .col-num { width: 35px; }
        .col-product { width: auto; }
        .col-qty { width: 60px; }
        .col-price { width: 85px; }
        .col-total { width: 95px; }
        .col-profit { width: 85px; }
        
        .product-name {
            font-weight: 500;
            color: #1e293b;
        }
        
        .product-code {
            font-size: 7pt;
            color: #94a3b8;
            margin-top: 2px;
        }
        
        .unit {
            font-size: 7pt;
            color: #6b7280;
        }
        
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        
        .profit-positive {
            font-weight: 600;
        }
        
        .profit-negative {
            font-weight: 600;
        }
        
        /* Pied du tableau */
        .table-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: -10px;
            margin-bottom: 20px;
            padding: 12px;
            background: #fafcff;
            border-radius: 8px;
        }
        
        .summary-badge {
            display: flex;
            gap: 12px;
            font-size: 8.5pt;
            color: #4b5563;
        }
        
        .separator {
            color: #d1d5db;
        }
        
        .totals-compact {
            text-align: right;
        }
        
        .total-line {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-bottom: 4px;
            font-size: 8.5pt;
        }
        
        .total-line.grand {
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px solid #e5e7eb;
            font-weight: bold;
            font-size: 9.5pt;
        }
        
        .total-label {
            color: #6b7280;
        }
        
        .total-value {
            color: #111827;
            font-weight: 500;
            min-width: 100px;
            text-align: right;
        }
        
        .total-line.grand .total-value {
            color: #1e40af;
            font-weight: bold;
        }
        
        /* Section profit */
        .profit-section {
            margin: 20px 0;
        }
        
        .profit-box {
            background: linear-gradient(135deg, #fef3c7 0%, #fffbeb 100%);
            border-left: 4px solid #f59e0b;
            padding: 12px 20px;
            border-radius: 8px;
            display: inline-block;
            width: auto;
        }
        
        .profit-icon {
            font-size: 14pt;
            margin-right: 8px;
        }
        
        .profit-text {
            font-weight: 600;
            color: #92400e;
            margin-right: 10px;
        }
        
        .profit-amount {
            font-size: 13pt;
            font-weight: bold;
            color: #d97706;
        }
        
        /* Notes modernes */
        .notes-modern {
            margin: 20px 0;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .notes-header-modern {
            background: #fef3c7;
            padding: 8px 15px;
            display: flex;
            align-items: center;
            gap: 6px;
            border-bottom: 1px solid #fde68a;
        }
        
        .notes-icon {
            font-size: 10pt;
        }
        
        .notes-title {
            font-weight: 600;
            font-size: 9pt;
            color: #92400e;
        }
        
        .notes-content-modern {
            padding: 12px 15px;
            font-size: 8.5pt;
            color: #78350f;
            line-height: 1.5;
        }
        
        /* Signatures modernes */
        .signatures-modern {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
            margin-bottom: 20px;
            gap: 30px;
        }
        
        .signature-modern {
            flex: 1;
            text-align: center;
        }
        
        .signature-line-modern {
            border-top: 1px solid #9ca3af;
            margin: 0 20px 10px 20px;
            padding-top: 8px;
        }
        
        .signature-text-modern {
            font-size: 8pt;
            font-weight: 500;
            color: #374151;
        }
        
        .signature-date-modern {
            font-size: 7pt;
            color: #9ca3af;
            margin-top: 4px;
        }
        
        /* Footer */
        .footer-modern {
            text-align: center;
            padding-top: 15px;
            margin-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        
        .footer-text {
            font-size: 7pt;
            color: #9ca3af;
        }
        
        .footer-thanks {
            font-size: 8pt;
            color: #6b7280;
            margin-top: 5px;
        }
        
        .empty-row {
            text-align: center;
            padding: 30px;
            color: #9ca3af;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
        }
    CSS;
    }

    /**
     * Formate une date
     */
    private function formatDate($date): string
    {
        if (empty($date)) {
            return '-';
        }
        return date('d/m/Y H:i', strtotime($date));
    }

    /**
     * Retourne la classe CSS pour la priorité
     */
    private function getPriorityClass(string $priority): string
    {
        $classes = [
            'high' => 'priority-high',
            'medium' => 'priority-medium',
            'low' => 'priority-low'
        ];
        return $classes[$priority] ?? 'priority-normal';
    }

    /**
     * Formate la priorité pour l'affichage
     */
    private function formatPriority(string $priority): string
    {
        $priorities = [
            'high' => 'Haute',
            'medium' => 'Moyenne',
            'low' => 'Basse'
        ];
        return $priorities[$priority] ?? ucfirst($priority);
    }

    /**
     * Formate le statut pour l'affichage
     */
    private function formatStatus(string $status): string
    {
        $statuses = [
            'draft' => 'Brouillon',
            'sent' => 'Envoyé',
            'confirmed' => 'Confirmé',
            'delivered' => 'Livré',
            'cancelled' => 'Annulé'
        ];
        return $statuses[$status] ?? ucfirst($status);
    }

    /**
     * Valide les données de la commande
     * 
     * @param array $order Données de la commande
     * @throws InvalidArgumentException Si les données sont invalides
     */
    private function validateOrderData(array $order): void
    {
        if (empty($order['order_number'])) {
            throw new InvalidArgumentException('Le numéro de commande est requis');
        }

        if (empty($order['items']) || !is_array($order['items'])) {
            throw new InvalidArgumentException('La commande doit contenir au moins un article');
        }
    }

    /**
     * Récupère les settings de l'entreprise avec cache
     * 
     * @return array Settings de l'entreprise
     */
    private function getCompanySettings(): array
    {
        // Utilisation d'un cache statique pour éviter les requêtes multiples
        static $settings = null;

        if ($settings === null) {
            $settingsResult = $this->db->table('settings')
                ->select('setting_key, setting_value')
                ->get()
                ->getResultArray();

            $settings = [];
            foreach ($settingsResult as $setting) {
                $settings[$setting['setting_key']] = $setting['setting_value'];
            }
        }

        return $settings;
    }

    /**
     * Extrait les données de l'entreprise avec valeurs par défaut
     * 
     * @param array $settings Settings de l'entreprise
     * @return array Données formatées de l'entreprise
     */
    private function extractCompanyData(array $settings): array
    {
        $defaults = [
            'company_name' => 'MUHIZI BLESSED COMPANY',
            'company_nif' => '4002141416',
            'company_rc' => '0041847/23',
            'company_center' => 'DPMC',
            'company_activity' => 'COMMERCE GENERAL',
            'company_legal_form' => 'SU',
            'company_address' => 'ROHERO',
            'company_commune' => 'MUKAZA',
            'company_phone' => '69377364',
            'company_email' => 'contact@muhizi.com'
        ];

        return [
            'name' => $this->getSettingValue($settings, 'company_name', $defaults['company_name']),
            'nif' => $this->getSettingValue($settings, 'company_nif', $defaults['company_nif']),
            'rc' => $this->getSettingValue($settings, 'company_rc', $defaults['company_rc']),
            'center' => $this->getSettingValue($settings, 'company_center', $defaults['company_center']),
            'activity' => $this->getSettingValue($settings, 'company_activity', $defaults['company_activity']),
            'legal_form' => $this->getSettingValue($settings, 'company_legal_form', $defaults['company_legal_form']),
            'address' => $this->getSettingValue($settings, 'company_address', $defaults['company_address']),
            'commune' => $this->getSettingValue($settings, 'company_commune', $defaults['company_commune']),
            'phone' => $this->getSettingValue($settings, 'company_phone', $defaults['company_phone']),
            'email' => $this->getSettingValue($settings, 'company_email', $defaults['company_email'])
        ];
    }

    /**
     * Extrait les données du fournisseur
     * 
     * @param array $order Données de la commande
     * @return array Données formatées du fournisseur
     */
    private function extractSupplierData(array $order): array
    {
        return [
            'name' => $this->sanitizeValue($order['supplier_name'] ?? '-'),
            'phone' => $this->sanitizeValue($order['supplier_phone'] ?? '-'),
            'email' => $this->sanitizeValue($order['supplier_email'] ?? '-')
        ];
    }

    /**
     * Calcule les données financières de la commande
     * 
     * @param array $order Données de la commande
     * @return array Données financières calculées
     */
    private function calculateFinancialData(array $order): array
    {
        $items = $order['items'] ?? [];
        $totalQuantity = 0;
        foreach ($items as $item) {
            $totalQuantity += (float)($item['quantity'] ?? 0);
        }

        return [
            'subtotal_aed' => (float)($order['subtotal_aed'] ?? $order['subtotal'] ?? 0),
            'subtotal_bif' => (float)($order['subtotal_bif'] ?? 0),
            'total_aed' => (float)($order['total_amount_aed'] ?? $order['total_amount'] ?? 0),
            'total_bif' => (float)($order['total_amount_bif'] ?? 0),
            'total_profit' => (float)($order['total_expected_profit'] ?? 0),
            'rate_aed_to_usd' => (float)($order['exchange_rate_aed_to_usd'] ?? 3.6725),
            'rate_usd_to_bif' => (float)($order['exchange_rate_usd_to_bif'] ?? 2830),
            'total_quantity' => $totalQuantity,
            'items_count' => count($items)
        ];
    }

    /**
     * Nettoie une valeur pour l'affichage HTML
     * 
     * @param mixed $value Valeur à nettoyer
     * @return string Valeur nettoyée
     */
    private function sanitizeValue($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Formate un nombre pour l'affichage
     * 
     * @param mixed $value Valeur à formater
     * @param int $decimals Nombre de décimales
     * @return string Nombre formaté
     */
    private function formatNumber($value, int $decimals = 0): string
    {
        $value = (float)$value;
        return number_format($value, $decimals, ',', ' ');
    }

    /**
     * Récupère la date et l'heure actuelles formatées
     * 
     * @return string Date et heure formatées
     */
    private function getCurrentDateTime(): string
    {
        return date('d/m/Y H:i:s');
    }

    /**
     * Génère le PDF à partir du HTML
     * 
     * @param string $html Contenu HTML
     * @param string $orderNumber Numéro de commande
     * @return string Contenu du PDF
     * @throws RuntimeException Si la génération échoue
     */
    private function generatePDF(string $html, string $orderNumber): string
    {
        try {
            $options = new Options();
            $options->set('defaultFont', 'Helvetica');
            $options->set('isRemoteEnabled', false);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isPhpEnabled', false);
            $options->set('chroot', realpath(__DIR__ . '/../../'));

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            // Vérification du rendu
            $output = $dompdf->output();
            if (empty($output)) {
                throw new RuntimeException('Le PDF généré est vide');
            }

            return $output;
        } catch (Exception $e) {
            // Log l'erreur (si un système de log est disponible)
            if (function_exists('log_message')) {
                log_message('error', "Erreur génération PDF commande {$orderNumber}: " . $e->getMessage());
            }
            throw new RuntimeException("Impossible de générer le PDF: " . $e->getMessage());
        }
    }

    /**
     * Récupère une valeur de setting avec fallback
     * 
     * @param array $settings Tableau des settings
     * @param string $key Clé à récupérer
     * @param mixed $default Valeur par défaut
     * @return mixed Valeur du setting ou défaut
     */
    private function getSettingValue(array $settings, string $key, $default = null)
    {
        return $settings[$key] ?? $default;
    }
}
