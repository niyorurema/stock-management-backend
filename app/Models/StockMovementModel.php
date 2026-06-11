<?php
// app/Models/StockMovementModel.php

namespace App\Models;

use CodeIgniter\Model;

class StockMovementModel extends Model
{
    protected $table = 'stock_movements';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;


    protected $allowedFields = [
        'movement_number',
        'movement_group',
        'warehouse_id',
        'product_id',
        'movement_type',
        'quantity',
        'previous_quantity',
        'new_quantity',
        'unit_cost',
        'total_cost',
        'invoice_ref',
        'reference',
        'reference_doc',
        'description',
        'movement_date',
        'created_by',
        'ebms_synced',
        'ebms_response'
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    protected $validationMessages = [
        'movement_number' => [
            'required' => 'Le numéro de mouvement est requis',
            'is_unique' => 'Ce numéro de mouvement existe déjà'
        ],
        'warehouse_id' => [
            'required' => 'L\'entrepôt est requis',
            'numeric' => 'L\'entrepôt doit être un nombre valide'
        ],
        'product_id' => [
            'required' => 'Le produit est requis',
            'numeric' => 'Le produit doit être un nombre valide'
        ],
        'movement_type' => [
            'required' => 'Le type de mouvement est requis',
            'in_list' => 'Type de mouvement invalide'
        ],
        'quantity' => [
            'required' => 'La quantité est requise',
            'numeric' => 'La quantité doit être un nombre',
            'greater_than' => 'La quantité doit être supérieure à 0'
        ],
        'movement_date' => [
            'required' => 'La date du mouvement est requise',
            'valid_date' => 'Date invalide'
        ]
    ];

    protected $skipValidation = false;

    // Types de mouvements
    const MOVEMENT_TYPES = [
        'EN' => ['label' => 'Entrée Normale', 'type' => 'in', 'icon' => '📥'],
        'ER' => ['label' => 'Entrée Retour', 'type' => 'in', 'icon' => '🔄'],
        'EI' => ['label' => 'Entrée Inventaire', 'type' => 'in', 'icon' => '📋'],
        'EAJ' => ['label' => 'Entrée Ajustement', 'type' => 'in', 'icon' => '⚙️'],
        'ET' => ['label' => 'Entrée Transfert', 'type' => 'in', 'icon' => '🚚'],
        'EAU' => ['label' => 'Entrée Autres', 'type' => 'in', 'icon' => '📦'],
        'SN' => ['label' => 'Sortie Normale', 'type' => 'out', 'icon' => '📤'],
        'SP' => ['label' => 'Sortie Perte', 'type' => 'out', 'icon' => '⚠️'],
        'SV' => ['label' => 'Sortie Vol', 'type' => 'out', 'icon' => '🚨'],
        'SD' => ['label' => 'Sortie Désuétude', 'type' => 'out', 'icon' => '⏰'],
        'SC' => ['label' => 'Sortie Casse', 'type' => 'out', 'icon' => '💔'],
        'SAJ' => ['label' => 'Sortie Ajustement', 'type' => 'out', 'icon' => '⚙️'],
        'ST' => ['label' => 'Sortie Transfert', 'type' => 'out', 'icon' => '🚚'],
        'SAU' => ['label' => 'Sortie Autres', 'type' => 'out', 'icon' => '📤']
    ];

    public function getStockByWarehouse($warehouseId = null)
    {
        $builder = $this->db->table('stock_movements sm')
            ->select('p.id, p.code, p.name, p.unit, p.min_stock_alert, 
                      w.id as warehouse_id, w.name as warehouse_name,
                      sm.new_quantity as stock')
            ->join('products p', 'p.id = sm.product_id')
            ->join('warehouses w', 'w.id = sm.warehouse_id')
            ->whereIn('sm.id', function ($subquery) {
                $subquery->select('MAX(id)')->from('stock_movements')->groupBy('product_id, warehouse_id');
            });

        if ($warehouseId) {
            $builder->where('sm.warehouse_id', $warehouseId);
        }

        return $builder->orderBy('p.name')->get()->getResultArray();
    }

    public function getMovementsByProduct($productId, $limit = 50)
    {
        return $this->where('product_id', $productId)
            ->orderBy('movement_date', 'DESC')
            ->limit($limit)
            ->findAll();
    }
    public function validateMovement($data)
    {
        $validation = \Config\Services::validation();
        $validation->setRules($this->validationRules);
        $validation->setRules($this->validationMessages);

        return $validation->run($data);
    }

    /**
     * Récupérer les mouvements d'une facture
     */
    public function getMovementsByInvoice($invoiceRef)
    {
        return $this->where('invoice_ref', $invoiceRef)
            ->orderBy('movement_date', 'DESC')
            ->findAll();
    }

    public function generateMovementNumber($attempt = 1)
    {
        $year = date('Y');

        $lastMovement = $this->select('movement_number')
            ->like('movement_number', 'M', 'after')
            ->where('YEAR(created_at)', $year)
            ->orderBy('id', 'DESC')
            ->first();
        if ($lastMovement) {
            preg_match('/M(\d+)-/', $lastMovement['movement_number'], $matches);
            $lastNumber = isset($matches[1]) ? intval($matches[1]) : 0;
            $newNumber = str_pad($lastNumber + $attempt, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = str_pad($attempt, 4, '0', STR_PAD_LEFT);
        }

        $movementNumber = 'M' . $newNumber . '-' . $year;

        // Vérifier l'unicité
        $exists = $this->where('movement_number', $movementNumber)->first();
        if ($exists) {
            return $this->generateMovementNumber($attempt + 1);
        }
        return $movementNumber;
    }
}
