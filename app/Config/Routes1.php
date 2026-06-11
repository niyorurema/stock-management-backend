<?php
// E:\laragon\www\stock-management\backend\app\Config\Routes.php

namespace Config;

// Create a new instance of our RouteCollection class.
$routes = Services::routes();

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




$routes->get('uploads/(:any)', 'FileController::serve/$1');
$routes->get('public/uploads/(:any)', 'FileController::serve/$1');
// ============================================
// GROUPE API PRINCIPAL
// ============================================
$routes->group('api', ['namespace' => 'App\Controllers'], function ($routes) {

    // --------------------------------------------
    // AUTHENTIFICATION
    // --------------------------------------------
    $routes->group('auth', function ($routes) {
        $routes->post('login', 'AuthController::login');
        $routes->post('logout', 'AuthController::logout');
        $routes->post('reset-password', 'AuthController::resetPassword');
        $routes->post('change-password', 'AuthController::changePassword');
    });


    // app/Config/Routes.php

    $routes->group('receptions', function ($routes) {
        $routes->get('(:num)', 'ReceptionController::show/$1');
        $routes->put('(:num)', 'ReceptionController::update/$1');
        $routes->post('(:num)/sign', 'ReceptionController::sign/$1');
        $routes->get('verify/(:any)', 'ReceptionController::verify/$1');
        $routes->get('verify-signature/(:num)', 'ReceptionController::verifySignature/$1');
        $routes->get('verify/(:num)', 'ReceptionController::verify/$1');
        $routes->get('(:num)/print-signed', 'ReceptionController::printSigned/$1');
    });

    // --------------------------------------------
    // UTILISATEURS
    // --------------------------------------------
    $routes->group('users', function ($routes) {
        $routes->get('/', 'UserController::index');
        $routes->get('(:num)', 'UserController::show/$1');
        $routes->post('/', 'UserController::create');
        $routes->put('(:num)', 'UserController::update/$1');
        $routes->delete('(:num)', 'UserController::delete/$1');
        $routes->post('(:num)/reset-password', 'UserController::resetPassword/$1');
        $routes->get('roles', 'UserController::getRoles');
        $routes->get('permissions', 'UserController::getPermissions');
    });

    // --------------------------------------------
    // PRODUITS
    // --------------------------------------------
    $routes->group('products', function ($routes) {
        $routes->get('/', 'ProductController::index');
        $routes->get('(:num)', 'ProductController::show/$1');
        $routes->post('/', 'ProductController::create');
        $routes->put('(:num)', 'ProductController::update/$1');
        $routes->delete('(:num)', 'ProductController::delete/$1');
        $routes->post('bulk-delete', 'ProductController::bulkDelete');
        $routes->post('bulk-activate', 'ProductController::bulkActivate');
        $routes->post('bulk-deactivate', 'ProductController::bulkDeactivate');
        $routes->get('categories', 'ProductController::getCategories');
        $routes->post('categories', 'ProductController::createCategory');
        $routes->put('categories/(:num)', 'ProductController::updateCategory/$1');
        $routes->delete('categories/(:num)', 'ProductController::deleteCategory/$1');
        // app/Config/Routes.php - Ajouter ces routes
        $routes->get('code/(:any)', 'ProductController::getByCode/$1');
        $routes->post('bulk-update-prices', 'ProductController::bulkUpdatePrices');
        $routes->post('recalc-prices', 'ProductController::recalcPrices');
        $routes->get('generate-code', 'ProductController::generateCode');
    });

    // --------------------------------------------
    // FOURNISSEURS
    // --------------------------------------------
    $routes->group('suppliers', function ($routes) {
        $routes->get('/', 'SupplierController::index');
        $routes->get('(:num)', 'SupplierController::show/$1');
        $routes->post('/', 'SupplierController::create');
        $routes->put('(:num)', 'SupplierController::update/$1');
        $routes->delete('(:num)', 'SupplierController::delete/$1');
    });

    // --------------------------------------------
    // COMMANDES FOURNISSEURS (PURCHASE ORDERS)
    // --------------------------------------------
    $routes->group('purchase-orders', function ($routes) {
        $routes->get('/', 'PurchaseOrderController::index');
        $routes->get('(:num)', 'PurchaseOrderController::show/$1');
        $routes->post('/', 'PurchaseOrderController::create');
        $routes->put('(:num)', 'PurchaseOrderController::update/$1');
        $routes->delete('(:num)', 'PurchaseOrderController::delete/$1');
        $routes->post('(:num)/receive', 'PurchaseOrderController::receive/$1');
        $routes->post('(:num)/approve', 'PurchaseOrderController::approve/$1');
        $routes->patch('(:num)/status', 'PurchaseOrderController::updateStatus/$1');
        $routes->post('(:num)/share-email', 'PurchaseOrderController::shareEmail/$1');
        $routes->get('generate-number', 'PurchaseOrderController::generateOrderNumber');

        $routes->get('receptions/(:num)/print', 'PurchaseOrderController::printReception/$1');
        $routes->get('receptions/(:num)/attachments', 'PurchaseOrderController::getReceptionAttachments/$1');
        $routes->get('receptions/(:num)/print', 'PurchaseOrderController::printReception/$1');
        // app/Config/Routes.php
        $routes->get('(:num)/signatures', 'PurchaseOrderController::getSignatures/$1');
    });

    $routes->get('suppliers', 'SupplierController::index');
    $routes->get('suppliers/(:num)', 'SupplierController::show/$1');
    $routes->post('suppliers', 'SupplierController::create');
    $routes->put('suppliers/(:num)', 'SupplierController::update/$1');
    $routes->delete('suppliers/(:num)', 'SupplierController::delete/$1');

    // --------------------------------------------
    // CLIENTS
    // --------------------------------------------
    $routes->group('customers', function ($routes) {
        $routes->get('/', 'CustomerController::index');
        $routes->get('(:num)', 'CustomerController::show/$1');
        $routes->post('/', 'CustomerController::create');
        $routes->put('(:num)', 'CustomerController::update/$1');
        $routes->delete('(:num)', 'CustomerController::delete/$1');
    });

    // --------------------------------------------
    // FACTURES (INVOICES)
    // --------------------------------------------
    $routes->group('invoices', function ($routes) {
        $routes->get('/', 'InvoiceController::index');
        $routes->get('(:num)', 'InvoiceController::show/$1');
        $routes->post('/', 'InvoiceController::create');
        $routes->put('(:num)', 'InvoiceController::update/$1');
        $routes->delete('(:num)', 'InvoiceController::delete/$1');
        $routes->post('(:num)/cancel', 'InvoiceController::cancel/$1');
        $routes->post('(:num)/payments', 'InvoiceController::addPayment/$1');
        $routes->post('(:num)/sync', 'InvoiceController::syncWithEBMS/$1');
        $routes->post('(:num)/verify', 'InvoiceController::verifyWithEBMS/$1');
        $routes->post('(:num)/send-email', 'InvoiceController::sendEmail/$1');
        $routes->post('(:num)/attachments', 'InvoiceController::addAttachments/$1');
        $routes->post('bulk-delete', 'InvoiceController::bulkDelete');


        $routes->post('(:num)/sync-ebms', 'InvoiceController::syncWithEBMS/$1');
        $routes->post('(:num)/email', 'InvoiceController::sendEmail/$1');
        $routes->post('(:num)/reminder', 'InvoiceController::sendReminder/$1');
        $routes->get('(:num)/ebms-logs', 'InvoiceController::getEbmsLogs/$1');

        $routes->post('bulk/sync', 'InvoiceController::bulkSync');

        // Statistiques
        $routes->get('stats', 'InvoiceController::getStats');
        $routes->get('export/csv', 'InvoiceController::exportCSV');
        $routes->post('bulk-sync', 'InvoiceController::bulkSync');
        $routes->delete('(:num)/attachments/(:num)', 'InvoiceController::deleteAttachment/$1/$2');
    });

    $routes->resource('customers', ['controller' => 'CustomerController']);
    $routes->resource('products', ['controller' => 'ProductController']);
    $routes->resource('warehouses', ['controller' => 'WarehouseController']);

    // --------------------------------------------
    // RÉSERVATIONS
    // --------------------------------------------
    $routes->group('reservations', function ($routes) {
        $routes->get('/', 'ReservationController::index');
        $routes->get('(:num)', 'ReservationController::show/$1');
        $routes->post('/', 'ReservationController::create');
        $routes->put('(:num)', 'ReservationController::update/$1');
        $routes->delete('(:num)', 'ReservationController::delete/$1');
        $routes->post('(:num)/deliver', 'ReservationController::deliver/$1');
    });

    // --------------------------------------------
    // ENTREPÔTS
    // --------------------------------------------
    $routes->group('warehouses', function ($routes) {
        $routes->get('/', 'Warehouses::index');
        $routes->get('(:num)', 'Warehouses::show/$1');
    });

    // --------------------------------------------
    // STOCK
    // --------------------------------------------
    $routes->group('stock', function ($routes) {
        $routes->get('movements', 'StockController::getMovements');
        $routes->post('movement', 'StockController::addMovement');
        $routes->post('transfer', 'StockController::transferStock');
        $routes->post('bulk-delete', 'StockController::bulkDelete');
        $routes->get('summary', 'StockController::getStockSummary');
        $routes->get('product-stock/(:num)/(:num)', 'StockController::getProductStock/$1/$2');
        $routes->post('check-stock', 'StockController::checkStock');

        // Entrepôts (stock)
        $routes->get('warehouses', 'StockController::getWarehouses');
        $routes->post('warehouses', 'StockController::createWarehouse');
        $routes->put('warehouses/(:num)', 'StockController::updateWarehouse/$1');
        $routes->delete('warehouses/(:num)', 'StockController::deleteWarehouse/$1');

        // Réservations (stock)
        $routes->post('reservation', 'StockController::createReservation');
        $routes->get('reservations', 'StockController::getReservations');

        // Inventaire
        $routes->post('inventory', 'StockController::createInventory');
        $routes->post('inventory/complete/(:num)', 'StockController::completeInventory/$1');
        $routes->get('inventories', 'StockController::getInventories');
        $routes->get('movements/comparison', 'StockController::getMovementComparison');

        // Attachments
        $routes->get('download-attachment/(:num)', 'StockController::downloadAttachment/$1');
        $routes->post('movement/(:num)/attachments', 'StockController::addMovementAttachments/$1');
        $routes->get('movement/(:num)/attachments', 'StockController::getMovementAttachments/$1');
        $routes->delete('attachments/(:num)', 'StockController::deleteAttachment/$1');

        // Stock produit par entrepôt
        $routes->get('product/(:num)/warehouse/(:num)', 'StockMovementController::getProductStock/$1/$2');
    });

    // --------------------------------------------
    // TAUX DE CHANGE
    // --------------------------------------------
    $routes->group('exchange-rates', function ($routes) {
        $routes->get('latest', 'ExchangeRateController::latest');
        $routes->get('/', 'ExchangeRateController::index');
        $routes->post('/', 'ExchangeRateController::create');
        $routes->put('(:num)', 'ExchangeRateController::update/$1');
        $routes->delete('(:num)', 'ExchangeRateController::delete/$1');
    });

    // --------------------------------------------
    // RAPPORTS
    // --------------------------------------------
    $routes->group('reports', function ($routes) {
        $routes->get('dashboard', 'ReportController::dashboard');
        $routes->get('quick-stats', 'ReportController::quickStats');
        $routes->post('performance', 'ReportController::performance');
        $routes->post('inventory', 'ReportController::inventory');
        $routes->post('financial', 'ReportController::financial');
        $routes->post('orders', 'ReportController::orders');
        $routes->post('cashflow', 'ReportController::cashflow');
        $routes->post('ebms', 'ReportController::ebms');
        $routes->post('suppliers', 'ReportController::suppliers');
        $routes->post('export', 'ReportController::export');
        $routes->post('dashboard-stats', 'ReportController::dashboardStats');
        $routes->get('stock', 'ReportController::stockReport');
        $routes->get('sales', 'ReportController::salesReport');
        $routes->get('taxes', 'ReportController::taxesReport');
    });

    // --------------------------------------------
    // PARAMÈTRES
    // --------------------------------------------
    $routes->group('settings', function ($routes) {
        $routes->get('/', 'SettingsController::index');
        $routes->get('ebms/test', 'SettingsController::testEBMSConnection');
        $routes->get('ebms/check-tin', 'SettingsController::checkTIN');
        $routes->post('/', 'SettingsController::update');
        $routes->post('reset', 'SettingsController::reset');
        $routes->post('upload-logo', 'SettingsController::uploadLogo');
        $routes->get('(:any)', 'SettingsController::show/$1');
    });

    // --------------------------------------------
    // TEST
    // --------------------------------------------
    $routes->get('test', function () {
        return $this->response->setJSON([
            'success' => true,
            'message' => 'Backend API is working!',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    });

    $routes->get('settings/logo', 'SettingsController::getLogo');


    $routes->get('sales-dashboard', 'SalesDashboardController::index');
    $routes->post('sales-dashboard/collect', 'SalesDashboardController::collect');
    $routes->post('sales-dashboard/bank-deposit', 'SalesDashboardController::bankDeposit');
    $routes->get('sales-dashboard', 'SalesDashboardController::index');
    $routes->post('sales-dashboard/generate-report', 'SalesDashboardController::generateReport');
});


// ============================================
// ROUTE POUR LES OPTIONS (CORS preflight)
// ============================================
$routes->options('(:any)', function () {
    return '';
});

// ============================================
// ROUTES POUR REACT (SPA)
// ============================================
$routes->get('(:any)', 'HomeController::index');
$routes->get('/', 'HomeController::index');
// app/Config/Routes.php


/*
 * --------------------------------------------------------------------
 * Additional Routing
 * --------------------------------------------------------------------
 */
/*if (file_exists(APPPATH . 'Config/Routes.php')) {
    require APPPATH . 'Config/Routes.php';
}*/