<?php

namespace App\Libraries;

/**
 * Coût moyen pondéré (CMP) à l'entrée en stock.
 */
class StockCostingService
{
    /**
     * Calcule le nouveau prix de revient après entrée.
     *
     * @return array{unit_cost: float, new_average_cost: float, previous_average_cost: float}
     */
    public function calculateWeightedAverage(
        float $currentQty,
        float $currentAverageCost,
        float $incomingQty,
        float $incomingUnitCost
    ): array {
        $incomingUnitCost = max(0, $incomingUnitCost);
        $currentAverageCost = max(0, $currentAverageCost);

        if ($incomingQty <= 0) {
            return [
                'unit_cost' => $incomingUnitCost,
                'new_average_cost' => $currentAverageCost,
                'previous_average_cost' => $currentAverageCost,
            ];
        }

        $totalQty = $currentQty + $incomingQty;
        if ($totalQty <= 0) {
            $newAvg = $incomingUnitCost;
        } elseif ($currentQty <= 0) {
            $newAvg = $incomingUnitCost;
        } else {
            $newAvg = (($currentQty * $currentAverageCost) + ($incomingQty * $incomingUnitCost)) / $totalQty;
        }

        return [
            'unit_cost' => round($incomingUnitCost, 4),
            'new_average_cost' => round($newAvg, 4),
            'previous_average_cost' => round($currentAverageCost, 4),
        ];
    }

    public function isInboundMovement(string $movementType): bool
    {
        return in_array($movementType, ['EN', 'ER', 'EI', 'EAJ', 'ET', 'EAU'], true);
    }
}
