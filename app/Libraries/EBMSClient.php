<?php

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;

class EBMSClient
{
    protected $loginUrl;
    protected $getInvoiceUrl;
    protected $addInvoiceUrl;
    protected $checkTinUrl;
    protected $cancelInvoiceUrl;
    protected $addStockMovementUrl;
    protected $username;
    protected $password;
    protected $publicKey;
    protected $token;
    protected $client;

    public function __construct()
    {
        $this->client = \Config\Services::curlrequest();
        $this->loadConfig();
    }

    private function loadConfig()
    {
        $db = \Config\Database::connect();
        $settings = $db->table('settings')->get()->getResultArray();
        $systemSettings = [];

        if ($db->tableExists('system_settings')) {
            $systemSettings = $db->table('system_settings')->get()->getResultArray();
        }

        $allSettings = array_merge($settings, $systemSettings);

        foreach ($allSettings as $setting) {
            switch ($setting['setting_key']) {
                case 'ebms_login_url':
                    $this->loginUrl = $setting['setting_value'];
                    break;
                case 'ebms_get_invoice_url':
                    $this->getInvoiceUrl = $setting['setting_value'];
                    break;
                case 'ebms_add_invoice_url':
                    $this->addInvoiceUrl = $setting['setting_value'];
                    break;
                case 'ebms_check_tin_url':
                    $this->checkTinUrl = $setting['setting_value'];
                    break;
                case 'ebms_cancel_invoice_url':
                    $this->cancelInvoiceUrl = $setting['setting_value'];
                    break;
                case 'ebms_add_stock_movement_url':
                    $this->addStockMovementUrl = $setting['setting_value'];
                    break;
                case 'ebms_username':
                    $this->username = $setting['setting_value'];
                    break;
                case 'ebms_password':
                    $this->password = $setting['setting_value'];
                    break;
                case 'ebms_public_key':
                    $this->publicKey = $setting['setting_value'];
                    break;
                case 'ebms_api_url':
                    $baseUrl = rtrim($setting['setting_value'], '/') . '/';
                    $this->loginUrl = $this->loginUrl ?? $baseUrl . 'login/';
                    $this->getInvoiceUrl = $this->getInvoiceUrl ?? $baseUrl . 'getInvoice/';
                    $this->addInvoiceUrl = $this->addInvoiceUrl ?? $baseUrl . 'addInvoice_confirm/';
                    $this->checkTinUrl = $this->checkTinUrl ?? $baseUrl . 'checkTIN/';
                    $this->cancelInvoiceUrl = $this->cancelInvoiceUrl ?? $baseUrl . 'cancelInvoice/';
                    $this->addStockMovementUrl = $this->addStockMovementUrl ?? $baseUrl . 'AddStockMovement/';
                    break;
            }
        }
    }

    private function getUrl(string $url, string $label)
    {
        if (empty($url)) {
            throw new \Exception("EBMS endpoint '{$label}' n'est pas configuré");
        }
        return rtrim($url, '/') . '/';
    }

    public function authenticate()
    {
        if (empty($this->loginUrl)) {
            throw new \Exception('EBMS login URL non configurée');
        }

        try {
            $response = $this->client->post($this->getUrl($this->loginUrl, 'login'), [
                'json' => [
                    'username' => $this->username,
                    'password' => $this->password,
                ],
                'timeout' => 30,
            ]);

            $data = json_decode($response->getBody(), true);

            if (!empty($data['success']) && isset($data['result']['token'])) {
                $this->token = $data['result']['token'];
                return $this->token;
            }

            throw new \Exception($data['msg'] ?? $data['message'] ?? 'Erreur d\'authentification EBMS');
        } catch (\Exception $e) {
            throw new \Exception('EBMS Auth Error: ' . $e->getMessage());
        }
    }

    public function addInvoice($invoiceData)
    {
        if (!$this->token) {
            $this->authenticate();
        }

        try {
            $response = $this->client->post($this->getUrl($this->addInvoiceUrl, 'addInvoice'), [
                'json' => $invoiceData,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 60,
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody(), true);

            return [
                'success' => $statusCode === 200 && !empty($body['success']),
                'status_code' => $statusCode,
                'data' => $body,
                'raw_response' => $body,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function checkTIN($tin)
    {
        if (empty($this->checkTinUrl)) {
            return ['success' => false, 'error' => 'EBMS TIN URL non configurée'];
        }

        if (!$this->token) {
            $this->authenticate();
        }

        try {
            $response = $this->client->post($this->getUrl($this->checkTinUrl, 'checkTIN'), [
                'json' => ['tp_TIN' => $tin],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 30,
            ]);

            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function cancelInvoice($invoiceIdentifier, $reason)
    {
        if (empty($this->cancelInvoiceUrl)) {
            return ['success' => false, 'error' => 'EBMS cancel URL non configurée'];
        }

        if (!$this->token) {
            $this->authenticate();
        }

        try {
            $response = $this->client->post($this->getUrl($this->cancelInvoiceUrl, 'cancelInvoice'), [
                'json' => [
                    'invoice_identifier' => $invoiceIdentifier,
                    'cn_motif' => $reason,
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 30,
            ]);

            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getInvoice($invoiceIdentifier)
    {
        if (empty($this->getInvoiceUrl)) {
            return ['success' => false, 'error' => 'EBMS get invoice URL non configurée'];
        }

        if (!$this->token) {
            $this->authenticate();
        }

        try {
            $response = $this->client->post($this->getUrl($this->getInvoiceUrl, 'getInvoice'), [
                'json' => ['invoice_identifier' => $invoiceIdentifier],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 30,
            ]);

            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function addStockMovement($movementData)
    {
        if (empty($this->addStockMovementUrl)) {
            return ['success' => false, 'error' => 'EBMS stock movement URL non configurée'];
        }

        if (!$this->token) {
            $this->authenticate();
        }

        try {
            $response = $this->client->post($this->getUrl($this->addStockMovementUrl, 'AddStockMovement'), [
                'json' => $movementData,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 30,
            ]);

            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function verifySignature($result, $signature)
    {
        $decodedSignature = base64_decode($signature);
        $publicKeyResource = openssl_pkey_get_public($this->publicKey);

        if (!$publicKeyResource) {
            return false;
        }

        $verified = openssl_verify(json_encode($result), $decodedSignature, $publicKeyResource, OPENSSL_ALGO_SHA256);

        return $verified === 1;
    }

    public function generateInvoiceIdentifier($tin, $systemId, $date, $invoiceNumber)
    {
        $tinPart = !empty($tin) ? $tin : '0000000000';
        return $tinPart . '/' . $systemId . '/' . date('YmdHis', strtotime($date)) . '/' . $invoiceNumber;
    }
}
