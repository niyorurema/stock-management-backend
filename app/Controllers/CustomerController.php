<?php
// app/Controllers/CustomerController.php

namespace App\Controllers;

use App\Models\CustomerModel;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class CustomerController extends ResourceController
{
    use ResponseTrait;

    protected $modelName = CustomerModel::class;
    protected $format = 'json';

    /**
     * GET /api/customers - Liste des clients
     */
    public function index()
    {
        try {
            $filters = [
                'search' => $this->request->getVar('search'),
                'status' => $this->request->getVar('status'),
                'customer_type' => $this->request->getVar('customer_type'),
                'is_vat_subject' => $this->request->getVar('is_vat_subject')
            ];

            $page = (int)($this->request->getVar('page') ?? 1);
            $limit = (int)($this->request->getVar('limit') ?? 10);

            $customers = $this->model->getCustomersWithStats($filters);
            $total = count($customers);

            // Pagination manuelle
            $offset = ($page - 1) * $limit;
            $paginatedCustomers = array_slice($customers, $offset, $limit);

            return $this->respond([
                'success' => true,
                'data' => $paginatedCustomers,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => ceil($total / $limit)
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Customer index error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors du chargement des clients ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/customers/(:num) - Détail d'un client
     */
    public function show($id = null)
    {
        try {
            $customer = $this->model->find($id);

            if (!$customer || $customer['deleted_at'] !== null) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Client non trouvé'
                ], 404);
            }

            // Récupérer les factures du client
            $db = \Config\Database::connect();
            $invoices = $db->table('invoices')
                ->select('invoice_number, invoice_date, total_amount, payment_status')
                ->where('customer_id', $id)
                ->orderBy('invoice_date', 'DESC')
                ->limit(10)
                ->get()
                ->getResultArray();

            $customer['recent_invoices'] = $invoices;

            // Récupérer les paiements récents
            $payments = $db->table('order_payments p')
                ->select('p.amount, p.payment_date, p.payment_method, p.reference')
                ->join('orders o', 'o.id = p.order_id')
                ->join('invoices i', 'i.order_id = o.id')
                ->where('i.customer_id', $id)
                ->orderBy('p.payment_date', 'DESC')
                ->limit(10)
                ->get()
                ->getResultArray();

            $customer['recent_payments'] = $payments;

            return $this->respond([
                'success' => true,
                'data' => $customer
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Customer show error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors du chargement des détails ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/customers - Créer un client
     */
    public function create()
    {
        try {
            $input = $this->request->getJSON(true);

            // Validation
            if (empty($input['first_name']) && empty($input['last_name']) && empty($input['company_name'])) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Au moins un nom (prénom, nom ou société) est requis'
                ], 400);
            }

            if (empty($input['phone'])) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Le numéro de téléphone est requis'
                ], 400);
            }

            // Générer le numéro client
            $customerNumber = $this->model->generateCustomerNumber();

            $data = [
                'customer_number' => $customerNumber,
                'customer_type' => $input['customer_type'] ?? 'individual',
                'first_name' => $input['first_name'] ?? null,
                'last_name' => $input['last_name'] ?? null,
                'company_name' => $input['company_name'] ?? null,
                'email' => $input['email'] ?? null,
                'email_secondary' => $input['email_secondary'] ?? null,
                'phone' => $input['phone'],
                'phone_secondary' => $input['phone_secondary'] ?? null,
                'whatsapp' => $input['whatsapp'] ?? null,
                'website' => $input['website'] ?? null,
                'address_line1' => $input['address'] ?? $input['address_line1'] ?? null,
                'address_line2' => $input['address_line2'] ?? null,
                'city' => $input['city'] ?? null,
                'province' => $input['province'] ?? null,
                'postal_code' => $input['postal_code'] ?? null,
                'country' => $input['country'] ?? 'Burundi',
                'tin' => $input['tin'] ?? null,
                'is_vat_subject' => isset($input['is_vat_subject']) ? (int)$input['is_vat_subject'] : 0,
                'status' => $input['status'] ?? 'active',
                'price_tier' => $input['price_tier'] ?? 'retail',
                'payment_terms' => $input['payment_terms'] ?? 30,
                'credit_limit' => $input['credit_limit'] ?? 0,
                'notes' => $input['notes'] ?? null,
                'preferred_contact_method' => $input['preferred_contact_method'] ?? 'email',
                'preferred_payment_method' => $input['preferred_payment_method'] ?? null,
                'preferred_language' => $input['preferred_language'] ?? 'fr',
                'currency' => $input['currency'] ?? 'BIF',
                'marketing_consent' => isset($input['marketing_consent']) ? (int)$input['marketing_consent'] : 0,
                'created_by' => session()->get('user_id')
            ];

            $id = $this->model->insert($data);

            if (!$id) {
                $errors = $this->model->errors();
                return $this->respond([
                    'success' => false,
                    'message' => 'Erreur lors de la création',
                    'errors' => $errors
                ], 500);
            }

            $customer = $this->model->find($id);

            return $this->respond([
                'success' => true,
                'message' => 'Client créé avec succès',
                'data' => $customer
            ], 201);
        } catch (\Exception $e) {
            log_message('error', 'Customer create error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors de la création du client'
            ], 500);
        }
    }

    /**
     * PUT /api/customers/(:num) - Mettre à jour un client
     */
    public function update($id = null)
    {
        try {
            $customer = $this->model->find($id);

            if (!$customer || $customer['deleted_at'] !== null) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Client non trouvé'
                ], 404);
            }

            $input = $this->request->getJSON(true);

            $data = [
                'customer_type' => $input['customer_type'] ?? $customer['customer_type'],
                'first_name' => $input['first_name'] ?? $customer['first_name'],
                'last_name' => $input['last_name'] ?? $customer['last_name'],
                'company_name' => $input['company_name'] ?? $customer['company_name'],
                'email' => $input['email'] ?? $customer['email'],
                'email_secondary' => $input['email_secondary'] ?? $customer['email_secondary'],
                'phone' => $input['phone'] ?? $customer['phone'],
                'phone_secondary' => $input['phone_secondary'] ?? $customer['phone_secondary'],
                'whatsapp' => $input['whatsapp'] ?? $customer['whatsapp'],
                'website' => $input['website'] ?? $customer['website'],
                'address_line1' => $input['address'] ?? $input['address_line1'] ?? $customer['address_line1'],
                'address_line2' => $input['address_line2'] ?? $customer['address_line2'],
                'city' => $input['city'] ?? $customer['city'],
                'province' => $input['province'] ?? $customer['province'],
                'postal_code' => $input['postal_code'] ?? $customer['postal_code'],
                'country' => $input['country'] ?? $customer['country'],
                'tin' => $input['tin'] ?? $customer['tin'],
                'is_vat_subject' => isset($input['is_vat_subject']) ? (int)$input['is_vat_subject'] : $customer['is_vat_subject'],
                'status' => $input['status'] ?? $customer['status'],
                'price_tier' => $input['price_tier'] ?? $customer['price_tier'],
                'payment_terms' => $input['payment_terms'] ?? $customer['payment_terms'],
                'credit_limit' => $input['credit_limit'] ?? $customer['credit_limit'],
                'notes' => $input['notes'] ?? $customer['notes'],
                'preferred_contact_method' => $input['preferred_contact_method'] ?? $customer['preferred_contact_method'],
                'preferred_payment_method' => $input['preferred_payment_method'] ?? $customer['preferred_payment_method'],
                'preferred_language' => $input['preferred_language'] ?? $customer['preferred_language'],
                'currency' => $input['currency'] ?? $customer['currency'],
                'marketing_consent' => isset($input['marketing_consent']) ? (int)$input['marketing_consent'] : $customer['marketing_consent'],
                'updated_by' => session()->get('user_id')
            ];

            $this->model->update($id, $data);

            return $this->respond([
                'success' => true,
                'message' => 'Client mis à jour avec succès'
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Customer update error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour'
            ], 500);
        }
    }

    /**
     * DELETE /api/customers/(:num) - Supprimer un client (soft delete)
     */
    public function delete($id = null)
    {
        try {
            $customer = $this->model->find($id);

            if (!$customer) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Client non trouvé'
                ], 404);
            }

            // Vérifier si le client a des factures
            $db = \Config\Database::connect();
            $hasInvoices = $db->table('invoices')
                ->where('customer_id', $id)
                ->where('deleted_at IS NULL')
                ->countAllResults() > 0;

            if ($hasInvoices) {
                // Soft delete uniquement
                $this->model->delete($id);
                return $this->respond([
                    'success' => true,
                    'message' => 'Client désactivé avec succès (il a des factures associées)'
                ]);
            } else {
                // Suppression définitive
                $this->model->delete($id, true);
                return $this->respond([
                    'success' => true,
                    'message' => 'Client supprimé avec succès'
                ]);
            }
        } catch (\Exception $e) {
            log_message('error', 'Customer delete error: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'message' => 'Erreur lors de la suppression'
            ], 500);
        }
    }
}

