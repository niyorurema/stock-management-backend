<?php
// app/Models/InvoicePaymentModel.php

namespace App\Models;

use CodeIgniter\Model;

class InvoicePaymentModel extends Model
{
    protected $table = 'invoice_payments';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    
    protected $allowedFields = [
        'invoice_id', 'payment_date', 'amount', 'payment_method', 
        'reference', 'notes', 'created_by'
    ];
    
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = false;
    
    protected $validationRules = [
        'invoice_id' => 'required|numeric',
        'amount' => 'required|numeric|greater_than[0]',
        'payment_method' => 'required|string'
    ];
    
    protected $validationMessages = [
        'invoice_id' => [
            'required' => 'L\'ID de la facture est requis',
            'numeric' => 'L\'ID de la facture doit être un nombre'
        ],
        'amount' => [
            'required' => 'Le montant est requis',
            'greater_than' => 'Le montant doit être supérieur à 0'
        ],
        'payment_method' => [
            'required' => 'Le mode de paiement est requis'
        ]
    ];
    
    /**
     * Récupérer tous les paiements d'une facture
     * 
     * @param int $invoiceId ID de la facture
     * @return array
     */
    public function getPaymentsByInvoice($invoiceId)
    {
        return $this->where('invoice_id', $invoiceId)
                    ->orderBy('payment_date', 'DESC')
                    ->findAll();
    }
    
    /**
     * Récupérer le montant total payé pour une facture
     * 
     * @param int $invoiceId ID de la facture
     * @return float
     */
    public function getTotalPaidByInvoice($invoiceId)
    {
        $result = $this->select('SUM(amount) as total')
                      ->where('invoice_id', $invoiceId)
                      ->first();
        
        return $result['total'] ?? 0;
    }
    
    /**
     * Vérifier si un paiement existe pour une facture
     * 
     * @param int $invoiceId ID de la facture
     * @return bool
     */
    public function hasPayments($invoiceId)
    {
        return $this->where('invoice_id', $invoiceId)->countAllResults() > 0;
    }
    
    /**
     * Récupérer le dernier paiement d'une facture
     * 
     * @param int $invoiceId ID de la facture
     * @return array|null
     */
    public function getLastPayment($invoiceId)
    {
        return $this->where('invoice_id', $invoiceId)
                    ->orderBy('payment_date', 'DESC')
                    ->orderBy('id', 'DESC')
                    ->first();
    }
}