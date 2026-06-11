<?php
// app/Controllers/ExchangeRateController.php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class ExchangeRateController extends ResourceController
{
    use ResponseTrait;
    
    protected $db;
    protected $format = 'json';
    
    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }
    
    /**
     * GET /api/exchange-rates - Liste des taux de change
     */
    public function index()
    {
        try {
            $rates = $this->db->table('exchange_rates')
                ->orderBy('effective_date', 'DESC')
                ->orderBy('from_currency', 'ASC')
                ->get()
                ->getResultArray();
            
            return $this->respond([
                'success' => true,
                'data' => $rates
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * GET /api/exchange-rates/latest - Derniers taux de change
     */
    public function latest()
    {
        try {
            // Récupérer le dernier taux AED -> USD
            $aedToUsd = $this->db->table('exchange_rates')
                ->where('from_currency', 'AED')
                ->where('to_currency', 'USD')
                ->orderBy('effective_date', 'DESC')
                ->get()
                ->getRowArray();
            
            // Récupérer le dernier taux USD -> BIF
            $usdToBif = $this->db->table('exchange_rates')
                ->where('from_currency', 'USD')
                ->where('to_currency', 'BIF')
                ->orderBy('effective_date', 'DESC')
                ->get()
                ->getRowArray();
            
            $data = [
                'AED_to_USD' => $aedToUsd ? (float)$aedToUsd['rate'] : 3.6725,
                'USD_to_BIF' => $usdToBif ? (float)$usdToBif['rate'] : 2830.0
            ];
            
            return $this->respond([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * POST /api/exchange-rates - Créer un taux de change
     */
    public function create()
    {
        try {
            $input = $this->request->getJSON(true);
            
            // Vérifier si les données sont au format simple (rates)
            if (isset($input['rates']) && is_array($input['rates'])) {
                // Format: { rates: { AED_to_USD: 3.6725, USD_to_BIF: 2830 }, effective_date: "2025-01-01" }
                $rates = $input['rates'];
                $effectiveDate = $input['effective_date'] ?? date('Y-m-d');
                $createdBy = session()->get('user_id');
                
                $saved = 0;
                
                // Sauvegarder AED -> USD
                if (isset($rates['AED_to_USD'])) {
                    $data = [
                        'from_currency' => 'AED',
                        'to_currency' => 'USD',
                        'rate' => (float)$rates['AED_to_USD'],
                        'effective_date' => $effectiveDate,
                        'created_by' => $createdBy
                    ];
                    $this->db->table('exchange_rates')->insert($data);
                    $saved++;
                }
                
                // Sauvegarder USD -> BIF
                if (isset($rates['USD_to_BIF'])) {
                    $data = [
                        'from_currency' => 'USD',
                        'to_currency' => 'BIF',
                        'rate' => (float)$rates['USD_to_BIF'],
                        'effective_date' => $effectiveDate,
                        'created_by' => $createdBy
                    ];
                    $this->db->table('exchange_rates')->insert($data);
                    $saved++;
                }
                
                return $this->respond([
                    'success' => true,
                    'message' => $saved . ' taux de change enregistrés',
                    'data' => $rates
                ], 201);
            }
            
            // Format standard: { from_currency, to_currency, rate, effective_date }
            if (empty($input['from_currency']) || empty($input['to_currency']) || empty($input['rate'])) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Les champs from_currency, to_currency et rate sont requis'
                ], 400);
            }
            
            $data = [
                'from_currency' => strtoupper($input['from_currency']),
                'to_currency' => strtoupper($input['to_currency']),
                'rate' => (float)$input['rate'],
                'effective_date' => $input['effective_date'] ?? date('Y-m-d'),
                'created_by' => session()->get('user_id')
            ];
            
            $this->db->table('exchange_rates')->insert($data);
            $id = $this->db->insertID();
            
            return $this->respond([
                'success' => true,
                'message' => 'Taux de change enregistré avec succès',
                'data' => ['id' => $id]
            ], 201);
            
        } catch (\Exception $e) {
            log_message('error', 'ExchangeRate create error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * PUT /api/exchange-rates/(:num) - Mettre à jour un taux de change
     */
    public function update($id = null)
    {
        try {
            $input = $this->request->getJSON(true);
            
            $rate = $this->db->table('exchange_rates')
                ->where('id', $id)
                ->get()
                ->getRowArray();
            
            if (!$rate) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Taux de change non trouvé'
                ], 404);
            }
            
            $data = [];
            if (isset($input['rate'])) $data['rate'] = (float)$input['rate'];
            if (isset($input['effective_date'])) $data['effective_date'] = $input['effective_date'];
            
            if (empty($data)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Aucune donnée à mettre à jour'
                ], 400);
            }
            
            $this->db->table('exchange_rates')
                ->where('id', $id)
                ->update($data);
            
            return $this->respond([
                'success' => true,
                'message' => 'Taux de change mis à jour avec succès'
            ]);
            
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * DELETE /api/exchange-rates/(:num) - Supprimer un taux de change
     */
    public function delete($id = null)
    {
        try {
            $rate = $this->db->table('exchange_rates')
                ->where('id', $id)
                ->get()
                ->getRowArray();
            
            if (!$rate) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Taux de change non trouvé'
                ], 404);
            }
            
            $this->db->table('exchange_rates')
                ->where('id', $id)
                ->delete();
            
            return $this->respond([
                'success' => true,
                'message' => 'Taux de change supprimé avec succès'
            ]);
            
        } catch (\Exception $e) {
            return $this->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
