<?php

namespace Config;

/**
 * Cartographie route API → permission requise.
 * Clé: "METHOD /chemin/pattern" (sans préfixe api/)
 * Valeur: permission ou liste (une suffit).
 */
class Permissions
{
  public static array $routes = [
    // Utilisateurs
    'GET users' => 'users.view',
    'GET users/roles' => 'users.view',
    'GET users/permissions' => ['roles.view', 'users.view'],
    'GET users/*' => 'users.view',
    'POST users' => 'users.create',
    'POST users/*/reset-password' => 'users.edit',
    'PUT users/*' => 'users.edit',
    'DELETE users/*' => 'users.delete',

    // Rôles
    'GET roles' => 'roles.view',
    'GET roles/*' => 'roles.view',
    'POST roles' => 'roles.manage',
    'PUT roles/*' => 'roles.manage',
    'DELETE roles/*' => 'roles.manage',
    'PUT roles/*/permissions' => 'roles.manage',

    // Produits
    'GET products' => 'products.view',
    'GET products/categories' => 'categories.view',
    'GET products/*' => 'products.view',
    'POST products' => 'products.create',
    'PUT products/*' => 'products.edit',
    'DELETE products/*' => 'products.delete',
    'POST products/bulk-delete' => 'products.delete',
    'POST products/bulk-activate' => 'products.edit',
    'POST products/bulk-deactivate' => 'products.edit',
    'POST products/bulk-update-prices' => 'products.edit',
    'POST products/recalc-prices' => 'products.edit',
    'POST products/categories' => 'categories.manage',
    'PUT products/categories/*' => 'categories.manage',
    'DELETE products/categories/*' => 'categories.manage',

    // Stock
    'GET stock/*' => 'stock.view',
    'POST stock/movement' => 'stock.movement',
    'POST stock/transfer' => 'stock.transfer',
    'POST stock/inventory' => 'stock.inventory',
    'POST stock/reservation' => 'stock.reservations',
    'POST stock/bulk-delete' => 'stock.adjust',
    'POST stock/warehouses' => 'warehouses.create',
    'PUT stock/warehouses/*' => 'warehouses.edit',
    'DELETE stock/warehouses/*' => 'warehouses.delete',

    // Factures
    'GET invoices' => 'invoices.view',
    'GET invoices/*' => 'invoices.view',
    'POST invoices' => 'invoices.create',
    'PUT invoices/*' => 'invoices.edit',
    'DELETE invoices/*' => 'invoices.delete',
    'POST invoices/*/cancel' => 'invoices.cancel',
    'POST invoices/*/sync-ebms' => 'invoices.sync_ebms',
    'POST invoices/*/sync' => 'invoices.sync_ebms',
    'POST invoices/bulk/*' => 'invoices.delete',

    // Clients / fournisseurs
    'GET customers' => 'customers.view',
    'GET customers/*' => 'customers.view',
    'POST customers' => 'customers.create',
    'PUT customers/*' => 'customers.edit',
    'DELETE customers/*' => 'customers.delete',

    'GET suppliers' => 'suppliers.view',
    'GET suppliers/*' => 'suppliers.view',
    'POST suppliers' => 'suppliers.create',
    'PUT suppliers/*' => 'suppliers.edit',
    'DELETE suppliers/*' => 'suppliers.delete',

    // Commandes
    'GET purchase-orders' => 'purchase_orders.view',
    'GET purchase-orders/*' => 'purchase_orders.view',
    'POST purchase-orders' => 'purchase_orders.create',
    'PUT purchase-orders/*' => 'purchase_orders.edit',
    'DELETE purchase-orders/*' => 'purchase_orders.delete',
    'POST purchase-orders/*/approve' => 'purchase_orders.approve',
    'POST purchase-orders/*/receive' => 'purchase_orders.receive',

    'POST reservations/*/confirm' => 'reservations.manage',

    // Entrepôts
    'GET warehouses' => 'warehouses.view',
    'GET warehouses/*' => 'warehouses.view',
    'POST warehouses' => 'warehouses.create',
    'PUT warehouses/*' => 'warehouses.edit',
    'DELETE warehouses/*' => 'warehouses.delete',
    'PATCH warehouses/*' => 'warehouses.edit',

    // Réservations
    'GET reservations' => 'reservations.view',
    'GET reservations/*' => 'reservations.view',
    'POST reservations' => 'reservations.manage',
    'PUT reservations/*' => 'reservations.manage',
    'DELETE reservations/*' => 'reservations.manage',
    'POST reservations/*/deliver' => 'reservations.manage',

    // Rapports
    'GET reports/*' => 'reports.view',
    'POST reports/*' => 'reports.view',

    // Paramètres
    'GET settings' => 'settings.view',
    'GET settings/*' => 'settings.view',
    'POST settings' => 'settings.edit',
    'POST settings/*' => 'settings.edit',

    // Ventes
    'GET sales-dashboard' => 'sales_dashboard.view',
    'POST sales-dashboard/*' => 'sales_dashboard.manage',

    // Taux
    'GET exchange-rates' => 'exchange_rates.view',
    'GET exchange-rates/*' => 'exchange_rates.view',
    'POST exchange-rates' => 'exchange_rates.manage',
    'PUT exchange-rates/*' => 'exchange_rates.manage',
    'DELETE exchange-rates/*' => 'exchange_rates.manage',

    // Notifications (tout utilisateur connecté)
    'GET notifications' => 'notifications.view',
    'GET notifications/*' => 'notifications.view',
    'PATCH notifications/*' => 'notifications.view',
    'DELETE notifications/*' => 'notifications.view',

    // Profil (pas de permission métier — auth suffit)
  ];

  /** Routes sans contrôle de permission (auth JWT seulement). */
  public static array $authOnly = [
    'GET profile',
    'PUT profile',
    'POST auth/logout',
    'GET auth/me',
    'POST auth/change-password',
    'POST auth/refresh',
    // Tableau de bord & stats (tout utilisateur connecté)
    'GET reports/dashboard',
    'POST reports/performance',
    'POST reports/dashboard-stats',
    'GET reports/quick-stats',
    'GET reports/daily-performance',
    'POST reports/inventory',
    'POST reports/financial',
    'POST reports/suppliers',
    'POST reports/export',
    'GET exchange-rates/latest',
    'GET sales-dashboard/export',
    'POST settings/upload-logo',
  ];
}
