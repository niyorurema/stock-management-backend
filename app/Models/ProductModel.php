<?php
// app/Models/ProductModel.php

namespace App\Models;

use CodeIgniter\Model;
use App\Services\ReservationService;

class ProductModel extends Model
{
    protected $table = 'products';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'code',
        'name',
        'description',
        'category_id',
        'unit',
        'purchase_price',
        'purchase_price_aed',
        'purchase_price_usd',
        'purchase_price_bif',
        'avg_purchase_price_aed',
        'avg_purchase_price_usd',
        'avg_purchase_price_bif',
        'selling_price',
        'tax_rate',
        'ct_tax_rate',
        'tl_tax_rate',
        'tsce_tax',
        'ott_tax',
        'min_stock_alert',
        'current_stock',
        'is_active',
        'created_by_name'
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    // Récupérer les produits avec leurs catégories
    public function getProductsWithCategory($filters = [], $page = 1, $limit = 10)
    {
        $builder = $this->db->table('products p')
            ->select('p.*, c.name as category_name')
            ->join('product_categories c', 'c.id = p.category_id', 'left')
            ->where('p.deleted_at', null)
            ->orderBy('p.created_at', 'DESC');

        // Application des filtres
        if (!empty($filters['code'])) {
            $builder->like('p.code', $filters['code']);
        }

        if (!empty($filters['name'])) {
            $builder->like('p.name', $filters['name']);
        }

        if (!empty($filters['category_id'])) {
            $builder->where('p.category_id', $filters['category_id']);
        }

        if (!empty($filters['min_stock'])) {
            $builder->where('p.current_stock <=', $filters['min_stock']);
        }

        if (!empty($filters['max_stock'])) {
            $builder->where('p.current_stock >=', $filters['max_stock']);
        }

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'normal') {
                $builder->where('p.current_stock >', 0);
                $builder->where('p.current_stock >', 'p.min_stock_alert', false);
            } elseif ($filters['status'] === 'low') {
                $builder->where('p.current_stock >', 0);
                $builder->where('p.current_stock <=', 'p.min_stock_alert', false);
            } elseif ($filters['status'] === 'out') {
                $builder->where('p.current_stock <=', 0);
            }
        }

        // Pagination
        $total = $builder->countAllResults(false);

        $products = $builder->limit($limit, ($page - 1) * $limit)
            ->get()
            ->getResultArray();

        // Ajouter le stock_quantity pour la compatibilité frontend
        foreach ($products as &$product) {
            $reservationService = new ReservationService();
            $currentStock = (float)($product['current_stock'] ?? 0);
            $reserved = $reservationService->getReservedQuantity($product['id']);

            $product['stock_quantity'] = max(0, $currentStock - $reserved);
            $product['total_stock'] = $currentStock;
            $product['reserved_quantity'] = $reserved;
            $product['stock_status'] = $this->getStockStatus($product['stock_quantity'], $product['min_stock_alert'] ?? 0);
        }

        return [
            'data' => $products,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($total / $limit)
            ]
        ];
    }

    // Récupérer un produit avec ses détails
    public function getProductWithDetails($id)
    {
        $product = $this->db->table('products p')
            ->select('p.*, c.name as category_name')
            ->join('product_categories c', 'c.id = p.category_id', 'left')
            ->where('p.id', $id)
            ->where('p.deleted_at', null)
            ->get()
            ->getRowArray();

        if ($product) {
            $reservationService = new ReservationService();
            $stock_quantity = (float)($product['current_stock'] ?? 0);
            $reserved = $reservationService->getReservedQuantity($product['id']);

            $product['stock_quantity'] = max(0, $stock_quantity - $reserved);
            $product['total_stock'] = $stock_quantity;
            $product['reserved_quantity'] = $reserved;
            $product['stock_status'] = $this->getStockStatus($product['stock_quantity'], $product['min_stock_alert'] ?? 0);
        }

        return $product;
    }

    // Obtenir le statut du stock
    private function getStockStatus($quantity, $minAlert)
    {
        if ($quantity <= 0) return 'out';
        if ($quantity <= $minAlert) return 'low';
        return 'normal';
    }

    // Mettre à jour le stock
    public function updateStock($productId, $quantity, $operation = 'add')
    {
        $product = $this->find($productId);
        if (!$product) return false;

        $currentStock = $product['current_stock'] ?? 0;
        $newStock = ($operation === 'add') ? $currentStock + $quantity : $currentStock - $quantity;
        $newStock = max(0, $newStock);

        return $this->update($productId, ['current_stock' => $newStock]);
    }

    // Actions groupées
    public function bulkDelete($ids)
    {
        return $this->whereIn('id', $ids)->delete();
    }

    public function bulkActivate($ids)
    {
        return $this->whereIn('id', $ids)->set(['is_active' => 1])->update();
    }

    public function bulkDeactivate($ids)
    {
        return $this->whereIn('id', $ids)->set(['is_active' => 0])->update();
    }

    // Générer un code produit unique
    public function generateProductCode()
    {
        $year = date('Y');
        $lastProduct = $this->where('code LIKE', 'PRD-' . $year . '-%')
            ->orderBy('id', 'DESC')
            ->first();

        if ($lastProduct) {
            $parts = explode('-', $lastProduct['code']);
            $lastNumber = intval(end($parts));
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return 'PRD-' . $year . '-' . $newNumber;
    }
}
