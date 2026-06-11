<?php
// app/Controllers/SalesDashboardController.php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class SalesDashboardController extends ResourceController
{
    use ResponseTrait;

    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * GET /api/sales-dashboard - Tableau de bord des ventes
     */
    public function index()
    {
        try {
            $date = $this->request->getGet('date') ?? date('Y-m-d');

            // Récupérer les entrepôts
            $warehouses = $this->db->table('warehouses')
                ->where('is_active', 1)
                ->get()
                ->getResultArray();

            $warehouseSales = [];
            $totalSales = 0;
            $totalCollected = 0;
            $totalBanked = 0;
            $totalCashInHand = 0;

            foreach ($warehouses as $warehouse) {
                // Ventes du jour pour cet entrepôt
                $sales = $this->db->table('invoices')
                    ->selectSum('total_amount')
                    ->where('DATE(created_at)', $date)
                    ->where('warehouse_id', $warehouse['id'])
                    ->where('payment_status !=', 'cancelled')
                    ->get()
                    ->getRow();

                $dailySales = $sales->total_amount ?? 0;
                $totalSales += $dailySales;

                // Collectes pour cet entrepôt
                $collection = $this->db->table('cash_collections')
                    ->where('warehouse_id', $warehouse['id'])
                    ->where('DATE(collection_date)', $date)
                    ->orderBy('id', 'DESC')
                    ->get()
                    ->getRow();

                $collectedAmount = (float) ($collection->amount ?? 0);
                $collectedStatus = $collection->status ?? 'pending';
                $collectorName = null;

                $totalCollected += $collectedAmount;

                if ($collection && $collection->collected_by) {
                    $collector = $this->db->table('users')
                        ->select('full_name, username')
                        ->where('id', $collection->collected_by)
                        ->get()
                        ->getRow();
                    $collectorName = $collector->full_name ?? $collector->username;
                }

                // Argent en caisse (collecté mais pas encore déposé)
                if ($collectedStatus === 'collected') {
                    $totalCashInHand += $collectedAmount;
                }

                // Dépôts bancaires
                $bankedAmount = 0;
                $bankReference = null;
                $bankSlipPath = null;
                $bankDepositDate = null;

                if ($collection && $collection->status === 'banked') {
                    $bankedAmount = $collection->amount;
                    $bankReference = $collection->bank_reference;
                    $bankSlipPath = $collection->bank_slip_path;
                    $bankDepositDate = $collection->bank_deposit_date;
                    $totalBanked += $bankedAmount;
                }

                $warehouseSales[] = [
                    'id' => $warehouse['id'],
                    'name' => $warehouse['name'],
                    'manager_name' => $warehouse['manager_name'] ?? 'Non défini',
                    'daily_sales' => $dailySales,
                    'collected_amount' => $collectedAmount,
                    'collected_status' => $collectedStatus,
                    'collector_name' => $collectorName,
                    'banked_amount' => $bankedAmount,
                    'bank_reference' => $bankReference,
                    'bank_slip_path' => $bankSlipPath,
                    'bank_deposit_date' => $bankDepositDate
                ];
            }

            // Récupérer le rapport quotidien
            $dailyReport = $this->db->table('daily_reports')
                ->where('report_date', $date)
                ->get()
                ->getRowArray();

            $pendingCollection = $totalSales - $totalCollected;
            $completionRate = $totalSales > 0 ? round(($totalCollected / $totalSales) * 100, 1) : 0;

            return $this->respond([
                'success' => true,
                'data' => [
                    'date' => $date,
                    'warehouses' => $warehouseSales,
                    'stats' => [
                        'total_sales' => $totalSales,
                        'total_collected' => $totalCollected,
                        'total_banked' => $totalBanked,
                        'total_cash_in_hand' => $totalCashInHand,
                        'pending_collection' => $pendingCollection,
                        'completion_rate' => $completionRate
                    ],
                    'daily_report' => $dailyReport
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'SalesDashboard error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/sales-dashboard/collect - Marquer comme collecté
     */
    public function collect()
    {
        try {
            $input = $this->request->getJSON(true);

            $collectionNumber = 'COL-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $collectionDate = !empty($input['collection_date'])
                ? date('Y-m-d', strtotime($input['collection_date']))
                : date('Y-m-d');

            // Vérifier si une collecte existe déjà
            $existing = $this->db->table('cash_collections')
                ->where('warehouse_id', $input['warehouse_id'])
                ->where('DATE(collection_date)', $collectionDate)
                ->get()
                ->getRow();

            if ($existing) {
                // Mettre à jour
                $this->db->table('cash_collections')
                    ->where('id', $existing->id)
                    ->update([
                        'amount' => $input['amount'],
                        'collected_by' => $input['collected_by'] ?? session()->get('user_id'),
                        'status' => 'collected',
                        'collection_date' => $collectionDate . ' ' . date('H:i:s')
                    ]);
            } else {
                // Insérer nouvelle collecte
                $data = [
                    'collection_number' => $collectionNumber,
                    'warehouse_id' => $input['warehouse_id'],
                    'amount' => $input['amount'],
                    'collected_by' => $input['collected_by'] ?? session()->get('user_id'),
                    'status' => 'collected',
                    'collection_date' => $collectionDate . ' ' . date('H:i:s')
                ];
                $this->db->table('cash_collections')->insert($data);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Collecte enregistrée avec succès'
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/sales-dashboard/bank-deposit - Dépôt bancaire
     */
    public function bankDeposit()
    {
        try {
            $warehouseId = $this->request->getPost('warehouse_id');
            $amount = $this->request->getPost('amount');
            $bankReference = $this->request->getPost('bank_reference');
            $collectionDate = $this->request->getPost('date') ?? date('Y-m-d');

            // Upload du justificatif
            $bankSlipPath = null;
            $file = $this->request->getFile('bank_slip');
            if ($file && $file->isValid() && !$file->hasMoved()) {
                $uploadPath = ROOTPATH . 'public/uploads/bank_slips/';
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath, 0777, true);
                }
                $filename = 'bank_slip_' . date('Ymd_His') . '_' . $warehouseId . '.' . $file->getExtension();
                $file->move($uploadPath, $filename);
                $bankSlipPath = 'uploads/bank_slips/' . $filename;
            }

            // Vérifier si une collecte existe
            $collection = $this->db->table('cash_collections')
                ->where('warehouse_id', $warehouseId)
                ->where('DATE(collection_date)', $collectionDate)
                ->get()
                ->getRow();

            if ($collection) {
                $this->db->table('cash_collections')
                    ->where('id', $collection->id)
                    ->update([
                        'status' => 'banked',
                        'bank_deposit_date' => $collectionDate . ' ' . date('H:i:s'),
                        'bank_reference' => $bankReference,
                        'bank_slip_path' => $bankSlipPath
                    ]);
            } else {
                // Créer une collecte directe
                $collectionNumber = 'COL-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $this->db->table('cash_collections')->insert([
                    'collection_number' => $collectionNumber,
                    'warehouse_id' => $warehouseId,
                    'amount' => $amount,
                    'collected_by' => session()->get('user_id'),
                    'status' => 'banked',
                    'bank_deposit_date' => $collectionDate . ' ' . date('H:i:s'),
                    'bank_reference' => $bankReference,
                    'bank_slip_path' => $bankSlipPath,
                    'collection_date' => $collectionDate . ' ' . date('H:i:s')
                ]);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Dépôt bancaire enregistré avec succès'
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/sales-dashboard/generate-report - Générer rapport quotidien
     */
    /*public function generateReport()
    {
        try {
            $input = $this->request->getJSON(true);
            $date = $input['date'] ?? date('Y-m-d');
            
            // Upload du rapport
            $attachmentPath = null;
            $file = $this->request->getFile('attachment');
            if ($file && $file->isValid() && !$file->hasMoved()) {
                $uploadPath = ROOTPATH . 'public/uploads/reports/';
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath, 0777, true);
                }
                $filename = 'daily_report_' . date('Ymd') . '.' . $file->getExtension();
                $file->move($uploadPath, $filename);
                $attachmentPath = 'uploads/reports/' . $filename;
            }
            
            // Calculer les totaux
            $totalSales = $this->db->table('invoices')
                ->selectSum('total_amount')
                ->where('DATE(created_at)', $date)
                ->where('payment_status !=', 'cancelled')
                ->get()
                ->getRow();
            
            $totalCollected = $this->db->table('cash_collections')
                ->selectSum('amount')
                ->where('DATE(collection_date)', $date)
                ->where('status', 'collected')
                ->get()
                ->getRow();
            
            $totalBanked = $this->db->table('cash_collections')
                ->selectSum('amount')
                ->where('DATE(bank_deposit_date)', $date)
                ->where('status', 'banked')
                ->get()
                ->getRow();
            
            $reportData = [
                'report_date' => $date,
                'total_sales' => $totalSales->total_amount ?? 0,
                'total_collected' => $totalCollected->amount ?? 0,
                'total_banked' => $totalBanked->amount ?? 0,
                'difference' => ($totalSales->total_amount ?? 0) - ($totalBanked->amount ?? 0),
                'status' => 'approved',
                'attachment_path' => $attachmentPath,
                'approved_by' => session()->get('user_id'),
                'approved_at' => date('Y-m-d H:i:s')
            ];
            
            // Vérifier si un rapport existe déjà
            $existing = $this->db->table('daily_reports')
                ->where('report_date', $date)
                ->get()
                ->getRow();
            
            if ($existing) {
                $this->db->table('daily_reports')
                    ->where('id', $existing->id)
                    ->update($reportData);
            } else {
                $this->db->table('daily_reports')->insert($reportData);
            }
            
            return $this->respond([
                'success' => true,
                'message' => 'Rapport quotidien généré avec succès'
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }*/

    public function generateReport()
    {
        try {
            // Récupérer les données du formulaire
            $date = $this->request->getPost('date') ?? date('Y-m-d');

            // Upload du justificatif
            $attachmentPath = null;
            $file = $this->request->getFile('attachment');

            if ($file && $file->isValid() && !$file->hasMoved()) {
                $uploadPath = ROOTPATH . 'public/uploads/reports/';
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath, 0777, true);
                }
                $filename = 'daily_report_' . date('Ymd_His') . '.' . $file->getExtension();
                $file->move($uploadPath, $filename);
                $attachmentPath = 'uploads/reports/' . $filename;
            }

            // Calculer les totaux
            $totalSales = $this->db->table('invoices')
                ->selectSum('total_amount')
                ->where('DATE(created_at)', $date)
                ->where('payment_status !=', 'cancelled')
                ->get()
                ->getRow();

            $totalCollected = $this->db->table('cash_collections')
                ->selectSum('amount')
                ->where('DATE(collection_date)', $date)
                ->where('status', 'collected')
                ->get()
                ->getRow();

            $totalBanked = $this->db->table('cash_collections')
                ->selectSum('amount')
                ->where('DATE(bank_deposit_date)', $date)
                ->where('status', 'banked')
                ->get()
                ->getRow();

            $reportData = [
                'report_date' => $date,
                'total_sales' => $totalSales->total_amount ?? 0,
                'total_collected' => $totalCollected->amount ?? 0,
                'total_banked' => $totalBanked->amount ?? 0,
                'difference' => ($totalSales->total_amount ?? 0) - ($totalBanked->amount ?? 0),
                'status' => 'approved',
                'attachment_path' => $attachmentPath,
                'approved_by' => session()->get('user_id'),
                'approved_at' => date('Y-m-d H:i:s'),
                'created_by' => session()->get('user_id'),
                'created_at' => date('Y-m-d H:i:s')
            ];

            // Vérifier si un rapport existe déjà
            $existing = $this->db->table('daily_reports')
                ->where('report_date', $date)
                ->get()
                ->getRow();

            if ($existing) {
                unset($reportData['created_at']);
                unset($reportData['created_by']);
                $this->db->table('daily_reports')
                    ->where('id', $existing->id)
                    ->update($reportData);
            } else {
                $this->db->table('daily_reports')->insert($reportData);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Rapport quotidien généré avec succès'
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Generate report error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Dans SalesDashboardController.php - Ajouter ces méthodes

    /**
     * POST /api/sales-dashboard/daily-collection - Collecte quotidienne par entrepôt
     */
    public function dailyCollection()
    {
        try {
            $input = $this->request->getJSON(true);

            $warehouseId = $input['warehouse_id'];
            $amount = $input['amount'];
            $collectedBy = $input['collected_by'] ?? session()->get('user_id');
            $paymentMethod = $input['payment_method'] ?? 'cash';

            // Vérifier les ventes du jour
            $dailySales = $this->db->table('invoices')
                ->selectSum('total_amount')
                ->where('warehouse_id', $warehouseId)
                ->where('DATE(created_at)', date('Y-m-d'))
                ->where('payment_status !=', 'cancelled')
                ->get()
                ->getRow();

            if ($amount > ($dailySales->total_amount ?? 0)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Le montant collecté ne peut pas dépasser les ventes du jour'
                ], 400);
            }

            // Vérifier si une collecte existe déjà
            $existingCollection = $this->db->table('cash_collections')
                ->where('warehouse_id', $warehouseId)
                ->where('DATE(collection_date)', date('Y-m-d'))
                ->get()
                ->getRow();

            if ($existingCollection) {
                // Mettre à jour la collecte existante
                $this->db->table('cash_collections')
                    ->where('id', $existingCollection->id)
                    ->update([
                        'amount' => $amount,
                        'collected_by' => $collectedBy,
                        'payment_method' => $paymentMethod,
                        'status' => 'collected',
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            } else {
                // Créer une nouvelle collecte
                $collectionNumber = 'COL-' . date('Ymd') . '-' . str_pad($warehouseId, 4, '0', STR_PAD_LEFT);
                $this->db->table('cash_collections')->insert([
                    'collection_number' => $collectionNumber,
                    'warehouse_id' => $warehouseId,
                    'collection_date' => date('Y-m-d H:i:s'),
                    'amount' => $amount,
                    'collected_by' => $collectedBy,
                    'payment_method' => $paymentMethod,
                    'status' => 'collected',
                    'created_by' => $collectedBy
                ]);
            }

            // Log de l'opération
            $this->logOperation(
                'COLLECTION',
                'cash_collections',
                null,
                "Collecte de {$amount} FBu pour l'entrepôt {$warehouseId}"
            );

            return $this->respond([
                'success' => true,
                'message' => 'Collecte enregistrée avec succès'
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/sales-dashboard/daily-bank-deposit - Dépôt bancaire quotidien
     */
    public function dailyBankDeposit()
    {
        try {
            $warehouseId = $this->request->getPost('warehouse_id');
            $bankReference = $this->request->getPost('bank_reference');
            $amount = $this->request->getPost('amount');

            // Upload du justificatif
            $bankSlipPath = null;
            $file = $this->request->getFile('bank_slip');
            if ($file && $file->isValid() && !$file->hasMoved()) {
                $uploadPath = ROOTPATH . 'public/uploads/bank_slips/';
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath, 0777, true);
                }
                $filename = 'bank_slip_' . date('Ymd_His') . '_' . $warehouseId . '.' . $file->getExtension();
                $file->move($uploadPath, $filename);
                $bankSlipPath = 'uploads/bank_slips/' . $filename;
            }

            // Récupérer la collecte du jour
            $collection = $this->db->table('cash_collections')
                ->where('warehouse_id', $warehouseId)
                ->where('DATE(collection_date)', date('Y-m-d'))
                ->get()
                ->getRow();

            if (!$collection) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Aucune collecte trouvée pour cet entrepôt aujourd\'hui'
                ], 400);
            }

            // Mettre à jour le statut
            $this->db->table('cash_collections')
                ->where('id', $collection->id)
                ->update([
                    'status' => 'banked',
                    'bank_deposit_date' => date('Y-m-d H:i:s'),
                    'bank_reference' => $bankReference,
                    'bank_slip_path' => $bankSlipPath,
                    'updated_by' => session()->get('user_id')
                ]);

            // Log de l'opération
            $this->logOperation(
                'BANK_DEPOSIT',
                'cash_collections',
                $collection->id,
                "Dépôt bancaire de {$amount} FBu - Réf: {$bankReference}"
            );

            return $this->respond([
                'success' => true,
                'message' => 'Dépôt bancaire enregistré avec succès'
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/sales-dashboard/auto-report - Génération automatique du rapport
     */
    public function autoReport()
    {
        try {
            $date = $this->request->getPost('date') ?? date('Y-m-d');

            // Appeler la procédure stockée
            $this->db->query("CALL sp_generate_daily_report('{$date}')");

            // Récupérer le rapport généré
            $report = $this->db->table('daily_reports')
                ->where('report_date', $date)
                ->get()
                ->getRowArray();

            return $this->respond([
                'success' => true,
                'message' => 'Rapport quotidien généré avec succès',
                'data' => $report
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Log des opérations
     */
    private function logOperation($type, $table, $recordId, $description)
    {
        $this->db->table('operation_logs')->insert([
            'operation_type' => $type,
            'table_name' => $table,
            'record_id' => $recordId,
            'description' => $description,
            'created_by' => session()->get('user_id'),
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
}
