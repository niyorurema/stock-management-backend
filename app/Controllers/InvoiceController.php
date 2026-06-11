<?php
// app/Controllers/InvoiceController.php

namespace App\Controllers;

use App\Models\InvoiceModel;
use App\Models\InvoiceItemModel;
use App\Models\InvoicePaymentModel;
use App\Models\ProductModel;
use App\Models\CustomerModel;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;
use App\Models\StockMovementModel;
use App\Libraries\EBMSClient;
use App\Models\SettingsModel;
use App\Services\ReservationService;

class InvoiceController extends ResourceController
{
    use ResponseTrait;

    protected $invoiceModel;
    protected $invoiceItemModel;
    protected $invoicePaymentModel;
    protected $productModel;
    protected $customerModel;
    protected $db;
    protected $stockMovementModel;
    protected $settingsModel;
    protected $reservationService;

    public function __construct()
    {
        $this->invoiceModel = new InvoiceModel();
        $this->invoiceItemModel = new InvoiceItemModel();
        $this->invoicePaymentModel = new InvoicePaymentModel();
        $this->productModel = new ProductModel();
        $this->customerModel = new CustomerModel();
        $this->db = \Config\Database::connect();
        $this->stockMovementModel = new StockMovementModel();
        $this->settingsModel = new SettingsModel();
        $this->reservationService = new ReservationService();
    }

    /**
     * GET /api/invoices - Liste des factures
     */
    public function index()
    {
        $this->updateOverdueStatus();
        $filters = [
            'invoice_number' => $this->request->getVar('invoice_number'),
            'customer_name' => $this->request->getVar('customer_name'),
            'customer_TIN' => $this->request->getVar('customer_TIN'),
            'invoice_type' => $this->request->getVar('invoice_type'),
            'payment_status' => $this->request->getVar('payment_status'),
            'ebms_status' => $this->request->getVar('ebms_status'),
            'date_from' => $this->request->getVar('date_from'),
            'date_to' => $this->request->getVar('date_to'),
            'min_amount' => $this->request->getVar('min_amount'),
            'max_amount' => $this->request->getVar('max_amount')
        ];

        // Filtrer les valeurs vides
        $filters = array_filter($filters, function ($value) {
            return !empty($value) && $value !== '';
        });

        $page = (int)($this->request->getVar('page') ?? 1);
        $limit = (int)($this->request->getVar('limit') ?? 10);
        $sort = $this->request->getVar('sort') ?? 'invoice_date';
        $order = $this->request->getVar('order') ?? 'desc';

        $result = $this->invoiceModel->getInvoices($filters, $page, $limit, $sort, $order);

        return $this->respond([
            'success' => true,
            'data' => $result['data'],
            'pagination' => $result['pagination'],
            'stats' => $this->getQuickStats($filters)
        ]);
    }


    private function updateOverdueStatus()
    {
        $db = \Config\Database::connect();
        return $db->table('invoices')
            ->set('payment_status', 'overdue')
            ->set('status', 'overdue')
            ->where('due_date <', date('Y-m-d'))
            ->where('payment_status !=', 'paid')
            ->where('status !=', 'cancelled')
            ->where('deleted_at IS NULL')
            ->update();
    }

    /**
     * GET /api/invoices/stats - Statistiques globales
     */
    public function getStats()
    {
        $dateFrom = $this->request->getVar('date_from') ?? date('Y-m-01');
        $dateTo = $this->request->getVar('date_to') ?? date('Y-m-t');

        $stats = $this->db->table('invoices')
            ->select("
                COUNT(*) as total_invoices,
                SUM(total_amount) as total_amount,
                SUM(CASE WHEN (payment_status = 'paid' || payment_status = 'partial' || payment_status = 'overdue') THEN paid_amount ELSE 0 END) as total_paid,
                SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN payment_status = 'partial' THEN 1 ELSE 0 END) as partial_count,
                SUM(CASE WHEN payment_status = 'overdue' THEN 1 ELSE 0 END) as overdue_count,
                SUM(CASE WHEN invoice_type = 'FN' THEN total_amount ELSE 0 END) as sales_amount,
                SUM(CASE WHEN invoice_type = 'FA' THEN total_amount ELSE 0 END) as credit_amount
            ")
            ->where('DATE(invoice_date) >=', $dateFrom)
            ->where('DATE(invoice_date) <=', $dateTo)
            ->get()
            ->getRowArray();

        return $this->respond([
            'success' => true,
            'data' => $stats,
            'period' => ['from' => $dateFrom, 'to' => $dateTo]
        ]);
    }

    /**
     * Statistiques rapides pour les filtres actuels
     */
    private function getQuickStats($filters)
    {
        $builder = $this->db->table('invoices');

        if (!empty($filters)) {
            $this->applyFilters($builder, $filters);
        }

        return $builder->select("
                COUNT(*) as total,
                SUM(total_amount) as amount,
                SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) as paid_amount,
                SUM(CASE WHEN payment_status IN ('pending', 'partial') THEN total_amount ELSE 0 END) as due_amount
            ")
            ->get()
            ->getRowArray();
    }

    /**
     * Appliquer les filtres à un builder
     */
    private function applyFilters($builder, $filters)
    {
        if (!empty($filters['invoice_number'])) {
            $builder->like('invoice_number', $filters['invoice_number']);
        }
        if (!empty($filters['customer_name'])) {
            $builder->like('customer_name', $filters['customer_name']);
        }
        if (!empty($filters['customer_TIN'])) {
            $builder->like('customer_TIN', $filters['customer_TIN']);
        }
        if (!empty($filters['invoice_type'])) {
            $builder->where('invoice_type', $filters['invoice_type']);
        }
        if (!empty($filters['payment_status'])) {
            $builder->where('payment_status', $filters['payment_status']);
        }
        if (!empty($filters['ebms_status'])) {
            $builder->where('ebms_status', $filters['ebms_status']);
        }
        if (!empty($filters['date_from'])) {
            $builder->where('DATE(invoice_date) >=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $builder->where('DATE(invoice_date) <=', $filters['date_to']);
        }
        if (!empty($filters['min_amount'])) {
            $builder->where('total_amount >=', $filters['min_amount']);
        }
        if (!empty($filters['max_amount'])) {
            $builder->where('total_amount <=', $filters['max_amount']);
        }
    }

    /**
     * GET /api/invoices/(:num) - Détail d'une facture
     */
    public function show($id = null)
    {
        $invoice = $this->invoiceModel->getInvoiceWithDetails($id);
        //$invoice = $this->invoiceModel->get($id);
        if (!$invoice) {
            return $this->respond(['success' => false, 'message' => 'Facture non trouvée'], 404);
        }

        return $this->respond([
            'success' => true,
            'data' => $invoice
        ]);
    }

    private function getCurrentStock($productId, $warehouseId)
    {
        $product = $this->productModel->find($productId);
        if (!$product) {
            return 0;
        }

        $currentStock = (float) ($product['current_stock'] ?? 0);
        return $this->reservationService->getAvailableStock($currentStock, $productId);
    }

    private function isValidTin($tin)
    {
        if (empty($tin)) {
            return false;
        }
        return preg_match('/^[0-9]{10}$/', trim($tin)) === 1;
    }

    public function create()
    {
        $input = $this->request->getJSON(true);

        $isMultipart = $this->request->getMethod() === 'post' && !empty($_FILES);

        if ($isMultipart) {
            $input = [
                'invoice_type' => $this->request->getPost('invoice_type'),
                'invoice_date' => $this->request->getPost('invoice_date'),
                'due_date' => $this->request->getPost('due_date'),
                'invoice_currency' => $this->request->getPost('invoice_currency'),
                'payment_type' => $this->request->getPost('payment_type'),
                'notes' => $this->request->getPost('notes'),
                'warehouse_id' => $this->request->getPost('warehouse_id'),
                'customer_id' => $this->request->getPost('customer_id'),
                'customer_name' => $this->request->getPost('customer_name'),
                'customer_TIN' => $this->request->getPost('customer_TIN'),
                'customer_address' => $this->request->getPost('customer_address'),
                'customer_email' => $this->request->getPost('customer_email'),
                'customer_phone' => $this->request->getPost('customer_phone'),
                'vat_customer_payer' => $this->request->getPost('vat_customer_payer'),
                'discount_percent' => $this->request->getPost('discount_percent'),
                'discount_amount' => $this->request->getPost('discount_amount'),
                'shipping_cost' => $this->request->getPost('shipping_cost'),
                'subtotal' => $this->request->getPost('subtotal'),
                'vat_amount' => $this->request->getPost('vat_amount'),
                'ct_amount' => $this->request->getPost('ct_amount'),
                'tl_amount' => $this->request->getPost('tl_amount'),
                'total_amount' => $this->request->getPost('total_amount'),
                'invoice_ref' => $this->request->getPost('invoice_ref'),
                'cn_motif' => $this->request->getPost('cn_motif'),
                'items' => json_decode($this->request->getPost('items'), true)
            ];
        }

        // Validation
        if (empty($input['customer_id']) && empty($input['customer_name'])) {
            return $this->respond(['success' => false, 'message' => 'Le client est requis'], 400);
        }

        // Validation spécifique à FA (Facture d'Avoir)
        if (($input['invoice_type'] ?? 'FN') === 'FA') {
            if (empty($input['cn_motif'])) {
                return $this->respond(['success' => false, 'message' => 'Le motif de l\'avoir est requis pour une facture d\'avoir'], 400);
            }
            $validMotifs = ['erreur', 'retour_marchandises', 'rabais', 'ristourne', 'remise', 'escompte'];
            if (!in_array($input['cn_motif'], $validMotifs)) {
                return $this->respond(['success' => false, 'message' => 'Motif d\'avoir invalide'], 400);
            }
        }

        // Validation des produits obligatoires seulement pour les factures qui affectent le stock (FN, RC)
        $invoiceType = $input['invoice_type'] ?? 'FN';
        $stockAffectingTypes = ['FN', 'RC', 'RHF'];
        if (in_array($invoiceType, $stockAffectingTypes) && (empty($input['items']) || !is_array($input['items']))) {
            return $this->respond(['success' => false, 'message' => 'Au moins un produit est requis pour ce type de facture'], 400);
        }

        // Vérifier le stock pour les factures qui affectent le stock
        if (in_array($invoiceType, $stockAffectingTypes) && !empty($input['items'])) {
            foreach ($input['items'] as $item) {
                $product = $this->productModel->find($item['product_id']);
                if (!$product) {
                    return $this->respond(['success' => false, 'message' => "Produit non trouvé"], 400);
                }

                $currentStock = $this->getCurrentStock($item['product_id'], $input['warehouse_id'] ?? 1);
                if ($currentStock < $item['quantity']) {
                    return $this->respond([
                        'success' => false,
                        'message' => "Stock insuffisant pour {$product['name']}. Disponible: {$currentStock}, Demandé: {$item['quantity']}"
                    ], 400);
                }
            }
        }

        // Récupérer les informations du client
        $customerName = $input['customer_name'] ?? '';
        $customerTin = $input['customer_TIN'] ?? null;
        $customerAddress = $input['customer_address'] ?? null;
        $customerEmail = $input['customer_email'] ?? null;
        $customerPhone = $input['customer_phone'] ?? null;

        if (!empty($input['customer_id'])) {
            $customer = $this->customerModel->find($input['customer_id']);
            if ($customer) {
                $customer['customer_name'] = ($customer['last_name'] ?? '') . ' ' . ($customer['first_name'] ?? '');
                $customerName = $customer['customer_name'] ?? $customer['display_name'] ?? $customerName;
                $customerTin = $customer['tin'] ?? $customer['tax_number'] ?? $customerTin;
                $customerAddress = $customer['address'] ?? $customer['billing_address'] ?? $customerAddress;
                $customerEmail = $customer['email'] ?? $customerEmail;
                $customerPhone = $customer['phone'] ?? $customerPhone;
            }
        }

        if (!empty($customerTin) && !$this->isValidTin($customerTin)) {
            return $this->respond(['success' => false, 'message' => 'NIF client invalide'], 400);
        }

        // Générer le numéro de facture
        $invoiceNumber = $this->invoiceModel->generateInvoiceNumber($input['invoice_type']);
        $invoiceIdentifier = $this->generateInvoiceIdentifier($customerTin, $invoiceNumber);

        // Calculer les totaux avec remises
        $subtotal = 0;
        $discountTotal = floatval($input['discount_amount'] ?? 0);
        $vatAmount = 0;
        $ctAmount = 0;
        $tlAmount = 0;
        $tsceAmount = 0;
        $ottAmount = 0;
        $itemsData = [];

        if (!empty($input['items']) && is_array($input['items'])) {
            foreach ($input['items'] as $item) {
                $product = $this->productModel->find($item['product_id']);
                $itemTotal = $item['quantity'] * $item['unit_price'];
                $subtotal += $itemTotal;

                $itemDiscountPercent = $item['discount_percent'] ?? 0;
                $itemDiscountAmount = $itemTotal * ($itemDiscountPercent / 100);
                $itemTotalAfterDiscount = $itemTotal - $itemDiscountAmount;

                $taxRate = $product['tax_rate'] ?? 0;
                $ctRate = $product['ct_tax_rate'] ?? 0;
                $tlRate = $product['tl_tax_rate'] ?? 0;
                $tsceRate = $product['tsce_tax'] ?? 0;
                $ottRate = $product['ott_tax'] ?? 0;

                // HTVA (prix hors TVA) = montant après remise
                $itemVat = $itemTotalAfterDiscount * ($taxRate / 100);
                $itemCt = $itemTotalAfterDiscount * ($ctRate / 100);
                $itemTl = $itemTotalAfterDiscount * ($tlRate / 100);
                $itemTsce = $itemTotalAfterDiscount * ($tsceRate / 100);
                $itemOtt = $itemTotalAfterDiscount * ($ottRate / 100);

                // TVAC (prix avec TVA)
                $itemPriceNvat = $itemTotalAfterDiscount; // HTVA
                $itemPriceWvat = $itemTotalAfterDiscount + $itemVat; // TVAC
                // TTC pour l'item = TVAC + OTT + TSCE (calculé client côté affichage si nécessaire)

                $vatAmount += $itemVat;
                $ctAmount += $itemCt;
                $tlAmount += $itemTl;
                $tsceAmount += $itemTsce;
                $ottAmount += $itemOtt;

                $discountTotal += $itemDiscountAmount;

                $itemsData[] = [
                    'product_id' => $item['product_id'],
                    'item_code' => $product['code'] ?? null,
                    'item_designation' => $product['name'] ?? $item['item_designation'] ?? '',
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'item_price_nvat' => $itemPriceNvat,
                    'item_price_wvat' => $itemPriceWvat,
                    'discount_percent' => $itemDiscountPercent,
                    'discount_amount' => $itemDiscountAmount,
                    'ct_amount' => $itemCt,
                    'tl_amount' => $itemTl,
                    'vat_amount' => $itemVat,
                    'tsce_tax' => $tsceRate,
                    'ott_tax' => $ottRate,
                    'total_amount' => $itemTotalAfterDiscount
                ];
            }
        }

        $shippingCost = floatval($input['shipping_cost'] ?? 0);

        // HTVA = montant après remises (somme des items après remises moins remises globales)
        $htva = $subtotal - $discountTotal;
        // TVAC = HTVA + TVA
        $tvac = $htva + $vatAmount;
        // TTC = TVAC + autres taxes (CT, TL, TSCE, OTT) + shipping
        $ttc = $tvac + $ctAmount + $tlAmount + $tsceAmount + $ottAmount + $shippingCost;

        $totalAmount = $ttc;

        // Date d'échéance (30 jours par défaut)
        $dueDate = $input['due_date'] ?? date('Y-m-d', strtotime('+30 days', strtotime($input['invoice_date'] ?? date('Y-m-d'))));

        // Préparer les données de la facture
        $invoiceData = [
            'invoice_number' => $invoiceNumber,
            'invoice_identifier' => $invoiceIdentifier,
            'invoice_type' => $input['invoice_type'] ?? 'FN',
            'invoice_date' => $input['invoice_date'] ?? date('Y-m-d H:i:s'),
            'due_date' => $dueDate,
            'invoice_currency' => $input['invoice_currency'] ?? 'BIF',
            'payment_type' => $input['payment_type'] ?? null,
            'notes' => $input['notes'] ?? null,
            'warehouse_id' => $input['warehouse_id'],
            'customer_id' => $input['customer_id'] ?? null,
            'customer_name' => $customerName,
            'customer_TIN' => $customerTin,
            'customer_address' => $customerAddress,
            'customer_email' => $customerEmail,
            'customer_phone' => $customerPhone,
            'vat_customer_payer' => isset($input['vat_customer_payer']) ? (int)$input['vat_customer_payer'] : 0,
            // Store HTVA into the `subtotal` column (réutilisation du champ existant)
            'subtotal' => $htva,
            'discount_total' => $discountTotal,
            'vat_amount' => $vatAmount,
            'ct_amount' => $ctAmount,
            'tl_amount' => $tlAmount,
            'shipping_amount' => $shippingCost,
            // Store TTC into total_amount
            'total_amount' => $totalAmount,
            'invoice_ref' => $input['invoice_ref'] ?? null,
            'cn_motif' => $input['cn_motif'] ?? null,
            'payment_status' => 'pending',
            'ebms_status' => 'PENDING',
            'created_by' => session()->get('user_id')
        ];

        // Transaction et logique d'insertion protégées
        $this->db->transStart();

        try {
            log_message('info', 'Création facture: ' . ($invoiceNumber ?? 'N/A') . ' par utilisateur ' . session()->get('user_id'));

            // Insérer la facture et ses items
            $invoiceId = $this->invoiceModel->insertWithItems($invoiceData, $itemsData);

            if (!$invoiceId) {
                throw new \Exception('insertWithItems a retourné false');
            }

            // Gérer les pièces jointes via la méthode helper (si multipart)
            if ($isMultipart && !empty($_FILES['attachments'])) {
                try {
                    $this->handleAttachments($invoiceId, $_FILES['attachments']);
                } catch (\Exception $e) {
                    // Ne pas empêcher la création si l'upload échoue, mais logguer
                    log_message('error', 'Erreur upload pièces jointes pour facture ' . $invoiceId . ': ' . $e->getMessage());
                }
            }

            // Gérer le stock via la méthode unifiée
            try {
                $this->manageStockForInvoice($invoiceId, $invoiceData, $itemsData, $invoiceNumber);
            } catch (\Exception $e) {
                log_message('error', 'Erreur gestion stock pour facture ' . $invoiceId . ': ' . $e->getMessage());
                throw $e;
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                throw new \Exception('Transaction DB échouée');
            }

            // Déclencher la synchronisation EBMS en arrière-plan si activé
            if ($this->isEbmsInvoiceSyncEnabled()) {
                $this->triggerEbmsSync($invoiceId);
            } else {
                log_message('info', 'Synchronisation EBMS des factures désactivée, synchronisation automatique ignorée pour facture ' . $invoiceNumber);
            }

            $invoice = $this->invoiceModel->getInvoiceWithDetails($invoiceId);

            return $this->respond([
                'success' => true,
                'message' => 'Facture créée avec succès',
                'data' => $invoice,
                'meta' => [
                    'invoice_id' => $invoiceId,
                    'invoice_number' => $invoiceNumber
                ]
            ], 201);
        } catch (\Throwable $e) {
            // Rollback and detailed logging
            $this->db->transRollback();

            // Log with context (truncate large inputs)
            $inputPreview = json_encode(array_slice($input, 0, 20));
            log_message('error', 'Erreur création facture: ' . $e->getMessage() . ' | invoice_number=' . ($invoiceNumber ?? 'N/A') . ' | input=' . $inputPreview);

            // Attempt to provide useful error info to client without exposing sensitive data
            $errorMsg = $e->getMessage();
            $response = [
                'success' => false,
                'message' => 'Erreur lors de la création de la facture',
                'error' => $errorMsg
            ];

            return $this->respond($response, 500);
        }
    }

    /**
     * Générer l'identifiant unique pour EBMS
     */
    private function generateInvoiceIdentifier($customerTin, $invoiceNumber)
    {
        $tin = !empty($customerTin) ? trim($customerTin) : '0000000000';
        $systemId = trim($this->settingsModel->getSetting('system_id', 'STOCKMANAGER')) ?: 'STOCKMANAGER';
        $dateTime = date('YmdHis');
        return $tin . '/' . $systemId . '/' . $dateTime . '/' . $invoiceNumber;
    }

    /**
     * Gérer l'upload des pièces jointes
     */
    private function handleAttachments($invoiceId, $files)
    {
        $uploadPath = WRITEPATH . 'uploads/invoices/' . $invoiceId . '/';
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0777, true);
        }

        $uploadedFiles = $this->normalizeFiles($files);
        $allowedExt = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
        $maxSize = 10 * 1024 * 1024; // 10MB

        foreach ($uploadedFiles as $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                continue;
            }

            if ($file['size'] > $maxSize) {
                log_message('warning', 'Fichier trop volumineux: ' . $file['name']);
                continue;
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt)) {
                log_message('warning', 'Extension non autorisée: ' . $ext);
                continue;
            }

            $filename = time() . '_' . uniqid() . '.' . $ext;
            $filePath = $uploadPath . $filename;

            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                $this->db->table('invoice_attachments')->insert([
                    'invoice_id' => $invoiceId,
                    'filename' => $filename,
                    'original_name' => $file['name'],
                    'file_path' => $filePath,
                    'file_size' => $file['size'],
                    'mime_type' => $file['type'],
                    'uploaded_by' => session()->get('user_id'),
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }
        }
    }

    /**
     * Normaliser le tableau $_FILES pour les multiples fichiers
     */
    private function normalizeFiles($files)
    {
        $normalized = [];
        if (isset($files['name']) && is_array($files['name'])) {
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $normalized[] = [
                        'name' => $files['name'][$i],
                        'type' => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error' => $files['error'][$i],
                        'size' => $files['size'][$i]
                    ];
                }
            }
        } else if (!empty($files['name']) && $files['error'] === UPLOAD_ERR_OK) {
            $normalized[] = $files;
        }
        return $normalized;
    }

    /**
     * Déterminer l'impact sur le stock selon le type de facture
     */
    private function getStockImpact($invoiceType)
    {
        switch ($invoiceType) {
            case 'FN': // Facture Normale
                return [
                    'type' => 'out',
                    'movement_type' => 'SN',
                    'movement_label' => 'Sortie Normale',
                    'update_stock' => true
                ];

            case 'FA': // Facture d'Avoir
                return [
                    'type' => 'in',
                    'movement_type' => 'ER',
                    'movement_label' => 'Entrée Retour',
                    'update_stock' => true
                ];

            default:
                return [
                    'type' => 'none',
                    'movement_type' => null,
                    'movement_label' => 'Aucun impact stock',
                    'update_stock' => false
                ];
        }
    }

    /**
     * Gérer le stock selon le type de facture
     */
    private function manageStockForInvoice($invoiceId, $invoiceData, $items, $invoiceNumber)
    {
        $invoiceType = $invoiceData['invoice_type'] ?? 'FN';
        $stockImpact = $this->getStockImpact($invoiceType);

        if (!$stockImpact['update_stock']) {
            return true;
        }

        $warehouseId = $invoiceData['warehouse_id'] ?? null;

        // Vérifier que l'entrepôt existe
        if (!$warehouseId) {
            log_message('warning', 'Pas d\'entrepôt pour la facture ' . $invoiceNumber);
            return true;
        }

        foreach ($items as $item) {
            $product = $this->productModel->find($item['product_id']);
            if (!$product) continue;

            $currentStock = $product['current_stock'] ?? 0;

            if ($stockImpact['type'] === 'out') {
                $newStock = $currentStock - $item['quantity'];
            } else {
                $newStock = $currentStock + $item['quantity'];
            }

            // Mettre à jour le stock
            $this->productModel->update($item['product_id'], [
                'current_stock' => max(0, $newStock)
            ]);

            // Créer le mouvement de stock
            $movementNumber = $this->stockMovementModel->generateMovementNumber();

            $movementData = [
                'movement_number' => $movementNumber,
                'movement_group' => 'INVOICE_' . $invoiceId,
                'warehouse_id' => $warehouseId,  // <-- DÉCOMMENTER CETTE LIGNE
                'product_id' => $item['product_id'],
                'movement_type' => $stockImpact['movement_type'],
                'quantity' => $item['quantity'],
                'previous_quantity' => $currentStock,
                'new_quantity' => max(0, $newStock),
                'unit_cost' => $item['unit_price'],
                'total_cost' => $item['quantity'] * $item['unit_price'],
                'movement_value' => $item['quantity'] * $item['unit_price'],
                'invoice_ref' => $invoiceNumber,
                'description' => $stockImpact['movement_label'] . ' via facture ' . $invoiceNumber,
                'movement_date' => $invoiceData['invoice_date'] ?? date('Y-m-d H:i:s'),
                'created_by' => session()->get('user_id'),
                'reference' => $invoiceNumber,
                'reference_doc' => 'INVOICE_' . $invoiceNumber
            ];

            $this->stockMovementModel->insert($movementData);

            if ($this->isEbmsStockSyncEnabled()) {
                $this->sendStockMovementToEbms($movementData, $product);
            }
        }

        return true;
    }

    private function isEbmsStockSyncEnabled()
    {
        return filter_var($this->settingsModel->getSetting('ebms_sync_stock_movements', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function isEbmsInvoiceSyncEnabled()
    {
        return filter_var($this->settingsModel->getSetting('ebms_sync_invoices', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function sendStockMovementToEbms(array $movementData, array $product)
    {
        try {
            $ebmsClient = new EBMSClient();
            $payload = [
                'movement_number' => $movementData['movement_number'],
                'warehouse_id' => $movementData['warehouse_id'],
                'product_id' => $movementData['product_id'],
                'product_code' => $product['code'] ?? null,
                'product_name' => $product['name'] ?? null,
                'movement_type' => $movementData['movement_type'],
                'quantity' => $movementData['quantity'],
                'previous_quantity' => $movementData['previous_quantity'],
                'new_quantity' => $movementData['new_quantity'],
                'unit_cost' => $movementData['unit_cost'],
                'total_cost' => $movementData['total_cost'],
                'movement_value' => $movementData['movement_value'],
                'invoice_ref' => $movementData['invoice_ref'],
                'description' => $movementData['description'],
                'movement_date' => $movementData['movement_date'],
                'reference' => $movementData['reference'] ?? null,
                'reference_doc' => $movementData['reference_doc'] ?? null,
            ];

            $response = $ebmsClient->addStockMovement($payload);
            if (!empty($response['success'])) {
                log_message('info', 'Mouvement de stock EBMS synchronisé: ' . $movementData['movement_number']);
            } else {
                log_message('warning', 'Erreur EBMS stock movement: ' . ($response['error'] ?? json_encode($response)));
            }
        } catch (\Throwable $e) {
            log_message('error', 'Exception EBMS stock movement: ' . $e->getMessage());
        }
    }

    /**
     * Déclencher la synchronisation EBMS en arrière-plan
     */
    private function triggerEbmsSync($invoiceId)
    {
        if (!$this->isEbmsInvoiceSyncEnabled()) {
            log_message('info', 'Synchronisation EBMS des factures désactivée, triggerEbmsSync ignorée pour facture ID ' . $invoiceId);
            return;
        }

        // En production, utiliser un système de file d'attente
        // Pour l'instant, on appelle de manière asynchrone si possible
        try {
            $client = \Config\Services::curlrequest();
            $client->post(site_url("api/invoices/{$invoiceId}/sync-ebms"), [
                'timeout' => 1,
                'async' => true
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Erreur déclenchement sync EBMS: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/invoices/(:num)/attachments - Ajouter des pièces jointes
     */
    public function addAttachments($id = null)
    {
        $invoice = $this->invoiceModel->find($id);

        if (!$invoice) {
            return $this->respond(['success' => false, 'message' => 'Facture non trouvée'], 404);
        }

        if (!empty($_FILES['attachments'])) {
            $uploadPath = WRITEPATH . 'uploads/invoices/' . $id . '/';
            if (!is_dir($uploadPath)) {
                mkdir($uploadPath, 0777, true);
            }

            $files = $this->normalizeFiles($_FILES['attachments']);
            $uploaded = [];

            foreach ($files as $file) {
                if ($file['error'] === UPLOAD_ERR_OK && $file['size'] <= 10 * 1024 * 1024) {
                    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $allowedExt = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];

                    if (in_array(strtolower($ext), $allowedExt)) {
                        $filename = time() . '_' . uniqid() . '.' . $ext;
                        $filePath = $uploadPath . $filename;

                        if (move_uploaded_file($file['tmp_name'], $filePath)) {
                            $attachmentId = $this->db->table('invoice_attachments')->insert([
                                'invoice_id' => $id,
                                'filename' => $filename,
                                'original_name' => $file['name'],
                                'file_path' => $filePath,
                                'file_size' => $file['size'],
                                'mime_type' => $file['type'],
                                'uploaded_by' => session()->get('user_id'),
                                'created_at' => date('Y-m-d H:i:s')
                            ]);
                            $uploaded[] = [
                                'id' => $attachmentId,
                                'name' => $file['name'],
                                'size' => $file['size'],
                                'type' => $file['type']
                            ];
                        }
                    }
                }
            }

            return $this->respond([
                'success' => true,
                'message' => count($uploaded) . ' fichier(s) uploadé(s)',
                'data' => $uploaded
            ]);
        }

        return $this->respond(['success' => false, 'message' => 'Aucun fichier à uploader'], 400);
    }

    /**
     * GET /api/invoices/(:num)/attachments - Récupérer les pièces jointes
     */
    public function getAttachments($id = null)
    {
        $attachments = $this->db->table('invoice_attachments')
            ->where('invoice_id', $id)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();

        return $this->respond([
            'success' => true,
            'data' => $attachments
        ]);
    }

    /**
     * DELETE /api/invoices/(:num)/attachments/(:num) - Supprimer une pièce jointe
     */
    public function deleteAttachment($invoiceId = null, $attachmentId = null)
    {
        $attachment = $this->db->table('invoice_attachments')
            ->where('id', $attachmentId)
            ->where('invoice_id', $invoiceId)
            ->get()
            ->getRowArray();

        if (!$attachment) {
            return $this->respond(['success' => false, 'message' => 'Fichier non trouvé'], 404);
        }

        // Supprimer le fichier physiquement
        if (file_exists($attachment['file_path'])) {
            unlink($attachment['file_path']);
        }

        // Supprimer de la base
        $this->db->table('invoice_attachments')
            ->where('id', $attachmentId)
            ->delete();

        return $this->respond([
            'success' => true,
            'message' => 'Fichier supprimé avec succès'
        ]);
    }

    /**
     * PUT /api/invoices/(:num) - Modifier une facture
     */
    public function update($id = null)
    {
        $invoice = $this->invoiceModel->find($id);

        if (!$invoice) {
            return $this->respond(['success' => false, 'message' => 'Facture non trouvée'], 404);
        }

        if ($invoice['payment_status'] === 'paid') {
            return $this->respond(['success' => false, 'message' => 'Une facture payée ne peut pas être modifiée'], 400);
        }

        if ($invoice['payment_status'] === 'cancelled') {
            return $this->respond(['success' => false, 'message' => 'Une facture annulée ne peut pas être modifiée'], 400);
        }

        $input = $this->request->getJSON(true);

        // Validation spécifique à FA (Facture d'Avoir)
        if ($invoice['invoice_type'] === 'FA' || $input['invoice_type'] === 'FA') {
            if (empty($input['cn_motif']) && empty($invoice['cn_motif'])) {
                return $this->respond(['success' => false, 'message' => 'Le motif de l\'avoir est requis pour une facture d\'avoir'], 400);
            }
            if (!empty($input['cn_motif'])) {
                $validMotifs = ['erreur', 'retour_marchandises', 'rabais', 'ristourne', 'remise', 'escompte'];
                if (!in_array($input['cn_motif'], $validMotifs)) {
                    return $this->respond(['success' => false, 'message' => 'Motif d\'avoir invalide'], 400);
                }
            }
        }

        // Mise à jour des champs autorisés
        $updateData = [];
        $allowedFields = ['customer_name', 'customer_TIN', 'customer_address', 'customer_email', 'customer_phone', 'notes', 'payment_type', 'due_date', 'invoice_ref', 'cn_motif'];

        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updateData[$field] = $input[$field];
            }
        }

        if (!empty($updateData)) {
            $this->invoiceModel->update($id, $updateData);
        }

        return $this->respond([
            'success' => true,
            'message' => 'Facture mise à jour avec succès'
        ]);
    }

    /**
     * DELETE /api/invoices/(:num) - Supprimer une facture
     */
    public function delete($id = null)
    {
        $invoice = $this->invoiceModel->find($id);

        if (!$invoice) {
            return $this->respond(['success' => false, 'message' => 'Facture non trouvée'], 404);
        }

        if ($invoice['payment_status'] === 'paid') {
            return $this->respond(['success' => false, 'message' => 'Une facture payée ne peut pas être supprimée'], 400);
        }

        if ($invoice['payment_status'] === 'cancelled') {
            return $this->respond(['success' => false, 'message' => 'Une facture annulée ne peut pas être supprimée'], 400);
        }

        $this->db->transStart();

        // Supprimer les pièces jointes physiquement
        $attachments = $this->db->table('invoice_attachments')
            ->where('invoice_id', $id)
            ->get()
            ->getResultArray();

        foreach ($attachments as $att) {
            if (file_exists($att['file_path'])) {
                unlink($att['file_path']);
            }
        }

        $this->db->table('invoice_attachments')->where('invoice_id', $id)->delete();
        $this->db->table('invoice_items')->where('invoice_id', $id)->delete();
        $this->db->table('invoice_payments')->where('invoice_id', $id)->delete();
        $this->invoiceModel->delete($id);

        $this->db->transComplete();

        return $this->respond([
            'success' => true,
            'message' => 'Facture supprimée avec succès'
        ]);
    }

    /**
     * POST /api/invoices/(:num)/cancel - Annuler une facture
     */
    public function cancel($id = null)
    {
        $input = $this->request->getJSON(true);
        $reason = $input['reason'] ?? 'Annulation manuelle';

        $invoice = $this->invoiceModel->getInvoiceWithDetails($id);

        if (!$invoice) {
            return $this->respond(['success' => false, 'message' => 'Facture non trouvée'], 404);
        }

        if ($invoice['payment_status'] === 'paid') {
            return $this->respond(['success' => false, 'message' => 'Une facture payée ne peut pas être annulée'], 400);
        }

        if ($invoice['payment_status'] === 'cancelled') {
            return $this->respond(['success' => false, 'message' => 'Cette facture est déjà annulée'], 400);
        }

        $this->db->transStart();

        // 1. Annuler la facture
        $this->invoiceModel->update($id, [
            'payment_status' => 'cancelled',
            'cn_motif' => $reason,
            'cancelled_invoice_ref' => $invoice['invoice_number'],
            'status' => 'cancelled'
        ]);

        // 2. Gérer l'impact inverse sur le stock
        $stockImpact = $this->getStockImpact($invoice['invoice_type']);

        if ($stockImpact['update_stock']) {
            $reverseImpact = ($stockImpact['type'] === 'out') ? 'in' : 'out';
            $reverseMovementType = ($stockImpact['type'] === 'out') ? 'ER' : 'SN';

            foreach ($invoice['items'] as $item) {
                $product = $this->productModel->find($item['product_id']);
                if (!$product) continue;

                $currentStock = $product['current_stock'] ?? 0;

                if ($reverseImpact === 'in') {
                    $newStock = $currentStock + $item['quantity'];
                } else {
                    $newStock = max(0, $currentStock - $item['quantity']);
                }

                $this->productModel->update($item['product_id'], [
                    'current_stock' => $newStock
                ]);

                $movementNumber = $this->stockMovementModel->generateMovementNumber();
                $movementData = [
                    'movement_number' => $movementNumber,
                    'movement_group' => 'CANCEL_INVOICE_' . $id,
                    'warehouse_id' => $invoice['warehouse_id'],
                    'product_id' => $item['product_id'],
                    'movement_type' => $reverseMovementType,
                    'quantity' => $item['quantity'],
                    'previous_quantity' => $currentStock,
                    'new_quantity' => $newStock,
                    'unit_cost' => $item['unit_price'],
                    'total_cost' => $item['quantity'] * $item['unit_price'],
                    'movement_value' => $item['quantity'] * $item['unit_price'],
                    'invoice_ref' => $invoice['invoice_number'],
                    'description' => 'Annulation de facture - ' . $reason,
                    'movement_date' => date('Y-m-d H:i:s'),
                    'created_by' => session()->get('user_id'),
                    'reference' => 'CANCEL_' . $invoice['invoice_number']
                ];

                $this->stockMovementModel->insert($movementData);
            }
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return $this->respond(['success' => false, 'message' => 'Erreur lors de l\'annulation'], 500);
        }

        try {
            $this->cancelInvoiceOnEbms($invoice, $reason);
        } catch (\Throwable $e) {
            log_message('warning', 'Erreur EBMS annulation facture ' . $invoice['invoice_number'] . ': ' . $e->getMessage());
        }

        return $this->respond([
            'success' => true,
            'message' => 'Facture annulée avec succès'
        ]);
    }

    private function cancelInvoiceOnEbms(array $invoice, string $reason)
    {
        if (empty($invoice['invoice_identifier'])) {
            throw new \Exception('Identifiant EBMS manquant pour la facture');
        }

        $ebmsClient = new EBMSClient();
        $result = $ebmsClient->cancelInvoice($invoice['invoice_identifier'], $reason);

        $updateData = [];
        if (!empty($result['success'])) {
            $updateData['ebms_status'] = 'CANCELLED';
            $updateData['ebms_error_msg'] = null;
        } else {
            $updateData['ebms_status'] = 'FAILED';
            $updateData['ebms_error_msg'] = $result['error'] ?? ($result['message'] ?? 'Erreur EBMS annulation');
        }

        $this->invoiceModel->update($invoice['id'], $updateData);

        $this->db->table('ebms_logs')->insert([
            'invoice_id' => $invoice['id'],
            'endpoint' => 'cancelInvoice',
            'request_data' => json_encode(['invoice_identifier' => $invoice['invoice_identifier'], 'cn_motif' => $reason]),
            'response_data' => json_encode($result),
            'status_code' => !empty($result['success']) ? 200 : 500,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * POST /api/invoices/(:num)/payments - Ajouter un paiement
     */
    /* public function addPayment($id = null)
    {
        $input = $this->request->getJSON(true);

        $invoice = $this->invoiceModel->find($id);

        if (!$invoice) {
            return $this->respond(['success' => false, 'message' => 'Facture non trouvée'], 404);
        }

        if ($invoice['payment_status'] === 'cancelled') {
            return $this->respond(['success' => false, 'message' => 'Une facture annulée ne peut pas recevoir de paiement'], 400);
        }

        if (empty($input['amount']) || $input['amount'] <= 0) {
            return $this->respond(['success' => false, 'message' => 'Montant invalide'], 400);
        }

        $totalPaid = $this->db->table('invoice_payments')
            ->selectSum('amount')
            ->where('invoice_id', $id)
            ->get()
            ->getRow()
            ->amount ?? 0;

        $newPaidAmount =  $input['amount'];

        if ($newPaidAmount > $invoice['total_amount']) {
            return $this->respond(['success' => false, 'message' => 'Le montant dépasse le solde restant'], 400);
        }

        $remainingAmount = $invoice['total_amount'] - $newPaidAmount;
        $paymentStatus = ($remainingAmount <= 0) ? 'paid' : 'partial';

        $this->db->transStart();

        // Ajouter le paiement
        $paymentData = [
            'invoice_id' => $id,
            'payment_date' => $input['payment_date'] ?? date('Y-m-d H:i:s'),
            'amount' => $input['amount'],
            'payment_method' => $input['payment_method'] ?? 'cash',
            'reference' => $input['reference'] ?? null,
            'notes' => $input['notes'] ?? null,
            'created_by' => session()->get('user_id')
        ];

        $this->db->table('invoice_payments')->insert($paymentData);

        // Mettre à jour le statut de paiement
        $updateData = [
            'payment_status' => $paymentStatus,
            'status' => $paymentStatus,
            'paid_amount' => $newPaidAmount
        ];

        if ($paymentStatus === 'paid') {
            $updateData['payment_date'] = $input['payment_date'] ?? date('Y-m-d H:i:s');
            $updateData['payment_method'] = $input['payment_method'] ?? 'cash';
        }

        $this->invoiceModel->update($id, $updateData);

        // Mettre à jour le solde client si customer_id existe
        // Mettre à jour le solde client si customer_id existe
        if (!empty($invoice['customer_id'])) {
            $this->db->table('customers')
                ->set('current_balance', "current_balance - {$input['amount']}", false)
                ->where('id', $invoice['customer_id'])
                ->update();
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return $this->respond(['success' => false, 'message' => 'Erreur lors de l\'enregistrement du paiement'], 500);
        }

        return $this->respond([
            'success' => true,
            'message' => 'Paiement enregistré avec succès',
            'data' => [
                'paid_amount' => $newPaidAmount,
                'remaining_amount' => $remainingAmount,
                'payment_status' => $paymentStatus
            ]
        ]);
    }*/

    public function addPayment($id = null)
    {
        $this->db->transStart();

        try {
            $input = $this->request->getJSON(true);

            // Vérifier si la facture existe
            $invoice = $this->invoiceModel->find($id);

            if (!$invoice) {
                return $this->respond(['success' => false, 'message' => 'Facture non trouvée'], 404);
            }

            // Vérifier si la facture n'est pas annulée
            if ($invoice['status'] === 'cancelled') {
                return $this->respond(['success' => false, 'message' => 'Une facture annulée ne peut pas recevoir de paiement'], 400);
            }

            // Valider le montant
            if (empty($input['amount']) || !is_numeric($input['amount']) || $input['amount'] <= 0) {
                return $this->respond(['success' => false, 'message' => 'Le montant du paiement est invalide'], 400);
            }

            // Récupérer le montant déjà payé
            $totalPaid = $this->db->table('invoice_payments')
                ->selectSum('amount')
                ->where('invoice_id', $id)
                ->where('deleted_at IS NULL')
                ->get()
                ->getRow()
                ->amount ?? 0;

            // Calculer le nouveau montant total payé
            $newPaidAmount = $totalPaid + $input['amount'];
            $totalAmount = floatval($invoice['total_amount']);

            // Vérifier si le paiement ne dépasse pas le total
            if ($newPaidAmount > $totalAmount) {
                return $this->respond([
                    'success' => false,
                    'message' => "Le montant du paiement ({$input['amount']}) dépasse le solde restant (" . ($totalAmount - $totalPaid) . ")"
                ], 400);
            }

            // Calculer le reste à payer et déterminer le statut
            $remainingAmount = $totalAmount - $newPaidAmount;
            $paymentStatus = ($remainingAmount <= 0) ? 'paid' : 'partial';

            // Préparer les données du paiement
            $paymentData = [
                'invoice_id' => $id,
                'payment_date' => $input['payment_date'] ?? date('Y-m-d H:i:s'),
                'amount' => $input['amount'],
                'payment_method' => $input['payment_method'] ?? 'cash',
                'reference' => $input['reference'] ?? null,
                'notes' => $input['notes'] ?? null,
                'created_by' => session()->get('user_id'),
                'created_at' => date('Y-m-d H:i:s')
            ];


            // Insérer le paiement
            if (!$this->db->table('invoice_payments')->insert($paymentData)) {
                throw new \Exception('Erreur lors de l\'insertion du paiement');
            }

            // Mettre à jour la facture
            $updateData = [
                'payment_status' => $paymentStatus,
                'status' => $paymentStatus,
                'paid_amount' => $newPaidAmount,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if ($paymentStatus === 'paid') {
                $updateData['payment_date'] = $input['payment_date'] ?? date('Y-m-d H:i:s');
                $updateData['payment_method'] = $input['payment_method'] ?? 'cash';
            }

            if (!$this->invoiceModel->update($id, $updateData)) {
                throw new \Exception('Erreur lors de la mise à jour de la facture');
            }

            // Mettre à jour le solde du client
            if (!empty($invoice['customer_id'])) {
                $customer = $this->db->table('customers')
                    ->where('id', $invoice['customer_id'])
                    ->get()
                    ->getRowArray();

                if ($customer) {
                    $newBalance = ($customer['current_balance'] ?? 0) - $input['amount'];
                    $this->db->table('customers')
                        ->where('id', $invoice['customer_id'])
                        ->update([
                            'current_balance' => max(0, $newBalance),
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                }
            }

            // Log de l'activité (optionnel)
            log_message('info', "Paiement ajouté pour la facture {$invoice['invoice_number']}: {$input['amount']} par utilisateur " . (session()->get('user_id')));

            $this->db->transComplete();

            return $this->respond([
                'success' => true,
                'message' => 'Paiement enregistré avec succès',
                'data' => [
                    'invoice_id' => $id,
                    'invoice_number' => $invoice['invoice_number'],
                    'paid_amount' => $newPaidAmount,
                    'remaining_amount' => $remainingAmount,
                    'payment_status' => $paymentStatus,
                    'payment_id' => $this->db->insertID()
                ]
            ]);
        } catch (\Exception $e) {
            $this->db->transRollback();
            log_message('error', 'Erreur addPayment: ' . $e->getMessage());

            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors de l\'enregistrement du paiement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Générer un numéro de paiement unique
     */
    private function generatePaymentNumber()
    {
        $prefix = 'PAY';
        $date = date('Ymd');
        $random = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

        // Vérifier l'unicité
        $exists = $this->db->table('invoice_payments')
            ->where('payment_number', $prefix . $date . $random)
            ->countAllResults();

        if ($exists > 0) {
            return $this->generatePaymentNumber();
        }

        return $prefix . $date . $random;
    }

    /**
     * POST /api/invoices/(:num)/sync-ebms - Synchroniser avec EBMS
     */
    public function syncWithEBMS($id = null)
    {
        if (!$this->isEbmsInvoiceSyncEnabled()) {
            return $this->respond(['success' => false, 'message' => 'La synchronisation EBMS des factures est désactivée'], 400);
        }

        $invoice = $this->invoiceModel->getInvoiceWithDetails($id);

        if (!$invoice) {
            return $this->respond(['success' => false, 'message' => 'Facture non trouvée'], 404);
        }

        if ($invoice['payment_status'] === 'cancelled') {
            return $this->respond(['success' => false, 'message' => 'Une facture annulée ne peut pas être synchronisée'], 400);
        }

        $result = $this->performEbmsSync($invoice);

        if ($result['success']) {
            return $this->respond([
                'success' => true,
                'message' => $result['message'],
                'data' => $result['data'] ?? []
            ]);
        }

        return $this->respond([
            'success' => false,
            'message' => $result['message']
        ], $result['code'] ?? 500);
    }

    private function performEbmsSync(array $invoice)
    {
        $logId = $this->db->table('ebms_logs')->insert([
            'invoice_id' => $invoice['id'],
            'endpoint' => 'addInvoice',
            'request_data' => json_encode($this->buildEbmsInvoicePayload($invoice)),
            'retry_count' => ($invoice['ebms_retry_count'] ?? 0) + 1,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        try {
            $ebmsClient = new EBMSClient();
            $payload = $this->buildEbmsInvoicePayload($invoice);
            $response = $ebmsClient->addInvoice($payload);

            $success = !empty($response['success']);
            $responseData = $response['data'] ?? $response;
            $statusCode = $response['status_code'] ?? ($success ? 200 : 500);

            if ($success) {
                $result = $responseData['result'] ?? $responseData;
                $this->invoiceModel->update($invoice['id'], [
                    'ebms_status' => 'ACKNOWLEDGED',
                    'ebms_sent_at' => date('Y-m-d H:i:s'),
                    'ebms_registered_number' => $result['registered_number'] ?? ($result['invoice_number'] ?? $invoice['invoice_number']),
                    'ebms_registered_date' => $result['registered_date'] ?? date('Y-m-d H:i:s'),
                    'ebms_signature' => $result['signature'] ?? null,
                    'ebms_retry_count' => 0,
                    'ebms_error_msg' => null
                ]);

                $this->db->table('ebms_logs')->where('id', $logId)->update([
                    'response_data' => json_encode($responseData),
                    'status_code' => $statusCode,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                return ['success' => true, 'message' => 'Facture synchronisée avec EBMS', 'data' => $responseData];
            }

            throw new \Exception($response['error'] ?? $responseData['message'] ?? 'Erreur de synchronisation EBMS');
        } catch (\Throwable $e) {
            $retryCount = ($invoice['ebms_retry_count'] ?? 0) + 1;
            $this->invoiceModel->update($invoice['id'], [
                'ebms_status' => 'FAILED',
                'ebms_retry_count' => $retryCount,
                'ebms_error_msg' => $e->getMessage()
            ]);

            $this->db->table('ebms_logs')->where('id', $logId)->update([
                'response_data' => json_encode(['error' => $e->getMessage()]),
                'status_code' => 500,
                'error_message' => $e->getMessage(),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            return ['success' => false, 'message' => 'Erreur de synchronisation: ' . $e->getMessage(), 'code' => 500];
        }
    }

    private function buildEbmsInvoicePayload(array $invoice)
    {
        $items = [];
        foreach ($invoice['items'] as $item) {
            $items[] = [
                'product_id' => $item['product_id'],
                'item_code' => $item['item_code'],
                'item_designation' => $item['item_designation'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_amount' => $item['total_amount'],
                'vat_amount' => $item['vat_amount'],
                'ct_amount' => $item['ct_amount'],
                'tl_amount' => $item['tl_amount'],
                'discount_amount' => $item['discount_amount'] ?? 0,
                'tax_rate' => $item['tax_rate'] ?? null,
            ];
        }

        return [
            'invoice_number' => $invoice['invoice_number'],
            'invoice_identifier' => $invoice['invoice_identifier'],
            'invoice_date' => $invoice['invoice_date'],
            'due_date' => $invoice['due_date'] ?? null,
            'invoice_type' => $invoice['invoice_type'],
            'customer_tin' => $invoice['customer_TIN'],
            'customer_name' => $invoice['customer_name'],
            'customer_address' => $invoice['customer_address'],
            'customer_email' => $invoice['customer_email'],
            'customer_phone' => $invoice['customer_phone'],
            'subtotal' => $invoice['subtotal'],
            'discount_total' => $invoice['discount_total'],
            'vat_amount' => $invoice['vat_amount'],
            'ct_amount' => $invoice['ct_amount'],
            'tl_amount' => $invoice['tl_amount'],
            'shipping_amount' => $invoice['shipping_amount'],
            'total_amount' => $invoice['total_amount'],
            'notes' => $invoice['notes'],
            'items' => $items,
        ];
    }

    /**
     * POST /api/invoices/(:num)/verify - Vérifier la facture auprès de l'EBMS
     */
    public function verifyWithEBMS($id = null)
    {
        $invoice = $this->invoiceModel->getInvoiceWithDetails($id);

        if (!$invoice) {
            return $this->respond(['success' => false, 'message' => 'Facture non trouvée'], 404);
        }

        if (empty($invoice['invoice_identifier'])) {
            return $this->respond(['success' => false, 'message' => 'Identifiant EBMS manquant pour la facture'], 400);
        }

        try {
            $ebmsClient = new EBMSClient();
            $response = $ebmsClient->getInvoice($invoice['invoice_identifier']);

            if (!empty($response['success'])) {
                return $this->respond(['success' => true, 'message' => 'Vérification EBMS réussie', 'data' => $response]);
            }

            return $this->respond(['success' => false, 'message' => $response['error'] ?? ($response['message'] ?? 'Échec de la vérification EBMS'), 'data' => $response], 400);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => 'Erreur de vérification EBMS: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/invoices/(:num)/ebms-logs - Récupérer les logs EBMS
     */
    public function getEbmsLogs($id = null)
    {
        $logs = $this->db->table('ebms_logs')
            ->where('invoice_id', $id)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();

        return $this->respond([
            'success' => true,
            'data' => $logs
        ]);
    }

    /**
     * POST /api/invoices/(:num)/email - Envoyer par email
     */
    public function sendEmail($id = null)
    {
        $invoice = $this->invoiceModel->getInvoiceWithDetails($id);

        if (!$invoice) {
            return $this->respond(['success' => false, 'message' => 'Facture non trouvée'], 404);
        }

        $email = $this->request->getPost('email') ?? $invoice['customer_email'] ?? null;

        if (!$email) {
            return $this->respond(['success' => false, 'message' => 'Adresse email non fournie'], 400);
        }

        // Générer le PDF de la facture
        $pdfContent = $this->generateInvoicePDF($invoice);

        // Envoyer l'email
        $emailService = \Config\Services::email();
        $emailService->setTo($email);
        $emailService->setFrom('noreply@stock-management.com', 'Stock Management System');
        $emailService->setSubject("Facture {$invoice['invoice_number']}");
        $emailService->setMessage($this->getEmailTemplate($invoice));

        // Attacher le PDF
        $emailService->attach($pdfContent, 'application/pdf', "facture_{$invoice['invoice_number']}.pdf");

        if ($emailService->send()) {
            $this->invoiceModel->update($id, [
                'email_sent' => 1,
                'email_sent_at' => date('Y-m-d H:i:s')
            ]);

            return $this->respond([
                'success' => true,
                'message' => 'Email envoyé avec succès'
            ]);
        }

        return $this->respond([
            'success' => false,
            'message' => 'Erreur lors de l\'envoi de l\'email: ' . $emailService->printDebugger()
        ], 500);
    }

    /**
     * POST /api/invoices/(:num)/reminder - Envoyer un rappel de paiement
     */
    public function sendReminder($id = null)
    {
        $invoice = $this->invoiceModel->getInvoiceWithDetails($id);

        if (!$invoice) {
            return $this->respond(['success' => false, 'message' => 'Facture non trouvée'], 404);
        }

        if ($invoice['payment_status'] === 'paid') {
            return $this->respond(['success' => false, 'message' => 'Cette facture est déjà payée'], 400);
        }

        $email = $invoice['customer_email'];
        if (!$email) {
            return $this->respond(['success' => false, 'message' => 'Le client n\'a pas d\'adresse email'], 400);
        }

        $remainingAmount = $invoice['total_amount'] - ($invoice['paid_amount'] ?? 0);
        $dueDate = $invoice['due_date'] ?? date('Y-m-d', strtotime($invoice['invoice_date'] . ' +30 days'));

        $emailService = \Config\Services::email();
        $emailService->setTo($email);
        $emailService->setFrom('noreply@stock-management.com', 'Stock Management System');
        $emailService->setSubject("Rappel de paiement - Facture {$invoice['invoice_number']}");
        $emailService->setMessage($this->getReminderTemplate($invoice, $remainingAmount, $dueDate));

        if ($emailService->send()) {
            return $this->respond([
                'success' => true,
                'message' => 'Rappel envoyé avec succès'
            ]);
        }

        return $this->respond([
            'success' => false,
            'message' => 'Erreur lors de l\'envoi du rappel'
        ], 500);
    }

    /**
     * POST /api/invoices/bulk-delete - Suppression groupée
     */
    public function bulkDelete()
    {
        $input = $this->request->getJSON(true);
        $ids = $input['ids'] ?? [];

        if (empty($ids)) {
            return $this->respond(['success' => false, 'message' => 'Aucune facture sélectionnée'], 400);
        }

        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            $invoice = $this->invoiceModel->find($id);
            if ($invoice && $invoice['payment_status'] !== 'paid' && $invoice['payment_status'] !== 'cancelled') {
                // Supprimer les pièces jointes
                $attachments = $this->db->table('invoice_attachments')
                    ->where('invoice_id', $id)
                    ->get()
                    ->getResultArray();

                foreach ($attachments as $att) {
                    if (file_exists($att['file_path'])) {
                        unlink($att['file_path']);
                    }
                }

                $this->db->table('invoice_attachments')->where('invoice_id', $id)->delete();
                $this->db->table('invoice_items')->where('invoice_id', $id)->delete();
                $this->db->table('invoice_payments')->where('invoice_id', $id)->delete();
                $this->invoiceModel->delete($id);
                $deleted++;
            } else {
                $errors[] = $id;
            }
        }

        return $this->respond([
            'success' => true,
            'message' => $deleted . ' facture(s) supprimée(s) avec succès',
            'deleted_count' => $deleted,
            'errors' => $errors
        ]);
    }

    /**
     * POST /api/invoices/bulk-sync - Synchronisation groupée EBMS
     */
    public function bulkSync()
    {
        if (!$this->isEbmsInvoiceSyncEnabled()) {
            return $this->respond(['success' => false, 'message' => 'La synchronisation EBMS des factures est désactivée'], 400);
        }

        $input = $this->request->getJSON(true);
        $ids = $input['ids'] ?? [];

        if (empty($ids)) {
            return $this->respond(['success' => false, 'message' => 'Aucune facture sélectionnée'], 400);
        }

        $successCount = 0;
        $errors = [];

        foreach ($ids as $id) {
            $invoice = $this->invoiceModel->find($id);
            if ($invoice && $invoice['payment_status'] !== 'cancelled') {
                try {
                    // Appel à la méthode de synchronisation
                    $result = $this->syncWithEBMSInternal($id);
                    if ($result['success']) {
                        $successCount++;
                    } else {
                        $errors[] = ['id' => $id, 'error' => $result['message']];
                    }
                } catch (\Exception $e) {
                    $errors[] = ['id' => $id, 'error' => $e->getMessage()];
                }
            } else {
                $errors[] = ['id' => $id, 'error' => 'Facture annulée ou inexistante'];
            }
        }

        return $this->respond([
            'success' => true,
            'message' => "{$successCount} facture(s) synchronisée(s)",
            'success_count' => $successCount,
            'error_count' => count($errors),
            'errors' => $errors
        ]);
    }

    /**
     * Synchronisation EBMS interne (pour bulk)
     */
    private function syncWithEBMSInternal($id)
    {
        if (!$this->isEbmsInvoiceSyncEnabled()) {
            return ['success' => false, 'message' => 'La synchronisation EBMS des factures est désactivée'];
        }

        $invoice = $this->invoiceModel->getInvoiceWithDetails($id);

        if (!$invoice) {
            return ['success' => false, 'message' => 'Facture non trouvée'];
        }

        return $this->performEbmsSync($invoice);
    }

    /**
     * GET /api/invoices/export/csv - Export CSV
     */
    public function exportCSV()
    {
        $filters = [
            'date_from' => $this->request->getVar('date_from'),
            'date_to' => $this->request->getVar('date_to')
        ];

        $invoices = $this->invoiceModel->getInvoicesForExport($filters);

        if (empty($invoices)) {
            return $this->respond(['success' => false, 'message' => 'Aucune donnée à exporter'], 404);
        }

        $filename = 'invoices_' . date('Y-m-d_His') . '.csv';

        // En-têtes CSV
        $headers = [
            'N° Facture',
            'Client',
            'TIN Client',
            'Date',
            'Date Échéance',
            'Type',
            'Sous-total',
            'Remise',
            'TVA',
            'Frais Livraison',
            'Total',
            'Payé',
            'Reste',
            'Statut',
            'Statut Paiement',
            'Statut EBMS'
        ];

        $rows = [];
        foreach ($invoices as $inv) {
            $rows[] = [
                $inv['invoice_number'],
                $inv['customer_name'],
                $inv['customer_TIN'] ?? '-',
                date('d/m/Y', strtotime($inv['invoice_date'])),
                $inv['due_date'] ? date('d/m/Y', strtotime($inv['due_date'])) : '-',
                $inv['invoice_type'],
                $inv['subtotal'] ?? 0,
                $inv['discount_total'] ?? 0,
                $inv['vat_amount'] ?? 0,
                $inv['shipping_amount'] ?? 0,
                $inv['total_amount'] ?? 0,
                $inv['paid_amount'] ?? 0,
                ($inv['total_amount'] - ($inv['paid_amount'] ?? 0)),
                $inv['payment_status'],
                $inv['payment_status'],
                $inv['ebms_status']
            ];
        }

        // Générer le CSV
        $output = fopen('php://temp', 'w');
        fputcsv($output, $headers);
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody("\xEF\xBB\xBF" . $csvContent);
    }

    /**
     * GET /api/invoices/verify/(:any) - Vérifier une facture par numéro
     */
    public function verify($invoiceNumber = null)
    {
        $invoice = $this->invoiceModel->getByInvoiceNumber($invoiceNumber);

        if (!$invoice) {
            return view('verify_error', ['message' => 'Facture non trouvée']);
        }

        // Ajouter les items et paiements
        $invoice['items'] = $this->db->table('invoice_items')
            ->where('invoice_id', $invoice['id'])
            ->get()
            ->getResultArray();

        $invoice['payments'] = $this->db->table('invoice_payments')
            ->where('invoice_id', $invoice['id'])
            ->get()
            ->getResultArray();

        $invoice['paid_amount'] = array_sum(array_column($invoice['payments'], 'amount'));

        return view('verify_invoice', ['invoice' => $invoice]);
    }
    
    // ========== MÉTHODES PRIVÉES (TEMPLATES, PDF, ETC.) ==========

    /**
     * Génère le PDF d'une facture
     */
    private function generateInvoicePDF($invoice)
    {
        $dompdf = new \Dompdf\Dompdf();
        $html = view('pdf/invoice_template', ['invoice' => $invoice]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    /**
     * Template d'email pour l'envoi de facture
     */
    private function getEmailTemplate($invoice)
    {

        $settings = $this->settingsModel->getAllSettings();

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 30px; text-align: center; }
                .content { padding: 30px; }
                .invoice-details { background: #f8fafc; padding: 20px; border-radius: 10px; margin: 20px 0; }
                .amount { font-size: 24px; color: #667eea; font-weight: bold; }
                .footer { background: #f1f5f9; padding: 15px; text-align: center; font-size: 12px; color: #666; }
                .btn { display: inline-block; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h2>Stock Management System</h2>
                <h3>Facture {$invoice['invoice_number']}</h3>
            </div>
            <div class='content'>
                <p>Bonjour <strong>{$invoice['customer_name']}</strong>,</p>
                <p>Veuillez trouver ci-joint votre facture n° <strong>{$invoice['invoice_number']}</strong>.</p>
                
                <div class='invoice-details'>
                    <p><strong>Montant total:</strong> <span class='amount'>" . number_format($invoice['total_amount'], 0, ',', ' ') . " FBu</span></p>
                    <p><strong>Date d'échéance:</strong> " . date('d/m/Y', strtotime($invoice['due_date'] ?? $invoice['invoice_date'])) . "</p>
                    <p><strong>Statut:</strong> " . ucfirst($invoice['payment_status']) . "</p>
                </div>
                
                <p>Pour toute question relative à cette facture, n'hésitez pas à nous contacter.</p>
                <p>Cordialement,<br><strong></strong></p>
            </div>
            <div class='footer'>
                <p>Ce message est généré automatiquement, merci de ne pas y répondre.</p>
                <p>&copy; " . date('Y') . " Stock Management System - Tous droits réservés</p>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * Template d'email pour le rappel de paiement
     */
    private function getReminderTemplate($invoice, $remainingAmount, $dueDate)
    {
        $daysOverdue = max(0, (int)((time() - strtotime($dueDate)) / (60 * 60 * 24)));
        $urgencyClass = $daysOverdue > 30 ? 'critical' : ($daysOverdue > 15 ? 'warning' : 'info');

        $urgencyText = '';
        if ($daysOverdue > 30) {
            $urgencyText = '<span style="color: #dc2626; font-weight: bold;">⚠️ CRITIQUE - Retard important</span>';
        } elseif ($daysOverdue > 15) {
            $urgencyText = '<span style="color: #f59e0b; font-weight: bold;">⚠️ Urgent - Dépassement de délai</span>';
        } elseif ($daysOverdue > 0) {
            $urgencyText = '<span style="color: #f59e0b;">⚠️ En retard de ' . $daysOverdue . ' jours</span>';
        } else {
            $urgencyText = '<span style="color: #3b82f6;">📅 Échéance dans ' . abs($daysOverdue) . ' jours</span>';
        }

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: linear-gradient(135deg, #f59e0b, #dc2626); color: white; padding: 30px; text-align: center; }
                .content { padding: 30px; }
                .reminder-box { background: #fef3c7; padding: 20px; border-radius: 10px; margin: 20px 0; border-left: 4px solid #f59e0b; }
                .amount { font-size: 28px; color: #dc2626; font-weight: bold; }
                .footer { background: #f1f5f9; padding: 15px; text-align: center; font-size: 12px; color: #666; }
                .btn-pay { display: inline-block; padding: 12px 30px; background: #10b981; color: white; text-decoration: none; border-radius: 5px; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h2>⚠️ Rappel de paiement</h2>
                <h3>Facture {$invoice['invoice_number']}</h3>
            </div>
            <div class='content'>
                <p>Bonjour <strong>{$invoice['customer_name']}</strong>,</p>
                
                <div class='reminder-box'>
                    <p>{$urgencyText}</p>
                    <p><strong>Montant restant dû:</strong> <span class='amount'>" . number_format($remainingAmount, 0, ',', ' ') . " FBu</span></p>
                    <p><strong>Date d'échéance:</strong> " . date('d/m/Y', strtotime($dueDate)) . "</p>
                    <p><strong>Facture n°:</strong> {$invoice['invoice_number']}</p>
                </div>
                
                <p>Nous vous rappelons que votre facture arrive à échéance. Merci de bien vouloir procéder à son règlement dans les meilleurs délais.</p>
                
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='#' class='btn-pay'>💰 Effectuer le paiement en ligne</a>
                </p>
                
                <p>Si vous avez déjà effectué le paiement, merci de ne pas tenir compte de ce message.</p>
                <p>Cordialement,<br><strong></strong></p>
            </div>
            <div class='footer'>
                <p>Ceci est un rappel automatique. En cas de litige, veuillez nous contacter.</p>
                <p>&copy; " . date('Y') . " Stock Management System</p>
            </div>
        </body>
        </html>
        ";
    }
}
