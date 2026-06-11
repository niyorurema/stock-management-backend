<?php
// app/Controllers/ReceptionController.php
namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class ReceptionController extends ResourceController
{
    use ResponseTrait;
    
    protected $db;
    
    public function __construct()
    {
        $this->db = \Config\Database::connect();
        helper('qrcode');
    }
    
    /**
     * GET /api/receptions/(:num) - Détail d'une réception
     */
    public function show($id = null)
    {
        try {
            $reception = $this->db->table('order_receptions or')
                ->select('or.*, u.username as received_by_name, so.order_number, so.supplier_id, s.name as supplier_name, s.tin as supplier_tin')
                ->join('users u', 'u.id = or.received_by', 'left')
                ->join('supplier_orders so', 'so.id = or.order_id')
                ->join('suppliers s', 's.id = so.supplier_id')
                ->where('or.id', $id)
                ->get()
                ->getRowArray();
            
            if (!$reception) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Réception non trouvée'
                ], 404);
            }
            
            // Récupérer les items
            $items = $this->db->table('reception_items ri')
                ->select('ri.*, p.name as product_name, p.unit')
                ->join('products p', 'p.id = ri.product_id')
                ->where('ri.reception_id', $id)
                ->get()
                ->getResultArray();
            
            $reception['items'] = $items;
            $reception['total_quantity'] = array_sum(array_column($items, 'received_quantity'));
            
            // Récupérer la signature
            $signature = $this->db->table('reception_signatures')
                ->where('reception_id', $id)
                ->get()
                ->getRowArray();
            
            $reception['signature'] = $signature;
            
            return $this->respond([
                'success' => true,
                'data' => $reception
            ]);
            
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * PUT /api/receptions/(:num) - Modifier une réception
     */
    public function update($id = null)
    {
        try {
            $reception = $this->db->table('order_receptions')
                ->where('id', $id)
                ->get()
                ->getRowArray();
            
            if (!$reception) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Réception non trouvée'
                ], 404);
            }
            
            $input = $this->request->getJSON(true);
            $updateData = [];
            
            if (isset($input['reception_date'])) {
                $updateData['reception_date'] = $input['reception_date'];
            }
            if (isset($input['notes'])) {
                $updateData['notes'] = $input['notes'];
            }
            
            if (!empty($updateData)) {
                $this->db->table('order_receptions')
                    ->where('id', $id)
                    ->update($updateData);
            }
            
            // Mise à jour des quantités reçues si nécessaire
            if (isset($input['items']) && is_array($input['items'])) {
                foreach ($input['items'] as $item) {
                    $this->db->table('reception_items')
                        ->where('id', $item['id'])
                        ->update([
                            'received_quantity' => $item['received_quantity'],
                            'notes' => $item['notes'] ?? null
                        ]);
                }
            }
            
            return $this->respond([
                'success' => true,
                'message' => 'Réception mise à jour avec succès'
            ]);
            
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    // app/Controllers/ReceptionController.php

public function sign($id = null)
{
    try {
        $reception = $this->db->table('order_receptions or')
            ->select('or.*, so.order_number, so.supplier_id, s.name as supplier_name, s.tin as supplier_tin')
            ->join('supplier_orders so', 'so.id = or.order_id')
            ->join('suppliers s', 's.id = so.supplier_id')
            ->where('or.id', $id)
            ->get()
            ->getRowArray();
        
        if (!$reception) {
            return $this->respond([
                'success' => false,
                'message' => 'Réception non trouvée'
            ], 404);
        }
        
        $input = $this->request->getJSON(true);
        
        // Récupérer les settings
        $settings = $this->db->table('settings')
            ->get()
            ->getRowArray();
        
        // Calculer la quantité totale reçue
        $totalQuantity = $this->db->table('reception_items')
            ->selectSum('received_quantity', 'total')
            ->where('reception_id', $id)
            ->get()
            ->getRowArray();
        
        // Générer le QR code du bon de réception
        $receptionData = [
            'reception_number' => $reception['reception_number'],
            'order_number' => $reception['order_number'],
            'supplier_name' => $reception['supplier_name'],
            'supplier_tin' => $reception['supplier_tin'] ?? '',
            'reception_date' => $reception['reception_date'],
            'total_quantity' => $totalQuantity['total'] ?? 0
        ];
        
        helper('qrcode');
        $qrcodePath = generateReceptionQRCode($receptionData, $settings);
        
        // Sauvegarder la signature
        $signatureData = [
            'reception_id' => $id,
            'order_id' => $reception['order_id'],
            'qrcode_path' => $qrcodePath,
            'signed_by' => $input['signed_by'] ?? $reception['supplier_name'],
            'signed_at' => date('Y-m-d H:i:s'),
            'ip_address' => $this->request->getIPAddress(),
            'signature_data' => json_encode($input['signature_data'] ?? []),
            'is_valid' => 1
        ];
        
        $this->db->table('reception_signatures')->insert($signatureData);
        $signatureId = $this->db->insertID();
        
        // Générer le QR code de signature (optionnel)
        $signatureQrcodePath = null;
        if (function_exists('generateReceptionSignatureQRCode')) {
            try {
                $signatureQrcodePath = generateReceptionSignatureQRCode([
                    'id' => $signatureId,
                    'reception_number' => $reception['reception_number'],
                    'signed_by' => $signatureData['signed_by'],
                    'signed_at' => $signatureData['signed_at']
                ]);
                
                // Mettre à jour avec le chemin du QR code de signature
                $this->db->table('reception_signatures')
                    ->where('id', $signatureId)
                    ->update(['signed_qrcode_path' => $signatureQrcodePath]);
            } catch (\Exception $e) {
                log_message('error', 'Signature QR Code error: ' . $e->getMessage());
            }
        }
        
        return $this->respond([
            'success' => true,
            'message' => 'Bon de réception signé avec succès',
            'data' => [
                'signature_id' => $signatureId,
                'qrcode_path' => $qrcodePath,
                'signed_qrcode_path' => $signatureQrcodePath
            ]
        ]);
        
    } catch (\Exception $e) {
        log_message('error', 'Sign reception error: ' . $e->getMessage());
        return $this->respond([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}

// app/Controllers/ReceptionController.php

/**
 * GET /api/receptions/verify/(:num) - Vérifier l'authenticité d'un bon
 */
public function verify($id = null)
{
    try {
        $signature = $this->db->table('reception_signatures rs')
            ->select('rs.*, or.reception_number, or.reception_date, so.order_number, s.name as supplier_name')
            ->join('order_receptions or', 'or.id = rs.reception_id')
            ->join('supplier_orders so', 'so.id = rs.order_id')
            ->join('suppliers s', 's.id = so.supplier_id')
            ->where('rs.id', $id)
            ->get()
            ->getRowArray();
        
        if (!$signature) {
            return $this->respond([
                'success' => false,
                'message' => 'Signature non trouvée'
            ], 404);
        }
        
        // Page de vérification HTML (pour affichage public)
        $html = $this->generateVerificationPage($signature);
        
        return $this->response->setBody($html)->setHeader('Content-Type', 'text/html');
        
    } catch (\Exception $e) {
        return $this->respond([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}

private function generateVerificationPage($signature)
{
    $isValid = $signature['is_valid'] && strtotime($signature['signed_at']) > strtotime('-30 days');
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Vérification de Bon de Réception</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; background: #f5f7fb; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 16px; padding: 30px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
            .header { text-align: center; border-bottom: 2px solid #667eea; padding-bottom: 20px; margin-bottom: 20px; }
            .status-valid { color: #10b981; font-size: 24px; font-weight: bold; }
            .status-invalid { color: #dc2626; font-size: 24px; font-weight: bold; }
            .info-row { display: flex; padding: 10px 0; border-bottom: 1px solid #e2e8f0; }
            .info-label { width: 140px; font-weight: 600; color: #1e293b; }
            .info-value { flex: 1; color: #334155; }
            .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b; }
            .qrcode { text-align: center; margin: 20px 0; }
            .qrcode img { width: 150px; height: 150px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>📄 Vérification de Bon de Réception</h1>
                <div class='status-" . ($isValid ? "valid" : "invalid") . "'>
                    " . ($isValid ? "✅ Document Authentique" : "❌ Document Non Authentique") . "
                </div>
            </div>
            <div class='info-row'><div class='info-label'>N° Réception:</div><div class='info-value'>" . ($signature['reception_number'] ?? '-') . "</div></div>
            <div class='info-row'><div class='info-label'>N° Commande:</div><div class='info-value'>" . ($signature['order_number'] ?? '-') . "</div></div>
            <div class='info-row'><div class='info-label'>Fournisseur:</div><div class='info-value'>" . ($signature['supplier_name'] ?? '-') . "</div></div>
            <div class='info-row'><div class='info-label'>Date réception:</div><div class='info-value'>" . date('d/m/Y H:i', strtotime($signature['reception_date'])) . "</div></div>
            <div class='info-row'><div class='info-label'>Signé par:</div><div class='info-value'>" . ($signature['signed_by'] ?? '-') . "</div></div>
            <div class='info-row'><div class='info-label'>Date signature:</div><div class='info-value'>" . date('d/m/Y H:i', strtotime($signature['signed_at'])) . "</div></div>
            <div class='info-row'><div class='info-label'>IP signature:</div><div class='info-value'>" . ($signature['ip_address'] ?? '-') . "</div></div>
            " . ($signature['qrcode_path'] ? "
            <div class='qrcode'>
                <img src='" . base_url($signature['qrcode_path']) . "' alt='QR Code'>
                <p>Scannez ce code pour vérifier l'authenticité</p>
            </div>" : "") . "
            <div class='footer'>
                <p>Ce document a été généré électroniquement et signé numériquement.</p>
                <p>Date de vérification: " . date('d/m/Y H:i:s') . "</p>
            </div>
        </div>
    </body>
    </html>";
}



// app/Controllers/ReceptionController.php

/**
 * GET /api/receptions/(:num)/print-signed
 * Récupère les données pour imprimer le bon signé
 */
public function printSigned($receptionId = null)
{
    try {
        // Récupérer la réception
        $reception = $this->db->table('order_receptions or')
            ->select('or.*, so.order_number, so.supplier_id, s.name as supplier_name, s.tin as supplier_tin')
            ->join('supplier_orders so', 'so.id = or.order_id')
            ->join('suppliers s', 's.id = so.supplier_id')
            ->where('or.id', $receptionId)
            ->get()
            ->getRowArray();
        
        if (!$reception) {
            return $this->respond([
                'success' => false,
                'message' => 'Réception non trouvée'
            ], 404);
        }
        
        // Récupérer les items
        $items = $this->db->table('reception_items ri')
            ->select('ri.*, p.name as product_name, p.unit')
            ->join('products p', 'p.id = ri.product_id')
            ->where('ri.reception_id', $receptionId)
            ->get()
            ->getResultArray();
        
        $reception['items'] = $items;
        
        // Récupérer la signature
        $signature = $this->db->table('reception_signatures')
            ->where('reception_id', $receptionId)
            ->get()
            ->getRowArray();
        
        // Récupérer les settings de l'entreprise
        $settings = $this->db->table('settings')
            ->get()
            ->getRowArray();
        
        // Récupérer la commande complète
        $order = $this->db->table('supplier_orders so')
            ->select('so.*, s.name as supplier_name, s.tin as supplier_tin')
            ->join('suppliers s', 's.id = so.supplier_id')
            ->where('so.id', $reception['order_id'])
            ->get()
            ->getRowArray();
        
        return $this->respond([
            'success' => true,
            'data' => [
                'reception' => $reception,
                'signature' => $signature,
                'order' => $order,
                'settings' => $settings
            ]
        ]);
        
    } catch (\Exception $e) {
        log_message('error', 'Print signed reception error: ' . $e->getMessage());
        return $this->respond([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}
    
    /**
     * Générer le PDF signé du bon de réception
     */
    private function generateSignedReceptionPDF($reception, $signatureData, $qrcodePath, $signatureQrcodePath)
    {
        // Implémentation de la génération du PDF avec les QR codes
        // Utilisez une bibliothèque comme Dompdf ou TCPDF
        
        $pdfPath = 'uploads/signed_receptions/reception_' . $reception['reception_number'] . '_signed.pdf';
        $fullPath = FCPATH . $pdfPath;
        
        $uploadPath = dirname($fullPath);
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0777, true);
        }
        
        // Logique de génération du PDF avec les QR codes
        // ...
        
        return $pdfPath;
    }
}