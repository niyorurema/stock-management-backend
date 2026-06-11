<?php
// app/Models/InvoiceItemModel.php

namespace App\Models;

use CodeIgniter\Model;

class InvoiceItemModel extends Model
{
    protected $table = 'invoice_items';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'invoice_id',
        'product_id',
        'item_code',
        'item_designation',
        'quantity',
        'unit_price',
        'ct_amount',
        'tl_amount',
        'vat_amount',
        'tsce_tax',
        'ott_tax',
        'total_amount'
    ];

    protected $useTimestamps = false;
}
