<?php
// app/Models/PurchaseOrderModel.php

namespace App\Models;

use CodeIgniter\Model;

class PurchaseOrderModel extends Model
{
    protected $table = 'supplier_orders';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;
    protected $protectFields = true;

protected $allowedFields = [
    'order_number', 'supplier_id', 'order_date', 'expected_delivery_date',
    'status', 'priority', 'payment_status', 'payment_method',
    'currency', 'exchange_rate_aed_to_usd', 'exchange_rate_usd_to_bif',
    'subtotal_aed', 'subtotal_usd', 'subtotal_bif',
    'total_amount_aed', 'total_amount_usd', 'total_amount_bif',
    'total_expected_profit', 'notes', 'created_by','subtotal','total_amount'
];
    
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';
    
    public function generateOrderNumber()
    {
        $year = date('Y');
        $month = date('m');
        
        $lastOrder = $this->where('order_number LIKE', 'PO-' . $year . '-' . $month . '-%')
            ->orderBy('id', 'DESC')
            ->first();
        
        if ($lastOrder) {
            $parts = explode('-', $lastOrder['order_number']);
            $lastNumber = intval(end($parts));
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }
        
        return 'PO-' . $year .'-'. $month .'-' . $newNumber;
    }

    // app/Models/PurchaseOrderModel.php

public function getOrderWithDetails($id)
{
    $order = $this->find($id);
    
    if (!$order) {
        return null;
    }
    
    // Récupérer les items avec les informations du produit
    $items = $this->db->table('supplier_order_items soi')
        ->select('soi.*, p.name as product_name, p.code as product_code, p.unit')
        ->join('products p', 'p.id = soi.product_id')
        ->where('soi.order_id', $id)
        ->get()
        ->getResultArray();
    
    $order['items'] = $items;
    
    // Calculer les totaux
    $order['total_quantity'] = array_sum(array_column($items, 'quantity'));
    $order['total_received'] = array_sum(array_column($items, 'received_quantity'));
    $order['total_remaining'] = $order['total_quantity'] - $order['total_received'];
    $order['reception_rate'] = $order['total_quantity'] > 0 
        ? ($order['total_received'] / $order['total_quantity']) * 100 
        : 0;
    
    // Calculer les montants totaux
    $order['total_amount_aed'] = $order['total_amount_aed'] ?? $order['total_amount'] ?? 0;
    $order['total_amount_bif'] = $order['total_amount_bif'] ?? 0;
    $order['total_expected_profit'] = $order['total_expected_profit'] ?? 0;
    
    // Récupérer les réceptions avec leurs pièces jointes
    $receptions = $this->db->table('order_receptions or')
        ->select('or.*, u.username as received_by_name')
        ->join('users u', 'u.id = or.received_by', 'left')
        ->where('or.order_id', $id)
        ->orderBy('or.reception_date', 'DESC')
        ->get()
        ->getResultArray();
    
    foreach ($receptions as &$reception) {
        // Récupérer les items de la réception
        $reception['items'] = $this->db->table('reception_items ri')
            ->select('ri.*, p.name as product_name, p.unit')
            ->join('products p', 'p.id = ri.product_id')
            ->where('ri.reception_id', $reception['id'])
            ->get()
            ->getResultArray();
        
        // Récupérer les pièces jointes de la réception
        $reception['attachments'] = $this->db->table('reception_attachments ra')
            ->select('*')
            ->where('ra.reception_id', $reception['id'])
            ->get()
            ->getResultArray();
    }
    
    $order['receptions'] = $receptions;
    
    return $order;
}
    
    public function getOrdersWithFilters($filters = [], $page = 1, $limit = 10)
    {
        $builder = $this->select('supplier_orders.*, s.name as supplier_name')
            ->join('suppliers s', 's.id = supplier_orders.supplier_id', 'left')
            ->orderBy('supplier_orders.created_at', 'DESC');
        
        if (!empty($filters['order_number'])) {
            $builder->like('order_number', $filters['order_number']);
        }
        
        if (!empty($filters['supplier_id'])) {
            $builder->where('supplier_id', $filters['supplier_id']);
        }
        
        if (!empty($filters['status'])) {
            $builder->where('status', $filters['status']);
        }
        
        if (!empty($filters['date_from'])) {
            $builder->where('order_date >=', $filters['date_from']);
        }
        
        if (!empty($filters['date_to'])) {
            $builder->where('order_date <=', $filters['date_to'] . ' 23:59:59');
        }
        
        $total = $builder->countAllResults(false);
        
        $orders = $builder->limit($limit, ($page - 1) * $limit)
            ->get()
            ->getResultArray();
        
        // Ajouter les statistiques pour chaque commande
        foreach ($orders as &$order) {
            $items = $this->db->table('supplier_order_items')
                ->select('SUM(quantity) as total_quantity, SUM(received_quantity) as total_received')
                ->where('order_id', $order['id'])
                ->get()
                ->getRowArray();
            
            $order['total_quantity'] = $items['total_quantity'] ?? 0;
            $order['total_received'] = $items['total_received'] ?? 0;
            $order['reception_rate'] = $order['total_quantity'] > 0 
                ? ($order['total_received'] / $order['total_quantity']) * 100 
                : 0;
        }
        
        return [
            'data' => $orders,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($total / $limit)
            ]
        ];
    }
}