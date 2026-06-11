<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAvgPurchasePriceToProducts extends Migration
{
    public function up()
    {
        $fields = [
            'avg_purchase_price_aed' => [
                'type' => 'DECIMAL',
                'constraint' => '12,4',
                'null' => true,
                'default' => null,
            ],
            'avg_purchase_price_usd' => [
                'type' => 'DECIMAL',
                'constraint' => '12,4',
                'null' => true,
                'default' => null,
            ],
            'avg_purchase_price_bif' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'null' => true,
                'default' => null,
            ],
        ];

        $this->forge->addColumn('products', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('products', 'avg_purchase_price_aed');
        $this->forge->dropColumn('products', 'avg_purchase_price_usd');
        $this->forge->dropColumn('products', 'avg_purchase_price_bif');
    }
}
