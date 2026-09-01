<?php

/**
 * Gudang Nasita Configuration
 * Warehouse business configuration - connects to master DB
 */

$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false &&
    strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

return [
    'name'                  => 'Gudang Nasita',
    'slug'                  => 'gudang-nasita',
    'code'                  => 'gudang-nasita',
    'type'                  => 'warehouse',
    'database'              => $isProduction ? 'adfb2574_adf' : (defined('DB_NAME') ? DB_NAME : 'adf_system'),
    'is_active'             => true,
    'status'                => 'active',

    // Warehouse specific config
    'warehouse'             => [
        'enable_stock_tracking'     => true,
        'enable_po_supplier'        => true,
        'enable_transfers'          => true,
        'enable_minimum_stock'      => true,
        'transfer_targets'          => ['narayana-hotel', 'bens-cafe', 'eaat-meet'],  // Businesses that can receive transfers
        'default_location'          => 'Gudang Utama',
    ],

    // Module settings
    'modules_enabled'       => [
        'gudang_dashboard',
        'gudang_barang',
        'gudang_stock',
        'gudang_po_supplier',
        'gudang_transfers',
        'gudang_minimum_stock',
        'gudang_reports',
        'gudang_finance',
    ],

    // Permissions for warehouse operations
    'default_permissions'   => [
        'gudang_view',
        'gudang_barang_view',
        'gudang_stock_view',
        'gudang_stock_edit',
        'gudang_po_view',
        'gudang_po_create',
        'gudang_transfer_view',
        'gudang_transfer_create',
        'gudang_reports_view',
        'gudang_finance',
    ],
];
