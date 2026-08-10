<?php
/**
 * Gudang Nasita Configuration
 * Warehouse business configuration - connects to master DB
 */

return [
    'name'                  => 'Gudang Nasita',
    'slug'                  => 'gudang-nasita',
    'code'                  => 'gudang-nasita',
    'type'                  => 'warehouse',
    'database'              => 'adfb2574_adf',  // Uses master DB for warehouse tables
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
    ],
];
?>
