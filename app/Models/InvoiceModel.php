<?php
// app/Models/InvoiceModel.php

namespace App\Models;

use CodeIgniter\Model;

class InvoiceModel extends Model
{
    protected $table = 'invoices';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;

    protected $allowedFields = [
        'invoice_number',
        'invoice_identifier',
        'invoice_type',
        'invoice_date',
        'due_date',           // <-- AJOUTER
        'invoice_currency',
        'customer_name',
        'customer_TIN',
        'customer_address',
        'customer_email',     // <-- AJOUTER
        'customer_phone',     // <-- AJOUTER
        'vat_customer_payer',
        'subtotal',
        'discount_total',     // <-- AJOUTER
        'vat_amount',
        'ct_amount',
        'tl_amount',
        'shipping_amount',    // <-- AJOUTER
        'total_amount',
        'paid_amount',        // <-- AJOUTER
        'payment_type',
        'payment_status',
        'status',             // <-- AJOUTER
        'payment_date',
        'payment_method',
        'cancelled_invoice_ref',
        'invoice_ref',
        'cn_motif',
        'ebms_status',
        'ebms_registered_number',
        'ebms_registered_date',
        'ebms_signature',
        'ebms_error_msg',
        'ebms_sent_at',
        'ebms_retry_count',
        'email_sent',
        'email_sent_at',
        'printed',
        'printed_at',
        'notes',
        'warehouse_id',
        'customer_id',        // <-- AJOUTER
        'created_by'
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * Récupérer une facture avec ses détails
     */
    public function getInvoiceWithDetails($id)
    {
        $invoice = $this->find($id);

        if (!$invoice) {
            return null;
        }

        // Récupérer les items
        $itemBuilder = $this->db->table('invoice_items ii');
        $itemBuilder->select('ii.*, p.name as product_name, p.code as product_code, p.unit');
        $itemBuilder->join('products p', 'p.id = ii.product_id', 'left');
        $itemBuilder->where('ii.invoice_id', $id);
        $invoice['items'] = $itemBuilder->get()->getResultArray();

        // Récupérer les paiements
        $paymentBuilder = $this->db->table('invoice_payments');
        $paymentBuilder->where('invoice_id', $id);
        $paymentBuilder->orderBy('payment_date', 'DESC');
        $payments = $paymentBuilder->get()->getResultArray();
        $invoice['payments'] = $payments;
        $invoice['paid_amount'] = array_sum(array_column($payments, 'amount'));

        return $invoice;
    }

    /**
     * Générer un numéro de facture unique
     */
    /* public function generateInvoiceNumber($type)
    {
        $year = date('Y');
        $month = date('m');

        $lastInvoice = $this->select('invoice_number')
            ->like('invoice_number', 'INV-' . $year . $month . '-', 'after')
            ->orderBy('id', 'DESC')
            ->first();

        if ($lastInvoice) {
            $parts = explode('-', $lastInvoice['invoice_number']);
            $lastNumber = intval(end($parts));
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return $type . $newNumber.'-'. $year . $month . '-' . ;
    }*/

    public function generateInvoiceNumber($type = 'FN', $attempt = 1)
    {
        $year = date('Y');
        $typePrefix = strtoupper($type);

        // Récupérer le dernier numéro
        $lastInvoice = $this->select('invoice_number')
            ->like('invoice_number', $typePrefix, 'after')
            ->where('YEAR(created_at)', $year)
            ->orderBy('id', 'DESC')
            ->first();

        if ($lastInvoice) {
            $numberPart = substr($lastInvoice['invoice_number'], strlen($typePrefix), 4);
            $lastNumber = intval($numberPart);
            $newNumber = str_pad($lastNumber + $attempt, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = str_pad($attempt, 4, '0', STR_PAD_LEFT);
        }

        $invoiceNumber = $typePrefix . $newNumber . '-' . $year;
        // Vérifier l'unicité
        $exists = $this->where('invoice_number', $invoiceNumber)->first();
        if ($exists) {
            return $this->generateInvoiceNumber($type, $attempt + 1);
        }
        return $invoiceNumber;
    }

    /**
     * Générer un identifiant EBMS
     */
    public function generateInvoiceIdentifier($tin, $systemId, $date, $invoiceNumber)
    {
        $tin = !empty($tin) ? $tin : '0000000000';
        $dateFormatted = date('YmdHis', strtotime($date));
        return $tin . '/' . $systemId . '/' . $dateFormatted . '/' . $invoiceNumber;
    }

    /**
     * Récupérer les factures avec filtres, tri et pagination
     */
    public function getInvoices($filters = [], $page = 1, $limit = 10, $sort = 'created_at', $order = 'desc')
    {
        $builder = $this->db->table('invoices');
        $builder->select('invoices.*,w.name as warehouse_name')
            ->join('warehouses w', 'invoices.warehouse_id = w.id')
            ->orderBy($sort, $order);

        // Application des filtres
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

        // Compter le total avant pagination
        $total = $builder->countAllResults(false);

        // Appliquer la pagination
        $offset = ($page - 1) * $limit;
        $builder->limit($limit, $offset);
        $invoices = $builder->get()->getResultArray();


        // Ajouter les items, paiements et montants payés pour chaque facture
        foreach ($invoices as &$invoice) {
            // Récupérer les items
            $itemBuilder = $this->db->table('invoice_items ii');
            $itemBuilder->select('ii.*, p.name as product_name, p.code as product_code, p.unit');
            $itemBuilder->join('products p', 'p.id = ii.product_id', 'left');
            $itemBuilder->where('ii.invoice_id', $invoice['id']);
            $items = $itemBuilder->get()->getResultArray();
            $invoice['items'] = $items;

            // Récupérer les paiements
            $paymentBuilder = $this->db->table('invoice_payments');
            $paymentBuilder->where('invoice_id', $invoice['id']);
            $paymentBuilder->orderBy('payment_date', 'DESC');
            $payments = $paymentBuilder->get()->getResultArray();
            $invoice['payments'] = $payments;

            // Calculer le montant payé
            $invoice['paid_amount'] = array_sum(array_column($payments, 'amount'));
        }

        return [
            'data' => $invoices,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($total / $limit)
            ]
        ];
    }

    /**
     * Récupérer les factures pour export
     */
    public function getInvoicesForExport($filters = [])
    {
        $builder = $this->db->table('invoices');
        $builder->select('invoices.*');

        if (!empty($filters['date_from'])) {
            $builder->where('DATE(invoice_date) >=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $builder->where('DATE(invoice_date) <=', $filters['date_to']);
        }

        $invoices = $builder->get()->getResultArray();

        foreach ($invoices as &$invoice) {
            $payments = $this->db->table('invoice_payments')
                ->where('invoice_id', $invoice['id'])
                ->get()
                ->getResultArray();
            $invoice['paid_amount'] = array_sum(array_column($payments, 'amount'));
        }

        return $invoices;
    }

    /**
     * Récupérer une facture par son numéro
     */
    public function getByInvoiceNumber($invoiceNumber)
    {
        return $this->where('invoice_number', $invoiceNumber)->first();
    }

    public function insertWithItems($invoiceData, $items)
    {
        // Protection renforcée avec logs et exceptions
        $this->db->transStart();

        try {
            // Générer le numéro si non fourni
            if (empty($invoiceData['invoice_number'])) {
                $invoiceData['invoice_number'] = $this->generateInvoiceNumber();
            }

            // Générer l'identifiant EBMS
            if (empty($invoiceData['invoice_identifier'])) {
                $invoiceData['invoice_identifier'] = $this->generateInvoiceIdentifier(
                    $invoiceData['customer_TIN'] ?? '0000000000',
                    'STOCKMANAGER',
                    $invoiceData['invoice_date'] ?? date('Y-m-d H:i:s'),
                    $invoiceData['invoice_number']
                );
            }

            // Valeurs par défaut
            $invoiceData['payment_status'] = $invoiceData['payment_status'] ?? 'pending';
            $invoiceData['ebms_status'] = $invoiceData['ebms_status'] ?? 'PENDING';
            $invoiceData['invoice_currency'] = $invoiceData['invoice_currency'] ?? 'BIF';
            $invoiceData['payment_type'] = $invoiceData['payment_type'] ?? '1';

            // Insérer la facture
            $insertResult = $this->insert($invoiceData);
            if ($insertResult === false) {
                $dbError = $this->db->error();
                log_message('error', 'InvoiceModel::insertWithItems - insert invoice failed: ' . json_encode($dbError) . ' | data=' . json_encode(array_slice($invoiceData, 0, 30)));
                $this->db->transRollback();
                throw new \RuntimeException('Insert invoice failed: ' . ($dbError['message'] ?? 'unknown DB error'));
            }

            $invoiceId = $this->insertID();

            // Insérer les items
            $itemModel = new InvoiceItemModel();

            // Récupérer dynamiquement les colonnes existantes dans la table invoice_items
            $dbColumns = [];
            try {
                $cols = $this->db->query("SHOW COLUMNS FROM `invoice_items`")->getResultArray();
                foreach ($cols as $c) {
                    $dbColumns[] = $c['Field'];
                }
            } catch (\Throwable $e) {
                // En cas d'erreur, tomber en backoff sur la liste statique connue (préventif)
                log_message('warning', 'Impossible de récupérer les colonnes invoice_items: ' . $e->getMessage());
                $dbColumns = ['invoice_id', 'product_id', 'item_code', 'item_designation', 'quantity', 'unit_price', 'ct_amount', 'tl_amount', 'vat_amount', 'tsce_tax', 'ott_tax', 'total_amount'];
            }

            foreach ($items as $item) {
                $item['invoice_id'] = $invoiceId;
                // Filter item fields to actual DB columns to avoid unknown column errors
                $toInsert = array_intersect_key($item, array_flip($dbColumns));
                $itemInsert = $itemModel->insert($toInsert);
                if ($itemInsert === false) {
                    $dbError = $this->db->error();
                    log_message('error', 'InvoiceModel::insertWithItems - insert item failed: ' . json_encode($dbError) . ' | item=' . json_encode($item));
                    $this->db->transRollback();
                    throw new \RuntimeException('Insert invoice item failed: ' . ($dbError['message'] ?? 'unknown DB error'));
                }
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                $dbError = $this->db->error();
                log_message('error', 'InvoiceModel::insertWithItems - transaction failed: ' . json_encode($dbError));
                throw new \RuntimeException('Transaction failed');
            }

            return $invoiceId;
        } catch (\Throwable $e) {
            // Ensure rollback and log
            if ($this->db->transStatus() !== false) {
                $this->db->transRollback();
            }
            log_message('error', 'InvoiceModel::insertWithItems exception: ' . $e->getMessage());
            throw $e;
        }
    }


    /**
     * Insérer une facture avec ses items (version améliorée)
     */
}
