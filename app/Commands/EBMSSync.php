<?php
namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class EBMSSync extends BaseCommand
{
    protected $group = 'EBMS';
    protected $name = 'ebms:sync-invoices';
    protected $description = 'Sync pending invoices with EBMS';
    
    public function run(array $params)
    {
        CLI::write('Starting EBMS sync...', 'blue');
        
        $invoiceModel = model('InvoiceModel');
        $ebmsClient = new \App\Libraries\EBMSClient();
        
        $pendingInvoices = $invoiceModel->where('ebms_status', 'PENDING')
            ->orWhere('ebms_status', 'FAILED')
            ->where('retry_count <', 5)
            ->findAll();
            
        foreach ($pendingInvoices as $invoice) {
            CLI::write("Syncing invoice: {$invoice['invoice_number']}", 'yellow');
            
            try {
                $result = $ebmsClient->addInvoice($invoice['ebms_data']);
                
                if ($result['success']) {
                    $invoiceModel->update($invoice['id'], [
                        'ebms_status' => 'ACKNOWLEDGED',
                        'ebms_registered_number' => $result['data']['result']['invoice_registered_number'],
                        'ebms_registered_date' => $result['data']['result']['invoice_registered_date'],
                        'ebms_signature' => $result['data']['electronic_signature']
                    ]);
                    CLI::write("✓ Invoice synced successfully", 'green');
                } else {
                    $invoiceModel->update($invoice['id'], [
                        'ebms_status' => 'FAILED',
                        'ebms_error_msg' => $result['error'] ?? 'Unknown error',
                        'retry_count' => $invoice['retry_count'] + 1
                    ]);
                    CLI::write("✗ Sync failed: " . ($result['error'] ?? 'Unknown'), 'red');
                }
            } catch (\Exception $e) {
                CLI::write("✗ Exception: " . $e->getMessage(), 'red');
            }
            
            sleep(1); // Rate limiting
        }
        
        CLI::write('Sync completed!', 'green');
    }
}