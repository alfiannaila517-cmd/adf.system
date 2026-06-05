<?php

function getPwfOfficePdo(): PDO
{
    $config = function_exists('getActiveBusinessConfig') ? getActiveBusinessConfig() : [];
    $dbName = $config['database'] ?? 'adf_pwf';

    if (function_exists('getDbName')) {
        $dbName = getDbName($dbName);
    }

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    ensurePwfOfficeTables($pdo);
    return $pdo;
}

function ensurePwfOfficeTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_code VARCHAR(30) UNIQUE,
        customer_name VARCHAR(150) NOT NULL,
        phone VARCHAR(50) NULL,
        address TEXT NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_craftsmen (
        id INT AUTO_INCREMENT PRIMARY KEY,
        craftsman_code VARCHAR(30) UNIQUE,
        craftsman_name VARCHAR(150) NOT NULL,
        phone VARCHAR(50) NULL,
        specialty VARCHAR(100) NULL,
        is_active TINYINT(1) DEFAULT 1,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_code VARCHAR(30) UNIQUE,
        customer_id INT NOT NULL,
        order_date DATE NOT NULL,
        due_date DATE NULL,
        product_name VARCHAR(200) NOT NULL,
        specification TEXT NULL,
        dimensions VARCHAR(120) NULL,
        quantity DECIMAL(10,2) DEFAULT 1,
        unit VARCHAR(20) DEFAULT 'pcs',
        image_path VARCHAR(255) NULL,
        assigned_craftsman_id INT NULL,
        progress_percent INT DEFAULT 0,
        status ENUM('draft','on_progress','qc','ready_ship','shipped','completed','cancelled') DEFAULT 'draft',
        notes TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_customer (customer_id),
        INDEX idx_craftsman (assigned_craftsman_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Alter pwf_orders - add pricing & detail columns
    try {
        $pdo->exec("ALTER TABLE pwf_orders ADD COLUMN unit_price DECIMAL(15,2) DEFAULT 0");
    } catch (\PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE pwf_orders ADD COLUMN total_price DECIMAL(15,2) DEFAULT 0");
    } catch (\PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE pwf_orders ADD COLUMN wood_color VARCHAR(100) NULL");
    } catch (\PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE pwf_orders ADD COLUMN finish VARCHAR(100) NULL");
    } catch (\PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE pwf_orders ADD COLUMN description TEXT NULL");
    } catch (\PDOException $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_order_progress (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        craftsman_id INT NULL,
        progress_date DATE NOT NULL,
        achievement_percent INT DEFAULT 0,
        work_note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_shipments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        shipment_date DATE NOT NULL,
        container_type ENUM('20ft','40ft','40hc','lcl') DEFAULT '20ft',
        destination_country VARCHAR(100) NULL,
        destination_port VARCHAR(100) NULL,
        forwarder VARCHAR(150) NULL,
        tracking_no VARCHAR(100) NULL,
        status ENUM('draft','booked','onboard','arrived','closed') DEFAULT 'draft',
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // qty_done migration — add column if not exists
    try {
        $pdo->exec("ALTER TABLE pwf_orders ADD COLUMN qty_done DECIMAL(10,2) NOT NULL DEFAULT 0");
    } catch (\PDOException $e) {
    }

    // partial_ship status migration — extend ENUM if not already
    try {
        $pdo->exec("ALTER TABLE pwf_orders MODIFY COLUMN status ENUM('draft','on_progress','qc','ready_ship','partial_ship','shipped','completed','cancelled') DEFAULT 'draft'");
    } catch (\PDOException $e) {
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_containers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        container_code VARCHAR(30) UNIQUE,
        container_no VARCHAR(50) NULL,
        container_type ENUM('20ft','40ft','40hc','lcl') DEFAULT '20ft',
        shipment_date DATE NOT NULL,
        destination_country VARCHAR(100) NULL,
        destination_port VARCHAR(100) NULL,
        forwarder VARCHAR(150) NULL,
        tracking_no VARCHAR(100) NULL,
        bl_no VARCHAR(100) NULL,
        status ENUM('draft','booked','onboard','arrived','closed') DEFAULT 'draft',
        notes TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_container_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        container_id INT NOT NULL,
        order_id INT NOT NULL,
        qty_shipped DECIMAL(10,2) DEFAULT 0,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_container (container_id),
        INDEX idx_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // SPK (Surat Perintah Kerja - Production Order) table
    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_spk (
        id INT AUTO_INCREMENT PRIMARY KEY,
        spk_no VARCHAR(50) UNIQUE,
        order_id INT NOT NULL,
        craftsman_id INT NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        status ENUM('draft','assigned','in_progress','completed','cancelled') DEFAULT 'draft',
        notes TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_order (order_id),
        INDEX idx_craftsman (craftsman_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function genPwfCode(PDO $pdo, string $prefix): string
{
    $yearMonth = date('Ym');
    $table = 'pwf_orders';
    $column = 'order_code';

    if ($prefix === 'CUS') {
        $table = 'pwf_customers';
        $column = 'customer_code';
    } elseif ($prefix === 'TKG') {
        $table = 'pwf_craftsmen';
        $column = 'craftsman_code';
    }

    $like = $prefix . '-' . $yearMonth . '-%';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} LIKE ?");
    $stmt->execute([$like]);
    $next = (int)$stmt->fetchColumn() + 1;

    return sprintf('%s-%s-%03d', $prefix, $yearMonth, $next);
}
