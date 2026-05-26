-- Narayana Hotel Database Backup
-- Backup Date: 2026-05-20 10:34:03
-- Database: adfb2574_adf

SET FOREIGN_KEY_CHECKS=0;

-- --------------------------------------------------------
-- Table structure for `audit_logs`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action_type` varchar(50) DEFAULT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `bill_records`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `bill_records`;
CREATE TABLE `bill_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) DEFAULT NULL,
  `bill_date` date DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `status` enum('pending','paid','overdue') DEFAULT 'pending',
  `paid_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `bill_templates`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `bill_templates`;
CREATE TABLE `bill_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_name` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT 0.00,
  `frequency` enum('monthly','weekly','yearly','once') DEFAULT 'monthly',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `booking_payments`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `booking_payments`;
CREATE TABLE `booking_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT 'cash',
  `payment_date` datetime DEFAULT current_timestamp(),
  `reference_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `synced_to_cashbook` tinyint(1) DEFAULT 0,
  `cashbook_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `bookings`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `bookings`;
CREATE TABLE `bookings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_code` varchar(20) DEFAULT NULL,
  `guest_id` int(11) DEFAULT NULL,
  `room_id` int(11) DEFAULT NULL,
  `check_in_date` date NOT NULL,
  `check_out_date` date NOT NULL,
  `total_nights` int(11) DEFAULT 1,
  `adults` int(11) DEFAULT 1,
  `children` int(11) DEFAULT 0,
  `room_price` decimal(12,2) DEFAULT 0.00,
  `total_price` decimal(12,2) DEFAULT 0.00,
  `discount` decimal(12,2) DEFAULT 0.00,
  `final_price` decimal(12,2) DEFAULT 0.00,
  `paid_amount` decimal(12,2) DEFAULT 0.00,
  `status` varchar(20) DEFAULT 'confirmed',
  `payment_status` varchar(20) DEFAULT 'unpaid',
  `booking_source` varchar(50) DEFAULT 'walk_in',
  `special_request` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `guest_count` int(11) DEFAULT 1,
  `actual_checkin_time` datetime DEFAULT NULL,
  `actual_checkout_time` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `booking_code` (`booking_code`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `breakfast_log`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `breakfast_log`;
CREATE TABLE `breakfast_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) DEFAULT NULL,
  `guest_id` int(11) DEFAULT NULL,
  `menu_id` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `date` date NOT NULL,
  `status` varchar(20) DEFAULT 'taken',
  `marked_by` int(11) DEFAULT NULL,
  `marked_at` timestamp NULL DEFAULT current_timestamp(),
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `breakfast_menus`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `breakfast_menus`;
CREATE TABLE `breakfast_menus` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `menu_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(30) DEFAULT 'indonesian',
  `price` decimal(10,2) DEFAULT 0.00,
  `is_free` tinyint(1) DEFAULT 1,
  `is_available` tinyint(1) DEFAULT 1,
  `image_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `breakfast_orders`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `breakfast_orders`;
CREATE TABLE `breakfast_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) DEFAULT NULL,
  `guest_name` varchar(100) DEFAULT NULL,
  `room_number` varchar(20) DEFAULT NULL,
  `total_pax` int(11) DEFAULT 1,
  `breakfast_time` time DEFAULT NULL,
  `breakfast_date` date DEFAULT NULL,
  `location` varchar(20) DEFAULT 'restaurant',
  `menu_items` text DEFAULT NULL,
  `special_requests` text DEFAULT NULL,
  `total_price` decimal(10,2) DEFAULT 0.00,
  `order_status` varchar(20) DEFAULT 'pending',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `cash_book`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `cash_book`;
CREATE TABLE `cash_book` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` varchar(50) DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `transaction_time` time DEFAULT NULL,
  `division_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `category_name` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `transaction_type` enum('income','expense') NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(30) DEFAULT 'cash',
  `cash_account_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `shift` varchar(20) DEFAULT NULL,
  `source_type` varchar(30) DEFAULT 'manual',
  `source_id` int(11) DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `is_editable` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=92 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Dumping data for table `cash_book`

INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('56', NULL, '2026-04-12', '10:29:50', '9', '1', NULL, '[CQC_PROJECT:18] [PRJ-2604-001] Pembayaran INV/2026/04/002/CQC - Termin 1 (25.00%) - Solarvest (PT. Lembaga Tenaga Indonesia)', 'income', '24975000.00', 'transfer', '20', NULL, NULL, '26', NULL, 'invoice_payment', NULL, NULL, '0', '2026-04-12 10:29:50', '2026-04-12 10:29:50');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('57', NULL, '2026-04-12', '10:29:58', '9', '1', NULL, '[CQC_PROJECT:18] [PRJ-2604-001] Pembayaran INV/2026/04/003/CQC - Termin 1 (25.00%) - Solarvest (PT. Lembaga Tenaga Indonesia)', 'income', '62437500.00', 'transfer', '20', NULL, NULL, '26', NULL, 'invoice_payment', NULL, NULL, '0', '2026-04-12 10:29:58', '2026-04-12 10:29:58');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('58', NULL, '2026-03-30', '11:20:08', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] DP - Box Panel SAM - 20% + ppn\r\nVendor : PT. Gemilang Inspirasi Logam Berkah Indonesia', 'expense', '1776000.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-12 11:20:08', '2026-04-12 11:20:08');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('59', NULL, '2026-03-31', '12:13:00', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Kas Besar] DP KOMPONEN SCHNEIDER & CT AXLE\r\n1. 29450 - 1 AUX.SWITCH C/O CONTACT OF/SDE/SDVNS80 (2 x 305.415 = 610.830)\r\n2. A9F74106 - ACTI9 1C60N 1P C 6A MCB (1 x 87.087 = 87.087)\r\n3. A9F74210 - ACTI9 IC60N 2P C 10A MCB (1 x 217.833 = 217.833)\r\n4. A9F74220 - ACTI9 IC60N 2P C 20A MCB (1 x 233.392,50 = 233.392,50)\r\n5. A9F74306 - ACTI9 IC60N 3P C 6A MCB (2 x 331.947 = 663.894)\r\n6. A9F74340 - ACTI9 IC60N 3P C B92240A MCB (1 x 402.864 = 402.864)\r\n7. A9L15688 - IPF 40 40 KA 340V 3P N (1 x 1.170.477 = 1.170.477)\r\n8. A9MEM3255-TERA - KWH METER 3P CT 5A MODBUS TERA (1 x 3.175.172 = 3.175.172)\r\n9. C63N42D360 - NSX630N 50KA AC 4P4D 630A 2.3 ( 1 x 10.835.550 = 10.835.550)\r\n10. EZ9R04240 - EASY9 RCCB 2P 40A 30MA AC RCCB (1 x 243.117 = 243.117)\r\n11. LV429387 - MX220-240V50/60HZ 208-277V60HZ SHT (1 x 815.760 = 815.760)\r\n12. LV525342 - CVS250F TM200D 4P3D (1 x 2.437.515 = 2.437.515)\r\n13. LV540308 - CVS400F TM320D 4P3D (1 x 3.547.665 = 3.547.665)\r\n14. MES-40 600/5A AXLE CURRENT TRANSFORMER BUSBAR 40 x 10 CL (7 x 82.500 = 577.500)\r\n15. METSEPM5350 - PM5350 POWER METER WITHTHD.ALARMING (1 x 5.200.624 = 5.200.624)', 'expense', '10075008.00', 'transfer', '20', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-12 12:13:00', '2026-04-12 12:13:00');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('60', NULL, '2026-03-31', '12:23:13', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Kas Besar] PAYMENT BUSBAR\r\nVENDOR : PENTA MITRA ABADI\r\n1. BUSBAR 30 x 10 x 4000mm (2.679.240)\r\n2. BUSBAR 30 x 5 x 4000mm (1.342.100)', 'expense', '4021340.00', 'transfer', '20', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-12 12:23:13', '2026-04-12 12:23:13');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('61', NULL, '2026-04-01', '18:36:40', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Kas Besar] TIGA FASA KOMPONEN - \r\nRX1000-230A MIKRO Over Current & Earth Fault Relay (1 Unit)', 'expense', '3393270.00', 'transfer', '20', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 18:36:40', '2026-04-16 18:36:40');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('62', NULL, '2026-04-02', '18:44:25', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] Komponen FORTINDO/ABF', 'expense', '1047305.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 18:44:25', '2026-04-16 18:44:25');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('63', NULL, '2026-04-02', '18:55:56', '9', '22', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] Lalamove komponen OCEF Relay', 'expense', '88000.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 18:55:56', '2026-04-16 18:55:56');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('64', NULL, '2026-04-02', '18:57:56', '9', '22', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] Bensin motor untuk ambil komponen FORTINDO/ABF', 'expense', '20000.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 18:57:56', '2026-04-16 18:57:56');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('65', NULL, '2026-04-04', '19:03:26', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] TOKO CIKARANG LISTRIK\r\nKABEL NYAF, ISOLATOR KABELTIES, SKUN Y VYNIL, KABEL DUCT', 'expense', '3138500.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:03:26', '2026-04-16 19:03:26');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('66', NULL, '2026-04-04', '19:08:26', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] TOKO ALFA ELECTRIC\r\nKabel NYAF 2.5 Kuning - 1 roll', 'expense', '417500.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:08:26', '2026-04-16 19:08:26');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('67', NULL, '2026-04-16', '19:12:00', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] TOKO JAYA BAUD\r\nRING PLAT M10 PUTIH (100 pcs)\r\nRING PER PUTIH M10 (50 pcs)\r\nRING PLAT M8 (50 pcs)\r\nRING PER M8 (25 pcs)', 'expense', '54000.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:12:00', '2026-04-16 19:12:00');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('68', NULL, '2026-04-04', '19:14:20', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] TOKO TERANG SINAR BARU\r\nBaud M4x15 (set), Baud M4x40 (set) Baud M8x15, baud m8x25', 'expense', '65500.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:14:20', '2026-04-16 19:14:20');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('69', NULL, '2026-04-04', '19:17:24', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] TOKO SINAR GALUH TEKNIK\r\n16 PCS RING PER + PLAT M6 PUTIH', 'expense', '5500.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:17:24', '2026-04-16 19:17:24');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('70', NULL, '2026-04-04', '19:18:06', '9', '22', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] BENSIN MOTOR BELANJA BAUD, KABEL, SKUN DLL', 'expense', '30000.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:18:06', '2026-04-16 19:18:06');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('71', NULL, '2026-04-04', '19:21:05', '9', '44', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] MAKAN SIANG TIM BELANJA KOMPONEN', 'expense', '26000.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:21:05', '2026-04-16 19:21:05');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('72', NULL, '2026-04-01', '19:22:18', '9', '22', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] NOTA BENSIN BELANJA KOMPONEN PANEL', 'expense', '35000.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:22:18', '2026-04-16 19:22:18');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('73', NULL, '2026-04-04', '19:24:20', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] BELI BAUD SAN KABEL KE SURATMAN RUDIANTO', 'expense', '300000.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:24:20', '2026-04-16 19:24:20');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('74', NULL, '2026-04-06', '19:30:54', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] TOKO OMEGA TEKNIK\r\nKABEL NYAF 2.5\r\nSKUN Y\r\nPILOT LAMP\r\nCUBING\r\nHEATSHRINK', 'expense', '1040500.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:30:54', '2026-04-16 19:30:54');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('75', NULL, '2026-04-06', '19:36:24', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] paku 10 - 1kg\r\nuntuk packing panel', 'expense', '10000.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:36:24', '2026-04-16 19:36:24');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('76', NULL, '2026-04-06', '19:39:38', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] skun ring 2.8 (25 pcs)', 'expense', '31250.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:39:38', '2026-04-16 19:39:38');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('77', NULL, '2026-04-06', '19:40:56', '9', '22', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] bensin untuk belanja komponen panel', 'expense', '60000.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:40:56', '2026-04-16 19:40:56');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('78', NULL, '2026-04-06', '19:42:46', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] nameplate panel', 'expense', '378250.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:42:46', '2026-04-16 19:42:46');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('79', NULL, '2026-04-08', '19:47:45', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] TOKO TERANG SINAR BARU\r\nBaud m12x40, Baud M12x45 + ongkir ke workshop', 'expense', '170750.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:47:45', '2026-04-16 19:47:45');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('80', NULL, '2026-04-09', '19:49:15', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] TOKO TERANG SINAR BARU\r\nMUR, RINGPLAT M10, RING PLAT M12, BMP M8 X 30, RING PLAT + RING PER + ONGKIR KE WORKSHOP', 'expense', '93750.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:49:15', '2026-04-16 19:49:15');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('81', NULL, '2026-04-08', '19:52:27', '9', '22', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] BENSIN MOBIL ANTAR PANEL KE TANGERANG', 'expense', '150000.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:52:27', '2026-04-16 19:52:27');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('82', NULL, '2026-04-08', '19:53:27', '9', '22', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] E TOLL KE TANGERANG', 'expense', '158000.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:53:27', '2026-04-16 19:53:27');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('83', NULL, '2026-04-08', '19:54:10', '9', '44', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] UANG MAKAN 2 ORANG', 'expense', '60000.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-16 19:54:10', '2026-04-16 19:54:10');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('84', NULL, '2026-04-05', '10:20:42', '9', '38', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Kas Besar] modifikasi busbar pa supri', 'expense', '2500000.00', 'transfer', '20', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-20 10:20:42', '2026-04-20 10:20:42');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('85', NULL, '2026-04-30', '10:30:42', '9', '44', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Kas Besar] PAJAK PROJECT', 'expense', '9900000.00', 'transfer', '20', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-20 10:30:42', '2026-04-20 10:30:42');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('86', NULL, '2026-04-07', '10:34:54', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] Akrilik dan long drat', 'expense', '307000.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-20 10:34:54', '2026-04-20 10:34:54');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('87', NULL, '2026-04-07', '10:35:41', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] Filter plastik 4\" 1 pcs', 'expense', '63000.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-20 10:35:41', '2026-04-20 10:35:41');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('88', NULL, '2026-05-02', '10:47:20', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Petty Cash] Pelunasan 70% komponen panel Listrik Kita', 'expense', '23508352.00', 'cash', '19', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-20 10:47:20', '2026-04-20 10:47:20');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('89', NULL, '2026-03-30', '09:28:57', '9', '37', NULL, '[CQC_PROJECT:18] [PRJ-2604-001] [Kas Besar] [DP 20% Box Panel ACSB 1 & 2 vendor :  GILBI]\r\n', 'expense', '3552000.00', 'transfer', '20', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-29 09:28:57', '2026-04-29 09:28:57');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('90', NULL, '2026-05-04', '11:48:35', '9', '37', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] [Kas Besar] Pelunasan 80% BOX PANEL GILBI', 'expense', '4662000.00', 'transfer', '20', NULL, NULL, '26', NULL, 'cqc_project', NULL, NULL, '1', '2026-04-29 11:48:35', '2026-04-29 11:48:35');
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `category_name`, `description`, `transaction_type`, `amount`, `payment_method`, `cash_account_id`, `notes`, `attachment`, `created_by`, `shift`, `source_type`, `source_id`, `reference_no`, `is_editable`, `created_at`, `updated_at`) VALUES ('91', NULL, '2026-04-30', '15:02:51', '9', '1', NULL, '[CQC_PROJECT:19] [PRJ-2604-002] Pembayaran INV/2026/04/004/CQC - Termin 2 (75.00%) - Solarvest - PT. Lembaga Tenaga Indonesia', 'income', '74925000.00', 'transfer', '20', NULL, NULL, '26', NULL, 'invoice_payment', NULL, NULL, '0', '2026-04-30 15:02:51', '2026-04-30 15:02:51');

-- --------------------------------------------------------
-- Table structure for `categories`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` varchar(50) DEFAULT NULL,
  `division_id` int(11) DEFAULT NULL,
  `category_name` varchar(100) NOT NULL,
  `category_type` enum('income','expense') DEFAULT 'income',
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=45 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Dumping data for table `categories`

INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('1', 'cqc-enjiniring', '1', 'Food Sales', 'income', 'Revenue from food sales', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('2', 'cqc-enjiniring', '1', 'Food Supplies', 'expense', 'Purchase of food ingredients', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('3', 'cqc-enjiniring', '2', 'Beverage Sales', 'income', 'Revenue from beverage sales', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('4', 'cqc-enjiniring', '2', 'Beverage Inventory', 'expense', 'Purchase of beverages', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('5', 'cqc-enjiniring', '3', 'Room Rental', 'income', 'Room rental income', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('6', 'cqc-enjiniring', '3', 'Staff Salary', 'expense', 'Employee salaries', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('7', 'cqc-enjiniring', '4', 'Housekeeping Service', 'income', 'Cleaning services', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('8', 'cqc-enjiniring', '4', 'Room Supplies', 'expense', 'Room cleaning supplies', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('9', 'cqc-enjiniring', '5', 'Hotel Income', 'income', 'Hotel revenue', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('10', 'cqc-enjiniring', '5', 'Hotel Expense', 'expense', 'Hotel operational expenses', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('11', 'cqc-enjiniring', '7', 'Other Income', 'income', 'Miscellaneous income', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('12', 'cqc-enjiniring', '7', 'Other Expense', 'expense', 'Miscellaneous expenses', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('13', NULL, '2', 'Dp masuk', 'income', NULL, '1', '2026-02-28 15:18:10', '2026-02-28 15:18:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('14', NULL, '2', 'Pembayaran Termin - proj 3 - mega resort', 'income', NULL, '1', '2026-02-28 21:16:29', '2026-02-28 21:16:29');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('15', NULL, '2', '? Tenaga Kerja', 'expense', NULL, '1', '2026-03-01 08:19:31', '2026-03-01 08:19:31');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('16', NULL, '2', '? Inverter', 'expense', NULL, '1', '2026-03-01 08:36:27', '2026-03-01 08:36:27');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('17', NULL, '2', 'vdxzv', 'expense', NULL, '1', '2026-03-01 10:16:52', '2026-03-01 10:16:52');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('18', NULL, '2', 'PETY CASH', 'income', NULL, '1', '2026-03-01 10:39:10', '2026-03-01 10:39:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('19', NULL, '2', 'gjgk', 'expense', NULL, '1', '2026-03-01 11:16:40', '2026-03-01 11:16:40');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('20', NULL, '2', 'Top Up Kas dari Owner - Operasional Harian', 'income', NULL, '1', '2026-03-01 20:25:39', '2026-03-01 20:25:39');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('21', NULL, '2', 'beli kertas', 'expense', NULL, '1', '2026-03-01 20:29:39', '2026-03-01 20:29:39');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('22', NULL, '9', 'Transportasi', 'expense', NULL, '1', '2026-03-01 20:39:00', '2026-03-01 20:39:00');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('23', NULL, '2', 'hilang', 'expense', NULL, '1', '2026-03-02 11:22:17', '2026-03-02 11:22:17');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('24', NULL, '2', '? pembelian tools', 'expense', NULL, '1', '2026-03-03 14:40:32', '2026-03-03 14:40:32');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('25', NULL, '2', '?? Oprasional site', 'expense', NULL, '1', '2026-03-03 14:57:10', '2026-03-03 14:57:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('26', NULL, '2', '?? Oprasional site', 'expense', NULL, '1', '2026-03-03 15:00:50', '2026-03-03 15:00:50');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('27', NULL, '2', 'OPRASIONAL SITE', 'income', NULL, '1', '2026-03-03 15:02:12', '2026-03-03 15:02:12');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('28', NULL, '9', 'Oprasional site', 'expense', NULL, '1', '2026-03-03 15:05:11', '2026-03-03 15:05:11');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('29', NULL, '2', 'Operasional Office &amp; Proyek', 'income', NULL, '1', '2026-03-03 21:18:30', '2026-03-03 21:18:30');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('30', NULL, '2', '? pembelian tools', 'expense', NULL, '1', '2026-03-04 08:43:38', '2026-03-04 08:43:38');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('31', NULL, '2', '? Tenaga Kerja', 'expense', NULL, '1', '2026-03-04 08:45:15', '2026-03-04 08:45:15');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('32', NULL, '2', '? Oprasional mess', 'expense', NULL, '1', '2026-03-04 08:46:42', '2026-03-04 08:46:42');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('33', NULL, '2', '? Tenaga Kerja', 'expense', NULL, '1', '2026-03-04 08:47:44', '2026-03-04 08:47:44');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('34', NULL, '2', '?? Oprasional site', 'expense', NULL, '1', '2026-03-04 08:48:22', '2026-03-04 08:48:22');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('35', NULL, '2', 'pembelian tools', 'expense', NULL, '1', '2026-03-04 14:59:55', '2026-03-04 14:59:55');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('36', NULL, '2', '?? Oprasional site', 'expense', NULL, '1', '2026-03-04 15:02:57', '2026-03-04 15:02:57');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('37', NULL, '9', 'pembelian material', 'expense', NULL, '1', '2026-03-06 16:43:36', '2026-03-06 16:43:36');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('38', NULL, '9', 'Tenaga Kerja', 'expense', NULL, '1', '2026-03-06 17:08:05', '2026-03-06 17:08:05');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('39', NULL, '2', '? Tenaga Kerja', 'expense', NULL, '1', '2026-03-11 12:56:48', '2026-03-11 12:56:48');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('40', NULL, '2', '? pembelian material', 'expense', NULL, '1', '2026-03-11 12:59:17', '2026-03-11 12:59:17');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('41', NULL, '2', 'BANK BALLANCE', 'income', NULL, '1', '2026-03-11 13:18:01', '2026-03-11 13:18:01');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('42', NULL, '2', 'DP Masuk - ? Operasional Office &amp; Proyek', 'income', NULL, '1', '2026-03-11 14:09:38', '2026-03-11 14:09:38');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('43', NULL, '2', '? Tenaga Kerja', 'expense', NULL, '1', '2026-03-11 14:12:31', '2026-03-11 14:12:31');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('44', NULL, '9', 'Oprasional mess', 'expense', NULL, '1', '2026-04-16 19:21:05', '2026-04-16 19:21:05');

-- --------------------------------------------------------
-- Table structure for `cqc_expense_categories`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `cqc_expense_categories`;
CREATE TABLE `cqc_expense_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  `category_icon` varchar(10) DEFAULT '?',
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `cqc_expense_categories`

INSERT INTO `cqc_expense_categories` (`id`, `category_name`, `category_icon`, `description`, `is_active`, `created_at`) VALUES ('2', 'Oprasional mess', '🔌', 'Pembelian inverter', '1', '2026-02-28 10:56:16');
INSERT INTO `cqc_expense_categories` (`id`, `category_name`, `category_icon`, `description`, `is_active`, `created_at`) VALUES ('4', 'Tenaga Kerja', '👷', 'Upah pekerja instalasi', '1', '2026-02-28 10:56:16');
INSERT INTO `cqc_expense_categories` (`id`, `category_name`, `category_icon`, `description`, `is_active`, `created_at`) VALUES ('11', 'Transportasi', '🚚', NULL, '1', '2026-03-01 09:07:54');
INSERT INTO `cqc_expense_categories` (`id`, `category_name`, `category_icon`, `description`, `is_active`, `created_at`) VALUES ('12', 'pembelian tools', '🔧', NULL, '1', '2026-03-01 09:08:29');
INSERT INTO `cqc_expense_categories` (`id`, `category_name`, `category_icon`, `description`, `is_active`, `created_at`) VALUES ('13', 'pembelian material site', '🔩', NULL, '1', '2026-03-01 09:08:54');
INSERT INTO `cqc_expense_categories` (`id`, `category_name`, `category_icon`, `description`, `is_active`, `created_at`) VALUES ('14', 'pembelian material', '📦', NULL, '1', '2026-03-06 16:43:05');

-- --------------------------------------------------------
-- Table structure for `cqc_general_invoice_items`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `cqc_general_invoice_items`;
CREATE TABLE `cqc_general_invoice_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `description` varchar(500) NOT NULL,
  `quantity` decimal(10,2) DEFAULT 1.00,
  `unit` varchar(50) DEFAULT 'unit',
  `unit_price` decimal(15,2) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_invoice` (`invoice_id`),
  CONSTRAINT `cqc_general_invoice_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `cqc_general_invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for `cqc_general_invoices`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `cqc_general_invoices`;
CREATE TABLE `cqc_general_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(50) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `client_name` varchar(200) NOT NULL,
  `client_phone` varchar(50) DEFAULT NULL,
  `client_email` varchar(100) DEFAULT NULL,
  `client_address` text DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL COMMENT 'Invoice subject/title',
  `notes` text DEFAULT NULL,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_percentage` decimal(5,2) DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `ppn_percentage` decimal(5,2) DEFAULT 11.00,
  `ppn_amount` decimal(15,2) DEFAULT 0.00,
  `pph_percentage` decimal(5,2) DEFAULT 0.00,
  `pph_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL,
  `payment_status` enum('draft','sent','paid','partial','overdue') DEFAULT 'draft',
  `paid_amount` decimal(15,2) DEFAULT 0.00,
  `payment_date` date DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `idx_status` (`payment_status`),
  KEY `idx_date` (`invoice_date`),
  KEY `idx_client` (`client_name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for `cqc_project_expenses`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `cqc_project_expenses`;
CREATE TABLE `cqc_project_expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `expense_date` date NOT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `cqc_project_expenses_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `cqc_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cqc_project_expenses_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `cqc_expense_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `cqc_project_expenses`

INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('19', '19', '14', 'DP - Box Panel SAM - 20% + ppn\r\nVendor : PT. Gemilang Inspirasi Logam Berkah Indonesia', '1776000.00', '2026-03-30', NULL, NULL, '26', '2026-04-12 11:20:08');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('20', '19', '14', 'DP KOMPONEN SCHNEIDER & CT AXLE\r\n1. 29450 - 1 AUX.SWITCH C/O CONTACT OF/SDE/SDVNS80 (2 x 305.415 = 610.830)\r\n2. A9F74106 - ACTI9 1C60N 1P C 6A MCB (1 x 87.087 = 87.087)\r\n3. A9F74210 - ACTI9 IC60N 2P C 10A MCB (1 x 217.833 = 217.833)\r\n4. A9F74220 - ACTI9 I', '10075008.00', '2026-03-31', NULL, NULL, '26', '2026-04-12 12:13:00');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('21', '19', '14', 'PAYMENT BUSBAR\r\nVENDOR : PENTA MITRA ABADI\r\n1. BUSBAR 30 x 10 x 4000mm (2.679.240)\r\n2. BUSBAR 30 x 5 x 4000mm (1.342.100)', '4021340.00', '2026-03-31', NULL, NULL, '26', '2026-04-12 12:23:13');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('22', '19', '14', 'TIGA FASA KOMPONEN - \r\nRX1000-230A MIKRO Over Current & Earth Fault Relay (1 Unit)', '3393270.00', '2026-04-01', NULL, NULL, '26', '2026-04-16 18:36:40');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('23', '19', '14', 'Komponen FORTINDO/ABF', '1047305.00', '2026-04-02', NULL, NULL, '26', '2026-04-16 18:44:25');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('24', '19', '11', 'Lalamove komponen OCEF Relay', '88000.00', '2026-04-02', NULL, NULL, '26', '2026-04-16 18:55:56');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('25', '19', '11', 'Bensin motor untuk ambil komponen FORTINDO/ABF', '20000.00', '2026-04-02', NULL, NULL, '26', '2026-04-16 18:57:56');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('26', '19', '14', 'TOKO CIKARANG LISTRIK\r\nKABEL NYAF, ISOLATOR KABELTIES, SKUN Y VYNIL, KABEL DUCT', '3138500.00', '2026-04-04', NULL, NULL, '26', '2026-04-16 19:03:26');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('27', '19', '14', 'TOKO ALFA ELECTRIC\r\nKabel NYAF 2.5 Kuning - 1 roll', '417500.00', '2026-04-04', NULL, NULL, '26', '2026-04-16 19:08:26');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('28', '19', '14', 'TOKO JAYA BAUD\r\nRING PLAT M10 PUTIH (100 pcs)\r\nRING PER PUTIH M10 (50 pcs)\r\nRING PLAT M8 (50 pcs)\r\nRING PER M8 (25 pcs)', '54000.00', '2026-04-16', NULL, NULL, '26', '2026-04-16 19:12:00');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('29', '19', '14', 'TOKO TERANG SINAR BARU\r\nBaud M4x15 (set), Baud M4x40 (set) Baud M8x15, baud m8x25', '65500.00', '2026-04-04', NULL, NULL, '26', '2026-04-16 19:14:20');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('30', '19', '14', 'TOKO SINAR GALUH TEKNIK\r\n16 PCS RING PER + PLAT M6 PUTIH', '5500.00', '2026-04-04', NULL, NULL, '26', '2026-04-16 19:17:24');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('31', '19', '11', 'BENSIN MOTOR BELANJA BAUD, KABEL, SKUN DLL', '30000.00', '2026-04-04', NULL, NULL, '26', '2026-04-16 19:18:06');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('32', '19', '2', 'MAKAN SIANG TIM BELANJA KOMPONEN', '26000.00', '2026-04-04', NULL, NULL, '26', '2026-04-16 19:21:05');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('33', '19', '11', 'NOTA BENSIN BELANJA KOMPONEN PANEL', '35000.00', '2026-04-01', NULL, NULL, '26', '2026-04-16 19:22:18');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('34', '19', '14', 'BELI BAUD SAN KABEL KE SURATMAN RUDIANTO', '300000.00', '2026-04-04', NULL, NULL, '26', '2026-04-16 19:24:20');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('35', '19', '14', 'TOKO OMEGA TEKNIK\r\nKABEL NYAF 2.5\r\nSKUN Y\r\nPILOT LAMP\r\nCUBING\r\nHEATSHRINK', '1040500.00', '2026-04-06', NULL, NULL, '26', '2026-04-16 19:30:54');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('36', '19', '14', 'paku 10 - 1kg\r\nuntuk packing panel', '10000.00', '2026-04-06', NULL, NULL, '26', '2026-04-16 19:36:24');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('37', '19', '14', 'skun ring 2.8 (25 pcs)', '31250.00', '2026-04-06', NULL, NULL, '26', '2026-04-16 19:39:38');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('38', '19', '11', 'bensin untuk belanja komponen panel', '60000.00', '2026-04-06', NULL, NULL, '26', '2026-04-16 19:40:56');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('39', '19', '14', 'nameplate panel', '378250.00', '2026-04-06', NULL, NULL, '26', '2026-04-16 19:42:46');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('40', '19', '14', 'TOKO TERANG SINAR BARU\r\nBaud m12x40, Baud M12x45 + ongkir ke workshop', '170750.00', '2026-04-08', NULL, NULL, '26', '2026-04-16 19:47:45');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('41', '19', '14', 'TOKO TERANG SINAR BARU\r\nMUR, RINGPLAT M10, RING PLAT M12, BMP M8 X 30, RING PLAT + RING PER + ONGKIR KE WORKSHOP', '93750.00', '2026-04-09', NULL, NULL, '26', '2026-04-16 19:49:15');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('42', '19', '11', 'BENSIN MOBIL ANTAR PANEL KE TANGERANG', '150000.00', '2026-04-08', NULL, NULL, '26', '2026-04-16 19:52:27');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('43', '19', '11', 'E TOLL KE TANGERANG', '158000.00', '2026-04-08', NULL, NULL, '26', '2026-04-16 19:53:27');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('44', '19', '2', 'UANG MAKAN 2 ORANG', '60000.00', '2026-04-08', NULL, NULL, '26', '2026-04-16 19:54:10');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('45', '19', '4', 'modifikasi busbar pa supri', '2500000.00', '2026-04-05', NULL, NULL, '26', '2026-04-20 10:20:42');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('46', '19', '2', 'PAJAK PROJECT', '9900000.00', '2026-04-30', NULL, NULL, '26', '2026-04-20 10:30:42');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('47', '19', '14', 'Akrilik dan long drat', '307000.00', '2026-04-07', NULL, NULL, '26', '2026-04-20 10:34:54');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('48', '19', '14', 'Filter plastik 4\" 1 pcs', '63000.00', '2026-04-07', NULL, NULL, '26', '2026-04-20 10:35:41');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('49', '19', '14', 'Pelunasan 70% komponen panel Listrik Kita', '23508352.00', '2026-05-02', NULL, NULL, '26', '2026-04-20 10:47:20');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('50', '18', '14', '[DP 20% Box Panel ACSB 1 & 2 vendor :  GILBI]\r\n', '3552000.00', '2026-03-30', NULL, NULL, '26', '2026-04-29 09:28:57');
INSERT INTO `cqc_project_expenses` (`id`, `project_id`, `category_id`, `description`, `amount`, `expense_date`, `receipt_number`, `notes`, `created_by`, `created_at`) VALUES ('51', '19', '14', 'Pelunasan 80% BOX PANEL GILBI', '4662000.00', '2026-05-04', NULL, NULL, '26', '2026-04-29 11:48:35');

-- --------------------------------------------------------
-- Table structure for `cqc_projects`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `cqc_projects`;
CREATE TABLE `cqc_projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_name` varchar(200) NOT NULL,
  `project_code` varchar(50) NOT NULL,
  `description` longtext DEFAULT NULL,
  `location` varchar(300) DEFAULT NULL,
  `client_name` varchar(150) DEFAULT NULL,
  `client_phone` varchar(20) DEFAULT NULL,
  `client_email` varchar(100) DEFAULT NULL,
  `solar_capacity_kwp` decimal(8,2) DEFAULT NULL COMMENT 'Kapasitas dalam KWp',
  `panel_count` int(11) DEFAULT NULL,
  `panel_type` varchar(100) DEFAULT NULL,
  `inverter_type` varchar(100) DEFAULT NULL,
  `budget_idr` decimal(15,2) DEFAULT NULL,
  `spent_idr` decimal(15,2) DEFAULT 0.00,
  `status` enum('planning','procurement','installation','testing','completed','on_hold') DEFAULT 'planning',
  `progress_percentage` int(11) DEFAULT 0,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `estimated_completion` date DEFAULT NULL,
  `actual_completion` date DEFAULT NULL,
  `project_manager_id` int(11) DEFAULT NULL,
  `lead_installer_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_code` (`project_code`),
  KEY `idx_status` (`status`),
  KEY `idx_code` (`project_code`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `cqc_projects`

INSERT INTO `cqc_projects` (`id`, `project_name`, `project_code`, `description`, `location`, `client_name`, `client_phone`, `client_email`, `solar_capacity_kwp`, `panel_count`, `panel_type`, `inverter_type`, `budget_idr`, `spent_idr`, `status`, `progress_percentage`, `start_date`, `end_date`, `estimated_completion`, `actual_completion`, `project_manager_id`, `lead_installer_id`, `created_by`, `created_at`, `updated_at`) VALUES ('17', 'contoh', 'proj 1', '', 'ldvffgbfbgfhfb', 'iLHAM - narayana', '081330316204', '', '0.00', '0', '', '', '1000000.00', '0.00', 'planning', '0', '2026-03-11', NULL, '2026-03-11', NULL, NULL, NULL, '26', '2026-03-11 14:27:50', '2026-03-11 14:27:50');
INSERT INTO `cqc_projects` (`id`, `project_name`, `project_code`, `description`, `location`, `client_name`, `client_phone`, `client_email`, `solar_capacity_kwp`, `panel_count`, `panel_type`, `inverter_type`, `budget_idr`, `spent_idr`, `status`, `progress_percentage`, `start_date`, `end_date`, `estimated_completion`, `actual_completion`, `project_manager_id`, `lead_installer_id`, `created_by`, `created_at`, `updated_at`) VALUES ('18', 'MALINDO GROBOGAN FEEDMILL', 'PRJ-2604-001', 'Dibuat otomatis dari Quotation: QUOT/04/2026/002\nSubject: Penawaran Harga Panel', 'GROBOGAN', 'Solarvest (PT. Lembaga Tenaga Indonesia)', '+60 18-256 4918', 'yongkai_wong@solarvest.com', '0.00', NULL, NULL, NULL, '249750000.00', '3552000.00', 'installation', '0', '2026-04-07', NULL, NULL, NULL, NULL, NULL, '8', '2026-04-07 23:38:59', '2026-04-29 09:28:57');
INSERT INTO `cqc_projects` (`id`, `project_name`, `project_code`, `description`, `location`, `client_name`, `client_phone`, `client_email`, `solar_capacity_kwp`, `panel_count`, `panel_type`, `inverter_type`, `budget_idr`, `spent_idr`, `status`, `progress_percentage`, `start_date`, `end_date`, `estimated_completion`, `actual_completion`, `project_manager_id`, `lead_installer_id`, `created_by`, `created_at`, `updated_at`) VALUES ('19', 'SAM COLD STORAGE', 'PRJ-2604-002', 'Dibuat otomatis dari Quotation: QUOT/04/2026/001\r\nSubject: Penawaran Harga Panel', 'Jl. Pembangunan 2, no 67, RT. 006/RW 004, Batusari, kec. Batuceper, kota Tangerang, Banten', 'Solarvest - PT. Lembaga Tenaga Indonesia', '+60 18-256 4918', 'yongkai_wong@solarvest.com', '0.00', '0', '', '', '99900000.00', '67585775.00', 'installation', '0', '2026-03-27', NULL, '2026-04-11', NULL, NULL, NULL, '26', '2026-04-09 10:30:30', '2026-04-29 11:48:35');

-- --------------------------------------------------------
-- Table structure for `cqc_quotation_items`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `cqc_quotation_items`;
CREATE TABLE `cqc_quotation_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quotation_id` int(11) NOT NULL,
  `description` varchar(500) NOT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `quantity` decimal(10,2) DEFAULT 1.00,
  `unit` varchar(50) DEFAULT 'unit',
  `unit_price` decimal(15,2) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_quotation` (`quotation_id`),
  CONSTRAINT `cqc_quotation_items_ibfk_1` FOREIGN KEY (`quotation_id`) REFERENCES `cqc_quotations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `cqc_quotation_items`

INSERT INTO `cqc_quotation_items` (`id`, `quotation_id`, `description`, `remarks`, `quantity`, `unit`, `unit_price`, `amount`, `sort_order`, `created_at`) VALUES ('5', '5', 'TEST', '', '4.05', 'unit', '100000.00', '405000.00', '1', '2026-03-11 14:24:42');
INSERT INTO `cqc_quotation_items` (`id`, `quotation_id`, `description`, `remarks`, `quantity`, `unit`, `unit_price`, `amount`, `sort_order`, `created_at`) VALUES ('6', '5', 'kapal', '', '7.93', 'unit', '50000.00', '396500.00', '2', '2026-03-11 14:24:42');
INSERT INTO `cqc_quotation_items` (`id`, `quotation_id`, `description`, `remarks`, `quantity`, `unit`, `unit_price`, `amount`, `sort_order`, `created_at`) VALUES ('7', '6', '(PV_MSB-630A_INDOOR FLOOR STANDING) CUSTOMISED PHOTOVOLTAIC MAIN SWITCH BOARD 630A_INDOOR FLOOR STANDING', 'CQC', '1.00', 'unit', '90000000.00', '90000000.00', '1', '2026-04-07 14:06:30');
INSERT INTO `cqc_quotation_items` (`id`, `quotation_id`, `description`, `remarks`, `quantity`, `unit`, `unit_price`, `amount`, `sort_order`, `created_at`) VALUES ('8', '7', '(PV_MSB_1000A_INDOOR FLOOR STANDING) CUSTOMISED PHOTOVOLTAIC MAIN SWITCH BOARD 1000A_ INDOOR FLOOR STANDING', 'CQC', '2.00', 'unit', '112500000.00', '225000000.00', '1', '2026-04-07 14:21:54');

-- --------------------------------------------------------
-- Table structure for `cqc_quotations`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `cqc_quotations`;
CREATE TABLE `cqc_quotations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) DEFAULT NULL,
  `quote_number` varchar(50) NOT NULL,
  `quote_date` date NOT NULL,
  `valid_until` date DEFAULT NULL,
  `client_name` varchar(200) NOT NULL,
  `client_attn` varchar(100) DEFAULT NULL,
  `client_phone` varchar(50) DEFAULT NULL,
  `client_email` varchar(100) DEFAULT NULL,
  `client_address` text DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL COMMENT 'Quote subject/title',
  `project_name` varchar(200) DEFAULT NULL,
  `project_location` text DEFAULT NULL,
  `solar_capacity_kwp` decimal(10,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `terms_conditions` text DEFAULT NULL,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_percentage` decimal(5,2) DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `ppn_percentage` decimal(5,2) DEFAULT 11.00,
  `ppn_amount` decimal(15,2) DEFAULT 0.00,
  `discount_type` enum('fixed','percentage') DEFAULT 'fixed',
  `discount_value` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL,
  `status` enum('draft','sent','approved','rejected','expired') DEFAULT 'draft',
  `created_by` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `quote_number` (`quote_number`),
  KEY `idx_status` (`status`),
  KEY `idx_date` (`quote_date`),
  KEY `idx_client` (`client_name`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `cqc_quotations`

INSERT INTO `cqc_quotations` (`id`, `project_id`, `quote_number`, `quote_date`, `valid_until`, `client_name`, `client_attn`, `client_phone`, `client_email`, `client_address`, `subject`, `project_name`, `project_location`, `solar_capacity_kwp`, `notes`, `terms_conditions`, `subtotal`, `discount_percentage`, `discount_amount`, `ppn_percentage`, `ppn_amount`, `discount_type`, `discount_value`, `total_amount`, `status`, `created_by`, `created_at`, `updated_at`) VALUES ('5', NULL, 'QUOT/03/2026/001', '2026-03-11', '2026-04-10', 'iLHAM (narayana)', 'ILHam', '081330316204', 'narayanahotelkarimunjawa@gmail.com', '', '', NULL, NULL, '0.00', 'Demikian penawaran ini kami ajukan atas perhatiannya kami ucapkan terima kasih', 'Price include Tax 11%\r\n\r\nTOP:\r\n    1. Payment 100%COD\r\n    2. FOT Jogja PT SGM\r\n\r\nLama waktu pekerjaan disesuaikan dengan Time Line After SPK', '801500.00', '0.00', '0.00', '11.00', '88165.00', 'fixed', '0.00', '889665.00', 'approved', '26', '2026-03-11 14:24:42', '2026-03-11 14:29:17');
INSERT INTO `cqc_quotations` (`id`, `project_id`, `quote_number`, `quote_date`, `valid_until`, `client_name`, `client_attn`, `client_phone`, `client_email`, `client_address`, `subject`, `project_name`, `project_location`, `solar_capacity_kwp`, `notes`, `terms_conditions`, `subtotal`, `discount_percentage`, `discount_amount`, `ppn_percentage`, `ppn_amount`, `discount_type`, `discount_value`, `total_amount`, `status`, `created_by`, `created_at`, `updated_at`) VALUES ('6', '19', 'QUOT/04/2026/001', '2026-04-07', '2026-05-07', 'Solarvest (PT. Lembaga Tenaga Indonesia)', 'Lawrence', '+60 18-256 4918', 'yongkai_wong@solarvest.com', '', 'Penawaran Harga Panel', 'SAM COLD STORAGE', 'Jl. Pembangunan 2, no 67, RT. 006/RW 004, Batusari, kec. Batuceper, kota Tangerang, Banten', '0.00', 'Demikian penawaran ini kami ajukan atas perhatiannya kami ucapkan terima kasih', 'Price include Tax 11%\r\n\r\nTOP:\r\n    1. Payment : 25% DOWN PAYMENT, 75% 30 DAYS AFTER INVOICE\r\n    2. DELIVERY : PRICE INCLUDING DELIVERY DIRECT TO SITE\r\n\r\nLama waktu pekerjaan disesuaikan dengan Time Line After SPK', '90000000.00', '0.00', '0.00', '11.00', '9900000.00', 'fixed', '0.00', '99900000.00', 'approved', '26', '2026-04-07 14:06:30', '2026-04-09 10:30:30');
INSERT INTO `cqc_quotations` (`id`, `project_id`, `quote_number`, `quote_date`, `valid_until`, `client_name`, `client_attn`, `client_phone`, `client_email`, `client_address`, `subject`, `project_name`, `project_location`, `solar_capacity_kwp`, `notes`, `terms_conditions`, `subtotal`, `discount_percentage`, `discount_amount`, `ppn_percentage`, `ppn_amount`, `discount_type`, `discount_value`, `total_amount`, `status`, `created_by`, `created_at`, `updated_at`) VALUES ('7', '18', 'QUOT/04/2026/002', '2026-04-07', '2026-05-07', 'Solarvest (PT. Lembaga Tenaga Indonesia)', 'Lawrence', '+60 18-256 4918', 'yongkai_wong@solarvest.com', '', 'Penawaran Harga Panel', 'MALINDO GROBOGAN FEEDMILL', 'GROBOGAN', '0.00', 'Demikian penawaran ini kami ajukan atas perhatiannya kami ucapkan terima kasih', 'Price include Tax 11%\r\n\r\nTOP:\r\n    1. Payment : 25% DOWN PAYMENT, 75% 30 DAYS AFTER INVOICED\r\n   \r\n\r\nLama waktu pekerjaan disesuaikan dengan Time Line After SPK', '225000000.00', '0.00', '0.00', '11.00', '24750000.00', 'fixed', '0.00', '249750000.00', 'approved', '26', '2026-04-07 14:21:54', '2026-04-07 23:38:59');

-- --------------------------------------------------------
-- Table structure for `cqc_termin_invoices`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `cqc_termin_invoices`;
CREATE TABLE `cqc_termin_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(50) NOT NULL,
  `project_id` int(11) NOT NULL,
  `termin_number` int(11) NOT NULL COMMENT 'Termin ke-1, ke-2, dst',
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `description` varchar(500) DEFAULT NULL,
  `contract_value` decimal(15,2) NOT NULL,
  `percentage` decimal(5,2) NOT NULL,
  `base_amount` decimal(15,2) NOT NULL,
  `ppn_percentage` decimal(5,2) DEFAULT 11.00,
  `ppn_amount` decimal(15,2) DEFAULT 0.00,
  `pph_percentage` decimal(5,2) DEFAULT 0.00,
  `pph_amount` decimal(15,2) DEFAULT 0.00,
  `retention_percentage` decimal(5,2) DEFAULT 0.00,
  `retention_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL,
  `payment_status` enum('draft','sent','paid','partial','overdue') DEFAULT 'draft',
  `paid_amount` decimal(15,2) DEFAULT 0.00,
  `payment_date` date DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_notes` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `idx_project` (`project_id`),
  KEY `idx_status` (`payment_status`),
  KEY `idx_date` (`invoice_date`),
  CONSTRAINT `cqc_termin_invoices_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `cqc_projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `cqc_termin_invoices`

INSERT INTO `cqc_termin_invoices` (`id`, `invoice_number`, `project_id`, `termin_number`, `invoice_date`, `due_date`, `description`, `contract_value`, `percentage`, `base_amount`, `ppn_percentage`, `ppn_amount`, `pph_percentage`, `pph_amount`, `retention_percentage`, `retention_amount`, `total_amount`, `payment_status`, `paid_amount`, `payment_date`, `payment_method`, `payment_notes`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('14', 'INV/2026/03/001/CQC', '17', '1', '2026-03-11', '2026-04-10', 'Pembayaran Termin 1 - QUOT/03/2026/001', '801500.00', '40.00', '320600.00', '11.00', '35266.00', '2.50', '8015.00', '0.00', '0.00', '347851.00', 'paid', '347851.00', '2026-03-11', 'transfer', '', '', '26', '2026-03-11 14:29:17', '2026-03-11 14:30:36');
INSERT INTO `cqc_termin_invoices` (`id`, `invoice_number`, `project_id`, `termin_number`, `invoice_date`, `due_date`, `description`, `contract_value`, `percentage`, `base_amount`, `ppn_percentage`, `ppn_amount`, `pph_percentage`, `pph_amount`, `retention_percentage`, `retention_amount`, `total_amount`, `payment_status`, `paid_amount`, `payment_date`, `payment_method`, `payment_notes`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('16', 'INV/2026/04/002/CQC', '18', '1', '2026-04-12', '2026-05-12', 'Pembayaran Termin 1 - Panel SAM COLD STORAGE', '90000000.00', '25.00', '22500000.00', '11.00', '2475000.00', '0.00', '0.00', '0.00', '0.00', '24975000.00', 'paid', '24975000.00', '2026-04-12', 'transfer', '', '', '26', '2026-04-12 10:21:43', '2026-04-12 10:29:50');
INSERT INTO `cqc_termin_invoices` (`id`, `invoice_number`, `project_id`, `termin_number`, `invoice_date`, `due_date`, `description`, `contract_value`, `percentage`, `base_amount`, `ppn_percentage`, `ppn_amount`, `pph_percentage`, `pph_amount`, `retention_percentage`, `retention_amount`, `total_amount`, `payment_status`, `paid_amount`, `payment_date`, `payment_method`, `payment_notes`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('17', 'INV/2026/04/003/CQC', '18', '1', '2026-04-12', '2026-05-12', 'Pembayaran Termin 1 - PANEL FEEDMIL ACSB 1 DAN 2', '225000000.00', '25.00', '56250000.00', '11.00', '6187500.00', '0.00', '0.00', '0.00', '0.00', '62437500.00', 'paid', '62437500.00', '2026-04-12', 'transfer', '', '', '26', '2026-04-12 10:23:29', '2026-04-12 10:29:58');
INSERT INTO `cqc_termin_invoices` (`id`, `invoice_number`, `project_id`, `termin_number`, `invoice_date`, `due_date`, `description`, `contract_value`, `percentage`, `base_amount`, `ppn_percentage`, `ppn_amount`, `pph_percentage`, `pph_amount`, `retention_percentage`, `retention_amount`, `total_amount`, `payment_status`, `paid_amount`, `payment_date`, `payment_method`, `payment_notes`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('18', 'INV/2026/04/004/CQC', '19', '2', '2026-04-10', '2026-05-21', 'Pembayaran TermIN 2 - Panel SAM COLD STORAGE (PELUNANSAN)', '90000000.00', '75.00', '67500000.00', '11.00', '7425000.00', '0.00', '0.00', '0.00', '0.00', '74925000.00', 'paid', '74925000.00', '2026-04-30', 'transfer', 'transfer tanggal 15 April 2026\r\ncust ref no. 2610314422880', '', '26', '2026-04-29 14:54:15', '2026-04-30 15:02:51');

-- --------------------------------------------------------
-- Table structure for `customers`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_code` varchar(30) DEFAULT NULL,
  `customer_name` varchar(100) NOT NULL,
  `customer_type` enum('individual','company','member') DEFAULT 'individual',
  `company_name` varchar(100) DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(50) DEFAULT NULL,
  `province` varchar(50) DEFAULT NULL,
  `postal_code` varchar(10) DEFAULT NULL,
  `npwp` varchar(30) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_code` (`customer_code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `customers`

INSERT INTO `customers` (`id`, `customer_code`, `customer_name`, `customer_type`, `company_name`, `contact_person`, `email`, `phone`, `address`, `city`, `province`, `postal_code`, `npwp`, `notes`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES ('1', 'CUST-0001', 'iLHAM test', 'company', 'narayana', 'HUNGER RESTO AT NARAYANA HOTEL', '', '081330316204', 'Jl. Sunan Nyamplungan', 'Kab. Jepara', 'Jawa Tengah', '59455', '', '', '1', '26', '2026-03-04 16:08:39', '2026-04-29 11:36:02');
INSERT INTO `customers` (`id`, `customer_code`, `customer_name`, `customer_type`, `company_name`, `contact_person`, `email`, `phone`, `address`, `city`, `province`, `postal_code`, `npwp`, `notes`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES ('2', 'CUST-0002', 'CQC Enjiniring', 'company', 'ROBERT', 'CQC Enjiniring', 'cqcenjiniring@gmail.com', '081330409528', 'Jl Kepatih RT/RW 004/002 Tulangan', '', 'Sidoarjo, Jawa Timur', '61273', '', '', '1', '26', '2026-03-04 17:06:35', '2026-03-04 17:06:35');
INSERT INTO `customers` (`id`, `customer_code`, `customer_name`, `customer_type`, `company_name`, `contact_person`, `email`, `phone`, `address`, `city`, `province`, `postal_code`, `npwp`, `notes`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES ('3', 'CUST-0003', 'Janis Darius', 'company', 'PT. Utama Niaga Optimal', '0877777777', 'project@voltsolar.com', '085627323176813', 'jl rawa buaya', 'jakarta Barat', 'dki Jakarta', '12345', '7815265628479198324', '', '1', '26', '2026-03-06 17:04:37', '2026-03-06 17:04:37');
INSERT INTO `customers` (`id`, `customer_code`, `customer_name`, `customer_type`, `company_name`, `contact_person`, `email`, `phone`, `address`, `city`, `province`, `postal_code`, `npwp`, `notes`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES ('4', 'CUST-0004', 'Solarvest', 'company', 'PT. Lembaga Tenaga Indonesia', 'Lawrence', 'yongkai_wong@solarvest.com', '+60 18-256 4918', 'PALMA TOWER 20TH FLOOR, TB SIMATUPANG JL. RA KARTINI II-S KAV. 6 RT 6/RW 14 KEL. PONDOK PINANG, KEC. KEBAYORAN LAMA JAKARTA SELATAN 12310', 'Jakarta Selatan', 'Jakarta Selatan', '12310', '0626345672025000', '', '1', '26', '2026-03-13 15:57:55', '2026-03-13 15:57:55');

-- --------------------------------------------------------
-- Table structure for `divisions`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `divisions`;
CREATE TABLE `divisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` varchar(50) DEFAULT NULL,
  `division_code` varchar(20) DEFAULT NULL,
  `division_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `division_type` enum('income','expense','both') DEFAULT 'both',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Dumping data for table `divisions`

INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('1', 'cqc-enjiniring', 'KITCHEN', 'Kitchen', NULL, 'both', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('2', 'cqc-enjiniring', 'BAR', 'Bar', NULL, 'both', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('3', 'cqc-enjiniring', 'RESTO', 'Resto', NULL, 'both', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('4', 'cqc-enjiniring', 'HOUSEKEEPING', 'Housekeeping', NULL, 'expense', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('5', 'cqc-enjiniring', 'HOTEL', 'Hotel', NULL, 'income', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('6', 'cqc-enjiniring', 'GARDENER', 'Gardener', NULL, 'expense', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('7', 'cqc-enjiniring', 'OTHERS', 'Lain-lain', NULL, 'both', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('8', 'cqc-enjiniring', 'PC', 'Petty Cash', NULL, 'both', '1', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('9', NULL, 'CQC', 'CQC Projects', NULL, 'both', '1', '2026-03-01 20:28:09', '2026-03-01 20:28:09');

-- --------------------------------------------------------
-- Table structure for `fingerprint_log`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `fingerprint_log`;
CREATE TABLE `fingerprint_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cloud_id` varchar(50) NOT NULL,
  `type` varchar(32) DEFAULT 'attlog',
  `pin` varchar(20) DEFAULT NULL,
  `scan_time` datetime DEFAULT NULL,
  `verify_method` varchar(30) DEFAULT NULL,
  `status_scan` varchar(30) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `processed` tinyint(1) DEFAULT 0,
  `process_result` varchar(255) DEFAULT NULL,
  `raw_data` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cloud` (`cloud_id`),
  KEY `idx_pin` (`pin`),
  KEY `idx_scan` (`scan_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for `frontdesk_rooms`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `frontdesk_rooms`;
CREATE TABLE `frontdesk_rooms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `room_number` varchar(10) DEFAULT NULL,
  `room_type` varchar(50) DEFAULT NULL,
  `floor` int(11) DEFAULT 1,
  `price` decimal(15,2) DEFAULT 0.00,
  `status` varchar(20) DEFAULT 'available',
  `guest_name` varchar(100) DEFAULT NULL,
  `check_in` date DEFAULT NULL,
  `check_out` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `guests`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `guests`;
CREATE TABLE `guests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `guest_name` varchar(200) NOT NULL,
  `id_card_type` varchar(20) DEFAULT 'ktp',
  `id_card_number` varchar(50) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `nationality` varchar(50) DEFAULT 'Indonesia',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `hotel_invoice_items`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `hotel_invoice_items`;
CREATE TABLE `hotel_invoice_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `service_type` enum('motor_rental','laundry','service','airport_drop','harbor_drop','narayana_trip','lain_lain') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `start_datetime` datetime DEFAULT NULL,
  `end_datetime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inv` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for `hotel_invoices`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `hotel_invoices`;
CREATE TABLE `hotel_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `business_id` int(11) NOT NULL DEFAULT 1,
  `invoice_number` varchar(30) NOT NULL,
  `booking_id` int(11) DEFAULT NULL,
  `guest_name` varchar(120) NOT NULL,
  `guest_phone` varchar(30) DEFAULT NULL,
  `room_number` varchar(20) DEFAULT NULL,
  `total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('unpaid','paid','partial') NOT NULL DEFAULT 'unpaid',
  `payment_method` varchar(20) NOT NULL DEFAULT 'cash',
  `status` enum('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'confirmed',
  `notes` text DEFAULT NULL,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `cashbook_synced` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `idx_biz` (`business_id`),
  KEY `idx_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for `hotel_service_catalog`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `hotel_service_catalog`;
CREATE TABLE `hotel_service_catalog` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `business_id` int(11) NOT NULL DEFAULT 1,
  `service_type` enum('motor_rental','laundry','service','airport_drop','harbor_drop','narayana_trip','lain_lain') NOT NULL,
  `item_name` varchar(120) NOT NULL,
  `default_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(30) DEFAULT 'unit',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_biz_svc` (`business_id`,`service_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for `investor_balances`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `investor_balances`;
CREATE TABLE `investor_balances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `investor_id` int(11) DEFAULT NULL,
  `balance` decimal(15,2) DEFAULT 0.00,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `investor_bills`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `investor_bills`;
CREATE TABLE `investor_bills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `investor_id` int(11) DEFAULT NULL,
  `bill_name` varchar(100) DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('pending','paid','overdue') DEFAULT 'pending',
  `paid_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `investor_transactions`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `investor_transactions`;
CREATE TABLE `investor_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `investor_id` int(11) DEFAULT NULL,
  `transaction_type` enum('investment','return','dividend') DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `transaction_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `investors`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `investors`;
CREATE TABLE `investors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `investor_name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `leave_requests`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `leave_requests`;
CREATE TABLE `leave_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `leave_type` varchar(50) DEFAULT 'cuti',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_emp` (`employee_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for `notifications`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`,`is_read`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for `overtime_requests`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `overtime_requests`;
CREATE TABLE `overtime_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `overtime_date` date NOT NULL,
  `reason` text NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_emp_date` (`employee_id`,`overtime_date`),
  KEY `idx_emp` (`employee_id`),
  KEY `idx_status` (`status`),
  KEY `idx_date` (`overtime_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for `payroll_attendance`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `payroll_attendance`;
CREATE TABLE `payroll_attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `check_in_time` time DEFAULT NULL,
  `check_in_lat` decimal(10,7) DEFAULT NULL,
  `check_in_lng` decimal(10,7) DEFAULT NULL,
  `check_in_distance_m` int(11) DEFAULT NULL,
  `check_in_address` varchar(255) DEFAULT NULL,
  `check_in_device` varchar(200) DEFAULT NULL,
  `check_out_time` time DEFAULT NULL,
  `check_out_lat` decimal(10,7) DEFAULT NULL,
  `check_out_lng` decimal(10,7) DEFAULT NULL,
  `check_out_distance_m` int(11) DEFAULT NULL,
  `check_out_device` varchar(200) DEFAULT NULL,
  `work_hours` decimal(5,2) DEFAULT NULL,
  `status` enum('present','late','absent','leave','holiday','half_day') NOT NULL DEFAULT 'present',
  `is_outside_radius` tinyint(1) DEFAULT 0,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `scan_3` time DEFAULT NULL,
  `scan_4` time DEFAULT NULL,
  `shift_1_hours` decimal(5,2) DEFAULT NULL,
  `shift_2_hours` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_attendance` (`employee_id`,`attendance_date`),
  KEY `idx_date` (`attendance_date`),
  KEY `idx_employee` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `payroll_attendance`

INSERT INTO `payroll_attendance` (`id`, `employee_id`, `attendance_date`, `check_in_time`, `check_in_lat`, `check_in_lng`, `check_in_distance_m`, `check_in_address`, `check_in_device`, `check_out_time`, `check_out_lat`, `check_out_lng`, `check_out_distance_m`, `check_out_device`, `work_hours`, `status`, `is_outside_radius`, `notes`, `created_at`, `updated_at`, `scan_3`, `scan_4`, `shift_1_hours`, `shift_2_hours`) VALUES ('1', '2', '2026-03-05', '00:48:56', '-7.4936461', '110.5461411', '66', 'Rejosari, Cabeankunti, Boyolali, Central Java, Java, 57362, Indonesia', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Mobile Safari/537.36', '01:14:35', '-7.4936999', '110.5461719', '68', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Mobile Safari/537.36', '0.43', 'present', '0', NULL, '2026-03-05 00:48:56', '2026-03-05 01:14:35', NULL, NULL, NULL, NULL);
INSERT INTO `payroll_attendance` (`id`, `employee_id`, `attendance_date`, `check_in_time`, `check_in_lat`, `check_in_lng`, `check_in_distance_m`, `check_in_address`, `check_in_device`, `check_out_time`, `check_out_lat`, `check_out_lng`, `check_out_distance_m`, `check_out_device`, `work_hours`, `status`, `is_outside_radius`, `notes`, `created_at`, `updated_at`, `scan_3`, `scan_4`, `shift_1_hours`, `shift_2_hours`) VALUES ('2', '3', '2026-03-05', '01:17:52', '-7.4936742', '110.5462146', '73', 'Rejosari, Cabeankunti, Boyolali, Central Java, Java, 57362, Indonesia', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Mobile Safari/537.36', '01:18:22', '-7.4936710', '110.5462133', '73', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Mobile Safari/537.36', '0.01', 'present', '0', NULL, '2026-03-05 01:17:52', '2026-03-05 01:18:22', NULL, NULL, NULL, NULL);
INSERT INTO `payroll_attendance` (`id`, `employee_id`, `attendance_date`, `check_in_time`, `check_in_lat`, `check_in_lng`, `check_in_distance_m`, `check_in_address`, `check_in_device`, `check_out_time`, `check_out_lat`, `check_out_lng`, `check_out_distance_m`, `check_out_device`, `work_hours`, `status`, `is_outside_radius`, `notes`, `created_at`, `updated_at`, `scan_3`, `scan_4`, `shift_1_hours`, `shift_2_hours`) VALUES ('3', '4', '2026-03-05', '11:37:45', '-6.4175027', '107.0986878', '23', 'Mutiara Bekasi Jaya, Wibawamulya, Kab Bekasi, Jawa Barat, Jawa, Indonesia', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Mobile Safari/537.36', '11:39:57', '-6.4175043', '107.0986760', '22', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Mobile Safari/537.36', '0.04', 'late', '0', NULL, '2026-03-05 11:37:45', '2026-03-05 11:39:57', NULL, NULL, NULL, NULL);
INSERT INTO `payroll_attendance` (`id`, `employee_id`, `attendance_date`, `check_in_time`, `check_in_lat`, `check_in_lng`, `check_in_distance_m`, `check_in_address`, `check_in_device`, `check_out_time`, `check_out_lat`, `check_out_lng`, `check_out_distance_m`, `check_out_device`, `work_hours`, `status`, `is_outside_radius`, `notes`, `created_at`, `updated_at`, `scan_3`, `scan_4`, `shift_1_hours`, `shift_2_hours`) VALUES ('4', '2', '2026-03-06', '12:20:26', '-7.4937512', '110.5461970', '71', 'Rejosari, Cabeankunti, Boyolali, Central Java, Java, 57362, Indonesia', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Mobile Safari/537.36', NULL, NULL, NULL, NULL, NULL, NULL, 'late', '0', NULL, '2026-03-06 12:20:26', '2026-03-06 12:20:26', NULL, NULL, NULL, NULL);
INSERT INTO `payroll_attendance` (`id`, `employee_id`, `attendance_date`, `check_in_time`, `check_in_lat`, `check_in_lng`, `check_in_distance_m`, `check_in_address`, `check_in_device`, `check_out_time`, `check_out_lat`, `check_out_lng`, `check_out_distance_m`, `check_out_device`, `work_hours`, `status`, `is_outside_radius`, `notes`, `created_at`, `updated_at`, `scan_3`, `scan_4`, `shift_1_hours`, `shift_2_hours`) VALUES ('5', '4', '2026-03-07', '14:49:39', '-6.4177213', '107.0986813', '45', 'Mutiara Bekasi Jaya, Wibawamulya, Kab Bekasi, Jawa Barat, Jawa, Indonesia', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Mobile Safari/537.36', '14:50:25', '-6.4175158', '107.0986990', '25', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Mobile Safari/537.36', '0.01', 'late', '0', NULL, '2026-03-07 14:49:39', '2026-03-07 14:50:25', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------
-- Table structure for `payroll_attendance_config`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `payroll_attendance_config`;
CREATE TABLE `payroll_attendance_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `office_lat` decimal(10,7) NOT NULL DEFAULT -6.2000000,
  `office_lng` decimal(10,7) NOT NULL DEFAULT 106.8166700,
  `allowed_radius_m` int(11) NOT NULL DEFAULT 200,
  `office_name` varchar(100) DEFAULT 'Kantor',
  `checkin_start` time DEFAULT '07:00:00',
  `checkin_end` time DEFAULT '10:00:00',
  `checkout_start` time DEFAULT '16:00:00',
  `allow_outside` tinyint(1) DEFAULT 0,
  `app_logo` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `fingerspot_cloud_id` varchar(50) DEFAULT NULL,
  `fingerspot_enabled` tinyint(1) DEFAULT 0,
  `fingerspot_token` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `payroll_attendance_config`

INSERT INTO `payroll_attendance_config` (`id`, `office_lat`, `office_lng`, `allowed_radius_m`, `office_name`, `checkin_start`, `checkin_end`, `checkout_start`, `allow_outside`, `app_logo`, `updated_at`, `updated_by`, `fingerspot_cloud_id`, `fingerspot_enabled`, `fingerspot_token`) VALUES ('1', '-6.2000000', '106.8166700', '200', 'Kantor', '07:00:00', '10:00:00', '16:00:00', '0', 'uploads/attendance_logos/logo_cqc.png', '2026-03-05 01:13:07', '26', NULL, '0', NULL);

-- --------------------------------------------------------
-- Table structure for `payroll_attendance_locations`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `payroll_attendance_locations`;
CREATE TABLE `payroll_attendance_locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `location_name` varchar(100) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `lat` decimal(10,7) NOT NULL DEFAULT 0.0000000,
  `lng` decimal(10,7) NOT NULL DEFAULT 0.0000000,
  `radius_m` int(11) NOT NULL DEFAULT 200,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `payroll_attendance_locations`

INSERT INTO `payroll_attendance_locations` (`id`, `location_name`, `address`, `lat`, `lng`, `radius_m`, `is_active`, `created_at`) VALUES ('1', 'Projek PT.Nyoba', 'Cepogo', '-7.4937496', '110.5455494', '100', '1', '2026-03-05 00:14:31');
INSERT INTO `payroll_attendance_locations` (`id`, `location_name`, `address`, `lat`, `lng`, `radius_m`, `is_active`, `created_at`) VALUES ('2', 'CQC OFFICE', 'Jl.Mutiara Bekasi Jaya', '-6.4173252', '107.0985729', '200', '1', '2026-03-05 11:22:50');

-- --------------------------------------------------------
-- Table structure for `payroll_employees`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `payroll_employees`;
CREATE TABLE `payroll_employees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_code` varchar(20) NOT NULL COMMENT 'Format: EMP-XXX',
  `full_name` varchar(100) NOT NULL,
  `position` varchar(100) NOT NULL COMMENT 'e.g. Manager, Chef, Waiter',
  `department` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `join_date` date NOT NULL,
  `base_salary` decimal(15,2) NOT NULL DEFAULT 0.00,
  `bank_name` varchar(50) DEFAULT NULL,
  `bank_account` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `attendance_pin` varchar(6) DEFAULT NULL,
  `face_descriptor` mediumtext DEFAULT NULL COMMENT 'JSON face descriptor from face-api.js',
  `finger_id` varchar(20) DEFAULT NULL,
  `monthly_target_hours` int(11) DEFAULT 200,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_employee_code` (`employee_code`),
  KEY `idx_active` (`is_active`),
  KEY `idx_position` (`position`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `payroll_employees`

INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`, `finger_id`, `monthly_target_hours`) VALUES ('1', 'EMP-001', 'Ilham', 'Manager', 'Service', '087727553855', NULL, '2026-03-01', '5000000.00', 'BNI', '2254542544245', '0', NULL, '8', '2026-03-01 10:08:18', '2026-03-05 11:23:11', NULL, '[-0.0526791512966156,0.1435430645942688,0.03142547979950905,-0.05542157590389252,-0.07163574546575546,-0.05024278536438942,-0.07006779313087463,-0.08152081072330475,0.14806783199310303,-0.10424286872148514,0.2576749920845032,0.06251401454210281,-0.12524400651454926,-0.1693834811449051,0.04051832854747772,0.1953052431344986,-0.29045605659484863,-0.1519445776939392,-0.027195682749152184,-0.012638640590012074,0.05289005860686302,-0.012217224575579166,0.04788205400109291,0.05253269150853157,-0.16958579421043396,-0.43476352095603943,-0.08473271876573563,-0.16368547081947327,0.056486837565898895,-0.04039378836750984,-0.07794889807701111,0.016962233930826187,-0.19488391280174255,-0.07271462678909302,0.014916441403329372,0.011351262219250202,0.014522665180265903,0.02525179646909237,0.1475248634815216,0.06262174993753433,-0.19070668518543243,0.08933282643556595,0.0012076030252501369,0.3094611167907715,0.245925635099411,0.09635046869516373,0.11295377463102341,-0.055333543568849564,0.12054333835840225,-0.17716331779956818,0.10171230137348175,0.12380887567996979,0.07753638923168182,0.05938764661550522,-0.020306771621108055,-0.11533641070127487,0.013883925974369049,0.12633150815963745,-0.20236706733703613,0.06360626965761185,0.10400045663118362,-0.026203496381640434,-0.04198607802391052,0.02866152487695217,0.2692558765411377,0.05454409494996071,-0.15552081167697906,-0.1063728854060173,0.10436473041772842,-0.1268097460269928,-0.06338845193386078,-0.048212163150310516,-0.19605319201946259,-0.15690405666828156,-0.3554868698120117,0.060725100338459015,0.3781641125679016,0.11385379731655121,-0.1888233870267868,0.0036784005351364613,-0.10777363181114197,0.042129091918468475,0.10317707061767578,0.10083853453397751,-0.0647381916642189,-0.02785281091928482,-0.09772837162017822,0.024998415261507034,0.1760045886039734,0.009374032728374004,-0.0015844007721170783,0.25292378664016724,-0.005253200884908438,0.027264583855867386,0.00313337123952806,0.07573670148849487,-0.09721667319536209,-0.0014257591683417559,-0.10507412999868393,-0.020661747083067894,-0.004013582598417997,-0.04828658327460289,-0.03981637954711914,0.15432532131671906,-0.23768854141235352,0.15649867057800293,-0.021333735436201096,0.05216997489333153,0.019577782601118088,0.06592212617397308,-0.09291181713342667,-0.14688178896903992,0.07849821448326111,-0.17218515276908875,0.18900693953037262,0.29797711968421936,0.08871448785066605,0.13261565566062927,0.0763368234038353,0.020710034295916557,-0.059446923434734344,-0.025604236871004105,-0.2209932506084442,-0.017257003113627434,0.07892757654190063,-0.04015152528882027,0.06348274648189545,0.016149619594216347]', NULL, '200');
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`, `finger_id`, `monthly_target_hours`) VALUES ('2', 'EMP-002', 'Arief', 'Manager', 'Service', '081330316204', NULL, '2026-03-04', '6500000.00', 'BNI', '676865644757', '0', NULL, '26', '2026-03-04 23:04:30', '2026-04-30 21:15:02', NULL, '[-0.06676212698221207,0.14234578609466553,0.04207853227853775,-0.05778927728533745,-0.029435480013489723,-0.07351850718259811,-0.09672627598047256,-0.16231973469257355,0.14554427564144135,-0.06816073507070541,0.2014797329902649,0.038036853075027466,-0.1170642077922821,-0.14640024304389954,0.010851454921066761,0.1560155153274536,-0.26313120126724243,-0.17518720030784607,-0.03597746416926384,-0.047615326941013336,0.023440582677721977,0.03762397915124893,0.03612576425075531,0.0009705156553536654,-0.14954261481761932,-0.40056976675987244,-0.10067785531282425,-0.11693944036960602,0.05075979232788086,-0.01613811030983925,-0.058883942663669586,-0.0031845441553741693,-0.24209123849868774,-0.08452517539262772,0.046410709619522095,0.08127989619970322,0.019721372053027153,-0.020778926089406013,0.1461804360151291,0.06369385123252869,-0.1745569109916687,0.08474946022033691,0.008387099951505661,0.2428140491247177,0.22252850234508514,0.0654287189245224,0.05843077227473259,-0.09397988021373749,0.1250540167093277,-0.18552090227603912,0.09988810867071152,0.15022797882556915,0.07251399010419846,0.05887061357498169,0.006604431662708521,-0.09052514284849167,0.01947348564863205,0.09894996881484985,-0.1948549896478653,0.05240005999803543,0.1162780225276947,-0.07888417690992355,0.00017468183068558574,0.022559350356459618,0.22209736704826355,0.10109502822160721,-0.09211824089288712,-0.07856298238039017,0.17498667538166046,-0.11039397865533829,-0.07026097178459167,-0.06143230199813843,-0.0977587029337883,-0.1446382850408554,-0.33322951197624207,0.04793679714202881,0.4007720649242401,0.10327071696519852,-0.22660383582115173,0.007693321444094181,-0.11695658415555954,0.02280677855014801,0.11114932596683502,0.05189886689186096,-0.0760403424501419,-0.0017698195297271013,-0.11821459978818893,0.0027371165342628956,0.2697855830192566,0.0389387384057045,-0.02289789728820324,0.22666506469249725,-0.020140813663601875,0.061253126710653305,0.08638419210910797,0.08785101771354675,-0.0891786590218544,-0.04541822895407677,-0.13802163302898407,-0.027313241735100746,0.00925099290907383,-0.07528560608625412,-0.048760589212179184,0.17845486104488373,-0.18792125582695007,0.15659591555595398,0.007218408398330212,-0.023867221549153328,-0.00693783862516284,0.045553866773843765,-0.037574559450149536,-0.11449091881513596,0.10920955240726471,-0.14570696651935577,0.24672672152519226,0.2224440574645996,0.03196878731250763,0.1769547164440155,0.0450744703412056,0.06284875422716141,-0.08293192088603973,-0.059781961143016815,-0.1623932272195816,-0.1061798557639122,0.025829598307609558,0.01273457333445549,0.08149353414773941,0.06222197413444519]', NULL, '200');
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`, `finger_id`, `monthly_target_hours`) VALUES ('3', 'EMP-003', 'dita anjarsari', 'Supervisor', 'Front Office', '82332109996', NULL, '2026-03-04', '6000000.00', 'BNI', '2254542544245', '0', NULL, '26', '2026-03-05 01:16:46', '2026-04-30 21:15:11', NULL, '[-0.09734375774860382,0.0653543546795845,0.051167916506528854,-0.012393670156598091,-0.054912298917770386,-0.07513245195150375,-0.038302671164274216,-0.16214999556541443,0.13953104615211487,-0.14616358280181885,0.19897311925888062,-0.06730259209871292,-0.16594628989696503,-0.1036076471209526,-0.05256492272019386,0.1911039650440216,-0.16379797458648682,-0.09890519082546234,-0.06878766417503357,-0.03204264119267464,0.053834106773138046,0.016376741230487823,0.046419408172369,0.004743395373225212,-0.04916256666183472,-0.37876173853874207,-0.1303093284368515,-0.05557949095964432,-0.028836797922849655,0.008129642345011234,-0.06248648464679718,0.0381244421005249,-0.16685998439788818,-0.11371836066246033,0.04568397253751755,0.07285681366920471,-0.02746945060789585,-0.055453963577747345,0.148563951253891,-0.026850659400224686,-0.1917160004377365,-0.04169980436563492,0.1327260136604309,0.23842690885066986,0.1549355685710907,0.11775833368301392,0.0034232251346111298,-0.15120618045330048,0.15599709749221802,-0.15794241428375244,0.053917620331048965,0.07426551729440689,0.05835305154323578,0.07694251835346222,0.05254422128200531,-0.11811862885951996,0.06425456702709198,0.12501561641693115,-0.14369605481624603,-0.02009667456150055,0.061441514641046524,-0.04050658643245697,-0.02618282660841942,-0.0580999031662941,0.2683563530445099,0.11924155056476593,-0.09655077010393143,-0.11282498389482498,0.13333660364151,-0.14321203529834747,-0.05371544137597084,-0.012632093392312527,-0.1262379139661789,-0.17508219182491302,-0.31766074895858765,-0.02890074998140335,0.42625775933265686,0.08069127798080444,-0.13213619589805603,0.01105592679232359,-0.02213735319674015,-0.021274013444781303,0.1897219717502594,0.15881793200969696,-0.043107397854328156,0.03127356246113777,-0.05105629563331604,-0.028057284653186798,0.22570714354515076,-0.04337967559695244,-0.044049058109521866,0.19102145731449127,-0.02233663946390152,0.060067929327487946,0.029430542141199112,0.013419141061604023,-0.0851762518286705,0.06656898558139801,-0.1712559461593628,-0.0039975931867957115,0.035299498587846756,-0.02354303188621998,-0.0049386234022676945,0.09421808272600174,-0.17100906372070312,0.09640704840421677,0.02004486322402954,0.038271352648735046,0.04517902433872223,-0.043048352003097534,-0.10019318759441376,-0.07607007771730423,0.0938480794429779,-0.18992459774017334,0.15259501338005066,0.15670868754386902,0.019784916192293167,0.18031588196754456,0.08686418831348419,0.10164197534322739,-0.00014857947826385498,-0.026867849752306938,-0.216944620013237,-0.004804070573300123,0.12578199803829193,-0.01925625465810299,0.08146405220031738,0.004493089392781258]', NULL, '200');
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`, `finger_id`, `monthly_target_hours`) VALUES ('4', 'EMP-004', 'ILHAM', 'Direktur', 'Admin', '82332109996', NULL, '2025-01-01', '9000000.00', 'BNI', '2254542544245', '1', NULL, '8', '2026-03-05 11:23:38', '2026-04-30 21:26:51', NULL, '[-0.11347512155771255,0.023129411041736603,0.08768424391746521,-0.02877522073686123,-0.03717915341258049,-0.11618173122406006,-0.01516123116016388,-0.1041589006781578,0.15616874396800995,-0.12189272046089172,0.27479174733161926,-0.06108715012669563,-0.23822961747646332,-0.07450632750988007,-0.045771896839141846,0.13117654621601105,-0.1405334621667862,-0.0803128257393837,-0.02269509620964527,-0.07064563781023026,0.0655575543642044,-0.046896517276763916,0.05979093536734581,0.09714575856924057,-0.055708616971969604,-0.3348468840122223,-0.13018293678760529,-0.1174546629190445,0.0764060914516449,-0.02978351339697838,-0.08764445036649704,0.055706221610307693,-0.17809392511844635,-0.062435418367385864,-0.017496325075626373,0.08661673963069916,-0.013279257342219353,-0.026886506006121635,0.20932376384735107,0.051737070083618164,-0.13385812938213348,-0.038376640528440475,-0.04777471348643303,0.2529580891132355,0.12376873195171356,0.1246950700879097,0.05772196128964424,-0.09011612832546234,0.036068860441446304,-0.18678079545497894,0.05397879704833031,0.18522952497005463,0.12958306074142456,0.05397282540798187,-0.026904668658971786,-0.23027825355529785,0.07025782763957977,0.04736769571900368,-0.1680355966091156,0.08304611593484879,0.06139883026480675,-0.1048935279250145,0.012115098536014557,0.03377518802881241,0.33622652292251587,0.09869537502527237,-0.12968823313713074,-0.1049942672252655,0.16462288796901703,-0.1525111049413681,-0.013076500035822392,0.03987114503979683,-0.17978765070438385,-0.1848098188638687,-0.2622605860233307,0.08852703869342804,0.38667261600494385,0.13205009698867798,-0.16923531889915466,0.03956993669271469,-0.18166621029376984,0.006907140836119652,0.10899876058101654,0.11731807887554169,-0.09745679050683975,0.02762996405363083,-0.14716002345085144,-0.03724327310919762,0.1430523842573166,-0.07406369596719742,-0.038488131016492844,0.2207052856683731,-0.01729116588830948,0.10367818921804428,0.013632679358124733,-0.033776141703128815,-0.10870660841464996,0.025214187800884247,-0.08730170875787735,-0.020941097289323807,0.03785352408885956,-0.07491293549537659,0.010247698985040188,0.06618189811706543,-0.2022007405757904,0.06507133692502975,-0.03253326192498207,-0.051076725125312805,-0.02098117396235466,0.011306153610348701,-0.16142138838768005,-0.1123252585530281,0.13708797097206116,-0.23848584294319153,0.23751038312911987,0.25078338384628296,0.12388663738965988,0.18435318768024445,0.1509162336587906,0.09450104087591171,0.021416161209344864,-0.03310040012001991,-0.1141035258769989,0.010950092226266861,0.09041263163089752,-0.02216167561709881,0.06720981001853943,0.015231813304126263]', NULL, '200');
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`, `finger_id`, `monthly_target_hours`) VALUES ('5', 'EMP-005', 'Ni Made Karuni Sanjiwani', 'Finance', 'Front Office', '081221660338', NULL, '2025-01-14', '4500000.00', 'BCA', '2030752573', '1', NULL, '26', '2026-04-30 21:19:26', '2026-04-30 21:19:26', NULL, NULL, NULL, '200');
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`, `finger_id`, `monthly_target_hours`) VALUES ('6', 'EMP-006', 'Reny Nur Fatimah', 'HSE Officer', 'Admin', '0895419509293', NULL, '2025-05-05', '5000000.00', 'BCA', '', '1', NULL, '26', '2026-04-30 21:22:55', '2026-04-30 21:22:55', NULL, NULL, NULL, '200');
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`, `finger_id`, `monthly_target_hours`) VALUES ('7', 'EMP-007', 'Adi Irmayadi', 'Komisaris', 'Front Office', '081358820474', NULL, '2025-01-01', '15000000.00', 'BCA', '', '1', NULL, '26', '2026-04-30 21:26:01', '2026-04-30 21:26:01', NULL, NULL, NULL, '200');
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`, `finger_id`, `monthly_target_hours`) VALUES ('8', 'EMP-008', 'Aji Paningit', 'Lead Engineering Electrical', 'Service', '', NULL, '2026-04-02', '7300000.00', '', '', '1', NULL, '26', '2026-04-30 21:27:56', '2026-04-30 21:28:30', NULL, NULL, NULL, '200');
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`, `finger_id`, `monthly_target_hours`) VALUES ('9', 'EMP-009', 'Sulthan Shalahuddin Rizqon Rangkuti', 'Engineering', 'Service', '', NULL, '2025-10-25', '5000000.00', '', '', '1', NULL, '26', '2026-04-30 21:29:45', '2026-04-30 21:29:45', NULL, NULL, NULL, '200');
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`, `finger_id`, `monthly_target_hours`) VALUES ('10', 'EMP-010', 'Tasya Oktavia', 'Procurement', 'Front Office', '', NULL, '2026-04-06', '3500000.00', '', '', '1', NULL, '26', '2026-04-30 21:30:47', '2026-04-30 21:30:47', NULL, NULL, NULL, '200');
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`, `finger_id`, `monthly_target_hours`) VALUES ('11', 'EMP-011', 'Endang Widiarti', 'HSE Site', 'Service', '', NULL, '2026-04-06', '5000000.00', '', '', '1', NULL, '26', '2026-04-30 21:33:42', '2026-04-30 21:33:42', NULL, NULL, NULL, '200');
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`, `finger_id`, `monthly_target_hours`) VALUES ('12', 'EMP-012', 'Ova Hapsari Maharani', 'HSE Site', 'Service', '', NULL, '2026-04-15', '5500000.00', '', '', '1', NULL, '26', '2026-04-30 21:35:08', '2026-04-30 21:35:08', NULL, NULL, NULL, '200');
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`, `finger_id`, `monthly_target_hours`) VALUES ('13', 'EMP-013', 'Andini Ucik Rahmawati', 'HSE Site', '', '', NULL, '2026-05-05', '5000000.00', '', '', '1', NULL, '26', '2026-04-30 21:38:14', '2026-04-30 21:38:14', NULL, NULL, NULL, '200');

-- --------------------------------------------------------
-- Table structure for `payroll_periods`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `payroll_periods`;
CREATE TABLE `payroll_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `period_month` int(11) NOT NULL COMMENT '1-12',
  `period_year` int(11) NOT NULL COMMENT 'e.g. 2026',
  `period_label` varchar(50) NOT NULL COMMENT 'e.g. Februari 2026',
  `status` enum('draft','submitted','approved','paid') NOT NULL DEFAULT 'draft',
  `total_gross` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_deductions` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_net` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_employees` int(11) NOT NULL DEFAULT 0,
  `submitted_at` datetime DEFAULT NULL,
  `submitted_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_period` (`period_month`,`period_year`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `payroll_periods`

INSERT INTO `payroll_periods` (`id`, `period_month`, `period_year`, `period_label`, `status`, `total_gross`, `total_deductions`, `total_net`, `total_employees`, `submitted_at`, `submitted_by`, `approved_at`, `approved_by`, `paid_at`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('1', '3', '2026', 'March 2026', 'draft', '12780000.00', '0.00', '12780000.00', '1', NULL, NULL, NULL, NULL, NULL, NULL, '8', '2026-03-01 10:09:48', '2026-03-01 19:43:20');

-- --------------------------------------------------------
-- Table structure for `payroll_slip_details`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `payroll_slip_details`;
CREATE TABLE `payroll_slip_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slip_id` int(11) NOT NULL,
  `component_type` enum('earning','deduction') NOT NULL,
  `component_name` varchar(100) NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `slip_id` (`slip_id`),
  CONSTRAINT `payroll_slip_details_ibfk_1` FOREIGN KEY (`slip_id`) REFERENCES `payroll_slips` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `payroll_slips`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `payroll_slips`;
CREATE TABLE `payroll_slips` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `period_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `employee_name` varchar(100) NOT NULL COMMENT 'Snapshot of name',
  `position` varchar(100) NOT NULL COMMENT 'Snapshot of position',
  `base_salary` decimal(15,2) NOT NULL DEFAULT 0.00,
  `work_hours` decimal(10,2) NOT NULL DEFAULT 200.00 COMMENT 'Monthly work hours (target: 200)',
  `actual_base` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Calculated base after work hours',
  `overtime_hours` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Custom logic: hours input',
  `overtime_rate` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Rate per hour = Base / 200',
  `overtime_amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Final amount',
  `incentive` decimal(15,2) NOT NULL DEFAULT 0.00,
  `allowance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `bonus` decimal(15,2) NOT NULL DEFAULT 0.00,
  `other_income` decimal(15,2) NOT NULL DEFAULT 0.00,
  `deduction_loan` decimal(15,2) NOT NULL DEFAULT 0.00,
  `deduction_absence` decimal(15,2) NOT NULL DEFAULT 0.00,
  `deduction_tax` decimal(15,2) NOT NULL DEFAULT 0.00,
  `deduction_bpjs` decimal(15,2) NOT NULL DEFAULT 0.00,
  `deduction_other` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_earnings` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_deductions` decimal(15,2) NOT NULL DEFAULT 0.00,
  `net_salary` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_slip` (`period_id`,`employee_id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `payroll_slips_ibfk_1` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_slips_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `payroll_employees` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `payroll_slips`

INSERT INTO `payroll_slips` (`id`, `period_id`, `employee_id`, `employee_name`, `position`, `base_salary`, `work_hours`, `actual_base`, `overtime_hours`, `overtime_rate`, `overtime_amount`, `incentive`, `allowance`, `bonus`, `other_income`, `deduction_loan`, `deduction_absence`, `deduction_tax`, `deduction_bpjs`, `deduction_other`, `total_earnings`, `total_deductions`, `net_salary`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('1', '1', '1', 'Ilham', 'Manager', '12000000.00', '200.00', '12000000.00', '13.00', '60000.00', '780000.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '12780000.00', '0.00', '12780000.00', NULL, NULL, '2026-03-01 10:09:48', '2026-03-01 19:43:20');

-- --------------------------------------------------------
-- Table structure for `payroll_work_schedules`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `payroll_work_schedules`;
CREATE TABLE `payroll_work_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `day_of_week` tinyint(4) NOT NULL DEFAULT 0,
  `start_time` time NOT NULL DEFAULT '09:00:00',
  `end_time` time NOT NULL DEFAULT '17:00:00',
  `break_minutes` int(11) DEFAULT 60,
  `is_off` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_emp_day` (`employee_id`,`day_of_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for `purchase_orders_detail`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `purchase_orders_detail`;
CREATE TABLE `purchase_orders_detail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_header_id` int(11) DEFAULT NULL,
  `item_name` varchar(200) DEFAULT NULL,
  `quantity` decimal(10,2) DEFAULT 1.00,
  `unit` varchar(20) DEFAULT NULL,
  `unit_price` decimal(15,2) DEFAULT 0.00,
  `total_price` decimal(15,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `purchase_orders_header`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `purchase_orders_header`;
CREATE TABLE `purchase_orders_header` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_number` varchar(30) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `po_date` date NOT NULL,
  `delivery_date` date DEFAULT NULL,
  `status` enum('draft','sent','received','cancelled') DEFAULT 'draft',
  `total_amount` decimal(15,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `po_number` (`po_number`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `push_subscriptions`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `push_subscriptions`;
CREATE TABLE `push_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL COMMENT 'users.id for admin/owner, NULL for staff',
  `employee_id` int(11) DEFAULT NULL COMMENT 'payroll_employees.id for staff portal',
  `endpoint` text NOT NULL,
  `public_key` varchar(255) NOT NULL,
  `auth_token` varchar(255) NOT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_employee` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `push_subscriptions`

INSERT INTO `push_subscriptions` (`id`, `user_id`, `employee_id`, `endpoint`, `public_key`, `auth_token`, `user_agent`, `created_at`, `updated_at`) VALUES ('37', '8', NULL, 'https://wns2-sg2p.notify.windows.com/w/?token=BQYAAABQ9sx6Mac9aHvP01my37SDdGnXDQqhYGXvebTWx4WpMPb09nX6a14DXCoSMAWVjvnGLit0KujD5GXsTDh143Pgbb1OSvtJYXKc0m2ooXCIvcRBrUIMO3K3Vi3XbmwvzEK6C01eOTKBg%2f1b7FCUUakHXxcN4eUh5icH05dadOsrqjySckcdCKqS6wmQIZOvjkh6jzpCUDNObIcnk1CYzlJPsp7%2bqnOhk3ViNZyz05%2bBdQzTyL8tkD4CxoO4tR%2fjmSgAHBhLwnQ6beeqFMWXlQEdYgGWFP5jfzcGjnD0MU3ISjsa0OE08mf61ix5Fb7E9WoLVPIQ0QU4qt10VTzuCzbY', 'BD7bFwy49YiX-X2CvKP5EqD50fw_unXdner63JclRPxG4PmdvFGfmAOyXHgIjwJAyVeUPgLyIJLizDWexBtnHuI', 'MF--OKVkDq4QMwzYMAxcpA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-07 23:52:02', '2026-04-07 23:52:02');

-- --------------------------------------------------------
-- Table structure for `roles`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  `role_code` varchar(30) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_system_role` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Dumping data for table `roles`

INSERT INTO `roles` (`id`, `role_name`, `role_code`, `description`, `is_system_role`, `created_at`) VALUES ('1', 'Admin', 'admin', 'System administrator', '1', '2026-02-27 08:57:24');
INSERT INTO `roles` (`id`, `role_name`, `role_code`, `description`, `is_system_role`, `created_at`) VALUES ('2', 'Manager', 'manager', 'Business manager', '1', '2026-02-27 08:57:24');
INSERT INTO `roles` (`id`, `role_name`, `role_code`, `description`, `is_system_role`, `created_at`) VALUES ('3', 'Staff', 'staff', 'Regular staff', '1', '2026-02-27 08:57:24');
INSERT INTO `roles` (`id`, `role_name`, `role_code`, `description`, `is_system_role`, `created_at`) VALUES ('4', 'Developer', 'developer', 'System developer', '1', '2026-02-27 08:57:24');
INSERT INTO `roles` (`id`, `role_name`, `role_code`, `description`, `is_system_role`, `created_at`) VALUES ('5', 'Owner', 'owner', 'Business owner', '1', '2026-02-27 08:57:24');

-- --------------------------------------------------------
-- Table structure for `room_types`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `room_types`;
CREATE TABLE `room_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type_name` varchar(100) NOT NULL,
  `base_price` decimal(12,2) DEFAULT 0.00,
  `max_occupancy` int(11) DEFAULT 2,
  `description` text DEFAULT NULL,
  `amenities` text DEFAULT NULL,
  `color_code` varchar(7) DEFAULT '#6366f1',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `rooms`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `rooms`;
CREATE TABLE `rooms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `room_number` varchar(20) NOT NULL,
  `room_type_id` int(11) DEFAULT NULL,
  `floor_number` int(11) DEFAULT 1,
  `status` varchar(20) DEFAULT 'available',
  `current_guest_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `position_x` int(11) DEFAULT 0,
  `position_y` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `sales_invoices_detail`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `sales_invoices_detail`;
CREATE TABLE `sales_invoices_detail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_header_id` int(11) DEFAULT NULL,
  `item_name` varchar(200) DEFAULT NULL,
  `quantity` decimal(10,2) DEFAULT 1.00,
  `unit` varchar(20) DEFAULT NULL,
  `unit_price` decimal(15,2) DEFAULT 0.00,
  `total_price` decimal(15,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `sales_invoices_header`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `sales_invoices_header`;
CREATE TABLE `sales_invoices_header` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(30) DEFAULT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('draft','sent','paid','cancelled') DEFAULT 'draft',
  `total_amount` decimal(15,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `settings`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) DEFAULT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` varchar(20) DEFAULT 'string',
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Dumping data for table `settings`

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('1', 'business_name', 'CQC Enjiniring', 'string', 'Business name', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('2', 'business_type', 'manufacture', 'string', 'Business type', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('3', 'currency', 'IDR', 'string', 'Currency', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('4', 'timezone', 'Asia/Jakarta', 'string', 'Timezone', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('5', 'date_format', 'd/m/Y', 'string', 'Date format', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('6', 'fiscal_year_start', '01', 'string', 'Fiscal year start month', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('7', 'show_running_balance', '1', 'boolean', 'Show running balance', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('8', 'show_daily_total', '1', 'boolean', 'Show daily total', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('9', 'enable_shift', '1', 'boolean', 'Enable shift', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('10', 'enable_approval', '0', 'boolean', 'Require approval', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('11', 'min_kas_awal', '0', 'string', 'Minimum kas awal', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('12', 'kas_awal_default', '0', 'string', 'Default kas awal', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('13', 'print_receipt', '1', 'boolean', 'Enable print receipt', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('14', 'demo_password', 'admin', 'string', 'Demo password', '2026-02-27 08:57:24', '2026-02-27 08:57:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('15', 'company_name', 'PT. CITRAQIAN CAHAYA ENJINIRING', 'text', 'Company Name', '2026-02-28 10:57:02', '2026-04-29 09:49:31');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('16', 'company_tagline', 'Empowering Your Project With Excellence', 'text', 'Company Tagline', '2026-02-28 10:57:02', '2026-04-29 09:49:31');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('17', 'company_address', 'Main office : Kedurus, Kepatihan, rt04 rw02, Sidoarjo Regency, East Java 61273\r\nBranch Office : Perumahan Mutiara Bekasi Jaua Blok R11 no 20, Sindangmulya, kec. Cibarusah, kab Bekasi\r\n', 'text', 'Company Address', '2026-02-28 10:57:02', '2026-04-30 12:15:49');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('18', 'company_phone', '+62 813 5882 0474', 'text', 'Company Phone', '2026-02-28 10:57:02', '2026-04-29 14:56:28');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('19', 'company_email', 'office@cqcenjiniring.com', 'text', 'Company Email', '2026-02-28 10:57:02', '2026-04-29 14:56:28');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('20', 'company_website', 'www.cqcenjiniring.com', 'text', 'Company Website', '2026-02-28 10:57:02', '2026-04-29 09:49:31');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('21', 'company_logo_cqc', 'https://res.cloudinary.com/dpdmut9ls/image/upload/v1772733932/adf_system/logos/company_logo_cqc.png', 'file', 'Company logo for CQC Enjiniring', '2026-02-28 10:57:02', '2026-03-06 01:05:25');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('22', 'login_logo', 'login-logo.png', 'string', NULL, '2026-03-01 06:26:51', '2026-03-01 06:26:51');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('23', 'login_background', 'login-bg.png', 'string', NULL, '2026-03-01 06:27:15', '2026-03-01 06:27:15');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('24', 'site_favicon', 'favicon.png', 'string', NULL, '2026-03-01 06:27:36', '2026-03-01 06:27:36');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('25', 'footer_copyright', '© 2026 AdF System - Multi-Business Management. All rights reserved.', 'string', NULL, '2026-03-01 06:27:59', '2026-03-01 06:28:15');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('26', 'footer_version', 'Version 20.2.3', 'string', NULL, '2026-03-01 06:27:59', '2026-03-01 06:27:59');

-- --------------------------------------------------------
-- Table structure for `staff`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `staff`;
CREATE TABLE `staff` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_code` varchar(30) DEFAULT NULL,
  `full_name` varchar(100) NOT NULL,
  `nickname` varchar(50) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(50) DEFAULT NULL,
  `id_number` varchar(30) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `join_date` date DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account` varchar(50) DEFAULT NULL,
  `bank_holder` varchar(100) DEFAULT NULL,
  `emergency_contact` varchar(100) DEFAULT NULL,
  `emergency_phone` varchar(20) DEFAULT NULL,
  `daily_rate` decimal(15,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_code` (`staff_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `suppliers`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `suppliers`;
CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(100) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `transaction_attachments`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `transaction_attachments`;
CREATE TABLE `transaction_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) DEFAULT NULL,
  `transaction_type` varchar(20) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `transactions`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `transactions`;
CREATE TABLE `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(50) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text DEFAULT NULL,
  `date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `user_permissions`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `user_permissions`;
CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `permission` varchar(50) NOT NULL,
  `can_view` tinyint(1) DEFAULT 1,
  `can_create` tinyint(1) DEFAULT 0,
  `can_edit` tinyint(1) DEFAULT 0,
  `can_delete` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `user_preferences`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `user_preferences`;
CREATE TABLE `user_preferences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `branch_id` varchar(50) DEFAULT NULL,
  `theme` varchar(20) DEFAULT 'dark',
  `language` varchar(5) DEFAULT 'id',
  `sidebar_collapsed` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Dumping data for table `user_preferences`

INSERT INTO `user_preferences` (`id`, `user_id`, `branch_id`, `theme`, `language`, `sidebar_collapsed`, `created_at`, `updated_at`) VALUES ('1', '23', NULL, 'light', 'id', '0', '2026-02-27 11:54:24', '2026-02-27 11:54:24');
INSERT INTO `user_preferences` (`id`, `user_id`, `branch_id`, `theme`, `language`, `sidebar_collapsed`, `created_at`, `updated_at`) VALUES ('2', '8', NULL, 'light', 'id', '0', '2026-02-28 10:56:24', '2026-02-28 10:56:24');
INSERT INTO `user_preferences` (`id`, `user_id`, `branch_id`, `theme`, `language`, `sidebar_collapsed`, `created_at`, `updated_at`) VALUES ('3', '25', NULL, 'light', 'id', '0', '2026-02-28 19:51:36', '2026-02-28 19:51:36');
INSERT INTO `user_preferences` (`id`, `user_id`, `branch_id`, `theme`, `language`, `sidebar_collapsed`, `created_at`, `updated_at`) VALUES ('4', '26', NULL, 'light', 'en', '0', '2026-02-28 20:55:40', '2026-02-28 21:57:36');
INSERT INTO `user_preferences` (`id`, `user_id`, `branch_id`, `theme`, `language`, `sidebar_collapsed`, `created_at`, `updated_at`) VALUES ('5', '28', NULL, 'light', 'id', '0', '2026-03-11 14:42:06', '2026-03-11 14:42:06');
INSERT INTO `user_preferences` (`id`, `user_id`, `branch_id`, `theme`, `language`, `sidebar_collapsed`, `created_at`, `updated_at`) VALUES ('6', '33', NULL, 'light', 'id', '0', '2026-04-29 10:09:33', '2026-04-29 10:09:33');

-- --------------------------------------------------------
-- Table structure for `users`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` enum('owner','admin','manager','frontdesk','cashier','accountant','staff') DEFAULT 'staff',
  `role_id` int(11) DEFAULT NULL,
  `business_access` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

SET FOREIGN_KEY_CHECKS=1;
