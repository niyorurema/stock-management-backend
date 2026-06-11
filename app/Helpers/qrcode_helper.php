<?php
// app/Helpers/qrcode_helper.php

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;


if (!function_exists('generateInvoiceQRCode')) {
    function generateInvoiceQRCode($invoiceData)
    {
        // Structure des données pour le QR Code (format EBMS)
        $qrData = json_encode([
            'invoice_number' => $invoiceData['invoice_number'],
            'invoice_identifier' => $invoiceData['invoice_identifier'],
            'invoice_date' => $invoiceData['invoice_date'],
            'seller_tin' => '4002141416', // Votre NIF
            'buyer_tin' => $invoiceData['customer_TIN'] ?? '',
            'total_amount' => $invoiceData['total_amount'],
            'vat_amount' => $invoiceData['vat_amount'],
            'currency' => $invoiceData['invoice_currency'] ?? 'BIF',
            'ebms_status' => $invoiceData['ebms_status'],
            'verification_url' => base_url('api/invoices/verify/' . $invoiceData['invoice_number'])
        ]);

        $result = Builder::create()
            ->writer(new PngWriter())
            ->data($qrData)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::High)
            ->size(200)
            ->margin(10)
            ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
            ->build();

        // Sauvegarder l'image temporairement
        $tempFile = WRITEPATH . 'temp/qrcode_' . $invoiceData['invoice_number'] . '.png';
        if (!is_dir(WRITEPATH . 'temp')) {
            mkdir(WRITEPATH . 'temp', 0777, true);
        }
        $result->saveToFile($tempFile);

        return $tempFile;
    }
}

function print_test($data)
{
    echo '<pre>';
    print_r($data);
    '</pre>';
}

/*
if (!function_exists('generateReceptionQRCode')) {
    function generateReceptionQRCode($receptionData, $settings)
    {
        $qrData = json_encode([
            'reception_number' => $receptionData['reception_number'],
            'order_number' => $receptionData['order_number'],
            'supplier_name' => $receptionData['supplier_name'],
            'reception_date' => $receptionData['reception_date'],
        ]);
        
        $encodedData = urlencode($qrData);
        
        // QuickChart.io - Gratuit, pas de bibliothèque nécessaire
        $qrUrl = "https://quickchart.io/qr?text={$encodedData}&size=250";
        
        $uploadPath = FCPATH . 'uploads/qrcodes/';
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0777, true);
        }
        
        $fileName = 'qrcode_reception_' . $receptionData['reception_number'] . '.png';
        $filePath = $uploadPath . $fileName;
        
        $qrImage = @file_get_contents($qrUrl);
        if ($qrImage === false) {
            // Fallback: créer une image vide
            $qrImage = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        }
        
        file_put_contents($filePath, $qrImage);
        
        return 'uploads/qrcodes/' . $fileName;
    }
}*/


if (!function_exists('generateReceptionQRCode')) {
    function generateReceptionQRCode($receptionData, $settings)
    {
        $qrData = json_encode([
            'reception_number' => $receptionData['reception_number'],
            'order_number' => $receptionData['order_number'],
            'supplier_name' => $receptionData['supplier_name'],
            'reception_date' => $receptionData['reception_date'],
            'total_quantity' => $receptionData['total_quantity'] ?? 0,
            'verification_url' => base_url('api/receptions/verify/' . $receptionData['reception_number']),
        ]);

        // Création du QR code localement
        $builder = new Builder(
            writer: new PngWriter(),
            data: $qrData,
            size: 250,
            margin: 10
        );

        $result = $builder->build();

        $uploadPath = FCPATH . 'uploads/qrcodes/';
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0777, true);
        }

        $fileName = 'qrcode_reception_' . $receptionData['reception_number'] . '.png';
        $filePath = $uploadPath . $fileName;
        $result->saveToFile($filePath);

        return 'uploads/qrcodes/' . $fileName;
    }
}

if (!function_exists('generateReceptionSignatureQRCode')) {
    function generateReceptionSignatureQRCode($signatureData)
    {
        $qrData = json_encode([
            'signature_id' => $signatureData['id'],
            'reception_number' => $signatureData['reception_number'],
            'signed_by' => $signatureData['signed_by'],
            'signed_at' => $signatureData['signed_at'],
        ]);

        $encodedData = urlencode($qrData);
        $qrUrl = "https://quickchart.io/qr?text={$encodedData}&size=150";

        $uploadPath = FCPATH . 'uploads/signatures/';
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0777, true);
        }

        $fileName = 'signature_qrcode_' . $signatureData['id'] . '.png';
        $filePath = $uploadPath . $fileName;

        $qrImage = @file_get_contents($qrUrl);
        file_put_contents($filePath, $qrImage);

        return 'uploads/signatures/' . $fileName;
    }
}
