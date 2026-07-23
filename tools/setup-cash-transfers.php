<?php
/**
 * Setup Cash Transfers Table
 * Untuk tracking & arsip setor tunai antar rekening kas
 * Usage: Run di browser sebagai developer /tools/setup-cash-transfers.php
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once dirname(dirname(__FILE__)) . '/config/database.php';
require_once dirname(dirname(__FILE__)) . '/includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn() || $auth->getCurrentUser()['role'] !== 'developer') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_table') {
    try {
        // Create cash_transfers table di master database
        $sql = "
        CREATE TABLE IF NOT EXISTS `cash_transfers` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `business_id` INT(11) NOT NULL,
          `cash_account_id` INT(11) NOT NULL COMMENT 'Akun kas tunai yang dikurangi',
          `bank_account_id` INT(11) NOT NULL COMMENT 'Rekening bank yang ditambah',
          `amount` DECIMAL(15,2) NOT NULL,
          `transfer_date` DATE NOT NULL,
          `transfer_time` TIME NOT NULL,
          `reference_number` VARCHAR(50) COMMENT 'Nomor referensi dari cash_book atau cash_account_transactions',
          `description` TEXT,
          `created_by` INT(11),
          `is_archived` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Status arsip',
          `archived_at` DATETIME NULL COMMENT 'Waktu arsip',
          `archived_by` INT(11) NULL COMMENT 'Siapa yang mengarsip',
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `business_id` (`business_id`),
          KEY `cash_account_id` (`cash_account_id`),
          KEY `bank_account_id` (`bank_account_id`),
          KEY `transfer_date` (`transfer_date`),
          KEY `is_archived` (`is_archived`),
          FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE RESTRICT,
          FOREIGN KEY (`bank_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        $db->getConnection()->exec($sql);
        
        $success = '✅ Tabel cash_transfers berhasil dibuat! Sekarang admin bisa gunakan tombol "Setor Tunai" di buku kas.';
        
    } catch (Exception $e) {
        $error = '❌ Error: ' . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Cash Transfers - ADF System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 600px;
            width: 100%;
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .content {
            padding: 40px;
        }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-size: 14px;
            line-height: 1.6;
        }
        .alert-success {
            background: #dcfce7;
            border: 1px solid #86efac;
            color: #166534;
        }
        .alert-error {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            color: #991b1b;
        }
        .info-box {
            background: #dbeafe;
            border: 1px solid #7dd3fc;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 25px;
            font-size: 14px;
            color: #0c4a6e;
            line-height: 1.6;
        }
        .info-box strong {
            display: block;
            margin-bottom: 8px;
        }
        button {
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #0284c7;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }
        .back-link:hover {
            color: #0369a1;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🏦 Setup Setor Tunai</h1>
            <p>Persiapan fitur setor tunai ke rekening operasional</p>
        </div>
        
        <div class="content">
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="info-box">
                <strong>Fitur: Setor Tunai ke Rekening Operasional</strong>
                Tombol ini memungkinkan admin untuk mencatat transfer uang tunai dari kas cabang ke rekening bank operasional, dengan otomatis tracking jam, tanggal, nominal, siapa yang input, dan dapat diarsipkan.
            </div>
            
            <div class="info-box">
                <strong>✅ Apa yang akan dilakukan:</strong>
                • Membuat tabel <code>cash_transfers</code> untuk tracking transfer<br/>
                • Menyimpan data: jam, tanggal, nominal, created_by, status arsip<br/>
                • Halaman ringkasan dengan filter & opsi arsip<br/>
                • Mengurangi saldo akun cash, menambah saldo akun bank
            </div>
            
            <?php if (!$success && !$error): ?>
                <form method="POST" style="text-align: center;">
                    <input type="hidden" name="action" value="create_table">
                    <button type="submit">📦 Buat Tabel cash_transfers</button>
                </form>
            <?php else: ?>
                <a href="<?php echo BASE_URL; ?>/modules/cashbook/index.php" class="back-link">← Kembali ke Buku Kas</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
