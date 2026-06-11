<?php
// app/Models/PurchaseOrderItemModel.php

namespace App\Models;

use CodeIgniter\Model;

class PurchaseOrderItemModel extends Model
{
    protected $table = 'supplier_order_items';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    
    protected $allowedFields = [
        // ========== RELATIONS ==========
        'order_id',
        'product_id',
        
        // ========== QUANTITÉS ==========
        'quantity',
        'received_quantity',
        
        // ========== PRIX UNITAIRE PAR DEVISE ==========
        'unit_cost',        // AED (devise de base)
        'unit_cost_aed',    // AED
        'unit_cost_usd',    // USD
        'unit_cost_bif',    // BIF
        
        // ========== COÛTS TOTAUX PAR DEVISE ==========
        'total_cost',       // AED (devise de base)
        'total_cost_aed',   // AED
        'total_cost_usd',   // USD
        'total_cost_bif',   // BIF
        
        // ========== REMISES ==========
        'unit_cost_before_discount',
        'discount_type',     // 'percentage' ou 'fixed'
        'discount_percent',
        'discount_amount',
        
        // ========== TAXES ==========
        'tax_rate',
        'tax_amount',
        
        // ========== PROFIT ET MARGE ==========
        'expected_profit',
        'profit_margin',
        
        // ========== TAUX DE CHANGE ==========
        'exchange_rate_aed_to_usd',
        'exchange_rate_usd_to_bif',
        
        // ========== MÉTADONNÉES ==========
        'notes'
    ];
    
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}