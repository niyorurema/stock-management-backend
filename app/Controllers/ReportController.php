<?php
// app/Controllers/ReportController.php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class ReportController extends ResourceController
{
    use ResponseTrait;

    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * GET /api/reports/dashboard - Tableau de bord
     */
    public function dashboard()
    {
        try {
            $startDate = $this->request->getGet('start_date') ?? date('Y-m-01');
            $endDate = $this->request->getGet('end_date') ?? date('Y-m-t');

            // 1. Statistiques globales
            // D'abord, récupérer les totaux par statut
            $statsByStatus = $this->db->table('invoices')
                ->select('
        payment_status,
        COUNT(*) as count,
        COALESCE(SUM(total_amount), 0) as total,
        COALESCE(SUM(paid_amount), 0) as paid,
        COALESCE(SUM(total_amount - paid_amount), 0) as due
    ')
                ->where('status !=', 'cancelled')
                ->where('deleted_at IS NULL')
                ->where('DATE(created_at) >=', $startDate)
                ->where('DATE(created_at) <=', $endDate)
                ->groupBy('payment_status')
                ->get()
                ->getResultArray();

            // Calculer les totaux généraux
            $totalInvoices = 0;
            $totalRevenue = 0;
            $totalPaid = 0;
            $totalRemaining = 0;
            $pendingPayments = 0;
            $partialPayments = 0;

            foreach ($statsByStatus as $stat) {
                $totalInvoices += $stat['count'];
                $totalRevenue += $stat['total'];
                $totalPaid += $stat['paid'];
                $totalRemaining += $stat['due'];

                if ($stat['payment_status'] === 'pending') {
                    $pendingPayments = $stat['due'];
                }
                if ($stat['payment_status'] === 'partial') {
                    $partialPayments = $stat['due'];
                }
            }

            // Construire le résultat
            $statsResult = [
                'total_invoices' => $totalInvoices,
                'total_revenue' => $totalRevenue,
                'total_paid' => $totalPaid,
                'total_remaining' => $totalRemaining,
                'pending_payments' => $pendingPayments,
                'partial_payments' => $partialPayments
            ];

            $stats = $statsResult;
            //$statsQuery->getRowArray();

            $dailySales = $this->db->table('invoices')
                ->select("DATE(invoice_date) as sale_date, COUNT(*) as invoice_count, COALESCE(SUM(total_amount), 0) as daily_revenue")
                ->where('DATE(invoice_date) >=', $startDate)
                ->where('DATE(invoice_date) <=', $endDate)
                ->where('status !=', 'cancelled')
                ->groupBy('DATE(invoice_date)')
                ->orderBy('sale_date', 'ASC')
                ->get()
                ->getResultArray();

            // Compléter les stats
            $stats['total_customers'] = $this->db->table('customers')->where('is_active', 1)->countAllResults();
            $stats['total_products'] = $this->db->table('products')->where('is_active', 1)->countAllResults();
            $stats['total_suppliers'] = $this->db->table('suppliers')->where('is_active', 1)->countAllResults();

            // 2. Évolution mensuelle
            $monthlyQuery = $this->db->table('invoices')
                ->select("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as invoice_count, COALESCE(SUM(total_amount), 0) as total_amount")
                ->where('DATE(created_at) >=', $startDate)
                ->where('DATE(created_at) <=', $endDate)
                ->where('status !=', 'cancelled')
                ->where('deleted_at IS NULL')
                ->groupBy("DATE_FORMAT(created_at, '%Y-%m')")
                ->orderBy('month', 'ASC')
                ->get();

            $monthlyEvolution = $monthlyQuery->getResultArray();

            // 3. Statuts des factures - Version corrigée
            $statusQuery = $this->db->table('invoices')
                ->select('status as status, COUNT(*) as count')
                ->where('deleted_at IS NULL')
                ->where('DATE(created_at) >=', $startDate)
                ->where('DATE(created_at) <=', $endDate)
                ->groupBy('status')
                ->get();

            $invoiceStatuses = $statusQuery->getResultArray();

            // Si aucun résultat, ajouter des valeurs par défaut
            if (empty($invoiceStatuses)) {
                // Vérifier s'il y a des factures
                $hasInvoices = $this->db->table('invoices')->countAllResults() > 0;

                if ($hasInvoices) {
                    // Récupérer toutes les factures pour analyser les statuts
                    $allStatuses = $this->db->table('invoices')
                        ->select('status as status, COUNT(*) as count')
                        ->groupBy('status')
                        ->get()
                        ->getResultArray();

                    if (!empty($allStatuses)) {
                        $invoiceStatuses = $allStatuses;
                    } else {
                        // Valeurs par défaut pour l'affichage
                        $invoiceStatuses = [
                            ['status' => 'paid', 'count' => 0],
                            ['status' => 'pending', 'count' => 0],
                            ['status' => 'overdue', 'count' => 0],
                            ['status' => 'partial', 'count' => 0],
                            ['status' => 'cancelled', 'count' => 0]
                        ];
                    }
                } else {
                    $invoiceStatuses = [
                        ['status' => 'paid', 'count' => 0],
                        ['status' => 'pending', 'count' => 0],
                        ['status' => 'overdue', 'count' => 0],
                        ['status' => 'partial', 'count' => 0],
                        ['status' => 'cancelled', 'count' => 0]
                    ];
                }
            }



            // 4. Méthodes de paiement - Version corrigée
            $paymentQuery = $this->db->table('invoices')
                ->select('payment_method, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total_amount')
                ->where('DATE(invoice_date) >=', $startDate)
                ->where('DATE(invoice_date) <=', $endDate)
                ->where('payment_method IS NOT NULL')
                ->where('payment_method !=', '')
                ->groupBy('payment_method')
                ->get();

            $paymentMethods = $paymentQuery->getResultArray();

            // Si aucun résultat, essayer sans filtre de date
            if (empty($paymentMethods)) {
                $paymentQuery = $this->db->table('invoices')
                    ->select('payment_method, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total_amount')
                    ->where('payment_method IS NOT NULL')
                    ->where('payment_method !=', '')
                    ->groupBy('payment_method')
                    ->get();

                $paymentMethods = $paymentQuery->getResultArray();
            }

            // Transformer les données pour le frontend
            $paymentMethodsData = [];
            $methodIcons = [
                'cash' => '💵',
                'bank_transfer' => '🏦',
                'mobile_money' => '📱',
                'check' => '📝',
                'credit' => '💳'
            ];

            foreach ($paymentMethods as $method) {
                $paymentMethodsData[] = [
                    'method' => $method['payment_method'],
                    'icon' => $methodIcons[$method['payment_method']] ?? '💰',
                    'count' => (int)$method['count'],
                    'total_amount' => (float)$method['total_amount']
                ];
            }

            return $this->respond([
                'success' => true,
                'data' => [
                    'DAILY_EVOLUTION' => $dailySales,
                    'stats' => $stats,
                    'monthly_evolution' => $monthlyEvolution,
                    'invoice_statuses' => $invoiceStatuses,
                    'payment_methods' => $paymentMethodsData
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Dashboard error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/reports/daily-performance - Performance journalière avec filtre par entrepôt
     */
    public function getDailyPerformance()
    {
        try {
            $startDate = $this->request->getGet('start_date') ?? date('Y-m-01');
            $endDate = $this->request->getGet('end_date') ?? date('Y-m-d');
            $warehouseId = $this->request->getGet('warehouse_id');
            $groupByWarehouse = $this->request->getGet('group_by_warehouse') === 'true';

            // Requête principale pour les ventes et factures
            $builder = $this->db->table('invoices i')
                ->select("
                DATE(i.invoice_date) as date,
                " . ($groupByWarehouse ? "i.warehouse_id," : "") . "
                " . ($groupByWarehouse ? "w.name as warehouse_name," : "") . "
                COUNT(DISTINCT i.id) as invoice_count,
                COALESCE(SUM(i.total_amount), 0) as total_sales,
                COALESCE(SUM(i.vat_amount), 0) as vat_collected,
                COALESCE(SUM(i.ct_amount), 0) as ct_collected,
                COALESCE(SUM(i.tl_amount), 0) as tl_collected,
                COALESCE(SUM(i.shipping_amount), 0) as shipping_collected
            ")
                ->where('DATE(i.invoice_date) >=', $startDate)
                ->where('DATE(i.invoice_date) <=', $endDate)
                ->where('i.status !=', 'cancelled');

            if ($warehouseId && !$groupByWarehouse) {
                $builder->where('i.warehouse_id', $warehouseId);
            }

            if ($groupByWarehouse) {
                $builder->join('warehouses w', 'w.id = i.warehouse_id', 'left');
                $builder->groupBy('DATE(i.invoice_date), i.warehouse_id');
            } else {
                $builder->groupBy('DATE(i.invoice_date)');
            }

            $builder->orderBy('date', 'ASC');

            if ($groupByWarehouse) {
                $builder->orderBy('i.warehouse_id', 'ASC');
            }

            $dailyData = $builder->get()->getResultArray();

            // Requête pour les collectes d'argent (cash_collections)
            $collectionsBuilder = $this->db->table('cash_collections cc')
                ->select("
                DATE(cc.collection_date) as date,
                " . ($groupByWarehouse ? "cc.warehouse_id," : "") . "
                " . ($groupByWarehouse ? "w.name as warehouse_name," : "") . "
                COALESCE(SUM(cc.amount), 0) as total_collected,
                SUM(CASE WHEN cc.status = 'banked' THEN cc.amount ELSE 0 END) as total_banked
            ")
                ->where('DATE(cc.collection_date) >=', $startDate)
                ->where('DATE(cc.collection_date) <=', $endDate);

            if ($warehouseId && !$groupByWarehouse) {
                $collectionsBuilder->where('cc.warehouse_id', $warehouseId);
            }

            if ($groupByWarehouse) {
                $collectionsBuilder->join('warehouses w', 'w.id = cc.warehouse_id', 'left');
                $collectionsBuilder->groupBy('DATE(cc.collection_date), cc.warehouse_id');
                $collectionsBuilder->orderBy('cc.warehouse_id', 'ASC');
            } else {
                $collectionsBuilder->groupBy('DATE(cc.collection_date)');
            }

            $collectionsBuilder->orderBy('date', 'ASC');
            $collections = $collectionsBuilder->get()->getResultArray();

            // Fusionner les données
            $result = [];
            $collectionMap = [];

            foreach ($collections as $col) {
                if ($groupByWarehouse) {
                    $key = $col['date'] . '_' . $col['warehouse_id'];
                    $collectionMap[$key] = [
                        'collected' => (float)$col['total_collected'],
                        'banked' => (float)$col['total_banked']
                    ];
                } else {
                    $collectionMap[$col['date']] = [
                        'collected' => (float)$col['total_collected'],
                        'banked' => (float)$col['total_banked']
                    ];
                }
            }

            foreach ($dailyData as $day) {
                if ($groupByWarehouse) {
                    $key = $day['date'] . '_' . $day['warehouse_id'];
                    $item = [
                        'date' => $day['date'],
                        'formatted_date' => date('d/m/Y', strtotime($day['date'])),
                        'warehouse_id' => (int)$day['warehouse_id'],
                        'warehouse_name' => $day['warehouse_name'] ?? 'Entrepôt ' . $day['warehouse_id'],
                        'invoice_count' => (int)$day['invoice_count'],
                        'total_sales' => (float)$day['total_sales'],
                        'fees_collected' => $collectionMap[$key]['collected'] ?? 0,
                        //(float)($day['vat_collected'] + $day['ct_collected'] + $day['tl_collected'] + $day['shipping_collected']),
                        'fees_banked' => $collectionMap[$key]['banked'] ?? 0,
                        'amount_collected' => $collectionMap[$key]['collected'] ?? 0
                    ];
                    $result[] = $item;
                } else {
                    $result[] = [
                        'date' => $day['date'],
                        'formatted_date' => date('d/m/Y', strtotime($day['date'])),
                        'invoice_count' => (int)$day['invoice_count'],
                        'total_sales' => (float)$day['total_sales'],
                        'fees_collected' => $collectionMap[$day['date']]['collected'] ?? 0, //(float)($day['vat_collected'] + $day['ct_collected'] + $day['tl_collected'] + $day['shipping_collected']),
                        'fees_banked' => $collectionMap[$day['date']]['banked'] ?? 0,
                        'amount_collected' => $collectionMap[$day['date']]['collected'] ?? 0
                    ];
                }
            }

            // Récupérer la liste des entrepôts pour le filtre
            $warehouses = $this->db->table('warehouses')
                ->select('id, name, code')
                ->where('is_active', 1)
                ->orderBy('name', 'ASC')
                ->get()
                ->getResultArray();

            // Calculer les totaux
            $summary = [
                'total_sales' => array_sum(array_column($result, 'total_sales')),
                'total_fees_collected' => array_sum(array_column($result, 'fees_collected')),
                'total_fees_banked' => array_sum(array_column($result, 'fees_banked')),
                'total_invoices' => array_sum(array_column($result, 'invoice_count')),
                'avg_daily_sales' => count($result) > 0 ? array_sum(array_column($result, 'total_sales')) / count($result) : 0,
                'avg_daily_invoices' => count($result) > 0 ? array_sum(array_column($result, 'invoice_count')) / count($result) : 0,
                'collection_rate' => array_sum(array_column($result, 'total_sales')) > 0
                    ? (array_sum(array_column($result, 'fees_collected')) / array_sum(array_column($result, 'total_sales'))) * 100
                    : 0,
                'banking_rate' => array_sum(array_column($result, 'fees_collected')) > 0
                    ? (array_sum(array_column($result, 'fees_banked')) / array_sum(array_column($result, 'fees_collected'))) * 100
                    : 0
            ];

            //Regrouper par entrepôt si demandé
            $warehouseSummary = [];
            if ($groupByWarehouse) {
                foreach ($result as $item) {
                    $wid = $item['warehouse_id'];
                    if (!isset($warehouseSummary[$wid])) {
                        $warehouseSummary[$wid] = [
                            'warehouse_id' => $wid,
                            'warehouse_name' => $item['warehouse_name'],
                            'total_sales' => 0,
                            'total_fees_collected' => 0,
                            'total_fees_banked' => 0,
                            'total_invoices' => 0
                        ];
                    }
                    $warehouseSummary[$wid]['total_sales'] += $item['total_sales'];
                    $warehouseSummary[$wid]['total_fees_collected'] += $item['fees_collected'];
                    $warehouseSummary[$wid]['total_fees_banked'] += $item['fees_banked'];
                    $warehouseSummary[$wid]['total_invoices'] += $item['invoice_count'];
                }
                $warehouseSummary = array_values($warehouseSummary);
            }

            return $this->respond([
                'success' => true,
                'data' => [
                    'daily' => $result,
                    'summary' => $summary,
                    'warehouse_summary' => $warehouseSummary,
                    'warehouses' => $warehouses,
                    'period' => ['start' => $startDate, 'end' => $endDate],
                    'group_by_warehouse' => $groupByWarehouse
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Daily performance error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors du chargement des performances'
            ], 500);
        }
    }

    /**
     * GET /api/reports/daily-performance - Performance journalière
     */
   /* public function getDailyPerformance()
    {
        try {
            $startDate = $this->request->getGet('start_date') ?? date('Y-m-01');
            $endDate = $this->request->getGet('end_date') ?? date('Y-m-d');
            $warehouseId = $this->request->getGet('warehouse_id');

            // Requête principale pour les ventes et factures
            $builder = $this->db->table('invoices i')
                ->select("
                DATE(i.invoice_date) as date,
                COUNT(DISTINCT i.id) as invoice_count,
                COALESCE(SUM(i.total_amount), 0) as total_sales,
                COALESCE(SUM(i.vat_amount), 0) as vat_collected,
                COALESCE(SUM(i.ct_amount), 0) as ct_collected,
                COALESCE(SUM(i.tl_amount), 0) as tl_collected,
                COALESCE(SUM(i.shipping_amount), 0) as shipping_collected
            ")
                ->where('DATE(i.invoice_date) >=', $startDate)
                ->where('DATE(i.invoice_date) <=', $endDate)
                ->where('i.status !=', 'cancelled');

            if ($warehouseId) {
                $builder->where('i.warehouse_id', $warehouseId);
            }

            $dailyData = $builder->groupBy('DATE(i.invoice_date)')
                ->orderBy('date', 'ASC')
                ->get()
                ->getResultArray();

            // Requête pour les collectes d'argent (cash_collections)
            $collectionsData = $this->db->table('cash_collections cc')
                ->select("
                DATE(cc.collection_date) as date,
                COALESCE(SUM(cc.amount), 0) as total_collected,
                SUM(CASE WHEN cc.status = 'banked' THEN cc.amount ELSE 0 END) as total_banked
            ")
                ->where('DATE(cc.collection_date) >=', $startDate)
                ->where('DATE(cc.collection_date) <=', $endDate);

            if ($warehouseId) {
                $collectionsData->where('cc.warehouse_id', $warehouseId);
            }

            $collections = $collectionsData->groupBy('DATE(cc.collection_date)')
                ->get()
                ->getResultArray();

            // Fusionner les données
            $result = [];
            $collectionMap = [];

            foreach ($collections as $col) {
                $collectionMap[$col['date']] = [
                    'collected' => (float)$col['total_collected'],
                    'banked' => (float)$col['total_banked']
                ];
            }

            foreach ($dailyData as $day) {
                $date = $day['date'];
                $result[] = [
                    'date' => $date,
                    'formatted_date' => date('d/m/Y', strtotime($date)),
                    'invoice_count' => (int)$day['invoice_count'],
                    'total_sales' => (float)$day['total_sales'],
                    'fees_collected' => (float)($day['vat_collected'] + $day['ct_collected'] + $day['tl_collected'] + $day['shipping_collected']),
                    'fees_banked' => $collectionMap[$date]['banked'] ?? 0,
                    'amount_collected' => $collectionMap[$date]['collected'] ?? 0,
                    'vat_amount' => (float)$day['vat_collected'],
                    'ct_amount' => (float)$day['ct_collected'],
                    'tl_amount' => (float)$day['tl_collected'],
                    'shipping_amount' => (float)$day['shipping_collected']
                ];
            }

            // Calculer les totaux
            $summary = [
                'total_sales' => array_sum(array_column($result, 'total_sales')),
                'total_fees_collected' => array_sum(array_column($result, 'fees_collected')),
                'total_fees_banked' => array_sum(array_column($result, 'fees_banked')),
                'total_invoices' => array_sum(array_column($result, 'invoice_count')),
                'avg_daily_sales' => count($result) > 0 ? array_sum(array_column($result, 'total_sales')) / count($result) : 0,
                'avg_daily_invoices' => count($result) > 0 ? array_sum(array_column($result, 'invoice_count')) / count($result) : 0,
                'collection_rate' => array_sum(array_column($result, 'total_sales')) > 0
                    ? (array_sum(array_column($result, 'fees_collected')) / array_sum(array_column($result, 'total_sales'))) * 100
                    : 0,
                'banking_rate' => array_sum(array_column($result, 'fees_collected')) > 0
                    ? (array_sum(array_column($result, 'fees_banked')) / array_sum(array_column($result, 'fees_collected'))) * 100
                    : 0
            ];

            return $this->respond([
                'success' => true,
                'data' => [
                    'daily' => $result,
                    'summary' => $summary,
                    'period' => ['start' => $startDate, 'end' => $endDate]
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Daily performance error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors du chargement des performances'
            ], 500);
        }
    }*/

    /**
     * POST /api/reports/performance - Rapport de performance
     */
    public function performance()
    {
        try {
            $input = $this->request->getJSON(true);
            $startDate = $input['start_date'] ?? date('Y-m-01');
            $endDate = $input['end_date'] ?? date('Y-m-t');

            $data = [];

            // 1. VENTES
            $ventesQuery = $this->db->table('invoices')
                ->select('
                    COUNT(DISTINCT id) as total_invoices,
                    COALESCE(SUM(total_amount), 0) as total_revenue,
                    COALESCE(AVG(total_amount), 0) as avg_invoice_value,
                    COALESCE(SUM(vat_amount), 0) as total_vat,
                    COUNT(DISTINCT customer_name) as unique_customers
                ')
                ->where('DATE(created_at) >=', $startDate)
                ->where('DATE(created_at) <=', $endDate)
                ->where('payment_status !=', 'cancelled')
                ->get();

            $data['VENTES'] = $ventesQuery->getResultArray();

            // 2. TOP PRODUITS
            $topProductsQuery = $this->db->table('invoice_items ii')
                ->select('p.name as product_name, COALESCE(SUM(ii.quantity), 0) as quantity_sold, COALESCE(SUM(ii.total_amount), 0) as revenue')
                ->join('products p', 'p.id = ii.product_id')
                ->join('invoices i', 'i.id = ii.invoice_id')
                ->where('DATE(i.created_at) >=', $startDate)
                ->where('DATE(i.created_at) <=', $endDate)
                ->where('i.payment_status !=', 'cancelled')
                ->groupBy('p.id, p.name')
                ->orderBy('revenue', 'DESC')
                ->limit(10)
                ->get();

            $data['TOP_PRODUCTS'] = $topProductsQuery->getResultArray();

            // 3. TOP CLIENTS
            $topCustomersQuery = $this->db->table('invoices i')
                ->select('i.customer_name, COUNT(DISTINCT i.id) as invoice_count, COALESCE(SUM(i.total_amount), 0) as total_spent, COALESCE(AVG(i.total_amount), 0) as avg_invoice')
                ->where('DATE(i.created_at) >=', $startDate)
                ->where('DATE(i.created_at) <=', $endDate)
                ->where('i.payment_status !=', 'cancelled')
                ->where('i.customer_name IS NOT NULL')
                ->where('i.customer_name !=', '')
                ->groupBy('i.customer_name')
                ->orderBy('total_spent', 'DESC')
                ->limit(10)
                ->get();

            $data['TOP_CUSTOMERS'] = $topCustomersQuery->getResultArray();

            // 4. ÉVOLUTION JOURNALIÈRE
            $dailyQuery = $this->db->table('invoices')
                ->select('DATE(created_at) as sale_date, COUNT(*) as invoice_count, COALESCE(SUM(total_amount), 0) as daily_revenue')
                ->where('DATE(created_at) >=', $startDate)
                ->where('DATE(created_at) <=', $endDate)
                ->where('payment_status !=', 'cancelled')
                ->groupBy('DATE(created_at)')
                ->orderBy('sale_date', 'ASC')
                ->get();

            $data['DAILY_EVOLUTION'] = $dailyQuery->getResultArray();

            // 5. MÉTHODES DE PAIEMENT
            $paymentMethodsQuery = $this->db->table('invoices')
                ->select("COALESCE(payment_method, 'non_specifie') as payment_method, COUNT(*) as usage_count, COALESCE(SUM(total_amount), 0) as total_amount")
                ->where('DATE(created_at) >=', $startDate)
                ->where('DATE(created_at) <=', $endDate)
                ->where('payment_status !=', 'cancelled')
                ->where('payment_method IS NOT NULL')
                ->groupBy('payment_method')
                ->get();

            $data['PAYMENT_METHODS'] = $paymentMethodsQuery->getResultArray();

            return $this->respond(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            log_message('error', 'Performance error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/reports/inventory - Rapport d'inventaire
     */
    public function inventory()
    {
        try {
            $input = $this->request->getJSON(true);
            $warehouseId = $input['warehouse_id'] ?? null;
            $criticalOnly = isset($input['critical_only']) ? (int)$input['critical_only'] : 0;

            $data = [];

            // 1. Résumé des produits
            $summaryQuery = $this->db->table('products')
                ->select('COUNT(*) as total_products, COALESCE(SUM(current_stock), 0) as total_items, COALESCE(SUM(current_stock * purchase_price), 0) as inventory_value_cost, COALESCE(SUM(current_stock * selling_price), 0) as inventory_value_sales')
                ->where('is_active', 1);

            if ($warehouseId) {
                $summaryQuery->where('warehouse_id', $warehouseId);
            }

            $data['summary'] = $summaryQuery->get()->getResultArray();

            // 2. Stock critique - Version corrigée sans CASE dans SELECT
            $stockQuery = $this->db->table('products')
                ->select('code, name, current_stock, min_stock_alert, unit')
                ->where('is_active', 1);

            if ($criticalOnly) {
                $stockQuery->where('current_stock <=', 'min_stock_alert', false)
                    ->where('min_stock_alert >', 0);
            }

            $products = $stockQuery->orderBy('current_stock', 'ASC')->limit(50)->get()->getResultArray();

            // Calculer les pourcentages et statuts en PHP
            foreach ($products as &$product) {
                // Déduire les réservations (confirmées ou partially_delivered) non livrées
                $reservedRow = $this->db->table('reservation_items ri')
                    ->selectSum('ri.quantity', 'qty')
                    ->selectSum('ri.delivered_quantity', 'del')
                    ->join('reservations r', 'r.id = ri.reservation_id')
                    ->where('ri.product_id', $product['id'] ?? null)
                    ->whereIn('r.status', ['confirmed', 'partially_delivered'])
                    ->get()
                    ->getRow();

                $reserved = (float) ($reservedRow->qty ?? 0) - (float) ($reservedRow->del ?? 0);
                if ($reserved < 0) $reserved = 0;

                $availableStock = max(0, (float)$product['current_stock'] - $reserved);

                if ($product['min_stock_alert'] > 0) {
                    $product['stock_percentage'] = ($availableStock / $product['min_stock_alert']) * 100;
                } else {
                    $product['stock_percentage'] = 100;
                }

                if ($availableStock == 0) {
                    $product['stock_status'] = 'RUPTURE';
                } elseif ($product['min_stock_alert'] > 0 && $availableStock <= $product['min_stock_alert'] / 2) {
                    $product['stock_status'] = 'CRITIQUE';
                } elseif ($product['min_stock_alert'] > 0 && $availableStock <= $product['min_stock_alert']) {
                    $product['stock_status'] = 'ALERTE';
                } else {
                    $product['stock_status'] = 'NORMAL';
                }

                $product['available_stock'] = $availableStock;
                $product['reserved_quantity'] = $reserved;
            }

            $data['stock_status'] = $products;

            // 3. Mouvements récents
            $movementsQuery = $this->db->table('stock_movements sm')
                ->select('sm.movement_date, sm.movement_type, p.name as product_name, sm.quantity, p.unit, sm.unit_cost, sm.total_cost')
                ->join('products p', 'p.id = sm.product_id')
                ->orderBy('sm.movement_date', 'DESC')
                ->limit(50)
                ->get();

            $data['recent_movements'] = $movementsQuery->getResultArray();

            return $this->respond(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            log_message('error', 'Inventory error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/reports/financial - Rapport financier
     */
    public function financial()
    {
        try {
            $input = $this->request->getJSON(true);
            $startDate = $input['start_date'] ?? date('Y-m-01');
            $endDate = $input['end_date'] ?? date('Y-m-t');

            $data = [];

            // 1. REVENUES
            $revenueQuery = $this->db->table('invoices')
                ->select('COALESCE(SUM(total_amount), 0) as total_revenue, COALESCE(SUM(subtotal), 0) as net_sales, COALESCE(SUM(vat_amount), 0) as vat_collected, COUNT(id) as invoice_count')
                ->where('DATE(created_at) >=', $startDate)
                ->where('DATE(created_at) <=', $endDate)
                ->where('payment_status !=', 'cancelled')
                ->get();

            $data['REVENUE'] = $revenueQuery->getResultArray();

            // 2. Créances
            $receivablesQuery = $this->db->table('invoices')
                ->select('COALESCE(SUM(total_amount), 0) as total_receivables, COUNT(id) as invoice_count, COALESCE(AVG(total_amount), 0) as avg_receivable, COALESCE(SUM(CASE WHEN payment_status = "overdue" THEN total_amount ELSE 0 END), 0) as overdue_receivables, COUNT(CASE WHEN payment_status = "overdue" THEN 1 END) as overdue_invoices')
                ->where('payment_status IN ("pending", "partial", "overdue")')
                ->get();

            $data['RECEIVABLES'] = $receivablesQuery->getResultArray();

            // 3. Agé des créances
            $agingQuery = $this->db->table('invoices')
                ->select("
                    CASE 
                        WHEN DATEDIFF(NOW(), created_at) <= 30 THEN '0-30 jours'
                        WHEN DATEDIFF(NOW(), created_at) <= 60 THEN '31-60 jours'
                        WHEN DATEDIFF(NOW(), created_at) <= 90 THEN '61-90 jours'
                        ELSE '90+ jours'
                    END as aging_bucket,
                    COUNT(id) as invoice_count,
                    COALESCE(SUM(total_amount), 0) as amount_due
                ")
                ->where('payment_status IN ("pending", "partial", "overdue")')
                ->groupBy('aging_bucket')
                ->get();

            $data['AGING_RECEIVABLES'] = $agingQuery->getResultArray();

            return $this->respond(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            log_message('error', 'Financial error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/reports/suppliers - Rapport des fournisseurs
     */
    public function suppliers()
    {
        try {
            $input = $this->request->getJSON(true);
            $startDate = $input['start_date'] ?? date('Y-m-01');
            $endDate = $input['end_date'] ?? date('Y-m-t');

            $data = [];

            // 1. Liste des fournisseurs
            $suppliersQuery = $this->db->table('suppliers')
                ->select('id, code, name, contact_person, email, phone, address, tin, payment_terms, is_active, created_at')
                ->where('is_active', 1)
                ->orderBy('name', 'ASC')
                ->get();

            $data['suppliers'] = $suppliersQuery->getResultArray();

            // 2. Statistiques par fournisseur
            foreach ($data['suppliers'] as &$supplier) {
                // Achats par fournisseur (via les factures)
                $purchasesQuery = $this->db->table('invoices')
                    ->select('COUNT(*) as purchase_count, COALESCE(SUM(total_amount), 0) as total_purchases')
                    ->where('DATE(created_at) >=', $startDate)
                    ->where('DATE(created_at) <=', $endDate)
                    ->where('payment_status !=', 'cancelled')
                    ->like('customer_name', $supplier['name'])
                    ->get();

                $purchases = $purchasesQuery->getRowArray();
                $supplier['purchase_count'] = $purchases['purchase_count'] ?? 0;
                $supplier['total_purchases'] = $purchases['total_purchases'] ?? 0;
            }

            // 3. Top fournisseurs
            $topSuppliersQuery = $this->db->table('invoices')
                ->select('customer_name as supplier_name, COUNT(*) as invoice_count, COALESCE(SUM(total_amount), 0) as total_amount')
                ->where('DATE(created_at) >=', $startDate)
                ->where('DATE(created_at) <=', $endDate)
                ->where('payment_status !=', 'cancelled')
                ->where('customer_name IS NOT NULL')
                ->groupBy('customer_name')
                ->orderBy('total_amount', 'DESC')
                ->limit(10)
                ->get();

            $data['top_suppliers'] = $topSuppliersQuery->getResultArray();

            return $this->respond(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            log_message('error', 'Suppliers error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/reports/export - Export
     */
    public function export()
    {
        try {
            $input = $this->request->getJSON(true);
            $reportType = $input['report_type'] ?? 'sales';
            $startDate = $input['start_date'] ?? date('Y-m-01');
            $endDate = $input['end_date'] ?? date('Y-m-t');
            $customerName = $input['customer_name'] ?? null;
            $productCode = $input['product_code'] ?? null;

            switch ($reportType) {
                case 'sales':
                    $data = $this->db->table('invoices i')
                        ->select('i.invoice_number, i.created_at as invoice_date, i.customer_name, i.total_amount, i.payment_status, i.ebms_status')
                        ->where('DATE(i.created_at) >=', $startDate)
                        ->where('DATE(i.created_at) <=', $endDate)
                        ->where('i.payment_status !=', 'cancelled')
                        ->orderBy('i.created_at', 'DESC');

                    if ($customerName) {
                        $data->like('i.customer_name', $customerName);
                    }

                    $data = $data->get()->getResultArray();
                    $filename = 'rapport_ventes_' . date('Ymd_His') . '.csv';
                    break;

                case 'products':
                    $data = $this->db->table('products')
                        ->select('code, name, unit, current_stock, min_stock_alert, selling_price')
                        ->where('is_active', 1)
                        ->orderBy('name', 'ASC');

                    if ($productCode) {
                        $data->where('code', $productCode);
                    }

                    $data = $data->get()->getResultArray();
                    $filename = 'rapport_produits_' . date('Ymd_His') . '.csv';
                    break;

                case 'suppliers':
                    $data = $this->db->table('suppliers')
                        ->select('code, name, contact_person, email, phone, address, tin, payment_terms')
                        ->where('is_active', 1)
                        ->orderBy('name', 'ASC')
                        ->get()
                        ->getResultArray();
                    $filename = 'rapport_fournisseurs_' . date('Ymd_His') . '.csv';
                    break;

                case 'stock':
                    $data = $this->db->table('products')
                        ->select('code, name, current_stock, min_stock_alert, unit, selling_price')
                        ->where('is_active', 1)
                        ->orderBy('current_stock', 'ASC')
                        ->get()
                        ->getResultArray();
                    $filename = 'rapport_stock_' . date('Ymd_His') . '.csv';
                    break;

                default:
                    return $this->respond(['success' => false, 'message' => 'Type non supporté'], 400);
            }

            if (empty($data)) {
                return $this->respond(['success' => false, 'message' => 'Aucune donnée à exporter'], 404);
            }

            // Générer CSV
            $output = fopen('php://temp', 'w');
            fputcsv($output, array_keys($data[0]));
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
            rewind($output);
            $csvContent = stream_get_contents($output);
            fclose($output);

            return $this->response
                ->setHeader('Content-Type', 'text/csv; charset=utf-8')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->setBody("\xEF\xBB\xBF" . $csvContent);
        } catch (\Exception $e) {
            log_message('error', 'Export error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/reports/quick-stats - Statistiques rapides
     */
    public function quickStats()
    {
        try {
            $today = date('Y-m-d');
            $thisMonth = date('Y-m-01');
            $thisYear = date('Y-01-01');

            // Ventes du jour
            $todayRevenue = $this->db->table('invoices')
                ->selectSum('total_amount')
                ->where('DATE(created_at)', $today)
                ->where('payment_status !=', 'cancelled')
                ->get()
                ->getRow();

            $todayInvoices = $this->db->table('invoices')
                ->where('DATE(created_at)', $today)
                ->where('payment_status !=', 'cancelled')
                ->countAllResults();

            // Ventes du mois
            $monthRevenue = $this->db->table('invoices')
                ->selectSum('total_amount')
                ->where('created_at >=', $thisMonth)
                ->where('payment_status !=', 'cancelled')
                ->get()
                ->getRow();

            $monthInvoices = $this->db->table('invoices')
                ->where('created_at >=', $thisMonth)
                ->where('payment_status !=', 'cancelled')
                ->countAllResults();

            // Ventes de l'année
            $yearRevenue = $this->db->table('invoices')
                ->selectSum('total_amount')
                ->where('created_at >=', $thisYear)
                ->where('payment_status !=', 'cancelled')
                ->get()
                ->getRow();

            // Stock critique
            $criticalStock = $this->db->table('products')
                ->where('current_stock <=', 'min_stock_alert', false)
                ->where('min_stock_alert >', 0)
                ->where('is_active', 1)
                ->countAllResults();

            // Fournisseurs actifs
            $activeSuppliers = $this->db->table('suppliers')
                ->where('is_active', 1)
                ->countAllResults();

            return $this->respond([
                'success' => true,
                'data' => [
                    'today' => [
                        'revenue' => $todayRevenue->total_amount ?? 0,
                        'invoices' => $todayInvoices
                    ],
                    'this_month' => [
                        'revenue' => $monthRevenue->total_amount ?? 0,
                        'invoices' => $monthInvoices
                    ],
                    'this_year' => [
                        'revenue' => $yearRevenue->total_amount ?? 0
                    ],
                    'critical_stock' => $criticalStock,
                    'active_suppliers' => $activeSuppliers
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/reports/orders - Rapport des commandes
     */
    public function orders()
    {
        try {
            $input = $this->request->getJSON(true);
            $startDate = $input['start_date'] ?? date('Y-m-01');
            $endDate = $input['end_date'] ?? date('Y-m-t');

            $data = [];

            // 1. Résumé des commandes (basé sur les factures)
            $summaryQuery = $this->db->table('invoices')
                ->select('
                COUNT(*) as total_orders,
                COALESCE(SUM(total_amount), 0) as total_value,
                COALESCE(AVG(total_amount), 0) as average_value,
                COUNT(CASE WHEN payment_status = "paid" THEN 1 END) as completed,
                COUNT(CASE WHEN payment_status = "pending" THEN 1 END) as pending,
                COUNT(CASE WHEN payment_status = "overdue" THEN 1 END) as overdue,
                COUNT(CASE WHEN payment_status = "cancelled" THEN 1 END) as cancelled
            ')
                ->where('DATE(created_at) >=', $startDate)
                ->where('DATE(created_at) <=', $endDate)
                ->get();

            $data['ORDERS_SUMMARY'] = $summaryQuery->getResultArray();

            // 2. Commandes par statut
            $statusQuery = $this->db->table('invoices')
                ->select('payment_status as status, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total_amount')
                ->where('DATE(created_at) >=', $startDate)
                ->where('DATE(created_at) <=', $endDate)
                ->groupBy('payment_status')
                ->get();

            $data['ORDERS_BY_STATUS'] = $statusQuery->getResultArray();

            // 3. Évolution des commandes
            $evolutionQuery = $this->db->table('invoices')
                ->select("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as order_count, COALESCE(SUM(total_amount), 0) as total_amount")
                ->where('DATE(created_at) >=', $startDate)
                ->where('DATE(created_at) <=', $endDate)
                ->groupBy("DATE_FORMAT(created_at, '%Y-%m')")
                ->orderBy('month', 'ASC')
                ->get();

            $data['ORDERS_EVOLUTION'] = $evolutionQuery->getResultArray();

            return $this->respond(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            log_message('error', 'Orders error: ' . $e->getMessage());
            return $this->respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
