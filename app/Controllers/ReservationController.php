<?php
// app/Controllers/ReservationController.php

namespace App\Controllers;

use App\Models\ReservationModel;
use App\Models\CustomerModel;
use App\Models\ProductModel;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;
use App\Models\InvoiceModel;
use App\Models\InvoiceItemModel;
use App\Models\StockMovementModel;
use App\Models\SettingsModel;
use App\Services\ReservationService;

class ReservationController extends ResourceController
{
    use ResponseTrait;

    protected $reservationModel;
    protected $customerModel;
    protected $productModel;
    protected $invoiceItemModel;
    protected $db;
    protected $invoiceModel;
    protected $stockMovementModel;
    protected $settingsModel;
    protected $reservationService;

    public function __construct()
    {
        $this->reservationModel = new ReservationModel();
        $this->customerModel = new CustomerModel();
        $this->productModel = new ProductModel();
        $this->invoiceModel = new InvoiceModel();
        $this->invoiceItemModel = new InvoiceItemModel();
        $this->db = \Config\Database::connect();
        $this->stockMovementModel = new StockMovementModel();
        $this->settingsModel = new SettingsModel();
        $this->reservationService = new ReservationService();
    }

    private function formatDateTime($value)
    {
        if (empty($value)) {
            return null;
        }

        $timestamp = strtotime(str_replace('T', ' ', $value));
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function getPostValue(string $key, $default = null)
    {
        $value = $this->request->getPost($key);
        if ($value === false || $value === null) {
            $body = $this->request->getJSON(true);
            if (is_array($body) && array_key_exists($key, $body)) {
                return $body[$key];
            }
        }

        return $value !== null && $value !== false ? $value : $default;
    }

    /**
     * GET /api/reservations - Liste des réservations
     */
    public function index()
    {
        try {
            $filters = array_filter([
                'status' => $this->request->getVar('status'),
                'customer_id' => $this->request->getVar('customer_id'),
                'reservation_number' => $this->request->getVar('reservation_number'),
                'date_from' => $this->request->getVar('date_from'),
                'date_to' => $this->request->getVar('date_to'),
            ], fn($v) => $v !== null && $v !== '');

            $reservations = $this->reservationModel->getAllReservations($filters);

            foreach ($reservations as &$row) {
                $totals = $this->db->table('reservation_items')
                    ->selectSum('total_price', 'total_amount')
                    ->selectSum('quantity', 'total_quantity')
                    ->selectSum('delivered_quantity', 'total_delivered')
                    ->where('reservation_id', $row['id'])
                    ->get()
                    ->getRowArray();
                $row['total_amount'] = (float) ($totals['total_amount'] ?? 0);
                $row['total_quantity'] = (float) ($totals['total_quantity'] ?? 0);
                $row['total_delivered'] = (float) ($totals['total_delivered'] ?? 0);
                $row['items_count'] = $this->db->table('reservation_items')
                    ->where('reservation_id', $row['id'])
                    ->countAllResults();
            }

            return $this->respond([
                'success' => true,
                'data' => $reservations
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Reservation index error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/reservations/(:num) - Détail d'une réservation
     */
    public function show($id = null)
    {
        try {
            $reservation = $this->reservationModel->getReservationWithDetails($id);

            if (!$reservation) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Réservation non trouvée'
                ], 404);
            }

            return $this->respond([
                'success' => true,
                'data' => $reservation
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
            $contentType = $this->request->getHeaderLine('Content-Type');
            $isMultipart = strpos($contentType, 'multipart/form-data') !== false;

            $input = [];

            if ($isMultipart || !empty($_FILES)) {
                // Requête multipart/form-data (upload de fichiers)
                log_message('info', 'Traitement requête multipart pour réservation');

                $input['customer_id'] = $this->request->getPost('customer_id');
                $input['reservation_date'] = $this->request->getPost('reservation_date');
                $input['expected_delivery_date'] = $this->request->getPost('expected_delivery_date');
                $input['priority'] = $this->request->getPost('priority');
                $input['notes'] = $this->request->getPost('notes');

                $itemsJson = $this->request->getPost('items');
                if ($itemsJson !== null && $itemsJson !== '') {
                    if (is_string($itemsJson)) {
                        $input['items'] = json_decode($itemsJson, true);
                    } elseif (is_array($itemsJson)) {
                        $input['items'] = $itemsJson;
                    }

                    if (!isset($input['items']) || json_last_error() !== JSON_ERROR_NONE) {
                        return $this->respond([
                            'success' => false,
                            'message' => 'Format des produits invalide: ' . json_last_error_msg()
                        ], 400);
                    }
                }
            } else {
                // Requête JSON classique
                $input = $this->request->getJSON(true);
                if ($input === null) {
                    $rawInput = $this->request->getBody();
                    if (!empty($rawInput)) {
                        $input = json_decode($rawInput, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            return $this->respond([
                                'success' => false,
                                'message' => 'Format JSON invalide: ' . json_last_error_msg()
                            ], 400);
                        }
                    }
                }
            }

            // ========== VALIDATION DES DATES ==========
            if (!empty($input['reservation_date'])) {
                $formattedReservationDate = $this->formatDateTime($input['reservation_date']);
                if ($formattedReservationDate === null) {
                    return $this->respond([
                        'success' => false,
                        'message' => 'Format de la date de réservation invalide'
                    ], 400);
                }
                $input['reservation_date'] = $formattedReservationDate;
            } else {
                $input['reservation_date'] = date('Y-m-d H:i:s');
            }

            if (!empty($input['expected_delivery_date'])) {
                $formattedExpectedDate = $this->formatDateTime($input['expected_delivery_date']);
                if ($formattedExpectedDate === null) {
                    return $this->respond([
                        'success' => false,
                        'message' => 'Format de la date de livraison attendue invalide'
                    ], 400);
                }
                $input['expected_delivery_date'] = $formattedExpectedDate;
            }

            // ========== VALIDATION DES DONNÉES ==========
            $validationErrors = [];

            if (empty($input['customer_id'])) {
                $validationErrors[] = 'Le client est requis';
            }

            if (empty($input['items']) || !is_array($input['items']) || count($input['items']) === 0) {
                $validationErrors[] = 'Au moins un produit est requis';
            }

            if (!empty($validationErrors)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validationErrors
                ], 400);
            }

            // ========== VÉRIFICATION DU CLIENT ==========
            $customer = $this->customerModel->find($input['customer_id']);
            if (!$customer) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Client non trouvé'
                ], 404);
            }

            // ========== CALCUL DES MONTANTS ET PRÉPARATION DES ITEMS ==========
            $totalAmount = 0;
            $itemsToInsert = [];
            $productErrors = [];

            foreach ($input['items'] as $index => $item) {
                if (empty($item['product_id'])) {
                    $productErrors[] = "L'article " . ($index + 1) . " n'a pas d'ID produit";
                    continue;
                }

                $product = $this->productModel->find($item['product_id']);
                if (!$product) {
                    $productErrors[] = "Produit ID {$item['product_id']} non trouvé";
                    continue;
                }

                $quantity = (float)($item['quantity'] ?? 1);
                if ($quantity <= 0) {
                    $productErrors[] = "La quantité pour le produit {$product['name']} doit être supérieure à zéro";
                    continue;
                }

                $unitPrice = (float)($item['unit_price'] ?? $product['selling_price'] ?? 0);
                $discount = (float)($item['discount_percent'] ?? 0);
                $taxRate = (float)($item['tax_rate'] ?? $product['tax_rate'] ?? 0);

                $lineSubtotal = $quantity * $unitPrice;
                $afterDiscount = $lineSubtotal * (1 - $discount / 100);
                $totalPrice = $afterDiscount * (1 + $taxRate / 100);
                $totalAmount += $totalPrice;

                $itemsToInsert[] = [
                    'product_id' => $item['product_id'],
                    'quantity' => $quantity,
                    'reserved_quantity' => $quantity,
                    'delivered_quantity' => 0,
                    'released_quantity' => 0,
                    'unit_price' => round($unitPrice, 2),
                    'discount_percent' => $discount,
                    'tax_rate' => $taxRate,
                    'total_price' => round($totalPrice, 2),
                    'notes' => $item['notes'] ?? null
                ];
            }

            if (!empty($productErrors)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Erreurs dans les produits',
                    'errors' => $productErrors
                ], 400);
            }

            if (empty($itemsToInsert)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Aucun produit valide à réserver'
                ], 400);
            }

            // ========== GÉNÉRATION DU NUMÉRO DE RÉSERVATION ==========
            $reservationNumber = $this->reservationModel->generateReservationNumber();

            // ========== PRÉPARATION DES DONNÉES DE LA RÉSERVATION ==========
            $reservationData = [
                'reservation_number' => $reservationNumber,
                'customer_id' => $input['customer_id'],
                'reservation_date' => $input['reservation_date'],
                'expected_delivery_date' => $input['expected_delivery_date'] ?? null,
                'total_amount' => round($totalAmount, 2),
                'status' => 'pending',
                'priority' => $input['priority'] ?? 'normal',
                'notes' => $input['notes'] ?? null,
                'created_by' => $this->request->user_id ?? session()->get('user_id')
            ];

            // ========== DÉBUT DE LA TRANSACTION ==========
            $this->db->transStart();

            // Insertion de la réservation
            if (!$this->reservationModel->insert($reservationData)) {
                $errors = $this->reservationModel->errors();
                $errorMessage = !empty($errors) ? implode(', ', $errors) : 'Erreur inconnue';

                $this->db->transRollback();
                return $this->respond([
                    'success' => false,
                    'message' => 'Erreur lors de la création de la réservation ' . $errorMessage,
                    'details' => $errorMessage
                ], 500);
            }

            $reservationId = $this->reservationModel->insertID();

            // Insertion des items
            $itemModel = $this->db->table('reservation_items');
            foreach ($itemsToInsert as $item) {
                $item['reservation_id'] = $reservationId;
                if (!$itemModel->insert($item)) {
                    $dbError = $this->db->error();
                    $this->db->transRollback();
                    return $this->respond([
                        'success' => false,
                        'message' => 'Erreur lors de l\'ajout d\'un produit',
                        'details' => $dbError['message'] ?? 'Erreur inconnue'
                    ], 500);
                }
            }

            // ========== TRAITEMENT DES PIÈCES JOINTES ==========
            $attachments = [];
            if ($isMultipart || !empty($_FILES)) {
                $files = $this->request->getFiles();
                $attachmentFiles = [];

                if (!empty($files) && isset($files['attachments'])) {
                    $attachmentFiles = $files['attachments'];
                } elseif (!empty($files) && isset($files['attachments[]'])) {
                    $attachmentFiles = $files['attachments[]'];
                }

                if (!empty($attachmentFiles)) {
                    $uploadPath = WRITEPATH . 'uploads/reservations/';
                    if (!is_dir($uploadPath)) {
                        mkdir($uploadPath, 0777, true);
                    }

                    if (!is_array($attachmentFiles)) {
                        $attachmentFiles = [$attachmentFiles];
                    }

                    $attachmentModel = $this->db->table('reservation_attachments');
                    foreach ($attachmentFiles as $file) {
                        if (!is_object($file) || !$file->isValid() || $file->getError() !== UPLOAD_ERR_OK) {
                            continue;
                        }

                        // Limiter la taille du fichier (5MB)
                        if ($file->getSize() > 5 * 1024 * 1024) {
                            log_message('warning', 'Fichier trop volumineux ignoré: ' . $file->getClientName());
                            continue;
                        }

                        $newName = $file->getRandomName();
                        if ($file->move($uploadPath, $newName)) {
                            $attachmentData = [
                                'reservation_id' => $reservationId,
                                'filename' => $newName,
                                'original_name' => $file->getClientName(),
                                'file_path' => 'uploads/reservations/' . $newName,
                                'file_size' => $file->getSize(),
                                'mime_type' => $file->getClientMimeType(),
                                'uploaded_by' => $this->request->user_id ?? session()->get('user_id') ?? 1
                            ];

                            $attachmentModel->insert($attachmentData);
                            $attachments[] = $attachmentData;
                        } else {
                            log_message('error', 'Erreur upload fichier: ' . $file->getErrorString());
                        }
                    }
                }
            }

            // ========== AJOUT DE L'HISTORIQUE ==========
            $this->db->table('reservation_status_history')->insert([
                'reservation_id' => $reservationId,
                'old_status' => null,
                'new_status' => 'pending',
                'changed_by' => $this->request->user_id ?? session()->get('user_id') ?? 1,
                'changed_at' => date('Y-m-d H:i:s')
            ]);

            // ========== VALIDATION DE LA TRANSACTION ==========
            if ($this->db->transStatus() === false) {
                $this->db->transRollback();

                $dbError = $this->db->error();
                $errorCode = $dbError['code'] ?? 0;
                $errorMessage = $dbError['message'] ?? 'Erreur de base de données inconnue';

                // Messages personnalisés selon le code d'erreur MySQL
                $userMessage = $this->getDatabaseErrorMessage($errorCode, $errorMessage);

                log_message('error', '=== TRANSACTION RÉSERVATION ÉCHOUÉE ===');
                log_message('error', 'Code erreur: ' . $errorCode);
                log_message('error', 'Message: ' . $errorMessage);
                log_message('error', 'Réservation data: ' . json_encode($reservationData));

                return $this->respond([
                    'success' => false,
                    'message' => $userMessage,
                    'code' => $errorCode,
                    'debug' => (ENVIRONMENT === 'development') ? [
                        'sql_error' => $errorMessage,
                        'reservation_data' => $reservationData
                    ] : null
                ], 500);
            }

            $this->db->transComplete();

            // ========== RÉCUPÉRATION DE LA RÉSERVATION CRÉÉE ==========
            $reservation = $this->reservationModel->getReservationWithDetails($reservationId);

            if (!$reservation) {
                $reservation = [
                    'id' => $reservationId,
                    'reservation_number' => $reservationNumber,
                    'customer' => $customer,
                    'items' => $itemsToInsert,
                    'attachments' => $attachments
                ];
            }

            return $this->respond([
                'success' => true,
                'message' => 'Réservation créée avec succès',
                'data' => $reservation
            ], 201);
        } catch (\Exception $e) {
            // En cas d'exception, annuler la transaction si elle est en cours
            if ($this->db->transStatus() !== false) {
                $this->db->transRollback();
            }

            log_message('error', 'Reservation create error: ' . $e->getMessage());
            log_message('error', 'Reservation create trace: ' . $e->getTraceAsString());

            return $this->respond([
                'success' => false,
                'message' => 'Une erreur inattendue est survenue',
                'debug' => (ENVIRONMENT === 'development') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Récupère un message d'erreur personnalisé selon le code MySQL
     */
    private function getDatabaseErrorMessage($errorCode, $originalMessage)
    {
        $errorMessages = [
            1062 => 'Un enregistrement en double a été détecté. Vérifiez le numéro de réservation.',
            1452 => 'Violation de clé étrangère. Vérifiez que le client ou le produit existe.',
            1264 => 'Valeur hors limites. Vérifiez les montants des produits.',
            1048 => 'Un champ requis est vide. Vérifiez toutes les données.',
            1054 => 'Colonne inconnue dans la base de données.',
            1146 => 'Table introuvable dans la base de données.',
            1216 => 'Violation de contrainte de clé étrangère.',
            1217 => 'Impossible de supprimer ou mettre à jour une ligne parente.'
        ];

        return $errorMessages[$errorCode] ?? 'Erreur lors de la création de la réservation: ' . $originalMessage;
    }



    /**
     * PUT /api/reservations/(:num) - Mettre à jour une réservation
     */
    public function update($id = null)
    {
        try {
            $reservation = $this->reservationModel->find($id);

            if (!$reservation) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Réservation non trouvée'
                ], 404);
            }

            if (!in_array($reservation['status'], ['pending', 'confirmed'], true)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Réservation non modifiable dans cet état'
                ], 400);
            }

            $input = $this->request->getJSON(true) ?? [];

            $updateData = array_filter([
                'customer_id' => $input['customer_id'] ?? null,
                'reservation_date' => $input['reservation_date'] ?? null,
                'priority' => $input['priority'] ?? null,
                'expected_delivery_date' => $input['expected_delivery_date'] ?? null,
                'notes' => array_key_exists('notes', $input) ? $input['notes'] : null,
                'status' => $input['status'] ?? null,
            ], fn($v) => $v !== null);

            $this->db->transStart();

            if ($updateData) {
                $this->reservationModel->update($id, $updateData);
            }

            if (!empty($input['items']) && is_array($input['items'])) {
                $this->db->table('reservation_items')->where('reservation_id', $id)->delete();
                foreach ($input['items'] as $item) {
                    $product = $this->productModel->find($item['product_id']);
                    if (!$product) {
                        continue;
                    }
                    $qty = (float) ($item['quantity'] ?? 1);
                    $unitPrice = (float) ($item['unit_price'] ?? $product['selling_price'] ?? 0);
                    $disc = (float) ($item['discount_percent'] ?? 0);
                    $tax = (float) ($item['tax_rate'] ?? 18);
                    $lineSub = $qty * $unitPrice;
                    $afterDisc = $lineSub * (1 - $disc / 100);
                    $totalPrice = $afterDisc * (1 + $tax / 100);

                    $delivered_val = (float) ($item['delivered_quantity'] ?? 0);
                    $released_val = max(0, $qty - $delivered_val);

                    $this->db->table('reservation_items')->insert([
                        'reservation_id' => $id,
                        'product_id' => $item['product_id'],
                        'quantity' => $qty,
                        'reserved_quantity' => $qty,
                        'delivered_quantity' => $delivered_val,
                        'released_quantity' => $released_val,
                        'unit_price' => $unitPrice,
                        'discount_percent' => $disc,
                        'tax_rate' => $tax,
                        'total_price' => round($totalPrice, 2),
                        'notes' => $item['notes'] ?? null,
                    ]);
                }
            }

            $this->db->transComplete();

            return $this->respond([
                'success' => true,
                'message' => 'Réservation mise à jour avec succès',
                'data' => $this->reservationModel->getReservationWithDetails($id),
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/reservations/{id}/confirm
     */
    public function confirm($id = null)
    {
        // Récupérer la réservation avec les détails (items)
        $reservation = $this->reservationModel->getReservationWithDetails($id);
        if (!$reservation) {
            return $this->respond(['success' => false, 'message' => 'Réservation non trouvée'], 404);
        }

        if ($reservation['status'] !== 'pending') {
            return $this->respond(['success' => false, 'message' => 'Seules les réservations en attente peuvent être confirmées'], 400);
        }

        // Vérifier la disponibilité pour chaque article avant de confirmer
        foreach ($reservation['items'] ?? [] as $it) {
            $needed = (float) $it['quantity'] - (float) $it['delivered_quantity'];
            if ($needed <= 0) continue;

            // Utiliser le service pour vérifier la disponibilité (en excluant cette réservation)
            $availability = $this->reservationService->checkStockAvailability(
                $it['product_id'],
                $needed,
                $id  // Exclure cette réservation du calcul
            );

            if (!$availability['available']) {
                return $this->respond([
                    'success' => false,
                    'message' => "Stock insuffisant pour confirmer la réservation pour le produit {$availability['product_name']}. Disponible: {$availability['available_quantity']}, Nécessaire: {$needed}"
                ], 400);
            }
        }

        // Tous les articles sont disponibles -> passer en 'confirmed'
        $this->reservationModel->update($id, ['status' => 'confirmed']);
        $this->db->table('reservation_status_history')->insert([
            'reservation_id' => $id,
            'old_status' => 'pending',
            'new_status' => 'confirmed',
            'changed_by' => $this->request->user_id ?? session()->get('user_id'),
            'changed_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->respond([
            'success' => true,
            'message' => 'Réservation confirmée',
            'data' => $this->reservationModel->getReservationWithDetails($id),
        ]);
    }

    /**
     * DELETE /api/reservations/(:num) - Supprimer une réservation
     */
    public function delete($id = null)
    {
        try {
            $reservation = $this->reservationModel->find($id);

            if (!$reservation) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Réservation non trouvée'
                ], 404);
            }

            if ($reservation['status'] !== 'pending') {
                return $this->respond([
                    'success' => false,
                    'message' => 'Seules les réservations en attente peuvent être supprimées'
                ], 400);
            }

            $this->db->table('reservation_items')->where('reservation_id', $id)->delete();
            $this->db->table('reservation_attachments')->where('reservation_id', $id)->delete();
            $this->db->table('reservation_status_history')->where('reservation_id', $id)->delete();
            $this->reservationModel->delete($id);

            return $this->respond([
                'success' => true,
                'message' => 'Réservation supprimée avec succès'
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/reservations/(:num)/deliver - Livrer une réservation
     */
    public function deliver($id = null)
    {
        try {
            $reservation = $this->reservationModel->getReservationWithDetails($id);

            if (!$reservation) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Réservation non trouvée'
                ], 404);
            }

            $input = $this->request->getJSON(true);

            $deliverItems = $input['items'] ?? [];
            if (empty($deliverItems)) {
                $deliverItems = [];
                foreach ($reservation['items'] ?? [] as $it) {
                    $remaining = (float) $it['quantity'] - (float) $it['delivered_quantity'];
                    if ($remaining > 0) {
                        $deliverItems[] = [
                            'item_id' => $it['id'],
                            'quantity' => $remaining,
                        ];
                    }
                }
            }

            if (empty($deliverItems)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Aucun article à livrer'
                ], 400);
            }

            $this->db->transStart();

            $allDelivered = true;
            $anyDelivered = false;
            $deliveredItemsForInvoice = [];

            foreach ($deliverItems as $item) {
                $orderItem = $this->db->table('reservation_items')
                    ->where('id', $item['item_id'])
                    ->get()
                    ->getRow();

                if (!$orderItem) {
                    continue;
                }

                $newDelivered = $orderItem->delivered_quantity + $item['quantity'];

                if ($newDelivered > $orderItem->quantity) {
                    $this->db->transRollback();
                    return $this->respond([
                        'success' => false,
                        'message' => 'La quantité livrée ne peut pas dépasser la quantité réservée'
                    ], 400);
                }

                $this->db->table('reservation_items')
                    ->where('id', $item['item_id'])
                    ->update([
                        'delivered_quantity' => $newDelivered
                    ]);

                if ($newDelivered < $orderItem->quantity) {
                    $allDelivered = false;
                }
                if ($newDelivered > 0) {
                    $anyDelivered = true;
                }

                // Mettre à jour le stock du produit
                $product = $this->productModel->find($orderItem->product_id);
                if ($product) {
                    $newStock = $product['current_stock'] - $item['quantity'];
                    $this->productModel->update($orderItem->product_id, [
                        'current_stock' => max(0, $newStock)
                    ]);
                }

                // Collecter les articles livrés pour la facture
                $deliveredItemsForInvoice[] = [
                    'product_id' => $orderItem->product_id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $orderItem->unit_price,
                    'discount_percent' => $orderItem->discount_percent,
                    'tax_rate' => $orderItem->tax_rate,
                    'item_designation' => $product['name'] ?? 'Article',
                    'item_code' => $product['code'] ?? null
                ];
            }

            // Mettre à jour le statut
            $newStatus = 'pending';
            if ($allDelivered) {
                $newStatus = 'completed';
            } elseif ($anyDelivered) {
                $newStatus = 'partially_delivered';
            }

            $this->reservationModel->update($id, ['status' => $newStatus]);

            // Ajouter l'historique
            $this->db->table('reservation_status_history')->insert([
                'reservation_id' => $id,
                'old_status' => $reservation['status'],
                'new_status' => $newStatus,
                'changed_by' => session()->get('user_id'),
                'changed_at' => date('Y-m-d H:i:s')
            ]);

            // CRÉER LA FACTURE DE LIVRAISON (type FN)
            $invoiceData = null;
            if ($anyDelivered) {
                $invoiceData = $this->createInvoiceFromDelivery(
                    $reservation,
                    $deliveredItemsForInvoice,
                    $id
                );
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Erreur lors de la livraison'
                ], 500);
            }

            $responseData = [
                'status' => $newStatus
            ];

            // Ajouter les informations de facture à la réponse
            if ($invoiceData) {
                $responseData['invoice'] = $invoiceData;
            }

            return $this->respond([
                'success' => true,
                'message' => 'Livraison enregistrée avec succès',
                'data' => $responseData
            ]);
        } catch (\Exception $e) {
            $this->db->transRollback();
            log_message('error', 'Deliver error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/reservations/(:num)/complete-by-delivered - Finaliser une réservation selon la quantité déjà livrée
     */
    public function completeByDelivered($id = null)
    {
        try {
            $reservation = $this->reservationModel->getReservationWithDetails($id);

            if (!$reservation) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Réservation non trouvée'
                ], 404);
            }

            if (in_array($reservation['status'], ['completed', 'cancelled'], true)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'La réservation ne peut pas être finalisée dans cet état'
                ], 400);
            }

            $totalDelivered = 0;
            $totalQuantity = 0;
            foreach ($reservation['items'] ?? [] as $item) {
                $totalDelivered += (float) $item['delivered_quantity'];
                $totalQuantity += (float) $item['reserved_quantity'];
            }

            if ($totalDelivered <= 0) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Aucun article livré pour finaliser la réservation'
                ], 400);
            }

            // Start transaction to update items and reservation atomically
            $this->db->transStart();

            $releasedTotal = 0;
            $releasedAmountTotal = 0.0; // monetary value of released quantities
            $totalamountnew = 0.0; // new total amount after adjusting for delivered quantities
            $perItem = [];

            foreach ($reservation['items'] ?? [] as $item) {
                $row = $this->db->table('reservation_items')->where('id', $item['id'])->get()->getRowArray();
                if (!$row) continue;

                $prevReserved = (float) ($row['reserved_quantity'] ?? $item['reserved_quantity'] ?? $item['quantity']);
                $delivered = (float) ($row['delivered_quantity'] ?? $item['delivered_quantity'] ?? 0);

                $newReserved = $delivered;
                $released = $prevReserved - $delivered;

                // Determine unit price: prefer explicit unit_price, otherwise derive from total_price/quantity
                $unitPrice = 0.00;
                if (isset($row['unit_price']) && $row['unit_price'] !== null && $row['unit_price'] !== '') {
                    $unitPrice = (float) $row['unit_price'];
                } elseif (isset($row['total_price']) && (float)$row['quantity'] != 0) {
                    $unitPrice = (float) $row['total_price'] / max(1, (float) $row['quantity']);
                }

                $discount = (float)($row['discount_percent'] ?? 0);
                $taxRate = (float)($row['tax_rate'] ?? 0);

                $lineSubtotal = $delivered * $unitPrice;
                $afterDiscount = $lineSubtotal * (1 - $discount / 100);
                $totalPrice = $afterDiscount * (1 + $taxRate / 100);

                $releasedValue = $released * $unitPrice;

                $this->db->table('reservation_items')
                    ->where('id', $item['id'])
                    ->update([
                        'quantity' => $delivered,
                        'reserved_quantity' => $newReserved,
                        'released_quantity' => $released,
                        'total_price' => round($totalPrice, 2)
                    ]);

                $releasedTotal += $released;
                $releasedAmountTotal += $releasedValue;
                $totalamountnew += $totalPrice;
                $perItem[] = [
                    'item_id' => $item['id'],
                    'product_id' => $item['product_id'],
                    'quantity' => (float) $item['quantity'],
                    'delivered_quantity' => $delivered,
                    'previous_reserved' => $prevReserved,
                    'new_reserved' => $newReserved,
                    'released_quantity' => $released,
                    'released_value' => $releasedValue
                ];
            }

            $newStatus = 'completed';

            // Update reservation total amount by subtracting the monetary value of released quantities
            $oldTotal = (float) ($reservation['total_amount'] ?? 0);
            $newTotalAmount = $oldTotal - $releasedAmountTotal;

            $this->reservationModel->update($id, [
                'status' => $newStatus,
                'total_amount' =>  $totalamountnew
            ]);

            $this->db->table('reservation_status_history')->insert([
                'reservation_id' => $id,
                'old_status' => $reservation['status'],
                'new_status' => $newStatus,
                'changed_by' => $this->request->user_id ?? session()->get('user_id'),
                'changed_at' => date('Y-m-d H:i:s')
            ]);

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Erreur lors de la finalisation de la réservation'
                ], 500);
            }

            return $this->respond([
                'success' => true,
                'message' => 'La réservation a été finalisée selon la quantité déjà livrée',
                'data' => [
                    'status' => $newStatus,
                    'delivered_quantity' => $totalDelivered,
                    'reserved_quantity_released' => $releasedTotal,
                    'released_amount_reduced' => $releasedAmountTotal,
                    'total_amount_before' => $oldTotal,
                    'total_amount_after' => $newTotalAmount,
                    'items' => $perItem
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'CompleteByDelivered error: ' . $e->getMessage());
            if ($this->db->transStatus() !== false) {
                $this->db->transRollback();
            }
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors de la finalisation de la réservation'
            ], 500);
        }
    }

    /**
     * Créer une facture FN à partir des articles livrés
     */
    private function createInvoiceFromDelivery($reservation, $deliveredItems, $reservationId)
    {
        try {
            // Récupérer les informations du client
            $customer = $this->customerModel->find($reservation['customer_id']);
            $customerName = $customer ? ($customer['last_name'] . ' ' . $customer['first_name']) : $reservation['customer']['display_name'] ?? 'Client';
            $customerTin = $customer ? ($customer['tin'] ?? $customer['tax_number'] ?? null) : null;
            $customerAddress = $customer ? ($customer['address'] ?? $customer['billing_address'] ?? null) : null;
            $customerEmail = $customer ? ($customer['email'] ?? null) : null;
            $customerPhone = $customer ? ($customer['phone'] ?? null) : null;

            // Générer le numéro de facture
            $invoiceNumber = $this->invoiceModel->generateInvoiceNumber();

            // Calculer les totaux
            $subtotal = 0;
            $discountTotal = 0;
            $vatAmount = 0;
            $ctAmount = 0;
            $tlAmount = 0;
            $itemsData = [];

            foreach ($deliveredItems as $item) {
                $product = $this->productModel->find($item['product_id']);
                $itemTotal = $item['quantity'] * $item['unit_price'];
                $subtotal += $itemTotal;

                $itemDiscountPercent = $item['discount_percent'] ?? 0;
                $itemDiscountAmount = $itemTotal * ($itemDiscountPercent / 100);
                $itemTotalAfterDiscount = $itemTotal - $itemDiscountAmount;

                $taxRate = $product['tax_rate'] ?? 0;
                $ctRate = $product['ct_tax_rate'] ?? 0;
                $tlRate = $product['tl_tax_rate'] ?? 0;

                $itemVat = $itemTotalAfterDiscount * ($taxRate / 100);
                $itemCt = $itemTotalAfterDiscount * ($ctRate / 100);
                $itemTl = $itemTotalAfterDiscount * ($tlRate / 100);

                $vatAmount += $itemVat;
                $ctAmount += $itemCt;
                $tlAmount += $itemTl;

                $discountTotal += $itemDiscountAmount;

                $itemsData[] = [
                    'product_id' => $item['product_id'],
                    'item_code' => $item['item_code'] ?? null,
                    'item_designation' => $item['item_designation'] ?? '',
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_percent' => $itemDiscountPercent,
                    'discount_amount' => $itemDiscountAmount,
                    'ct_amount' => $itemCt,
                    'tl_amount' => $itemTl,
                    'vat_amount' => $itemVat,
                    'total_amount' => $itemTotalAfterDiscount
                ];
            }

            $shippingCost = 0;
            $totalAmount = $subtotal - $discountTotal + $vatAmount + $ctAmount + $tlAmount + $shippingCost;

            // Date d'échéance (30 jours par défaut)
            $dueDate = date('Y-m-d', strtotime('+30 days', strtotime(date('Y-m-d'))));

            // Préparer les données de la facture
            $invoiceDataToCreate = [
                'invoice_number' => $invoiceNumber,
                'invoice_type' => 'FN', // Facture Normale
                'invoice_date' => date('Y-m-d H:i:s'),
                'due_date' => $dueDate,
                'invoice_currency' => 'BIF',
                'payment_type' => null,
                'notes' => 'Facture automatique - Réservation #' . $reservation['reservation_number'],
                'warehouse_id' => 1, // Par défaut
                'customer_id' => $reservation['customer_id'],
                'customer_name' => $customerName,
                'customer_TIN' => $customerTin,
                'customer_address' => $customerAddress,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'vat_customer_payer' => 0,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'vat_amount' => $vatAmount,
                'ct_amount' => $ctAmount,
                'tl_amount' => $tlAmount,
                'shipping_amount' => $shippingCost,
                'total_amount' => $totalAmount,
                'payment_status' => 'pending',
                'ebms_status' => 'PENDING',
                'created_by' => session()->get('user_id'),
                'reservation_reference' => $reservation['reservation_number']
            ];

            // Insérer la facture
            $invoiceId = $this->invoiceModel->insertWithItems($invoiceDataToCreate, $itemsData);

            if (!$invoiceId) {
                log_message('error', 'Erreur création facture pour réservation ' . $reservationId);
                return null;
            }

            return [
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'total_amount' => $totalAmount,
                'customer_name' => $customerName
            ];
        } catch (\Exception $e) {
            log_message('error', 'Erreur création facture: ' . $e->getMessage());
            return null;
        }
    }
}
