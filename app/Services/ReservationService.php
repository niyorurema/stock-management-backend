<?php

namespace App\Services;

class ReservationService
{
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Obtenir la quantité totale réservée (confirmée ou partially_delivered) non encore livrée
     * pour un produit spécifique
     *
     * @param int $productId
     * @param int $excludeReservationId (optional) - ID de réservation à exclure du calcul
     * @return float
     */
    public function getReservedQuantity($productId, $excludeReservationId = null)
    {
        $query = $this->db->table('reservation_items ri')
            ->selectSum('ri.quantity', 'qty')
            ->selectSum('ri.delivered_quantity', 'del')
            ->join('reservations r', 'r.id = ri.reservation_id')
            ->where('ri.product_id', $productId)
            ->whereIn('r.status', ['confirmed', 'partially_delivered']);

        if ($excludeReservationId) {
            $query->where('ri.reservation_id !=', $excludeReservationId);
        }

        $row = $query->get()->getRow();

        $reserved = (float) ($row->qty ?? 0) - (float) ($row->del ?? 0);
        return max(0, $reserved);
    }

    /**
     * Obtenir le stock disponible pour un produit (après déduction des réservations)
     *
     * @param float $currentStock - Le stock actuel du produit
     * @param int $productId - L'ID du produit
     * @return float
     */
    public function getAvailableStock($currentStock, $productId)
    {
        $reserved = $this->getReservedQuantity($productId);
        return max(0, (float)$currentStock - $reserved);
    }

    /**
     * Vérifier si un produit a assez de stock disponible pour une certaine quantité
     *
     * @param int $productId
     * @param float $requiredQuantity
     * @param int $excludeReservationId (optional)
     * @return array ['available' => bool, 'available_quantity' => float, 'required' => float]
     */
    public function checkStockAvailability($productId, $requiredQuantity, $excludeReservationId = null)
    {
        $product = $this->db->table('products')
            ->where('id', $productId)
            ->get()
            ->getRow();

        if (!$product) {
            return [
                'available' => false,
                'available_quantity' => 0,
                'required' => $requiredQuantity,
                'message' => 'Produit non trouvé'
            ];
        }

        $currentStock = (float) ($product->current_stock ?? 0);
        $reserved = $this->getReservedQuantity($productId, $excludeReservationId);
        $availableQuantity = max(0, $currentStock - $reserved);

        return [
            'available' => $availableQuantity >= $requiredQuantity,
            'available_quantity' => $availableQuantity,
            'reserved_quantity' => $reserved,
            'current_stock' => $currentStock,
            'required' => $requiredQuantity,
            'product_name' => $product->name ?? 'Article inconnu'
        ];
    }

    /**
     * Obtenir les informations complètes de réservation pour un produit
     *
     * @param int $productId
     * @return array
     */
    public function getReservationInfo($productId)
    {
        $product = $this->db->table('products')
            ->where('id', $productId)
            ->get()
            ->getRow();

        if (!$product) {
            return null;
        }

        $currentStock = (float) ($product->current_stock ?? 0);
        $reserved = $this->getReservedQuantity($productId);
        $available = max(0, $currentStock - $reserved);

        return [
            'product_id' => $productId,
            'product_name' => $product->name,
            'current_stock' => $currentStock,
            'reserved_quantity' => $reserved,
            'available_quantity' => $available,
            'reservation_percentage' => $currentStock > 0 ? round(($reserved / $currentStock) * 100, 2) : 0
        ];
    }
}
