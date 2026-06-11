<?php
// app/Models/CustomerModel.php

namespace App\Models;

use CodeIgniter\Model;

class CustomerModel extends Model
{
    protected $table = 'customers';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;
    protected $protectFields = true;

    protected $allowedFields = [
        'customer_number',
        'customer_type',
        'first_name',
        'last_name',
        'company_name',
        'email',
        'email_secondary',
        'phone',
        'phone_secondary',
        'whatsapp',
        'website',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
        'shipping_address',
        'billing_address',
        'province',
        'commune',
        'zone',
        'quarter',
        'credit_limit',
        'current_balance',
        'payment_terms',
        'default_discount',
        'price_tier',
        'status',
        'category',
        'rating',
        'loyalty_points',
        'loyalty_tier',
        'birth_date',
        'anniversary_date',
        'first_purchase_date',
        'last_purchase_date',
        'last_activity_date',
        'total_purchases',
        'total_amount_spent',
        'average_order_value',
        'last_credit_check',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'bank_swift_code',
        'mobile_money_number',
        'preferred_contact_method',
        'preferred_payment_method',
        'preferred_language',
        'currency',
        'notes',
        'internal_notes',
        'tags',
        'id_card_path',
        'tax_certificate_path',
        'registration_certificate_path',
        'marketing_consent',
        'email_opt_in',
        'sms_opt_in',
        'newsletter_subscription',
        'external_id',
        'sync_status',
        'last_sync_date',
        'tin',
        'is_vat_subject',  // Nouvelle colonne
        'created_by',
        'updated_by',
        'deleted_by',
        'deleted_at'
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    protected $validationRules = [
        'phone' => 'required|min_length[8]|max_length[20]',
        'email' => 'permit_empty|valid_email'
    ];

    protected $validationMessages = [
        'phone' => [
            'required' => 'Le numéro de téléphone est requis',
            'min_length' => 'Le numéro de téléphone doit avoir au moins 8 caractères'
        ],
        'email' => [
            'valid_email' => 'Veuillez entrer une adresse email valide'
        ]
    ];

   /* public function delete($id = null, $purge = false)
    {
        if ($purge) {
            // Suppression définitive
            return parent::delete($id, true);
        }
        // Soft delete
        return $this->update($id, ['deleted_at' => date('Y-m-d H:i:s')]);
    }*/

    /**
     * Générer un numéro client unique
     */
    public function generateCustomerNumber()
    {
        $year = date('Y');
        $lastCustomer = $this->orderBy('id', 'DESC')->first();

        if ($lastCustomer && isset($lastCustomer['customer_number'])) {
            $lastNumber = intval(substr($lastCustomer['customer_number'], -4));
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return 'CU' . $year . '-' . $newNumber;
    }

    /**
     * Récupérer les clients avec leurs statistiques
     */
    public function getCustomersWithStats($filters = [])
    {
        $builder = $this->db->table('customers c');
        $builder->select('c.*');

        // Filtres
        if (!empty($filters['search'])) {
            $builder->groupStart()
                ->like('c.customer_number', $filters['search'])
                ->orLike('c.display_name', $filters['search'])
                ->orLike('c.first_name', $filters['search'])
                ->orLike('c.last_name', $filters['search'])
                ->orLike('c.company_name', $filters['search'])
                ->orLike('c.email', $filters['search'])
                ->orLike('c.phone', $filters['search'])
                ->groupEnd();
        }

        if (!empty($filters['status'])) {
            $builder->where('c.status', $filters['status']);
        }

        if (!empty($filters['customer_type'])) {
            $builder->where('c.customer_type', $filters['customer_type']);
        }

        if (isset($filters['is_vat_subject'])) {
            $builder->where('c.is_vat_subject', $filters['is_vat_subject']);
        }

        $builder->where('c.deleted_at IS NULL');
        $builder->orderBy('c.display_name', 'ASC');

        $customers = $builder->get()->getResultArray();

        // Ajouter les statistiques
        foreach ($customers as &$customer) {
            $customer['invoice_count'] = $this->db->table('invoices')
                ->where('customer_id', $customer['id'])
                ->where('deleted_at IS NULL')
                ->countAllResults();

            $customer['total_purchases'] = $this->db->table('invoices')
                ->selectSum('total_amount')
                ->where('customer_id', $customer['id'])
                ->where('payment_status !=', 'cancelled')
                ->where('deleted_at IS NULL')
                ->get()
                ->getRow()
                ->total_amount ?? 0;

            $lastPurchase = $this->db->table('invoices')
                ->select('invoice_date')
                ->where('customer_id', $customer['id'])
                ->orderBy('invoice_date', 'DESC')
                ->get()
                ->getRow();

            $customer['last_purchase_date'] = $lastPurchase->invoice_date ?? null;
        }

        return $customers;
    }


    /**
     * Récupérer les clients actifs
     */
    public function getActiveCustomers()
    {
        return $this->where('is_active', 1)->orderBy('last_name')->findAll();
    }

    /**
     * Rechercher un client par code
     */
    public function getByCode($code)
    {
        return $this->where('code', $code)->first();
    }
}
