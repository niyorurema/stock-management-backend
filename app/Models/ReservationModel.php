<?php
// app/Models/ReservationModel.php

namespace App\Models;

use CodeIgniter\Model;

class ReservationModel extends Model
{
    protected $table = 'reservations';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'reservation_number',
        'customer_id',
        'reservation_date',
        'expected_delivery_date',
        'status',
        'priority',
        'notes',
        'created_by'
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    protected $validationRules = [
        'customer_id' => 'required|numeric',
        'reservation_date' => 'required|valid_date'
    ];

    // Statuts possibles
    const STATUSES = [
        'pending' => 'En attente',
        'confirmed' => 'Confirmée',
        'partially_delivered' => 'Partiellement livrée',
        'completed' => 'Terminée',
        'cancelled' => 'Annulée'
    ];

    // Priorités
    const PRIORITIES = [
        'low' => 'Basse',
        'normal' => 'Normale',
        'high' => 'Haute',
        'urgent' => 'Urgente'
    ];

    /**
     * Récupérer une réservation avec ses détails
     */
    public function getReservationWithDetails($id)
    {
        $reservation = $this->select("
            reservations.*, 
            CASE 
                WHEN c.first_name IS NOT NULL AND c.last_name IS NOT NULL 
                    THEN CONCAT(c.first_name, ' ', c.last_name)
                WHEN c.first_name IS NOT NULL 
                    THEN c.first_name
                WHEN c.last_name IS NOT NULL 
                    THEN c.last_name
                ELSE 'Client inconnu'
            END as customer_name, 
            c.email as customer_email, 
            c.phone as customer_phone
        ")
            ->join('customers c', 'c.id = reservations.customer_id')
            ->where('reservations.id', $id)
            ->first();

        if ($reservation) {
            $items = $this->db->table('reservation_items ri')
                ->select('ri.*, p.name as product_name, p.code as product_code, p.unit')
                ->join('products p', 'p.id = ri.product_id')
                ->where('ri.reservation_id', $id)
                ->get()
                ->getResultArray();
            $reservation['items'] = $items;

            // Calculer les totaux
            $totalQuantity = 0;
            $totalDelivered = 0;
            foreach ($items as $item) {
                $totalQuantity += floatval($item['quantity'] ?? 0);
                $totalDelivered += floatval($item['delivered_quantity'] ?? 0);
            }
            $reservation['total_quantity'] = $totalQuantity;
            $reservation['total_delivered'] = $totalDelivered;

            $attachments = $this->db->table('reservation_attachments')
                ->where('reservation_id', $id)
                ->get()
                ->getResultArray();
            $reservation['attachments'] = $attachments;
        }

        return $reservation;
    }

    /**
     * Récupérer toutes les réservations
     */
    public function getAllReservations($filters = [])
    {
        $builder = $this->select("
            reservations.*, 
            CASE 
                WHEN c.first_name IS NOT NULL AND c.last_name IS NOT NULL 
                    THEN CONCAT(c.first_name, ' ', c.last_name)
                WHEN c.first_name IS NOT NULL 
                    THEN c.first_name
                WHEN c.last_name IS NOT NULL 
                    THEN c.last_name
                ELSE 'Client inconnu'
            END as customer_name, 
            c.email as customer_email
        ")
            ->join('customers c', 'c.id = reservations.customer_id')
            ->orderBy('reservations.created_at', 'DESC');

        if (!empty($filters['status'])) {
            $builder->where('reservations.status', $filters['status']);
        }

        if (!empty($filters['customer_id'])) {
            $builder->where('reservations.customer_id', $filters['customer_id']);
        }

        if (!empty($filters['date_from'])) {
            $builder->where('reservations.reservation_date >=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $builder->where('reservations.reservation_date <=', $filters['date_to'] . ' 23:59:59');
        }

        if (!empty($filters['reservation_number'])) {
            $builder->like('reservations.reservation_number', $filters['reservation_number']);
        }

        return $builder->findAll();
    }

    /**
     * Générer un numéro de réservation unique
     */
    public function generateReservationNumber()
    {
        $year = date('Y');
        $month = date('m');

        // Récupérer le dernier numéro de réservation pour l'année en cours
        $lastReservation = $this->db->table('reservations')
            ->like('reservation_number', 'RES-' . $year . '-', 'after')
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        if ($lastReservation) {
            $lastNumber = intval(substr($lastReservation['reservation_number'], -4));
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return 'RES-' . $year . '-' . $month . '-' . $newNumber;
    }

    public function insert($data = null, bool $returnID = true)
    {
        // Générer automatiquement le numéro de réservation si absent
        if (empty($data['reservation_number'])) {
            $data['reservation_number'] = $this->generateReservationNumber();
        }

        return parent::insert($data, $returnID);
    }
}
