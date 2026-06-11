<?php
// E:\laragon\www\stock-management\backend\app\Config\Routes.php

namespace Config;

// Create a new instance of our RouteCollection class.
$routes = Services::routes();

$routes->options('(:any)', function () {
    $response = service('response');
    $response->setHeader('Access-Control-Allow-Origin', '*');
    $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
    $response->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, x-auth-token');
    $response->setStatusCode(200);
    return $response;
});



// Load the system's routing file first, so that the app and ENVIRONMENT
// can override as needed.
if (file_exists(SYSTEMPATH . 'Config/Routes.php')) {
    require SYSTEMPATH . 'Config/Routes.php';
}

/*
 * --------------------------------------------------------------------
 * Router Setup
 * --------------------------------------------------------------------
 */
$routes->setDefaultNamespace('App\Controllers');
$routes->setDefaultController('Home');
$routes->setDefaultMethod('index');
$routes->setTranslateURIDashes(false);
$routes->set404Override();
$routes->setAutoRoute(false);

/*
 * --------------------------------------------------------------------
 * Route Definitions
 * --------------------------------------------------------------------
 */

// Route de test simple
$routes->get('/', function () {
    return json_encode(['message' => 'API is working']);
});

// Routes pour les fichiers uploadés
$routes->get('uploads/(:any)', 'FileController::serve/$1');
$routes->get('public/uploads/(:any)', 'FileController::serve/$1');

// ============================================
// GROUPE API PRINCIPAL
// ============================================
$routes->group('api', ['namespace' => 'App\Controllers'], function ($routes) {

    // ==========================================
    // 1. ROUTES DE TEST ET SIMPLES
    // ==========================================
    $routes->get('test', static function () {
        return service('response')->setJSON([
            'success' => true,
            'message' => 'Backend API is working!',
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    });

    // ==========================================
    // 2. AUTHENTIFICATION (login public)
    // ==========================================
    $routes->post('auth/login', 'AuthController::login');
    $routes->post('auth/reset-password', 'AuthController::resetPassword');

    // ==========================================
    // Routes protégées : JWT + permissions
    // ==========================================
    $routes->group('', ['filter' => ['auth', 'permission']], function ($routes) {

        $routes->group('auth', function ($routes) {
            $routes->post('logout', 'AuthController::logout');
            $routes->get('me', 'AuthController::me');
            $routes->get('verify', 'AuthController::verifyToken');
            $routes->post('change-password', 'AuthController::changePassword');
        });

        // ==========================================
        // RÔLES ET PERMISSIONS
        // ==========================================
        $routes->group('roles', function ($routes) {
            $routes->get('/', 'RoleController::index');
            $routes->post('/', 'RoleController::create');
            $routes->get('(:num)', 'RoleController::show/$1');
            $routes->put('(:num)', 'RoleController::update/$1');
            $routes->delete('(:num)', 'RoleController::delete/$1');
            $routes->put('(:num)/permissions', 'RoleController::updatePermissions/$1');
        });

        // ==========================================
        // PROFIL UTILISATEUR CONNECTÉ
        // ==========================================
        $routes->group('profile', function ($routes) {
            $routes->get('/', 'ProfileController::index');
            $routes->put('/', 'ProfileController::update');
        });

        // ==========================================
        // NOTIFICATIONS
        // ==========================================
        $routes->group('notifications', function ($routes) {
            $routes->get('unread-count', 'NotificationController::unreadCount');
            $routes->patch('read-all', 'NotificationController::markAllRead');
            $routes->patch('(:num)/read', 'NotificationController::markRead/$1');
            $routes->get('/', 'NotificationController::index');
            $routes->delete('(:num)', 'NotificationController::delete/$1');
        });

        // ==========================================
        // 3. UTILISATEURS
        // ==========================================
        $routes->group('users', function ($routes) {
            $routes->get('roles', 'UserController::getRoles');
            $routes->get('permissions', 'UserController::getPermissions');
            $routes->get('/', 'UserController::index');
            $routes->post('/', 'UserController::create');
            $routes->post('(:num)/reset-password', 'UserController::resetPassword/$1');
            $routes->get('(:num)', 'UserController::show/$1');
            $routes->put('(:num)', 'UserController::update/$1');
            $routes->delete('(:num)', 'UserController::delete/$1');
        });

        // ==========================================
        // 4. PRODUITS
        // ==========================================
        $routes->group('products', function ($routes) {
            // Routes spécifiques sans paramètres
            $routes->get('categories', 'ProductController::getCategories');
            $routes->post('bulk-delete', 'ProductController::bulkDelete');
            $routes->post('bulk-activate', 'ProductController::bulkActivate');
            $routes->post('bulk-deactivate', 'ProductController::bulkDeactivate');
            $routes->post('bulk-update-prices', 'ProductController::bulkUpdatePrices');
            $routes->post('recalc-prices', 'ProductController::recalcPrices');
            $routes->get('generate-code', 'ProductController::generateCode');

            // Routes avec paramètres
            $routes->get('code/(:any)', 'ProductController::getByCode/$1');

            // Routes CRUD
            $routes->get('/', 'ProductController::index');
            $routes->post('/', 'ProductController::create');
            $routes->get('(:num)', 'ProductController::show/$1');
            $routes->put('(:num)', 'ProductController::update/$1');
            $routes->delete('(:num)', 'ProductController::delete/$1');

            // Routes catégories
            $routes->post('categories', 'ProductController::createCategory');
            $routes->put('categories/(:num)', 'ProductController::updateCategory/$1');
            $routes->delete('categories/(:num)', 'ProductController::deleteCategory/$1');
        });

        // ==========================================
        // 5. FOURNISSEURS
        // ==========================================
        $routes->group('suppliers', function ($routes) {
            $routes->get('/', 'SupplierController::index');
            $routes->post('/', 'SupplierController::create');
            $routes->get('(:num)', 'SupplierController::show/$1');
            $routes->put('(:num)', 'SupplierController::update/$1');
            $routes->delete('(:num)', 'SupplierController::delete/$1');
        });

        // ==========================================
        // 6. CLIENTS
        // ==========================================
        // app/Config/Routes.php
        $routes->group('customers', function ($routes) {
            $routes->get('/', 'CustomerController::index');
            $routes->post('/', 'CustomerController::create');
            $routes->get('(:num)', 'CustomerController::show/$1');
            $routes->put('(:num)', 'CustomerController::update/$1');
            $routes->delete('(:num)', 'CustomerController::delete/$1');
        });

        // ==========================================
        // 7. COMMANDES FOURNISSEURS (PURCHASE ORDERS)
        // ==========================================
        $routes->group('purchase-orders', function ($routes) {
            // Routes spécifiques sans paramètres
            $routes->get('generate-number', 'PurchaseOrderController::generateOrderNumber');

            // Routes avec paramètres (ordre spécifique)
            $routes->get('(:num)/signatures', 'PurchaseOrderController::getSignatures/$1');
            $routes->post('(:num)/receive', 'PurchaseOrderController::receive/$1');
            $routes->post('(:num)/approve', 'PurchaseOrderController::approve/$1');
            $routes->patch('(:num)/status', 'PurchaseOrderController::updateStatus/$1');
            $routes->post('(:num)/share-email', 'PurchaseOrderController::shareEmail/$1');

            // Routes réceptions
            $routes->get('receptions/(:num)/print', 'PurchaseOrderController::printReception/$1');
            $routes->get('receptions/(:num)/attachments', 'PurchaseOrderController::getReceptionAttachments/$1');

            // Routes CRUD
            $routes->get('/', 'PurchaseOrderController::index');
            $routes->post('/', 'PurchaseOrderController::create');
            $routes->get('(:num)', 'PurchaseOrderController::show/$1');
            $routes->put('(:num)', 'PurchaseOrderController::update/$1');
            $routes->delete('(:num)', 'PurchaseOrderController::delete/$1');
        });

        // ==========================================
        // 8. RÉCEPTIONS
        // ==========================================
        $routes->group('receptions', function ($routes) {
            // Routes spécifiques
            $routes->get('verify/(:any)', 'ReceptionController::verify/$1');
            $routes->get('verify/(:num)', 'ReceptionController::verify/$1');
            $routes->get('verify-signature/(:num)', 'ReceptionController::verifySignature/$1');
            $routes->post('(:num)/sign', 'ReceptionController::sign/$1');
            $routes->get('(:num)/print-signed', 'ReceptionController::printSigned/$1');

            // Routes CRUD
            $routes->get('(:num)', 'ReceptionController::show/$1');
            $routes->put('(:num)', 'ReceptionController::update/$1');
        });

        // ==========================================
        // 9. FACTURES (INVOICES) - ORDRE SPÉCIFIQUE CORRIGÉ
        // ==========================================
        $routes->group('invoices', function ($routes) {
            // ===== 1. ROUTES STATISTIQUES ET EXPORTS =====
            $routes->get('stats', 'InvoiceController::getStats');
            $routes->get('export/csv', 'InvoiceController::exportCSV');

            // ===== 2. ROUTES D'ACTIONS GROUPÉES =====
            $routes->post('bulk/delete', 'InvoiceController::bulkDelete');
            $routes->post('bulk/sync', 'InvoiceController::bulkSync');
            $routes->post('bulk-delete', 'InvoiceController::bulkDelete');
            $routes->post('bulk-sync', 'InvoiceController::bulkSync');

            // ===== 3. ROUTES AVEC PARAMÈTRES (actions spécifiques) =====
            // Pièces jointes
            $routes->delete('(:num)/attachments/(:num)', 'InvoiceController::deleteAttachment/$1/$2');
            $routes->post('(:num)/attachments', 'InvoiceController::addAttachments/$1');
            $routes->get('(:num)/attachments', 'InvoiceController::getAttachments/$1');

            // EBMS
            $routes->get('(:num)/ebms-logs', 'InvoiceController::getEbmsLogs/$1');
            $routes->post('(:num)/sync-ebms', 'InvoiceController::syncWithEBMS/$1');
            $routes->post('(:num)/sync', 'InvoiceController::syncWithEBMS/$1');
            $routes->post('(:num)/verify', 'InvoiceController::verifyWithEBMS/$1');
            $routes->post('(:num)/verify', 'InvoiceController::verifyWithEBMS/$1');

            // Actions facture
            $routes->post('(:num)/cancel', 'InvoiceController::cancel/$1');
            $routes->post('(:num)/payments', 'InvoiceController::addPayment/$1');
            $routes->post('(:num)/payment', 'InvoiceController::addPayment/$1');
            $routes->post('(:num)/send-email', 'InvoiceController::sendEmail/$1');
            $routes->post('(:num)/email', 'InvoiceController::sendEmail/$1');
            $routes->post('(:num)/reminder', 'InvoiceController::sendReminder/$1');

            // ===== 4. ROUTES CRUD GÉNÉRIQUES =====
            $routes->get('/', 'InvoiceController::index');
            $routes->post('/', 'InvoiceController::create');
            $routes->get('(:num)', 'InvoiceController::show/$1');
            $routes->put('(:num)', 'InvoiceController::update/$1');
            $routes->delete('(:num)', 'InvoiceController::delete/$1');
        });

        // ==========================================
        // 10. RÉSERVATIONS
        // ==========================================
        $routes->group('reservations', function ($routes) {
            $routes->post('(:num)/confirm', 'ReservationController::confirm/$1');
            $routes->post('(:num)/deliver', 'ReservationController::deliver/$1');
            $routes->post('(:num)/complete-by-delivered', 'ReservationController::completeByDelivered/$1');
            $routes->get('/', 'ReservationController::index');
            $routes->post('/', 'ReservationController::create');
            $routes->get('(:num)', 'ReservationController::show/$1');
            $routes->put('(:num)', 'ReservationController::update/$1');
            $routes->delete('(:num)', 'ReservationController::delete/$1');
        });

        // ==========================================
        // 11. ENTREPÔTS
        // ==========================================
        /*$routes->group('warehouses', function ($routes) {
        $routes->get('/', 'Warehouses::index');
        $routes->get('(:num)', 'Warehouses::show/$1');
    });*/

        // Routes pour les entrepôts
        $routes->group('warehouses', function ($routes) {
            $routes->get('/', 'WarehouseController::index');
            $routes->post('/', 'WarehouseController::create');
            $routes->get('(:num)', 'WarehouseController::show/$1');
            $routes->put('(:num)', 'WarehouseController::update/$1');
            $routes->delete('(:num)', 'WarehouseController::delete/$1');
            $routes->patch('(:num)/toggle-status', 'WarehouseController::toggleStatus/$1');
            $routes->get('(:num)/stock-value', 'WarehouseController::getStockValue/$1');
        });

        // ==========================================
        // 12. STOCK
        // ==========================================
        $routes->group('stock', function ($routes) {
            // Routes spécifiques sans paramètres
            $routes->get('movements/comparison', 'StockController::getMovementComparison');
            $routes->get('summary', 'StockController::getStockSummary');
            $routes->get('warehouse-summary', 'StockController::getWarehouseStockSummary');
            $routes->get('warehouses', 'StockController::getWarehouses');
            $routes->get('reservations', 'StockController::getReservations');
            $routes->get('inventories', 'StockController::getInventories');
            $routes->post('check-stock', 'StockController::checkStock');
            $routes->post('bulk-delete', 'StockController::bulkDelete');
            $routes->post('transfer', 'StockController::transferStock');
            $routes->post('reservation', 'StockController::createReservation');
            $routes->post('inventory', 'StockController::createInventory');
            $routes->post('warehouses', 'StockController::createWarehouse');

            // Routes avec paramètres
            $routes->get('movements', 'StockController::getMovements');
            $routes->post('movement', 'StockController::addMovement');
            $routes->get('product-stock/(:num)/(:num)', 'StockController::getProductStock/$1/$2');
            $routes->get('product/(:num)/warehouse/(:num)', 'StockMovementController::getProductStock/$1/$2');
            $routes->post('movement/(:num)/attachments', 'StockController::addMovementAttachments/$1');
            $routes->get('movement/(:num)/attachments', 'StockController::getMovementAttachments/$1');
            $routes->post('inventory/complete/(:num)', 'StockController::completeInventory/$1');
            $routes->put('warehouses/(:num)', 'StockController::updateWarehouse/$1');
            $routes->delete('warehouses/(:num)', 'StockController::deleteWarehouse/$1');
            $routes->get('download-attachment/(:num)', 'StockController::downloadAttachment/$1');
            $routes->delete('attachments/(:num)', 'StockController::deleteAttachment/$1');
        });

        // ==========================================
        // 13. TAUX DE CHANGE
        // ==========================================
        $routes->group('exchange-rates', function ($routes) {
            $routes->get('latest', 'ExchangeRateController::latest');
            $routes->get('/', 'ExchangeRateController::index');
            $routes->post('/', 'ExchangeRateController::create');
            $routes->put('(:num)', 'ExchangeRateController::update/$1');
            $routes->delete('(:num)', 'ExchangeRateController::delete/$1');
        });

        // ==========================================
        // 14. RAPPORTS
        // ==========================================
        $routes->group('reports', function ($routes) {
            // Routes GET
            $routes->get('dashboard', 'ReportController::dashboard');
            $routes->get('quick-stats', 'ReportController::quickStats');
            $routes->get('stock', 'ReportController::stockReport');
            $routes->get('sales', 'ReportController::salesReport');
            $routes->get('taxes', 'ReportController::taxesReport');

            // Routes POST
            $routes->post('performance', 'ReportController::performance');
            $routes->post('inventory', 'ReportController::inventory');
            $routes->post('financial', 'ReportController::financial');
            $routes->post('orders', 'ReportController::orders');
            $routes->post('cashflow', 'ReportController::cashflow');
            $routes->post('ebms', 'ReportController::ebms');
            $routes->post('suppliers', 'ReportController::suppliers');
            $routes->post('export', 'ReportController::export');
            $routes->post('dashboard-stats', 'ReportController::dashboardStats');
            $routes->get('daily-performance', 'ReportController::getDailyPerformance');
        });

        // ==========================================
        // 15. PARAMÈTRES
        // ==========================================
        $routes->group('settings', function ($routes) {
            $routes->get('logo', 'SettingsController::getLogo');
            $routes->get('ebms/test', 'SettingsController::testEBMSConnection');
            $routes->get('ebms/check-tin', 'SettingsController::checkTIN');
            $routes->get('/', 'SettingsController::index');
            $routes->get('(:any)', 'SettingsController::show/$1');
            $routes->post('/', 'SettingsController::update');
            $routes->post('reset', 'SettingsController::reset');
            $routes->post('upload-logo', 'SettingsController::uploadLogo');
        });

        // ==========================================
        // 16. SALES DASHBOARD
        // ==========================================
        $routes->get('sales-dashboard', 'SalesDashboardController::index');
        $routes->post('sales-dashboard/collect', 'SalesDashboardController::collect');
        $routes->post('sales-dashboard/bank-deposit', 'SalesDashboardController::bankDeposit');
        $routes->post('sales-dashboard/generate-report', 'SalesDashboardController::generateReport');

        // ==========================================
        // 17. RESSOURCES (CRUD automatiques)
        // ==========================================
        $routes->resource('customers', ['controller' => 'CustomerController']);
        $routes->resource('products', ['controller' => 'ProductController']);
    }); // fin groupe auth + permission
});

// ============================================
// ROUTE POUR LES OPTIONS (CORS preflight)
// ============================================
/*$routes->options('(:any)', function () {
    return '';
});*/
/*$routes->options('(:any)', function () {
    $response = service('response');
    $response->setHeader('Access-Control-Allow-Origin', '*');
    $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
    $response->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, x-auth-token');
    $response->setHeader('Access-Control-Allow-Credentials', 'true');
    $response->setStatusCode(200);
    return $response;
});*/

// ============================================
// ROUTES POUR REACT (SPA) - À PLACER À LA FIN
// ============================================
$routes->get('/', 'HomeController::index');
$routes->get('(:any)', 'HomeController::index');

/*
 * --------------------------------------------------------------------
 * Additional Routing
 * --------------------------------------------------------------------
 */
/*if (file_exists(APPPATH . 'Config/Routes.php')) {
    require APPPATH . 'Config/Routes.php';
}*/