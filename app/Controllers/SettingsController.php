<?php
// app/Controllers/SettingsController.php

namespace App\Controllers;

use App\Models\SettingsModel;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class SettingsController extends ResourceController
{
    use ResponseTrait;

    protected $settingsModel;

    public function __construct()
    {
       /* $this->response->setHeader('Access-Control-Allow-Origin', '*');
        $this->response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $this->response->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        $this->response->setHeader('Access-Control-Allow-Credentials', 'true');
        $this->response->setHeader('Content-Type', 'application/json');*/
        $this->settingsModel = new SettingsModel();
    }

    /*public function index()
    {
        try {
            $settings = $this->settingsModel->getAllSettings();
            return $this->respond(['success' => true, 'data' => $settings]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }*/

    public function index()
    {
        try {
            $settings = $this->settingsModel->findAll();

            // Transformer en format key => value
            $settingsData = [];
            foreach ($settings as $setting) {
                $settingsData[$setting['setting_key']] = $setting['setting_value'];
            }

            return $this->respond([
                'success' => true,
                'data' => $settingsData
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Settings error: ' . $e->getMessage());
            return $this->fail('Erreur chargement paramètres: ' . $e->getMessage(), 500);
        }
    }

    public function show($key = null)
    {
        try {
            $value = $this->settingsModel->getSetting($key);
            return $this->respond(['success' => true, 'data' => ['key' => $key, 'value' => $value]]);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update($id = null)
    {
        try {
            $input = $this->request->getJSON(true);

            if (empty($input)) {
                return $this->respond(['success' => false, 'message' => 'Aucune donnée'], 400);
            }

            $success = $this->settingsModel->updateSettings($input);

            if ($success) {
                return $this->respond(['success' => true, 'message' => 'Paramètres mis à jour']);
            } else {
                return $this->respond(['success' => false, 'message' => 'Erreur lors de la mise à jour'], 500);
            }
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    public function uploadLogo()
    {
        try {
            $file = $this->request->getFile('logo');
            if (!$file || !$file->isValid()) {
                return $this->respond(['success' => false, 'message' => 'Aucun fichier valide'], 400);
            }

            $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
            if (!in_array($file->getMimeType(), $allowedTypes)) {
                return $this->respond(['success' => false, 'message' => 'Format non supporté. Utilisez JPG, PNG ou GIF'], 400);
            }

            if ($file->getSize() > 2 * 1024 * 1024) {
                return $this->respond(['success' => false, 'message' => 'Le fichier ne doit pas dépasser 2MB'], 400);
            }

            $uploadPath = ROOTPATH . 'public/uploads/logo/';
            if (!is_dir($uploadPath)) {
                mkdir($uploadPath, 0777, true);
            }

            $filename = 'logo_' . time() . '.' . $file->getExtension();
            $file->move($uploadPath, $filename);

            if ($file->hasMoved()) {
                // Stocker le chemin relatif pour l'URL publique
                $logoPath = 'uploads/logo/' . $filename;
                $this->settingsModel->updateSetting('company_logo', $logoPath);

                // Retourner l'URL complète
                $fullUrl = base_url($logoPath);

                return $this->respond([
                    'success' => true,
                    'message' => 'Logo téléchargé avec succès',
                    'path' => $logoPath,
                    'url' => $fullUrl
                ]);
            }

            return $this->respond(['success' => false, 'message' => 'Erreur lors de l\'upload'], 500);
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }


    /**
     * GET /api/settings/logo - Récupérer le logo de l'entreprise
     */
    public function getLogo()
    {
        try {
            $settingsModel = new \App\Models\SettingsModel();
            $logoPath = $settingsModel->getSetting('company_logo');

            if ($logoPath && file_exists(ROOTPATH . 'public/' . $logoPath)) {
                $logoUrl = base_url($logoPath);
                return $this->respond([
                    'success' => true,
                    'data' => [
                        'logo_url' => $logoUrl,
                        'has_logo' => true
                    ]
                ]);
            }

            return $this->respond([
                'success' => true,
                'data' => [
                    'logo_url' => null,
                    'has_logo' => false
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function testEBMSConnection()
    {
        try {
            $ebmsClient = new \App\Libraries\EBMSClient();
            $token = $ebmsClient->authenticate();
            return $this->respond([
                'success' => true,
                'message' => 'Connexion EBMS établie avec succès',
                'data' => ['token' => $token]
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function checkTIN()
    {
        try {
            $tin = $this->request->getVar('tin');
            if (empty($tin)) {
                return $this->respond(['success' => false, 'message' => 'Veuillez fournir un NIF'], 400);
            }

            $ebmsClient = new \App\Libraries\EBMSClient();
            $result = $ebmsClient->checkTIN($tin);

            if (!empty($result['success'])) {
                return $this->respond(['success' => true, 'message' => 'NIF valide', 'data' => $result]);
            }

            return $this->respond([
                'success' => false,
                'message' => $result['error'] ?? ($result['message'] ?? 'NIF invalide'),
                'data' => $result
            ], 400);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Erreur de validation TIN: ' . $e->getMessage()
            ], 500);
        }
    }

    public function reset()
    {
        try {
            $defaultSettings = [
                'company_name' => 'ENISA BUSNESS COMPANY',
                'company_nif' => '4002141416',
                'company_rc' => '0041847/23',
                'company_center' => 'DPMC',
                'company_activity' => 'COMMERCE GENERAL',
                'company_legal_form' => 'SU',
                'company_phone' => '69928549',
                'company_commune' => 'MUKAZA',
                'company_address' => 'ROHERO',
                'company_email' => 'sasa.ezechiel@gmail.com',
                'invoice_footer_text' => 'Merci de votre confiance',
                'invoice_validity_days' => '30'
            ];

            $success = $this->settingsModel->updateSettings($defaultSettings);

            if ($success) {
                return $this->respond(['success' => true, 'message' => 'Paramètres réinitialisés', 'data' => $defaultSettings]);
            } else {
                return $this->respond(['success' => false, 'message' => 'Erreur lors de la réinitialisation'], 500);
            }
        } catch (\Exception $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
