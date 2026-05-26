-- Narayana Hotel Database Backup
-- Backup Date: 2026-03-11 12:58:54
-- Database: adfb2574_adf

SET FOREIGN_KEY_CHECKS=0;

-- --------------------------------------------------------
-- Table structure for `activity_logs`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `activity_logs`

INSERT INTO `activity_logs` (`id`, `user_id`, `action`, `description`, `created_at`) VALUES ('40', '1', 'check_in', 'Check-in guest: Patrycja Maliszewska - Room 201 - Booking #BK-20260302-7338', '2026-03-02 11:05:28');
INSERT INTO `activity_logs` (`id`, `user_id`, `action`, `description`, `created_at`) VALUES ('42', '1', 'check_in', 'Check-in guest: Patrycja Maliszewska - Room 201 - Booking #BK-20260304-2485', '2026-03-04 12:39:53');
INSERT INTO `activity_logs` (`id`, `user_id`, `action`, `description`, `created_at`) VALUES ('44', '1', 'check_in', 'Check-in guest: AJIK - Room 105 - Booking #BK-20260306-6485', '2026-03-06 10:16:50');

-- --------------------------------------------------------
-- Table structure for `audit_logs`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `table_name` varchar(100) NOT NULL,
  `record_id` int(11) NOT NULL,
  `action` varchar(20) NOT NULL,
  `old_data` text DEFAULT NULL,
  `new_data` text DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(255) NOT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_table_record` (`table_name`,`record_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `audit_logs`

INSERT INTO `audit_logs` (`id`, `table_name`, `record_id`, `action`, `old_data`, `new_data`, `user_id`, `user_name`, `ip_address`, `user_agent`, `created_at`) VALUES ('1', 'cash_book', '1677', 'DELETE', '{\"id\":\"1677\",\"transaction_date\":\"2026-03-10\",\"transaction_time\":\"14:32:00\",\"division\":\"Project\",\"category\":\"Sandra\",\"transaction_type\":\"expense\",\"amount\":\"3572498.00\",\"payment_method\":\"cash\",\"description\":\"bayaran pak ipin [Petty Cash]\",\"created_by\":\"-\",\"source_type\":\"manual\",\"source_id\":null}', NULL, '8', 'Developer User', '36.65.220.178', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-10 18:46:18');
INSERT INTO `audit_logs` (`id`, `table_name`, `record_id`, `action`, `old_data`, `new_data`, `user_id`, `user_name`, `ip_address`, `user_agent`, `created_at`) VALUES ('2', 'cash_book', '1663', 'DELETE', '{\"id\":\"1663\",\"transaction_date\":\"2026-03-10\",\"transaction_time\":\"08:56:21\",\"division\":\"Hotel\",\"category\":\"Refund Booking\",\"transaction_type\":\"expense\",\"amount\":\"2337000.00\",\"payment_method\":\"cash\",\"description\":\"Refund pembatalan BK-20260302-2090 - Yo Suhendra (Refund Manual - Diproses oleh front desk)\",\"created_by\":\"Administrator\",\"source_type\":\"manual\",\"source_id\":null}', NULL, '8', 'Developer User', '36.65.220.178', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-10 18:47:32');
INSERT INTO `audit_logs` (`id`, `table_name`, `record_id`, `action`, `old_data`, `new_data`, `user_id`, `user_name`, `ip_address`, `user_agent`, `created_at`) VALUES ('3', 'cash_book', '1683', 'DELETE', '{\"id\":\"1683\",\"transaction_date\":\"2026-03-11\",\"transaction_time\":\"08:35:02\",\"division\":\"Hotel\",\"category\":\"Room Service Charges\",\"transaction_type\":\"income\",\"amount\":\"1799450.00\",\"payment_method\":\"ota\",\"description\":\"Pembayaran Reservasi - Laurin Laurin (Room 101) - BK-20260311-5010 [LUNAS]\",\"created_by\":\"Administrator\",\"source_type\":\"manual\",\"source_id\":null}', NULL, '27', 'Sandra Sofi Oktaviani', '36.65.220.178', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-03-11 12:54:47');
INSERT INTO `audit_logs` (`id`, `table_name`, `record_id`, `action`, `old_data`, `new_data`, `user_id`, `user_name`, `ip_address`, `user_agent`, `created_at`) VALUES ('4', 'cash_book', '1682', 'DELETE', '{\"id\":\"1682\",\"transaction_date\":\"2026-03-11\",\"transaction_time\":\"08:33:36\",\"division\":\"Hotel\",\"category\":\"Room Service Charges\",\"transaction_type\":\"income\",\"amount\":\"899725.00\",\"payment_method\":\"ota\",\"description\":\"Pembayaran Reservasi - Alvira Violita (Room 103) - BK-20260311-7724 [LUNAS]\",\"created_by\":\"Administrator\",\"source_type\":\"manual\",\"source_id\":null}', NULL, '27', 'Sandra Sofi Oktaviani', '36.65.220.178', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-03-11 12:54:51');
INSERT INTO `audit_logs` (`id`, `table_name`, `record_id`, `action`, `old_data`, `new_data`, `user_id`, `user_name`, `ip_address`, `user_agent`, `created_at`) VALUES ('5', 'cash_book', '1681', 'DELETE', '{\"id\":\"1681\",\"transaction_date\":\"2026-03-11\",\"transaction_time\":\"08:32:43\",\"division\":\"Hotel\",\"category\":\"Room Service Charges\",\"transaction_type\":\"income\",\"amount\":\"670225.00\",\"payment_method\":\"ota\",\"description\":\"Pembayaran Reservasi - Alvira Violita (Room 206) - BK-20260311-2294 [LUNAS]\",\"created_by\":\"Administrator\",\"source_type\":\"manual\",\"source_id\":null}', NULL, '27', 'Sandra Sofi Oktaviani', '36.65.220.178', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-03-11 12:55:03');
INSERT INTO `audit_logs` (`id`, `table_name`, `record_id`, `action`, `old_data`, `new_data`, `user_id`, `user_name`, `ip_address`, `user_agent`, `created_at`) VALUES ('6', 'cash_book', '1680', 'DELETE', '{\"id\":\"1680\",\"transaction_date\":\"2026-03-11\",\"transaction_time\":\"08:31:16\",\"division\":\"Hotel\",\"category\":\"Room Service Charges\",\"transaction_type\":\"income\",\"amount\":\"2699175.00\",\"payment_method\":\"ota\",\"description\":\"Pembayaran Reservasi - Calvin Calvin (Room 102) - BK-20260311-6719 [LUNAS]\",\"created_by\":\"Administrator\",\"source_type\":\"manual\",\"source_id\":null}', NULL, '27', 'Sandra Sofi Oktaviani', '36.65.220.178', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-03-11 12:55:07');
INSERT INTO `audit_logs` (`id`, `table_name`, `record_id`, `action`, `old_data`, `new_data`, `user_id`, `user_name`, `ip_address`, `user_agent`, `created_at`) VALUES ('7', 'cash_book', '1679', 'DELETE', '{\"id\":\"1679\",\"transaction_date\":\"2026-03-11\",\"transaction_time\":\"08:28:35\",\"division\":\"Hotel\",\"category\":\"Room Service Charges\",\"transaction_type\":\"income\",\"amount\":\"589475.00\",\"payment_method\":\"ota\",\"description\":\"Pembayaran Reservasi - Riyono Riyono (Room 205) - BK-20260311-9929 [LUNAS]\",\"created_by\":\"Administrator\",\"source_type\":\"manual\",\"source_id\":null}', NULL, '27', 'Sandra Sofi Oktaviani', '36.65.220.178', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-03-11 12:55:11');
INSERT INTO `audit_logs` (`id`, `table_name`, `record_id`, `action`, `old_data`, `new_data`, `user_id`, `user_name`, `ip_address`, `user_agent`, `created_at`) VALUES ('8', 'cash_book', '1678', 'DELETE', '{\"id\":\"1678\",\"transaction_date\":\"2026-03-11\",\"transaction_time\":\"08:23:23\",\"division\":\"Hotel\",\"category\":\"Room Service Charges\",\"transaction_type\":\"income\",\"amount\":\"589475.00\",\"payment_method\":\"ota\",\"description\":\"Pembayaran Reservasi - Alvintra Alvintra (Room 205) - BK-20260311-3774 [LUNAS]\",\"created_by\":\"Administrator\",\"source_type\":\"manual\",\"source_id\":null}', NULL, '27', 'Sandra Sofi Oktaviani', '36.65.220.178', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-03-11 12:55:15');
INSERT INTO `audit_logs` (`id`, `table_name`, `record_id`, `action`, `old_data`, `new_data`, `user_id`, `user_name`, `ip_address`, `user_agent`, `created_at`) VALUES ('9', 'cash_book', '1666', 'DELETE', '{\"id\":\"1666\",\"transaction_date\":\"2026-03-10\",\"transaction_time\":\"08:57:21\",\"division\":\"Hotel\",\"category\":\"Room Service Charges\",\"transaction_type\":\"income\",\"amount\":\"670225.00\",\"payment_method\":\"ota\",\"description\":\"Pembayaran Reservasi - Hari Mandala putra (Room 206) - BK-20260310-8891 [LUNAS]\",\"created_by\":\"Administrator\",\"source_type\":\"manual\",\"source_id\":null}', NULL, '27', 'Sandra Sofi Oktaviani', '36.65.220.178', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-03-11 12:55:24');
INSERT INTO `audit_logs` (`id`, `table_name`, `record_id`, `action`, `old_data`, `new_data`, `user_id`, `user_name`, `ip_address`, `user_agent`, `created_at`) VALUES ('10', 'cash_book', '1665', 'DELETE', '{\"id\":\"1665\",\"transaction_date\":\"2026-03-10\",\"transaction_time\":\"08:54:31\",\"division\":\"Hotel\",\"category\":\"Room Service Charges\",\"transaction_type\":\"income\",\"amount\":\"2045950.00\",\"payment_method\":\"ota\",\"description\":\"Pembayaran Reservasi - Dyah Ayu Dewanti (Room 106) - BK-20260310-9897 [LUNAS]\",\"created_by\":\"Administrator\",\"source_type\":\"manual\",\"source_id\":null}', NULL, '27', 'Sandra Sofi Oktaviani', '36.65.220.178', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-03-11 12:55:31');
INSERT INTO `audit_logs` (`id`, `table_name`, `record_id`, `action`, `old_data`, `new_data`, `user_id`, `user_name`, `ip_address`, `user_agent`, `created_at`) VALUES ('11', 'cash_book', '1664', 'DELETE', '{\"id\":\"1664\",\"transaction_date\":\"2026-03-10\",\"transaction_time\":\"08:54:31\",\"division\":\"Hotel\",\"category\":\"Room Service Charges\",\"transaction_type\":\"income\",\"amount\":\"2045950.00\",\"payment_method\":\"ota\",\"description\":\"Pembayaran Reservasi - Dyah Ayu Dewanti (Room 105) - BK-20260310-4095 [LUNAS]\",\"created_by\":\"Administrator\",\"source_type\":\"manual\",\"source_id\":null}', NULL, '27', 'Sandra Sofi Oktaviani', '36.65.220.178', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-03-11 12:55:36');

-- --------------------------------------------------------
-- Table structure for `bill_records`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `bill_records`;
CREATE TABLE `bill_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL,
  `bill_period` varchar(7) NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `due_date` date NOT NULL,
  `status` enum('pending','paid','overdue','cancelled') NOT NULL DEFAULT 'pending',
  `paid_date` date DEFAULT NULL,
  `paid_amount` decimal(15,2) DEFAULT NULL,
  `payment_method` enum('cash','transfer','qr','debit','other') DEFAULT NULL,
  `cashbook_id` int(11) DEFAULT NULL,
  `proof_file` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `paid_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_bill_period` (`template_id`,`bill_period`),
  KEY `idx_template` (`template_id`),
  KEY `idx_period` (`bill_period`),
  KEY `idx_status` (`status`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_cashbook` (`cashbook_id`),
  CONSTRAINT `bill_records_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `bill_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `bill_records`

INSERT INTO `bill_records` (`id`, `template_id`, `bill_period`, `amount`, `due_date`, `status`, `paid_date`, `paid_amount`, `payment_method`, `cashbook_id`, `proof_file`, `notes`, `paid_by`, `created_at`, `updated_at`) VALUES ('1', '1', '2026-02', '1250000.00', '2026-02-20', 'overdue', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-18 22:52:02', '2026-02-21 08:39:15');
INSERT INTO `bill_records` (`id`, `template_id`, `bill_period`, `amount`, `due_date`, `status`, `paid_date`, `paid_amount`, `payment_method`, `cashbook_id`, `proof_file`, `notes`, `paid_by`, `created_at`, `updated_at`) VALUES ('2', '1', '2026-03', '1250000.00', '2026-03-20', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-18 22:52:02', '2026-02-18 22:52:02');
INSERT INTO `bill_records` (`id`, `template_id`, `bill_period`, `amount`, `due_date`, `status`, `paid_date`, `paid_amount`, `payment_method`, `cashbook_id`, `proof_file`, `notes`, `paid_by`, `created_at`, `updated_at`) VALUES ('3', '2', '2026-02', '6000000.00', '2026-02-20', 'overdue', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-18 22:52:02', '2026-02-21 08:39:15');
INSERT INTO `bill_records` (`id`, `template_id`, `bill_period`, `amount`, `due_date`, `status`, `paid_date`, `paid_amount`, `payment_method`, `cashbook_id`, `proof_file`, `notes`, `paid_by`, `created_at`, `updated_at`) VALUES ('4', '2', '2026-03', '6000000.00', '2026-03-20', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-18 22:52:02', '2026-02-18 22:52:02');
INSERT INTO `bill_records` (`id`, `template_id`, `bill_period`, `amount`, `due_date`, `status`, `paid_date`, `paid_amount`, `payment_method`, `cashbook_id`, `proof_file`, `notes`, `paid_by`, `created_at`, `updated_at`) VALUES ('5', '3', '2026-02', '1450000.00', '2026-02-20', 'overdue', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-18 22:52:02', '2026-02-21 08:39:15');
INSERT INTO `bill_records` (`id`, `template_id`, `bill_period`, `amount`, `due_date`, `status`, `paid_date`, `paid_amount`, `payment_method`, `cashbook_id`, `proof_file`, `notes`, `paid_by`, `created_at`, `updated_at`) VALUES ('6', '3', '2026-03', '1450000.00', '2026-03-20', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-18 22:52:02', '2026-02-18 22:52:02');
INSERT INTO `bill_records` (`id`, `template_id`, `bill_period`, `amount`, `due_date`, `status`, `paid_date`, `paid_amount`, `payment_method`, `cashbook_id`, `proof_file`, `notes`, `paid_by`, `created_at`, `updated_at`) VALUES ('7', '4', '2026-02', '999999.00', '2026-02-20', 'overdue', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-21 08:39:15', '2026-02-21 08:39:15');
INSERT INTO `bill_records` (`id`, `template_id`, `bill_period`, `amount`, `due_date`, `status`, `paid_date`, `paid_amount`, `payment_method`, `cashbook_id`, `proof_file`, `notes`, `paid_by`, `created_at`, `updated_at`) VALUES ('8', '4', '2026-03', '999999.00', '2026-03-20', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-21 08:39:15', '2026-02-21 08:39:15');
INSERT INTO `bill_records` (`id`, `template_id`, `bill_period`, `amount`, `due_date`, `status`, `paid_date`, `paid_amount`, `payment_method`, `cashbook_id`, `proof_file`, `notes`, `paid_by`, `created_at`, `updated_at`) VALUES ('9', '1', '2026-04', '1250000.00', '2026-04-20', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-01 09:53:51', '2026-03-01 09:53:51');
INSERT INTO `bill_records` (`id`, `template_id`, `bill_period`, `amount`, `due_date`, `status`, `paid_date`, `paid_amount`, `payment_method`, `cashbook_id`, `proof_file`, `notes`, `paid_by`, `created_at`, `updated_at`) VALUES ('10', '2', '2026-04', '6000000.00', '2026-04-20', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-01 09:53:51', '2026-03-01 09:53:51');
INSERT INTO `bill_records` (`id`, `template_id`, `bill_period`, `amount`, `due_date`, `status`, `paid_date`, `paid_amount`, `payment_method`, `cashbook_id`, `proof_file`, `notes`, `paid_by`, `created_at`, `updated_at`) VALUES ('11', '3', '2026-04', '1450000.00', '2026-04-20', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-01 09:53:51', '2026-03-01 09:53:51');
INSERT INTO `bill_records` (`id`, `template_id`, `bill_period`, `amount`, `due_date`, `status`, `paid_date`, `paid_amount`, `payment_method`, `cashbook_id`, `proof_file`, `notes`, `paid_by`, `created_at`, `updated_at`) VALUES ('12', '4', '2026-04', '999999.00', '2026-04-20', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-01 09:53:51', '2026-03-01 09:53:51');

-- --------------------------------------------------------
-- Table structure for `bill_templates`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `bill_templates`;
CREATE TABLE `bill_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bill_name` varchar(150) NOT NULL,
  `bill_category` enum('electricity','tax','wifi','vehicle','po','receivable','other') NOT NULL DEFAULT 'other',
  `vendor_name` varchar(150) DEFAULT NULL,
  `vendor_contact` varchar(100) DEFAULT NULL,
  `account_number` varchar(100) DEFAULT NULL,
  `default_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `is_fixed_amount` tinyint(1) NOT NULL DEFAULT 0,
  `recurrence` enum('monthly','quarterly','yearly','one-time') NOT NULL DEFAULT 'monthly',
  `due_day` int(2) NOT NULL DEFAULT 1,
  `reminder_days` int(3) NOT NULL DEFAULT 3,
  `division_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `payment_method` enum('cash','transfer','qr','debit','other') DEFAULT 'transfer',
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_category` (`bill_category`),
  KEY `idx_active` (`is_active`),
  KEY `idx_due_day` (`due_day`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `bill_templates`

INSERT INTO `bill_templates` (`id`, `bill_name`, `bill_category`, `vendor_name`, `vendor_contact`, `account_number`, `default_amount`, `is_fixed_amount`, `recurrence`, `due_day`, `reminder_days`, `division_id`, `category_id`, `payment_method`, `notes`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES ('1', 'WIFI', 'wifi', 'TELCOM', NULL, NULL, '1250000.00', '1', 'monthly', '20', '3', '5', '21', 'transfer', NULL, '1', '8', '2026-02-18 22:48:21', '2026-02-18 22:48:21');
INSERT INTO `bill_templates` (`id`, `bill_name`, `bill_category`, `vendor_name`, `vendor_contact`, `account_number`, `default_amount`, `is_fixed_amount`, `recurrence`, `due_day`, `reminder_days`, `division_id`, `category_id`, `payment_method`, `notes`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES ('2', 'PLN Listrik', 'electricity', 'PLN', NULL, NULL, '6000000.00', '0', 'monthly', '20', '4', '5', '36', 'transfer', NULL, '1', '8', '2026-02-18 22:49:06', '2026-02-18 22:49:06');
INSERT INTO `bill_templates` (`id`, `bill_name`, `bill_category`, `vendor_name`, `vendor_contact`, `account_number`, `default_amount`, `is_fixed_amount`, `recurrence`, `due_day`, `reminder_days`, `division_id`, `category_id`, `payment_method`, `notes`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES ('3', 'MOTOR', 'vehicle', NULL, NULL, NULL, '1450000.00', '1', 'monthly', '20', '4', '7', NULL, 'transfer', NULL, '1', '8', '2026-02-18 22:51:40', '2026-02-18 22:51:40');
INSERT INTO `bill_templates` (`id`, `bill_name`, `bill_category`, `vendor_name`, `vendor_contact`, `account_number`, `default_amount`, `is_fixed_amount`, `recurrence`, `due_day`, `reminder_days`, `division_id`, `category_id`, `payment_method`, `notes`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES ('4', 'Cloudbed', 'other', 'Cloudbed', NULL, NULL, '999999.00', '1', 'monthly', '20', '3', '5', NULL, 'transfer', NULL, '1', '11', '2026-02-19 15:20:08', '2026-02-19 15:20:08');

-- --------------------------------------------------------
-- Table structure for `booking_payments`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `booking_payments`;
CREATE TABLE `booking_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `payment_date` datetime NOT NULL DEFAULT current_timestamp(),
  `amount` decimal(12,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT 'cash',
  `reference_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `synced_to_cashbook` tinyint(1) NOT NULL DEFAULT 0,
  `cashbook_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `processed_by` (`processed_by`),
  KEY `idx_booking` (`booking_id`),
  KEY `idx_date` (`payment_date`),
  CONSTRAINT `booking_payments_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `booking_payments_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=123 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `booking_payments`

INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('60', '78', '2026-03-02 09:31:08', '1558000.00', 'ota', NULL, NULL, NULL, '2026-03-02 09:31:08', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('61', '79', '2026-03-02 09:33:21', '760000.00', 'ota', NULL, NULL, NULL, '2026-03-02 09:33:21', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('62', '80', '2026-03-02 09:33:21', '760000.00', 'ota', NULL, NULL, NULL, '2026-03-02 09:33:21', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('67', '85', '2026-03-02 09:43:21', '2693333.33', 'transfer', NULL, NULL, NULL, '2026-03-02 09:43:21', '1', '1611', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('68', '86', '2026-03-02 09:43:21', '1693333.33', 'transfer', NULL, NULL, NULL, '2026-03-02 09:43:21', '1', '1612', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('69', '87', '2026-03-02 09:43:21', '1693333.33', 'transfer', NULL, NULL, NULL, '2026-03-02 09:43:21', '1', '1613', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('70', '88', '2026-03-02 09:43:22', '1693333.33', 'transfer', NULL, NULL, NULL, '2026-03-02 09:43:22', '1', '1614', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('71', '89', '2026-03-02 09:43:22', '1693333.33', 'transfer', NULL, NULL, NULL, '2026-03-02 09:43:22', '1', '1615', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('72', '90', '2026-03-02 09:43:22', '1693333.33', 'transfer', NULL, NULL, NULL, '2026-03-02 09:43:22', '1', '1616', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('73', '92', '2026-03-02 09:47:06', '2433000.00', 'transfer', NULL, NULL, NULL, '2026-03-02 09:47:06', '1', '1617', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('74', '93', '2026-03-02 09:47:06', '2433000.00', 'transfer', NULL, NULL, NULL, '2026-03-02 09:47:06', '1', '1618', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('75', '94', '2026-03-02 09:49:21', '1558000.00', 'ota', NULL, NULL, NULL, '2026-03-02 09:49:21', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('76', '97', '2026-03-02 09:50:41', '2337000.00', 'ota', NULL, NULL, NULL, '2026-03-02 09:50:41', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('77', '98', '2026-03-02 09:52:57', '760000.00', 'ota', NULL, NULL, NULL, '2026-03-02 09:52:57', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('78', '99', '2026-03-02 09:53:55', '760000.00', 'ota', NULL, NULL, NULL, '2026-03-02 09:53:55', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('79', '100', '2026-03-02 09:53:56', '760000.00', 'ota', NULL, NULL, NULL, '2026-03-02 09:53:56', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('80', '101', '2026-03-02 09:54:59', '760000.00', 'ota', NULL, NULL, NULL, '2026-03-02 09:54:59', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('81', '102', '2026-03-02 09:54:59', '760000.00', 'ota', NULL, NULL, NULL, '2026-03-02 09:54:59', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('82', '103', '2026-03-02 10:26:51', '3480000.00', 'ota', NULL, NULL, NULL, '2026-03-02 10:26:51', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('83', '104', '2026-03-02 10:27:59', '1520000.00', 'ota', NULL, NULL, NULL, '2026-03-02 10:27:59', '1', '1386', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('84', '105', '2026-03-02 10:39:58', '760000.00', 'ota', NULL, NULL, NULL, '2026-03-02 10:39:58', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('85', '106', '2026-03-02 11:41:02', '1558000.00', 'ota', NULL, NULL, NULL, '2026-03-02 11:41:02', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('86', '107', '2026-03-02 11:43:08', '1520000.00', 'ota', NULL, NULL, NULL, '2026-03-02 11:43:08', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('87', '108', '2026-03-02 11:43:08', '1520000.00', 'ota', NULL, NULL, NULL, '2026-03-02 11:43:08', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('88', '109', '2026-03-02 13:22:13', '2337000.00', 'ota', NULL, NULL, NULL, '2026-03-02 13:22:13', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('89', '110', '2026-03-02 13:23:33', '760000.00', 'ota', NULL, NULL, NULL, '2026-03-02 13:23:33', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('90', '111', '2026-03-02 13:25:07', '3769500.00', 'ota', NULL, NULL, NULL, '2026-03-02 13:25:07', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('91', '112', '2026-03-02 13:25:07', '2269500.00', 'ota', NULL, NULL, NULL, '2026-03-02 13:25:07', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('92', '113', '2026-03-02 13:25:07', '2269500.00', 'ota', NULL, NULL, NULL, '2026-03-02 13:25:07', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('93', '114', '2026-03-02 13:25:07', '2269500.00', 'ota', NULL, NULL, NULL, '2026-03-02 13:25:07', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('94', '115', '2026-03-02 13:26:08', '760000.00', 'ota', NULL, NULL, NULL, '2026-03-02 13:26:08', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('95', '116', '2026-03-02 13:26:08', '760000.00', 'ota', NULL, NULL, NULL, '2026-03-02 13:26:08', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('96', '117', '2026-03-02 13:27:17', '1520000.00', 'ota', NULL, NULL, NULL, '2026-03-02 13:27:17', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('97', '118', '2026-03-03 09:40:50', '5800000.00', 'ota', NULL, NULL, NULL, '2026-03-03 09:40:50', '0', NULL, NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('98', '119', '2026-03-04 09:26:50', '857500.00', 'transfer', NULL, NULL, NULL, '2026-03-04 09:26:50', '1', '1623', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('99', '120', '2026-03-04 09:29:17', '1501000.00', 'ota', NULL, NULL, NULL, '2026-03-04 09:29:17', '1', '1624', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('108', '122', '2026-03-06 09:27:47', '1577000.00', 'ota', NULL, NULL, NULL, '2026-03-06 09:27:47', '1', '1649', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('109', '123', '2026-03-06 09:28:58', '1577000.00', 'ota', NULL, NULL, NULL, '2026-03-06 09:28:58', '1', '1650', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('110', '124', '2026-03-06 09:37:35', '2407000.00', 'ota', NULL, NULL, NULL, '2026-03-06 09:37:35', '1', '1652', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('111', '125', '2026-03-06 09:37:35', '2407000.00', 'ota', NULL, NULL, NULL, '2026-03-06 09:37:35', '1', '1653', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('113', '127', '2026-03-06 10:16:50', '1174500.00', 'card', NULL, 'Dibayar saat check-in', '1', '2026-03-06 10:16:50', '1', '1656', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('114', '128', '2026-03-10 08:54:31', '2407000.00', 'ota', NULL, NULL, NULL, '2026-03-10 08:54:31', '1', '1664', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('115', '129', '2026-03-10 08:54:31', '2407000.00', 'ota', NULL, NULL, NULL, '2026-03-10 08:54:31', '1', '1665', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('116', '130', '2026-03-10 08:57:21', '788500.00', 'ota', NULL, NULL, NULL, '2026-03-10 08:57:21', '1', '1666', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('117', '131', '2026-03-11 08:23:23', '693500.00', 'ota', NULL, NULL, NULL, '2026-03-11 08:23:23', '1', '1678', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('118', '132', '2026-03-11 08:28:35', '693500.00', 'ota', NULL, NULL, NULL, '2026-03-11 08:28:35', '1', '1679', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('119', '133', '2026-03-11 08:31:16', '3175500.00', 'ota', NULL, NULL, NULL, '2026-03-11 08:31:16', '1', '1680', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('120', '134', '2026-03-11 08:32:43', '788500.00', 'ota', NULL, NULL, NULL, '2026-03-11 08:32:43', '1', '1681', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('121', '135', '2026-03-11 08:33:36', '1058500.00', 'ota', NULL, NULL, NULL, '2026-03-11 08:33:36', '1', '1682', NULL);
INSERT INTO `booking_payments` (`id`, `booking_id`, `payment_date`, `amount`, `payment_method`, `reference_number`, `notes`, `processed_by`, `created_at`, `synced_to_cashbook`, `cashbook_id`, `created_by`) VALUES ('122', '136', '2026-03-11 08:35:02', '2117000.00', 'ota', NULL, NULL, NULL, '2026-03-11 08:35:02', '1', '1683', NULL);

-- --------------------------------------------------------
-- Table structure for `bookings`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `bookings`;
CREATE TABLE `bookings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_code` varchar(20) NOT NULL,
  `guest_id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `check_in_date` date NOT NULL,
  `check_out_date` date NOT NULL,
  `actual_checkin_time` datetime DEFAULT NULL COMMENT 'Waktu aktual check-in',
  `actual_checkout_time` datetime DEFAULT NULL COMMENT 'Waktu aktual check-out',
  `checked_in_by` int(11) DEFAULT NULL COMMENT 'User ID yang melakukan check-in',
  `checked_out_by` int(11) DEFAULT NULL COMMENT 'User ID yang melakukan check-out',
  `actual_check_in` datetime DEFAULT NULL,
  `actual_check_out` datetime DEFAULT NULL,
  `adults` int(11) NOT NULL DEFAULT 1,
  `children` int(11) NOT NULL DEFAULT 0,
  `room_price` decimal(12,2) NOT NULL,
  `total_nights` int(11) NOT NULL,
  `total_price` decimal(12,2) NOT NULL,
  `discount` decimal(12,2) DEFAULT 0.00,
  `final_price` decimal(12,2) NOT NULL,
  `status` enum('pending','confirmed','checked_in','checked_out','cancelled') DEFAULT 'pending',
  `payment_status` enum('unpaid','partial','paid') DEFAULT 'unpaid',
  `paid_amount` decimal(12,2) DEFAULT 0.00,
  `booking_source` enum('walk_in','phone','online','ota') DEFAULT 'walk_in',
  `special_request` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `guest_count` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `booking_code` (`booking_code`),
  KEY `guest_id` (`guest_id`),
  KEY `room_id` (`room_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_dates` (`check_in_date`,`check_out_date`),
  KEY `idx_status` (`status`),
  KEY `idx_booking_code` (`booking_code`),
  CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`guest_id`) REFERENCES `guests` (`id`),
  CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  CONSTRAINT `bookings_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=137 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `bookings`

INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('78', 'BK-20260302-8904', '64', '45', '2026-03-16', '2026-03-18', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '950000.00', '2', '1900000.00', '0.00', '1558000.00', 'confirmed', 'paid', '1558000.00', 'ota', '', NULL, NULL, '2026-03-02 09:31:08', '2026-03-02 09:31:08', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('79', 'BK-20260302-0185', '65', '42', '2026-03-17', '2026-03-18', NULL, NULL, NULL, NULL, NULL, NULL, '4', '0', '950000.00', '1', '950000.00', '0.00', '760000.00', 'confirmed', 'paid', '760000.00', 'ota', '', NULL, NULL, '2026-03-02 09:33:21', '2026-03-02 09:33:21', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('80', 'BK-20260302-4575', '66', '46', '2026-03-17', '2026-03-18', NULL, NULL, NULL, NULL, NULL, NULL, '4', '0', '950000.00', '1', '950000.00', '0.00', '760000.00', 'confirmed', 'paid', '760000.00', 'ota', '', NULL, NULL, '2026-03-02 09:33:21', '2026-03-02 09:33:21', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('85', 'BK-20260302-3958', '71', '48', '2026-03-18', '2026-03-20', NULL, NULL, NULL, NULL, NULL, NULL, '12', '0', '1450000.00', '2', '2900000.00', '206666.67', '2693333.33', 'confirmed', 'paid', '2693333.33', 'walk_in', '', NULL, NULL, '2026-03-02 09:43:21', '2026-03-02 09:43:21', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('86', 'BK-20260302-6612', '72', '45', '2026-03-18', '2026-03-20', NULL, NULL, NULL, NULL, NULL, NULL, '12', '0', '950000.00', '2', '1900000.00', '206666.67', '1693333.33', 'confirmed', 'paid', '1693333.33', 'walk_in', '', NULL, NULL, '2026-03-02 09:43:21', '2026-03-02 09:43:21', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('87', 'BK-20260302-6929', '73', '42', '2026-03-18', '2026-03-20', NULL, NULL, NULL, NULL, NULL, NULL, '12', '0', '950000.00', '2', '1900000.00', '206666.67', '1693333.33', 'confirmed', 'paid', '1693333.33', 'walk_in', '', NULL, NULL, '2026-03-02 09:43:21', '2026-03-02 09:43:21', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('88', 'BK-20260302-9355', '74', '40', '2026-03-18', '2026-03-20', NULL, NULL, NULL, NULL, NULL, NULL, '12', '0', '950000.00', '2', '1900000.00', '206666.67', '1693333.33', 'confirmed', 'paid', '1693333.33', 'walk_in', '', NULL, NULL, '2026-03-02 09:43:22', '2026-03-02 09:43:22', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('89', 'BK-20260302-2257', '75', '41', '2026-03-18', '2026-03-20', NULL, NULL, NULL, NULL, NULL, NULL, '12', '0', '950000.00', '2', '1900000.00', '206666.67', '1693333.33', 'confirmed', 'paid', '1693333.33', 'walk_in', '', NULL, NULL, '2026-03-02 09:43:22', '2026-03-02 09:43:22', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('90', 'BK-20260302-3116', '76', '47', '2026-03-18', '2026-03-20', NULL, NULL, NULL, NULL, NULL, NULL, '12', '0', '950000.00', '2', '1900000.00', '206666.67', '1693333.33', 'confirmed', 'paid', '1693333.33', 'walk_in', '', NULL, NULL, '2026-03-02 09:43:22', '2026-03-02 09:43:22', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('92', 'BK-20260302-3893', '77', '43', '2026-03-18', '2026-03-20', NULL, NULL, NULL, NULL, NULL, NULL, '4', '0', '1450000.00', '2', '2900000.00', '467000.00', '2433000.00', 'confirmed', 'paid', '2433000.00', 'walk_in', '', NULL, NULL, '2026-03-02 09:47:06', '2026-03-02 09:47:06', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('93', 'BK-20260302-8648', '78', '44', '2026-03-18', '2026-03-20', NULL, NULL, NULL, NULL, NULL, NULL, '4', '0', '1450000.00', '2', '2900000.00', '467000.00', '2433000.00', 'confirmed', 'paid', '2433000.00', 'walk_in', '', NULL, NULL, '2026-03-02 09:47:06', '2026-03-02 09:47:06', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('94', 'BK-20260302-3540', '79', '45', '2026-03-20', '2026-03-22', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '950000.00', '2', '1900000.00', '0.00', '1558000.00', 'cancelled', 'paid', '1558000.00', 'ota', '\n[CANCELLED] H+7 (> 7 hari sebelum check-in): Refund 100% - Refund: Rp 1558000', NULL, NULL, '2026-03-02 09:49:21', '2026-03-06 09:21:32', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('97', 'BK-20260302-3930', '80', '40', '2026-03-20', '2026-03-23', NULL, NULL, NULL, NULL, NULL, NULL, '3', '0', '950000.00', '3', '2850000.00', '0.00', '2337000.00', 'confirmed', 'paid', '2337000.00', 'ota', '', NULL, NULL, '2026-03-02 09:50:41', '2026-03-02 09:50:41', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('98', 'BK-20260302-1773', '81', '46', '2026-03-18', '2026-03-19', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '950000.00', '1', '950000.00', '0.00', '760000.00', 'confirmed', 'paid', '760000.00', 'ota', '', NULL, NULL, '2026-03-02 09:52:57', '2026-03-02 09:52:57', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('99', 'BK-20260302-6280', '82', '42', '2026-03-20', '2026-03-21', NULL, NULL, NULL, NULL, NULL, NULL, '4', '0', '950000.00', '1', '950000.00', '0.00', '760000.00', 'confirmed', 'paid', '760000.00', 'ota', '', NULL, NULL, '2026-03-02 09:53:55', '2026-03-02 09:53:55', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('100', 'BK-20260302-1293', '83', '46', '2026-03-20', '2026-03-21', NULL, NULL, NULL, NULL, NULL, NULL, '4', '0', '950000.00', '1', '950000.00', '0.00', '760000.00', 'confirmed', 'paid', '760000.00', 'ota', '', NULL, NULL, '2026-03-02 09:53:56', '2026-03-02 09:53:56', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('101', 'BK-20260302-1482', '84', '41', '2026-03-20', '2026-03-21', NULL, NULL, NULL, NULL, NULL, NULL, '4', '0', '950000.00', '1', '950000.00', '0.00', '760000.00', 'cancelled', 'paid', '760000.00', 'ota', '\n[CANCELLED] H+7 (> 7 hari sebelum check-in): Refund 100% - Refund: Rp 760000', NULL, NULL, '2026-03-02 09:54:59', '2026-03-06 09:26:00', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('102', 'BK-20260302-7955', '85', '47', '2026-03-20', '2026-03-21', NULL, NULL, NULL, NULL, NULL, NULL, '4', '0', '950000.00', '1', '950000.00', '0.00', '760000.00', 'cancelled', 'paid', '760000.00', 'ota', '\n[CANCELLED] H+7 (> 7 hari sebelum check-in): Refund 100% - Refund: Rp 760000', NULL, NULL, '2026-03-02 09:54:59', '2026-03-06 09:26:15', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('103', 'BK-20260302-9166', '86', '38', '2026-03-19', '2026-03-22', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '1450000.00', '3', '4350000.00', '0.00', '3480000.00', 'confirmed', 'paid', '3480000.00', 'ota', '', NULL, NULL, '2026-03-02 10:26:51', '2026-03-02 10:26:51', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('104', 'BK-20260302-7338', '87', '45', '2026-03-02', '2026-03-04', '2026-03-02 11:05:28', '2026-03-04 12:39:44', '1', '27', NULL, NULL, '2', '0', '692000.00', '2', '1384000.00', '0.00', '1384000.00', 'checked_out', 'paid', '1520000.00', 'ota', '', NULL, NULL, '2026-03-02 10:27:59', '2026-03-05 20:29:14', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('105', 'BK-20260302-6178', '88', '42', '2026-03-21', '2026-03-22', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '950000.00', '1', '950000.00', '0.00', '760000.00', 'confirmed', 'paid', '760000.00', 'ota', '', NULL, NULL, '2026-03-02 10:39:58', '2026-03-02 10:39:58', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('106', 'BK-20260302-6016', '89', '46', '2026-03-21', '2026-03-23', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '950000.00', '2', '1900000.00', '0.00', '1558000.00', 'cancelled', 'paid', '1558000.00', 'ota', '\n[CANCELLED] H+7 (> 7 hari sebelum check-in): Refund 100% - Refund: Rp 1558000', NULL, NULL, '2026-03-02 11:41:02', '2026-03-06 09:24:32', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('107', 'BK-20260302-7001', '90', '45', '2026-03-22', '2026-03-24', NULL, NULL, NULL, NULL, NULL, NULL, '4', '0', '950000.00', '2', '1900000.00', '0.00', '1520000.00', 'confirmed', 'paid', '1520000.00', 'ota', '', NULL, NULL, '2026-03-02 11:43:08', '2026-03-02 11:43:08', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('108', 'BK-20260302-5617', '91', '42', '2026-03-22', '2026-03-24', NULL, NULL, NULL, NULL, NULL, NULL, '4', '0', '950000.00', '2', '1900000.00', '0.00', '1520000.00', 'confirmed', 'paid', '1520000.00', 'ota', '', NULL, NULL, '2026-03-02 11:43:08', '2026-03-02 11:43:08', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('109', 'BK-20260302-2090', '92', '47', '2026-03-22', '2026-03-25', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '950000.00', '3', '2850000.00', '0.00', '2337000.00', 'cancelled', 'paid', '2337000.00', 'ota', '\n[CANCELLED] Refund Manual - Diproses oleh front desk - Refund: Rp 2337000', NULL, NULL, '2026-03-02 13:22:13', '2026-03-10 08:56:21', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('110', 'BK-20260302-1951', '93', '46', '2026-03-23', '2026-03-24', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '950000.00', '1', '950000.00', '0.00', '760000.00', 'confirmed', 'paid', '760000.00', 'ota', '', NULL, NULL, '2026-03-02 13:23:33', '2026-03-02 13:23:33', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('111', 'BK-20260302-2034', '94', '48', '2026-03-24', '2026-03-27', NULL, NULL, NULL, NULL, NULL, NULL, '8', '0', '1450000.00', '3', '4350000.00', '0.00', '3769500.00', 'confirmed', 'paid', '3769500.00', 'ota', '', NULL, NULL, '2026-03-02 13:25:07', '2026-03-02 13:25:07', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('112', 'BK-20260302-5217', '95', '45', '2026-03-24', '2026-03-27', NULL, NULL, NULL, NULL, NULL, NULL, '8', '0', '950000.00', '3', '2850000.00', '0.00', '2269500.00', 'confirmed', 'paid', '2269500.00', 'ota', '', NULL, NULL, '2026-03-02 13:25:07', '2026-03-02 13:25:07', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('113', 'BK-20260302-3155', '96', '40', '2026-03-24', '2026-03-27', NULL, NULL, NULL, NULL, NULL, NULL, '8', '0', '950000.00', '3', '2850000.00', '0.00', '2269500.00', 'confirmed', 'paid', '2269500.00', 'ota', '', NULL, NULL, '2026-03-02 13:25:07', '2026-03-02 13:25:07', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('114', 'BK-20260302-6278', '97', '41', '2026-03-24', '2026-03-27', NULL, NULL, NULL, NULL, NULL, NULL, '8', '0', '950000.00', '3', '2850000.00', '0.00', '2269500.00', 'confirmed', 'paid', '2269500.00', 'ota', '', NULL, NULL, '2026-03-02 13:25:07', '2026-03-02 13:25:07', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('115', 'BK-20260302-8906', '98', '42', '2026-03-24', '2026-03-25', NULL, NULL, NULL, NULL, NULL, NULL, '4', '0', '950000.00', '1', '950000.00', '0.00', '760000.00', 'confirmed', 'paid', '760000.00', 'ota', '', NULL, NULL, '2026-03-02 13:26:08', '2026-03-02 13:26:08', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('116', 'BK-20260302-5866', '99', '46', '2026-03-24', '2026-03-25', NULL, NULL, NULL, NULL, NULL, NULL, '4', '0', '950000.00', '1', '950000.00', '0.00', '760000.00', 'confirmed', 'paid', '760000.00', 'ota', '', NULL, NULL, '2026-03-02 13:26:08', '2026-03-02 13:26:08', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('117', 'BK-20260302-5599', '100', '42', '2026-03-25', '2026-03-27', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '950000.00', '2', '1900000.00', '0.00', '1520000.00', 'confirmed', 'paid', '1520000.00', 'ota', '', NULL, NULL, '2026-03-02 13:27:17', '2026-03-02 13:27:17', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('118', 'BK-20260303-4953', '101', '36', '2026-03-20', '2026-03-25', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '1450000.00', '5', '7250000.00', '0.00', '5800000.00', 'confirmed', 'paid', '5800000.00', 'ota', '', NULL, NULL, '2026-03-03 09:40:50', '2026-03-03 09:40:50', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('119', 'BK-20260304-7277', '102', '41', '2026-03-22', '2026-03-24', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '950000.00', '2', '1900000.00', '190000.00', '1710000.00', 'confirmed', 'partial', '857500.00', 'walk_in', '', NULL, NULL, '2026-03-04 09:26:50', '2026-03-05 21:10:54', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('120', 'BK-20260304-8573', '103', '46', '2026-03-26', '2026-03-28', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '950000.00', '2', '1900000.00', '0.00', '1501000.00', 'confirmed', 'paid', '1501000.00', 'ota', '', NULL, NULL, '2026-03-04 09:29:17', '2026-03-04 09:29:17', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('121', 'BK-20260304-2485', '104', '45', '2026-03-04', '2026-03-06', '2026-03-04 12:39:53', '2026-03-06 09:19:55', '1', '27', NULL, NULL, '2', '0', '950000.00', '2', '1900000.00', '500000.00', '1400000.00', 'checked_out', 'paid', '1400000.00', 'walk_in', '', NULL, NULL, '2026-03-04 12:39:37', '2026-03-06 09:19:55', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('122', 'BK-20260306-5007', '105', '41', '2026-03-20', '2026-03-22', NULL, NULL, NULL, NULL, NULL, NULL, '1', '0', '950000.00', '2', '1900000.00', '0.00', '1577000.00', 'confirmed', 'paid', '1577000.00', 'ota', '', NULL, NULL, '2026-03-06 09:27:46', '2026-03-06 09:27:46', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('123', 'BK-20260306-4816', '106', '47', '2026-03-20', '2026-03-22', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '950000.00', '2', '1900000.00', '0.00', '1577000.00', 'confirmed', 'paid', '1577000.00', 'ota', '', NULL, NULL, '2026-03-06 09:28:58', '2026-03-06 09:28:58', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('124', 'BK-20260306-4069', '107', '37', '2026-03-24', '2026-03-26', NULL, NULL, NULL, NULL, NULL, NULL, '4', '0', '1450000.00', '2', '2900000.00', '0.00', '2407000.00', 'confirmed', 'paid', '2407000.00', 'ota', '', NULL, NULL, '2026-03-06 09:37:35', '2026-03-06 09:37:35', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('125', 'BK-20260306-7266', '108', '38', '2026-03-24', '2026-03-26', NULL, NULL, NULL, NULL, NULL, NULL, '4', '0', '1450000.00', '2', '2900000.00', '0.00', '2407000.00', 'confirmed', 'paid', '2407000.00', 'ota', '', NULL, NULL, '2026-03-06 09:37:35', '2026-03-06 09:37:35', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('127', 'BK-20260306-6485', '110', '43', '2026-03-07', '2026-03-08', '2026-03-06 10:16:50', '2026-03-06 10:17:52', '1', '8', NULL, NULL, '1', '0', '1450000.00', '1', '1450000.00', '0.00', '1174500.00', 'checked_out', 'paid', '1174500.00', 'ota', '', NULL, NULL, '2026-03-06 10:15:58', '2026-03-06 10:17:52', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('128', 'BK-20260310-4095', '111', '43', '2026-03-24', '2026-03-26', NULL, NULL, NULL, NULL, NULL, NULL, '4', '0', '1450000.00', '2', '2900000.00', '0.00', '2407000.00', 'confirmed', 'paid', '2407000.00', '', '', NULL, NULL, '2026-03-10 08:54:31', '2026-03-10 08:54:31', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('129', 'BK-20260310-9897', '112', '44', '2026-03-24', '2026-03-26', NULL, NULL, NULL, NULL, NULL, NULL, '4', '0', '1450000.00', '2', '2900000.00', '0.00', '2407000.00', 'confirmed', 'paid', '2407000.00', '', '', NULL, NULL, '2026-03-10 08:54:31', '2026-03-10 08:54:31', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('130', 'BK-20260310-8891', '113', '47', '2026-03-23', '2026-03-24', NULL, NULL, NULL, NULL, NULL, NULL, '1', '0', '950000.00', '1', '950000.00', '0.00', '788500.00', 'confirmed', 'paid', '788500.00', '', '', NULL, NULL, '2026-03-10 08:57:21', '2026-03-10 08:57:21', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('131', 'BK-20260311-3774', '114', '46', '2026-03-22', '2026-03-23', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '950000.00', '1', '950000.00', '0.00', '693500.00', 'confirmed', 'paid', '693500.00', '', '', NULL, NULL, '2026-03-11 08:23:23', '2026-03-11 08:23:23', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('132', 'BK-20260311-9929', '115', '46', '2026-03-25', '2026-03-26', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '950000.00', '1', '950000.00', '0.00', '693500.00', 'confirmed', 'paid', '693500.00', '', '', NULL, NULL, '2026-03-11 08:28:35', '2026-03-11 08:28:35', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('133', 'BK-20260311-6719', '116', '36', '2026-03-25', '2026-03-28', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '1450000.00', '3', '4350000.00', '0.00', '3175500.00', 'confirmed', 'paid', '3175500.00', '', '', NULL, NULL, '2026-03-11 08:31:16', '2026-03-11 08:31:16', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('134', 'BK-20260311-2294', '117', '47', '2026-03-24', '2026-03-25', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '950000.00', '1', '950000.00', '0.00', '788500.00', 'confirmed', 'paid', '788500.00', '', '', NULL, NULL, '2026-03-11 08:32:43', '2026-03-11 08:32:43', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('135', 'BK-20260311-7724', '118', '37', '2026-03-23', '2026-03-24', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '1450000.00', '1', '1450000.00', '0.00', '1058500.00', 'confirmed', 'paid', '1058500.00', '', '', NULL, NULL, '2026-03-11 08:33:36', '2026-03-11 08:33:36', '1');
INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `room_id`, `check_in_date`, `check_out_date`, `actual_checkin_time`, `actual_checkout_time`, `checked_in_by`, `checked_out_by`, `actual_check_in`, `actual_check_out`, `adults`, `children`, `room_price`, `total_nights`, `total_price`, `discount`, `final_price`, `status`, `payment_status`, `paid_amount`, `booking_source`, `special_request`, `notes`, `created_by`, `created_at`, `updated_at`, `guest_count`) VALUES ('136', 'BK-20260311-5010', '119', '48', '2026-03-22', '2026-03-24', NULL, NULL, NULL, NULL, NULL, NULL, '2', '0', '1450000.00', '2', '2900000.00', '0.00', '2117000.00', 'confirmed', 'paid', '2117000.00', '', '', NULL, NULL, '2026-03-11 08:35:02', '2026-03-11 08:35:02', '1');

-- --------------------------------------------------------
-- Table structure for `breakfast_log`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `breakfast_log`;
CREATE TABLE `breakfast_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `guest_id` int(11) NOT NULL,
  `menu_id` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `date` date NOT NULL,
  `status` enum('taken','not_taken','skipped') DEFAULT 'taken',
  `marked_by` int(11) NOT NULL,
  `marked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_breakfast` (`booking_id`,`date`),
  KEY `guest_id` (`guest_id`),
  KEY `marked_by` (`marked_by`),
  CONSTRAINT `breakfast_log_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`),
  CONSTRAINT `breakfast_log_ibfk_2` FOREIGN KEY (`guest_id`) REFERENCES `guests` (`id`),
  CONSTRAINT `breakfast_log_ibfk_3` FOREIGN KEY (`marked_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `breakfast_log`

INSERT INTO `breakfast_log` (`id`, `booking_id`, `guest_id`, `menu_id`, `quantity`, `date`, `status`, `marked_by`, `marked_at`, `notes`) VALUES ('1', '3', '1', NULL, '1', '2026-01-31', NULL, '1', '2026-01-31 11:12:15', '');

-- --------------------------------------------------------
-- Table structure for `breakfast_menus`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `breakfast_menus`;
CREATE TABLE `breakfast_menus` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `menu_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `category` enum('western','indonesian','japanese','asian','drinks','beverages','extras') NOT NULL,
  `price` decimal(10,2) DEFAULT 0.00,
  `is_free` tinyint(1) DEFAULT 1 COMMENT 'TRUE = Free breakfast, FALSE = Extra/Paid',
  `is_available` tinyint(1) DEFAULT 1,
  `image_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category`),
  KEY `idx_available` (`is_available`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `breakfast_menus`

INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('5', 'orange Juice', '', 'drinks', '0.00', '1', '1', NULL, '2026-01-31 12:28:30', '2026-01-31 12:28:30');
INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('6', 'Standart Breakfast', '', 'western', '0.00', '1', '1', NULL, '2026-02-08 11:33:59', '2026-02-08 11:33:59');
INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('9', 'extra breakfast', '', 'extras', '65000.00', '0', '1', NULL, '2026-02-08 11:49:57', '2026-02-08 11:49:57');
INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('10', 'Indonesian fried rice', '', 'indonesian', '0.00', '1', '1', NULL, '2026-02-14 09:19:17', '2026-02-14 09:19:17');
INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('11', 'Fried noodle', '', 'indonesian', '0.00', '1', '1', NULL, '2026-02-14 09:19:30', '2026-02-14 09:19:30');
INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('12', 'Waffle', '', 'western', '0.00', '1', '1', NULL, '2026-02-14 09:19:50', '2026-02-14 09:19:50');
INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('13', 'Pancake', '', 'western', '0.00', '1', '1', NULL, '2026-02-14 09:20:00', '2026-02-14 09:20:00');
INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('14', 'Granola', '', 'western', '0.00', '1', '1', NULL, '2026-02-14 09:20:13', '2026-02-14 09:20:13');
INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('15', 'Standard breakfast', '', 'western', '0.00', '1', '1', NULL, '2026-02-14 09:20:37', '2026-02-14 09:20:37');
INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('16', 'Standard Royal', '', 'western', '60000.00', '0', '1', NULL, '2026-02-14 09:21:15', '2026-02-14 09:21:15');
INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('17', 'cappuccino', '', 'drinks', '0.00', '1', '1', NULL, '2026-02-14 09:21:47', '2026-02-14 09:21:47');
INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('18', 'Mochaccino', '', 'drinks', '0.00', '1', '1', NULL, '2026-02-14 09:22:04', '2026-02-14 09:22:04');
INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('19', 'Americano', '', 'drinks', '0.00', '1', '1', NULL, '2026-02-14 09:22:17', '2026-02-14 09:22:17');
INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('20', 'Espresso', '', 'drinks', '0.00', '1', '1', NULL, '2026-02-14 09:22:29', '2026-02-14 09:22:29');
INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('21', 'Latte', '', 'drinks', '0.00', '1', '1', NULL, '2026-02-14 09:22:46', '2026-02-14 09:22:46');
INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('22', 'Ice black tea', '', 'drinks', '0.00', '1', '1', NULL, '2026-02-14 09:23:15', '2026-02-14 09:23:15');
INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('23', 'Hot black tea', '', 'drinks', '0.00', '1', '1', NULL, '2026-02-14 09:23:26', '2026-02-14 09:23:26');
INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('24', 'Orange juice', '', 'drinks', '0.00', '1', '1', NULL, '2026-02-14 09:23:36', '2026-02-14 09:23:36');
INSERT INTO `breakfast_menus` (`id`, `menu_name`, `description`, `category`, `price`, `is_free`, `is_available`, `image_url`, `created_at`, `updated_at`) VALUES ('25', 'Watermelon juice', '', 'drinks', '0.00', '1', '1', NULL, '2026-02-14 09:23:50', '2026-02-14 09:23:50');

-- --------------------------------------------------------
-- Table structure for `breakfast_orders`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `breakfast_orders`;
CREATE TABLE `breakfast_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) DEFAULT NULL,
  `guest_name` varchar(100) NOT NULL,
  `room_number` varchar(20) DEFAULT NULL,
  `total_pax` int(11) NOT NULL,
  `breakfast_time` time NOT NULL,
  `breakfast_date` date NOT NULL,
  `location` enum('restaurant','room_service') DEFAULT 'restaurant',
  `menu_items` text DEFAULT NULL COMMENT 'JSON array of menu items with quantities',
  `special_requests` text DEFAULT NULL,
  `total_price` decimal(10,2) DEFAULT 0.00,
  `order_status` enum('pending','preparing','served','completed','cancelled') DEFAULT 'pending',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_date` (`breakfast_date`),
  KEY `idx_status` (`order_status`),
  CONSTRAINT `breakfast_orders_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `breakfast_orders`

INSERT INTO `breakfast_orders` (`id`, `booking_id`, `guest_name`, `room_number`, `total_pax`, `breakfast_time`, `breakfast_date`, `location`, `menu_items`, `special_requests`, `total_price`, `order_status`, `created_by`, `created_at`, `updated_at`) VALUES ('4', '21', 'dita', '102', '2', '14:51:00', '2026-02-08', 'restaurant', '[{\"menu_id\":\"6\",\"menu_name\":\"Standart Breakfast\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":1},{\"menu_id\":\"5\",\"menu_name\":\"orange Juice\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":1}]', NULL, '0.00', 'pending', '1', '2026-02-08 12:49:58', '2026-02-08 12:49:58');
INSERT INTO `breakfast_orders` (`id`, `booking_id`, `guest_name`, `room_number`, `total_pax`, `breakfast_time`, `breakfast_date`, `location`, `menu_items`, `special_requests`, `total_price`, `order_status`, `created_by`, `created_at`, `updated_at`) VALUES ('5', '24', 'diki', '103', '1', '10:23:00', '2026-02-08', 'restaurant', '[{\"menu_id\":\"6\",\"menu_name\":\"Standart Breakfast\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":1},{\"menu_id\":\"5\",\"menu_name\":\"orange Juice\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":1}]', NULL, '0.00', 'pending', '1', '2026-02-08 13:21:03', '2026-02-08 13:21:03');
INSERT INTO `breakfast_orders` (`id`, `booking_id`, `guest_name`, `room_number`, `total_pax`, `breakfast_time`, `breakfast_date`, `location`, `menu_items`, `special_requests`, `total_price`, `order_status`, `created_by`, `created_at`, `updated_at`) VALUES ('6', '28', 'sapi', '101', '2', '07:03:00', '2026-02-11', 'restaurant', '[{\"menu_id\":\"6\",\"menu_name\":\"Standart Breakfast\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":1},{\"menu_id\":\"5\",\"menu_name\":\"orange Juice\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":1},{\"menu_id\":\"9\",\"menu_name\":\"extra breakfast\",\"quantity\":1,\"price\":\"65000.00\",\"is_free\":0}]', NULL, '65000.00', 'pending', '7', '2026-02-11 19:57:15', '2026-02-11 19:57:15');
INSERT INTO `breakfast_orders` (`id`, `booking_id`, `guest_name`, `room_number`, `total_pax`, `breakfast_time`, `breakfast_date`, `location`, `menu_items`, `special_requests`, `total_price`, `order_status`, `created_by`, `created_at`, `updated_at`) VALUES ('7', '30', 'dita', '104', '2', '18:13:00', '2026-02-12', 'restaurant', '[{\"menu_id\":\"6\",\"menu_name\":\"Standart Breakfast\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"5\",\"menu_name\":\"orange Juice\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"}]', NULL, '0.00', 'pending', '1', '2026-02-12 17:12:04', '2026-02-12 17:12:04');
INSERT INTO `breakfast_orders` (`id`, `booking_id`, `guest_name`, `room_number`, `total_pax`, `breakfast_time`, `breakfast_date`, `location`, `menu_items`, `special_requests`, `total_price`, `order_status`, `created_by`, `created_at`, `updated_at`) VALUES ('8', '34', 'Meii Ratnaa', '202', '4', '07:00:00', '2026-02-15', 'restaurant', '[{\"menu_id\":\"13\",\"menu_name\":\"Pancake\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"12\",\"menu_name\":\"Waffle\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"11\",\"menu_name\":\"Fried noodle\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"10\",\"menu_name\":\"Indonesian fried rice\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"19\",\"menu_name\":\"Americano\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"23\",\"menu_name\":\"Hot black tea\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"5\",\"menu_name\":\"orange Juice\",\"quantity\":4,\"price\":\"0.00\",\"is_free\":\"1\"}]', 'ice tea with out sugar,  dan 2 pax extra kids pancake dan waffle', '0.00', 'pending', '7', '2026-02-14 13:12:47', '2026-02-14 13:12:47');
INSERT INTO `breakfast_orders` (`id`, `booking_id`, `guest_name`, `room_number`, `total_pax`, `breakfast_time`, `breakfast_date`, `location`, `menu_items`, `special_requests`, `total_price`, `order_status`, `created_by`, `created_at`, `updated_at`) VALUES ('9', '34', 'Meii Ratnaa', '202', '4', '07:00:00', '2026-02-15', 'restaurant', '[{\"menu_id\":\"13\",\"menu_name\":\"Pancake\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"12\",\"menu_name\":\"Waffle\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"11\",\"menu_name\":\"Fried noodle\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"10\",\"menu_name\":\"Indonesian fried rice\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"19\",\"menu_name\":\"Americano\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"23\",\"menu_name\":\"Hot black tea\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"5\",\"menu_name\":\"orange Juice\",\"quantity\":4,\"price\":\"0.00\",\"is_free\":\"1\"}]', 'Ice tea without sugar, free kids 2 pax waffle and pancake', '0.00', 'pending', '7', '2026-02-14 13:18:42', '2026-02-14 13:18:42');
INSERT INTO `breakfast_orders` (`id`, `booking_id`, `guest_name`, `room_number`, `total_pax`, `breakfast_time`, `breakfast_date`, `location`, `menu_items`, `special_requests`, `total_price`, `order_status`, `created_by`, `created_at`, `updated_at`) VALUES ('10', '32', 'Mrs Putri', '204', '2', '07:30:00', '2026-02-15', 'restaurant', '[{\"menu_id\":\"12\",\"menu_name\":\"Waffle\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"11\",\"menu_name\":\"Fried noodle\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"23\",\"menu_name\":\"Hot black tea\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"22\",\"menu_name\":\"Ice black tea\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"}]', NULL, '0.00', 'pending', '7', '2026-02-14 15:42:29', '2026-02-14 15:42:29');
INSERT INTO `breakfast_orders` (`id`, `booking_id`, `guest_name`, `room_number`, `total_pax`, `breakfast_time`, `breakfast_date`, `location`, `menu_items`, `special_requests`, `total_price`, `order_status`, `created_by`, `created_at`, `updated_at`) VALUES ('11', '33', 'Mrs Putri', '205', '2', '07:30:00', '2026-02-15', 'restaurant', '[{\"menu_id\":\"12\",\"menu_name\":\"Waffle\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"10\",\"menu_name\":\"Indonesian fried rice\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"23\",\"menu_name\":\"Hot black tea\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"21\",\"menu_name\":\"Latte\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"}]', NULL, '0.00', 'pending', '7', '2026-02-14 15:46:18', '2026-02-14 15:46:18');
INSERT INTO `breakfast_orders` (`id`, `booking_id`, `guest_name`, `room_number`, `total_pax`, `breakfast_time`, `breakfast_date`, `location`, `menu_items`, `special_requests`, `total_price`, `order_status`, `created_by`, `created_at`, `updated_at`) VALUES ('12', '33', 'Mrs Putri', '205', '2', '07:30:00', '2026-02-14', 'restaurant', '[{\"menu_id\":\"12\",\"menu_name\":\"Waffle\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"10\",\"menu_name\":\"Indonesian fried rice\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"23\",\"menu_name\":\"Hot black tea\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"21\",\"menu_name\":\"Latte\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"}]', NULL, '0.00', 'pending', '7', '2026-02-14 15:47:19', '2026-02-14 15:47:19');
INSERT INTO `breakfast_orders` (`id`, `booking_id`, `guest_name`, `room_number`, `total_pax`, `breakfast_time`, `breakfast_date`, `location`, `menu_items`, `special_requests`, `total_price`, `order_status`, `created_by`, `created_at`, `updated_at`) VALUES ('13', '34', 'Meii Ratnaa', '202', '4', '07:00:00', '2026-02-14', 'restaurant', '[{\"menu_id\":\"13\",\"menu_name\":\"Pancake\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"12\",\"menu_name\":\"Waffle\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"11\",\"menu_name\":\"Fried noodle\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"10\",\"menu_name\":\"Indonesian fried rice\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"19\",\"menu_name\":\"Americano\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"22\",\"menu_name\":\"Ice black tea\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"5\",\"menu_name\":\"orange Juice\",\"quantity\":4,\"price\":\"0.00\",\"is_free\":\"1\"}]', 'ice tea without sugar, and free kids 2 pax waffle and pancake', '0.00', 'pending', '7', '2026-02-14 15:48:59', '2026-02-14 15:48:59');
INSERT INTO `breakfast_orders` (`id`, `booking_id`, `guest_name`, `room_number`, `total_pax`, `breakfast_time`, `breakfast_date`, `location`, `menu_items`, `special_requests`, `total_price`, `order_status`, `created_by`, `created_at`, `updated_at`) VALUES ('14', '32', 'Mrs Putri', '204', '2', '07:30:00', '2026-02-14', 'restaurant', '[{\"menu_id\":\"12\",\"menu_name\":\"Waffle\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"11\",\"menu_name\":\"Fried noodle\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"23\",\"menu_name\":\"Hot black tea\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"22\",\"menu_name\":\"Ice black tea\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"}]', NULL, '0.00', 'pending', '7', '2026-02-14 15:52:01', '2026-02-14 15:52:01');
INSERT INTO `breakfast_orders` (`id`, `booking_id`, `guest_name`, `room_number`, `total_pax`, `breakfast_time`, `breakfast_date`, `location`, `menu_items`, `special_requests`, `total_price`, `order_status`, `created_by`, `created_at`, `updated_at`) VALUES ('15', '31', 'Mrs Putri', '201', '4', '07:30:00', '2026-02-14', 'restaurant', '[{\"menu_id\":\"13\",\"menu_name\":\"Pancake\",\"quantity\":3,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"10\",\"menu_name\":\"Indonesian fried rice\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"23\",\"menu_name\":\"Hot black tea\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"21\",\"menu_name\":\"Latte\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"}]', 'Free kids 2 pax pancake', '0.00', 'pending', '7', '2026-02-14 18:46:13', '2026-02-14 18:46:13');
INSERT INTO `breakfast_orders` (`id`, `booking_id`, `guest_name`, `room_number`, `total_pax`, `breakfast_time`, `breakfast_date`, `location`, `menu_items`, `special_requests`, `total_price`, `order_status`, `created_by`, `created_at`, `updated_at`) VALUES ('16', '104', 'Patrycja Maliszewska', '201', '2', '09:00:00', '2026-03-02', 'restaurant', '[{\"menu_id\":\"13\",\"menu_name\":\"Pancake\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"11\",\"menu_name\":\"Fried noodle\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"20\",\"menu_name\":\"Espresso\",\"quantity\":2,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"5\",\"menu_name\":\"orange Juice\",\"quantity\":2,\"price\":\"0.00\",\"is_free\":\"1\"}]', '1 double americano', '0.00', 'pending', '1', '2026-03-02 13:29:07', '2026-03-02 13:29:07');
INSERT INTO `breakfast_orders` (`id`, `booking_id`, `guest_name`, `room_number`, `total_pax`, `breakfast_time`, `breakfast_date`, `location`, `menu_items`, `special_requests`, `total_price`, `order_status`, `created_by`, `created_at`, `updated_at`) VALUES ('17', '104', 'Patrycja Maliszewska', '201', '2', '09:30:00', '2026-03-03', 'restaurant', '[{\"menu_id\":\"13\",\"menu_name\":\"Pancake\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"11\",\"menu_name\":\"Fried noodle\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"19\",\"menu_name\":\"Americano\",\"quantity\":2,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"22\",\"menu_name\":\"Ice black tea\",\"quantity\":2,\"price\":\"0.00\",\"is_free\":\"1\"}]', 'Buatkan mie terlebih dahulu baru pancake, jika bisa tambah 2 telur', '0.00', 'pending', '1', '2026-03-03 19:02:21', '2026-03-03 19:02:21');
INSERT INTO `breakfast_orders` (`id`, `booking_id`, `guest_name`, `room_number`, `total_pax`, `breakfast_time`, `breakfast_date`, `location`, `menu_items`, `special_requests`, `total_price`, `order_status`, `created_by`, `created_at`, `updated_at`) VALUES ('18', '121', 'Patrycja Maliszewska', '201', '1', '09:30:00', '2026-03-04', 'restaurant', '[{\"menu_id\":\"12\",\"menu_name\":\"Waffle\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"11\",\"menu_name\":\"Fried noodle\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"19\",\"menu_name\":\"Americano\",\"quantity\":2,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"22\",\"menu_name\":\"Ice black tea\",\"quantity\":2,\"price\":\"0.00\",\"is_free\":\"1\"}]', NULL, '0.00', 'pending', '1', '2026-03-04 12:40:37', '2026-03-04 12:40:37');
INSERT INTO `breakfast_orders` (`id`, `booking_id`, `guest_name`, `room_number`, `total_pax`, `breakfast_time`, `breakfast_date`, `location`, `menu_items`, `special_requests`, `total_price`, `order_status`, `created_by`, `created_at`, `updated_at`) VALUES ('19', '121', 'Patrycja Maliszewska', '201', '1', '08:30:00', '2026-03-05', 'restaurant', '[{\"menu_id\":\"12\",\"menu_name\":\"Waffle\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"10\",\"menu_name\":\"Indonesian fried rice\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"19\",\"menu_name\":\"Americano\",\"quantity\":2,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"22\",\"menu_name\":\"Ice black tea\",\"quantity\":2,\"price\":\"0.00\",\"is_free\":\"1\"}]', NULL, '0.00', 'pending', '1', '2026-03-05 13:29:19', '2026-03-05 13:29:19');
INSERT INTO `breakfast_orders` (`id`, `booking_id`, `guest_name`, `room_number`, `total_pax`, `breakfast_time`, `breakfast_date`, `location`, `menu_items`, `special_requests`, `total_price`, `order_status`, `created_by`, `created_at`, `updated_at`) VALUES ('20', '121', 'Patrycja Maliszewska', '201', '2', '07:30:00', '2026-03-05', 'restaurant', '[{\"menu_id\":\"12\",\"menu_name\":\"Waffle\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"10\",\"menu_name\":\"Indonesian fried rice\",\"quantity\":1,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"19\",\"menu_name\":\"Americano\",\"quantity\":2,\"price\":\"0.00\",\"is_free\":\"1\"},{\"menu_id\":\"22\",\"menu_name\":\"Ice black tea\",\"quantity\":2,\"price\":\"0.00\",\"is_free\":\"1\"}]', NULL, '0.00', 'pending', '1', '2026-03-05 15:17:07', '2026-03-05 15:17:07');

-- --------------------------------------------------------
-- Table structure for `business_settings`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `business_settings`;
CREATE TABLE `business_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `business_id` int(11) NOT NULL,
  `whatsapp_number` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `businesses`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `businesses`;
CREATE TABLE `businesses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `business_slug` varchar(100) DEFAULT NULL,
  `business_name` varchar(255) NOT NULL,
  `database_name` varchar(255) NOT NULL,
  `business_type` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `database_name` (`database_name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `businesses`

INSERT INTO `businesses` (`id`, `business_slug`, `business_name`, `database_name`, `business_type`, `address`, `phone`, `created_at`) VALUES ('3', 'narayana-hotel', 'Narayana Hotel', 'adf_narayana_hotel', 'hotel', NULL, NULL, '2026-02-03 21:09:08');

-- --------------------------------------------------------
-- Table structure for `cash_balance`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `cash_balance`;
CREATE TABLE `cash_balance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `balance_date` date NOT NULL,
  `opening_balance` decimal(15,2) DEFAULT 0.00,
  `total_income` decimal(15,2) DEFAULT 0.00,
  `total_expense` decimal(15,2) DEFAULT 0.00,
  `closing_balance` decimal(15,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `balance_date` (`balance_date`),
  KEY `idx_date` (`balance_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `cash_book`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `cash_book`;
CREATE TABLE `cash_book` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` varchar(50) NOT NULL DEFAULT 'narayana-hotel',
  `transaction_date` date NOT NULL,
  `transaction_time` time NOT NULL,
  `division_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `cash_account_id` int(11) DEFAULT NULL,
  `transaction_type` enum('income','expense') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `payment_method` varchar(50) NOT NULL DEFAULT 'cash',
  `reference_number` varchar(100) DEFAULT NULL,
  `source_type` varchar(50) DEFAULT 'manual',
  `is_editable` tinyint(1) DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `shift` varchar(20) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `source_id` int(11) DEFAULT NULL,
  `category_name` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_date` (`transaction_date`),
  KEY `idx_division` (`division_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_type` (`transaction_type`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `cash_book_ibfk_1` FOREIGN KEY (`division_id`) REFERENCES `divisions` (`id`),
  CONSTRAINT `cash_book_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1688 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `cash_book`

INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1372', 'narayana-hotel', '2026-03-03', '12:36:00', '8', '95', '1', 'income', '2500000.00', 'Transfer dana operasional dari Bu Sita', NULL, NULL, 'cash', NULL, 'owner_fund', '1', '27', '2026-03-03 12:37:15', '2026-03-03 12:38:01', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1373', 'narayana-hotel', '2026-03-03', '12:38:00', '12', '98', '1', 'expense', '500000.00', 'bayar driver pak aan', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-03 12:45:53', '2026-03-03 12:45:53', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1374', 'narayana-hotel', '2026-03-03', '12:45:00', '9', '47', '1', 'expense', '350000.00', 'mingguan pak widi', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-03 12:46:30', '2026-03-03 12:46:30', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1375', 'narayana-hotel', '2026-03-03', '12:46:00', '12', '99', '1', 'expense', '30000.00', '1 dus piser', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-03 12:48:33', '2026-03-03 12:48:33', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1376', 'narayana-hotel', '2026-03-03', '12:49:00', '12', '99', '1', 'expense', '60000.00', '2 dus piser', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-03 12:50:07', '2026-03-03 12:50:07', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1377', 'narayana-hotel', '2026-03-03', '12:51:00', '8', '95', '1', 'income', '50000.00', 'sisa saldo cash february', NULL, NULL, 'cash', NULL, 'owner_fund', '1', '27', '2026-03-03 12:51:46', '2026-03-03 12:51:46', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1378', 'narayana-hotel', '2026-03-03', '12:52:00', '12', '99', '1', 'expense', '20000.00', 'beli secrup', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-03 12:53:07', '2026-03-03 12:53:07', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1379', 'narayana-hotel', '2026-03-03', '12:53:00', '12', '99', '1', 'expense', '21000.00', 'beli galon tukang 3', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-03 12:53:56', '2026-03-03 12:53:56', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1380', 'narayana-hotel', '2026-03-03', '12:54:00', '12', '99', '1', 'expense', '30000.00', 'beli paku beton', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-03 12:54:47', '2026-03-03 12:54:47', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1381', 'narayana-hotel', '2026-03-03', '12:54:00', '12', '67', '1', 'expense', '14000.00', '2 galon minum tukang', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-03 12:55:51', '2026-03-03 12:55:51', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1382', 'narayana-hotel', '2026-03-03', '12:55:00', '3', '49', '1', 'expense', '50000.00', 'belanja dapur breakfast tamu', NULL, NULL, 'transfer', NULL, 'manual', '1', '27', '2026-03-03 12:56:46', '2026-03-03 12:56:46', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1383', 'narayana-hotel', '2026-03-03', '12:57:00', '5', '50', '1', 'expense', '130000.00', 'bayar tkbm', NULL, NULL, 'transfer', NULL, 'manual', '1', '27', '2026-03-03 12:58:24', '2026-03-03 12:58:24', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1384', 'narayana-hotel', '2026-03-03', '13:00:00', '3', '49', '1', 'expense', '16000.00', 'beli kecap', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-03 13:00:22', '2026-03-03 13:00:22', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1385', 'narayana-hotel', '2026-03-03', '16:01:00', '7', '56', '1', 'expense', '38000.00', 'github copilot', NULL, NULL, 'transfer', NULL, 'manual', '1', '27', '2026-03-03 16:02:43', '2026-03-03 16:02:43', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1386', 'narayana-hotel', '2026-03-02', '12:00:00', '5', '3', NULL, 'income', '1292000.00', 'Pembayaran Reservasi - Patrycja Maliszewska (Room 201) - BK-20260302-7338 [OTA - ESTIMASI]', NULL, NULL, 'ota', NULL, 'manual', '1', '1', '2026-03-03 16:43:52', '2026-03-03 16:43:52', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1611', 'narayana-hotel', '2026-03-02', '09:43:21', '5', '3', '2', 'income', '2693333.33', 'Pembayaran Reservasi - PT KINGDA (Room 101) - BK-20260302-3958 [LUNAS]', NULL, NULL, 'transfer', NULL, 'manual', '1', '1', '2026-03-03 17:17:04', '2026-03-03 17:17:04', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1612', 'narayana-hotel', '2026-03-02', '09:43:21', '5', '3', '2', 'income', '1693333.33', 'Pembayaran Reservasi - PT KINGDA (Room 201) - BK-20260302-6612 [LUNAS]', NULL, NULL, 'transfer', NULL, 'manual', '1', '1', '2026-03-03 17:17:04', '2026-03-03 17:17:04', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1613', 'narayana-hotel', '2026-03-02', '09:43:21', '5', '3', '2', 'income', '1693333.33', 'Pembayaran Reservasi - PT KINGDA (Room 204) - BK-20260302-6929 [LUNAS]', NULL, NULL, 'transfer', NULL, 'manual', '1', '1', '2026-03-03 17:17:04', '2026-03-03 17:17:04', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1614', 'narayana-hotel', '2026-03-02', '09:43:22', '5', '3', '2', 'income', '1693333.33', 'Pembayaran Reservasi - PT KINGDA (Room 202) - BK-20260302-9355 [LUNAS]', NULL, NULL, 'transfer', NULL, 'manual', '1', '1', '2026-03-03 17:17:04', '2026-03-03 17:17:04', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1615', 'narayana-hotel', '2026-03-02', '09:43:22', '5', '3', '2', 'income', '1693333.33', 'Pembayaran Reservasi - PT KINGDA (Room 203) - BK-20260302-2257 [LUNAS]', NULL, NULL, 'transfer', NULL, 'manual', '1', '1', '2026-03-03 17:17:04', '2026-03-03 17:17:04', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1616', 'narayana-hotel', '2026-03-02', '09:43:22', '5', '3', '2', 'income', '1693333.33', 'Pembayaran Reservasi - PT KINGDA (Room 206) - BK-20260302-3116 [LUNAS]', NULL, NULL, 'transfer', NULL, 'manual', '1', '1', '2026-03-03 17:17:04', '2026-03-03 17:17:04', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1617', 'narayana-hotel', '2026-03-02', '09:47:06', '5', '3', '2', 'income', '2433000.00', 'Pembayaran Reservasi - PT KINGDA (Room 105) - BK-20260302-3893 [LUNAS]', NULL, NULL, 'transfer', NULL, 'manual', '1', '1', '2026-03-03 17:17:04', '2026-03-03 17:17:04', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1618', 'narayana-hotel', '2026-03-02', '09:47:06', '5', '3', '2', 'income', '2433000.00', 'Pembayaran Reservasi - PT KINGDA (Room 106) - BK-20260302-8648 [LUNAS]', NULL, NULL, 'transfer', NULL, 'manual', '1', '1', '2026-03-03 17:17:04', '2026-03-03 17:17:04', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1623', 'narayana-hotel', '2026-03-04', '09:26:50', '5', '4', '2', 'income', '857500.00', 'Pembayaran Reservasi - Karimunjawa Vocation (Room 203) - BK-20260304-7277 [DP] april', NULL, NULL, 'transfer', NULL, 'manual', '1', '1', '2026-03-04 09:26:50', '2026-03-05 10:47:06', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1624', 'narayana-hotel', '2026-03-04', '09:29:17', '5', '4', '2', 'income', '1400000.00', 'Pembayaran Reservasi - imania syarifatunisa (Room 205) - BK-20260304-8573 [LUNAS]', NULL, NULL, 'transfer', NULL, 'manual', '1', '1', '2026-03-04 09:29:17', '2026-03-05 09:52:09', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1626', 'narayana-hotel', '2026-03-05', '10:29:00', '5', '101', '1', 'expense', '50000.00', 'bayar penjemputan check in 2 maret [Petty Cash]', NULL, NULL, 'transfer', NULL, 'manual', '1', '27', '2026-03-05 10:29:56', '2026-03-05 10:29:56', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1627', 'narayana-hotel', '2026-03-05', '13:22:00', '12', '63', '1', 'expense', '53500.00', 'listrik 5 maret [Petty Cash]', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-05 13:23:32', '2026-03-05 13:23:32', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1628', 'narayana-hotel', '2026-03-05', '13:24:00', '12', '63', '1', 'expense', '103500.00', 'listrik rumah merah 1 maret [Petty Cash]', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-05 13:24:44', '2026-03-05 13:24:44', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1629', 'narayana-hotel', '2026-03-05', '13:26:00', '12', '63', '1', 'expense', '53500.00', 'bayar listrik rumah merah 28 february [Petty Cash]', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-05 13:27:39', '2026-03-05 13:48:27', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1631', 'narayana-hotel', '2026-03-05', '15:23:00', '3', '49', '1', 'expense', '61700.00', 'belanja resto untuk tamu [Petty Cash]', NULL, NULL, 'transfer', NULL, 'manual', '1', '27', '2026-03-05 15:24:05', '2026-03-05 15:24:05', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1643', 'narayana-hotel', '2026-03-06', '09:16:43', '14', '105', '2', 'income', '300000.00', '[HSV-202603-0001] Patrycja Maliszewska - Motor Rental', NULL, NULL, 'transfer', NULL, 'manual', '1', '27', '2026-03-06 09:16:43', '2026-03-06 09:25:01', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1644', 'narayana-hotel', '2026-03-06', '09:16:43', '18', '105', '2', 'income', '350000.00', '[HSV-202603-0001] Patrycja Maliszewska - Airport Drop', NULL, NULL, 'transfer', NULL, 'manual', '1', '27', '2026-03-06 09:16:43', '2026-03-06 20:57:41', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1651', 'narayana-hotel', '2026-03-06', '09:30:00', '5', '106', '1', 'expense', '10000.00', 'beli bensin motor hotel [Petty Cash]', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-06 09:31:21', '2026-03-06 09:31:21', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1654', 'narayana-hotel', '2026-03-06', '09:45:00', '5', '50', '1', 'expense', '250000.00', 'bayar transport check out ke airport pak moyong [Petty Cash]', NULL, NULL, 'transfer', NULL, 'manual', '1', '27', '2026-03-06 09:46:08', '2026-03-06 09:46:08', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1657', 'narayana-hotel', '2026-03-06', '15:03:00', '5', '50', '1', 'expense', '100000.00', 'Bayar jimpitan Jatikerep [Petty Cash]', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-06 15:04:29', '2026-03-06 15:04:29', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1658', 'narayana-hotel', '2026-03-07', '08:16:00', '12', '107', '1', 'expense', '203000.00', 'beli pulsa listrik rumah merah [Petty Cash]', NULL, NULL, 'transfer', NULL, 'manual', '1', '27', '2026-03-07 08:17:22', '2026-03-07 08:17:22', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1659', 'narayana-hotel', '2026-03-07', '09:57:00', '4', '50', '1', 'expense', '74000.00', 'beli plastik sampah [Petty Cash]', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-07 09:58:48', '2026-03-07 09:58:48', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1660', 'narayana-hotel', '2026-03-07', '15:42:00', '19', '108', '1', 'expense', '144500.00', 'beli solar [Petty Cash]', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-07 15:42:53', '2026-03-07 16:10:10', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1661', 'narayana-hotel', '2026-03-08', '16:37:00', '12', '63', '1', 'expense', '53000.00', 'Pulsa rumah merah [Petty Cash]', NULL, NULL, 'transfer', NULL, 'manual', '1', '27', '2026-03-08 16:38:23', '2026-03-08 16:38:23', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1662', 'narayana-hotel', '2026-03-09', '09:44:00', '11', '50', '1', 'expense', '103000.00', 'pulsa listrik pos satpam [Petty Cash]', NULL, NULL, 'transfer', NULL, 'manual', '1', '27', '2026-03-09 09:45:02', '2026-03-09 09:45:02', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1667', 'narayana-hotel', '2026-03-10', '12:11:00', '2', '43', '1', 'expense', '14000.00', 'Beli galon bar [Petty Cash]', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-10 12:13:17', '2026-03-10 12:13:17', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1668', 'narayana-hotel', '2026-03-10', '12:40:00', '8', '95', '1', 'income', '18000000.00', 'Transfer dana operasional dari Bu Sita', NULL, NULL, 'transfer', NULL, 'owner_fund', '1', '27', '2026-03-10 12:41:13', '2026-03-10 12:41:13', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1669', 'narayana-hotel', '2026-03-10', '12:46:00', '5', '50', '1', 'expense', '1211590.00', 'bayar wifi 20 maret [Petty Cash]', NULL, NULL, 'transfer', NULL, 'manual', '1', '27', '2026-03-10 12:47:23', '2026-03-10 12:47:23', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1670', 'narayana-hotel', '2026-03-10', '12:51:00', '5', '50', '1', 'expense', '3372040.00', 'tagihan listrik february [Petty Cash]', NULL, NULL, 'transfer', NULL, 'manual', '1', '27', '2026-03-10 12:52:21', '2026-03-10 12:52:21', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1671', 'narayana-hotel', '2026-03-10', '13:02:00', '5', '50', '1', 'expense', '6810000.00', 'Bayar cicilan motor [Petty Cash]', NULL, NULL, 'transfer', NULL, 'manual', '1', '27', '2026-03-10 13:03:38', '2026-03-10 13:03:38', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1672', 'narayana-hotel', '2026-03-10', '13:39:00', '12', '50', '1', 'expense', '3572498.00', 'Bayaran pak ipin dan iwan [Petty Cash]', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-10 13:40:31', '2026-03-10 13:40:31', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1673', 'narayana-hotel', '2026-03-10', '13:40:00', '11', '50', '1', 'expense', '55000.00', 'beli kopi dan gula satpam [Petty Cash]', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-10 13:41:08', '2026-03-10 13:41:08', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1674', 'narayana-hotel', '2026-03-10', '13:47:00', '5', '50', '1', 'expense', '150000.00', 'bayar tkbm [Petty Cash]', NULL, NULL, 'transfer', NULL, 'manual', '1', '27', '2026-03-10 13:48:43', '2026-03-10 13:48:43', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1675', 'narayana-hotel', '2026-03-10', '13:52:00', '7', '50', '1', 'expense', '120000.00', 'bayar kuli panggul jepara [Petty Cash]', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-10 13:53:07', '2026-03-10 13:53:07', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1676', 'narayana-hotel', '2026-03-10', '14:03:00', '9', '50', '1', 'expense', '350000.00', 'Mingguan pak widi [Petty Cash]', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-10 14:04:22', '2026-03-10 14:04:22', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1684', 'narayana-hotel', '2026-03-11', '09:56:00', '5', '109', '1', 'expense', '77000.00', 'Beli github untuk web [Petty Cash]', NULL, NULL, 'transfer', NULL, 'manual', '1', '27', '2026-03-11 09:57:38', '2026-03-11 09:57:38', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1685', 'narayana-hotel', '2026-03-11', '10:04:00', '5', '110', '1', 'expense', '24000.00', 'Bensin motor hotel [Petty Cash]', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-11 10:05:34', '2026-03-11 10:05:34', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1686', 'narayana-hotel', '2026-03-11', '10:06:00', '4', '110', '1', 'expense', '423000.00', 'Belanja kebutuhan housekeeping [Petty Cash]', NULL, NULL, 'cash', NULL, 'manual', '1', '27', '2026-03-11 10:07:01', '2026-03-11 10:07:01', NULL, NULL, NULL, NULL, NULL);
INSERT INTO `cash_book` (`id`, `branch_id`, `transaction_date`, `transaction_time`, `division_id`, `category_id`, `cash_account_id`, `transaction_type`, `amount`, `description`, `reference_no`, `receipt_number`, `payment_method`, `reference_number`, `source_type`, `is_editable`, `created_by`, `created_at`, `updated_at`, `shift`, `notes`, `attachment`, `source_id`, `category_name`) VALUES ('1687', 'narayana-hotel', '2026-03-11', '11:40:00', '5', '50', '1', 'expense', '250000.00', 'Bayar atensi polisi [Petty Cash]', NULL, NULL, 'transfer', NULL, 'manual', '1', '27', '2026-03-11 11:40:44', '2026-03-11 11:40:44', NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------
-- Table structure for `categories`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` varchar(50) NOT NULL DEFAULT 'narayana-hotel',
  `division_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `category_type` enum('income','expense') NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_division` (`division_id`),
  KEY `idx_type` (`category_type`),
  KEY `idx_active` (`is_active`),
  CONSTRAINT `categories_ibfk_1` FOREIGN KEY (`division_id`) REFERENCES `divisions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=111 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `categories`

INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('1', 'narayana-hotel', '1', 'Food Sales', 'income', 'Revenue from food sales', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('2', 'narayana-hotel', '1', 'Beverage Sales', 'income', 'Revenue from beverage sales', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('3', 'narayana-hotel', '2', 'Room Service Charges', 'income', 'Room service charges', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('4', 'narayana-hotel', '3', 'Room Rental', 'income', 'Hotel room rental income', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('5', 'narayana-hotel', '3', 'Reservation Fee', 'income', 'Booking & reservation fees', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('6', 'narayana-hotel', '3', 'Service Charges', 'income', 'Additional service fees', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('7', 'narayana-hotel', '4', 'Housekeeping Service', 'income', 'Special cleaning & turndown', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('8', 'narayana-hotel', '4', 'Room Supplies', 'expense', 'Room cleaning supplies', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('13', 'narayana-hotel', '7', 'Bar Sales', 'income', 'Bar & beverage sales', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('14', 'narayana-hotel', '7', 'Beverage Inventory', 'expense', 'Purchase of beverages', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('15', 'narayana-hotel', '8', 'Banquet Charges', 'income', 'Event & meeting room revenue', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('16', 'narayana-hotel', '8', 'Catering Cost', 'expense', 'Cost of catering & events', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('17', 'narayana-hotel', '9', 'Minibar Sales', 'income', 'Minibar product sales', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('18', 'narayana-hotel', '10', 'Transportation Fee', 'income', 'Airport transfer & car rental', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('19', 'narayana-hotel', '11', 'Electric Bill', 'expense', 'Electricity charges', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('20', 'narayana-hotel', '11', 'Water Bill', 'expense', 'Water & sewerage charges', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('21', 'narayana-hotel', '11', 'Internet Bill', 'expense', 'Internet & phone charges', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('22', 'narayana-hotel', '1', 'Food Supplies', 'expense', 'Purchase of food ingredients', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('23', 'narayana-hotel', '1', 'Kitchen Equipment', 'expense', 'Kitchen equipment & maintenance', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('24', 'narayana-hotel', '3', 'Staff Salary', 'expense', 'Employee salaries & wages', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('25', 'narayana-hotel', '4', 'Maintenance', 'expense', 'Equipment & facility maintenance', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('26', 'narayana-hotel', '7', 'Cleaning Supplies', 'expense', 'Cleaning materials & chemicals', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('27', 'narayana-hotel', '8', 'Decoration', 'expense', 'Event decoration & setup', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('28', 'narayana-hotel', '10', 'Fuel Cost', 'expense', 'Vehicle fuel & maintenance', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('29', 'narayana-hotel', '3', 'Guest Supplies', 'expense', 'Toiletries & guest amenities', '1', '2026-01-24 22:09:10', '2026-01-24 22:09:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('31', 'narayana-hotel', '2', 'Room Sell', 'income', NULL, '1', '2026-01-24 23:39:28', '2026-01-24 23:39:28');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('32', 'narayana-hotel', '1', 'Penjualan', 'income', NULL, '1', '2026-01-25 00:06:14', '2026-01-25 00:06:14');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('33', 'narayana-hotel', '4', 'Beli sabun', 'expense', NULL, '1', '2026-01-25 00:07:13', '2026-01-25 00:07:13');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('34', 'narayana-hotel', '11', 'Penjualan', 'income', NULL, '1', '2026-01-25 06:38:27', '2026-01-25 06:38:27');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('35', 'narayana-hotel', '7', 'belibuah', 'expense', NULL, '1', '2026-01-26 20:17:43', '2026-01-26 20:17:43');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('36', 'narayana-hotel', '11', 'Bayar Listrik', 'expense', NULL, '1', '2026-02-04 01:13:20', '2026-02-04 01:13:20');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('37', 'narayana-hotel', '11', 'kapal', 'income', NULL, '1', '2026-02-07 05:35:10', '2026-02-07 05:35:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('41', 'narayana-hotel', '14', 'Penjualan', 'income', NULL, '1', '2026-02-07 22:46:43', '2026-02-07 22:46:43');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('42', 'narayana-hotel', '5', 'Penjualan', 'income', NULL, '1', '2026-02-08 11:59:46', '2026-02-08 11:59:46');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('43', 'narayana-hotel', '3', 'Putri', 'expense', NULL, '1', '2026-02-13 11:49:08', '2026-02-13 11:49:08');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('44', 'narayana-hotel', '3', 'bayar', 'income', NULL, '1', '2026-02-13 11:50:51', '2026-02-13 11:50:51');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('45', 'narayana-hotel', '1', 'Putri', 'income', NULL, '1', '2026-02-13 11:51:48', '2026-02-13 11:51:48');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('46', 'narayana-hotel', '5', 'Saldo awal', 'income', NULL, '1', '2026-02-13 11:56:56', '2026-02-13 11:56:56');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('47', 'narayana-hotel', '9', 'Pak widi', 'expense', NULL, '1', '2026-02-13 11:59:28', '2026-02-13 11:59:28');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('48', 'narayana-hotel', '7', 'Keamanan', 'expense', NULL, '1', '2026-02-13 12:00:30', '2026-02-13 12:00:30');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('49', 'narayana-hotel', '1', 'Dela', 'expense', NULL, '1', '2026-02-13 12:01:20', '2026-02-13 12:01:20');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('50', 'narayana-hotel', '1', 'Sandra', 'expense', NULL, '1', '2026-02-13 12:02:55', '2026-02-13 12:02:55');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('51', 'narayana-hotel', '8', 'Bu sitta', 'income', NULL, '1', '2026-02-13 12:03:55', '2026-02-13 12:03:55');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('52', 'narayana-hotel', '7', 'Matrial tukang', 'expense', NULL, '1', '2026-02-13 12:04:55', '2026-02-13 12:04:55');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('53', 'narayana-hotel', '5', 'Busita', 'income', NULL, '1', '2026-02-15 10:20:06', '2026-02-15 10:20:06');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('54', 'narayana-hotel', '7', 'Gaji ipin', 'expense', NULL, '1', '2026-02-16 09:33:43', '2026-02-16 09:33:43');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('55', 'narayana-hotel', '3', 'Pembayaran tamu', 'income', NULL, '1', '2026-02-16 09:35:02', '2026-02-16 09:35:02');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('56', 'narayana-hotel', '5', 'arief', 'expense', NULL, '1', '2026-02-16 09:38:30', '2026-02-16 09:38:30');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('57', 'narayana-hotel', '5', 'Puspita', 'expense', NULL, '1', '2026-02-16 10:27:05', '2026-02-16 10:27:05');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('58', 'narayana-hotel', '1', 'Dela', 'income', NULL, '1', '2026-02-16 10:28:29', '2026-02-16 10:28:29');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('59', 'narayana-hotel', '10', 'Ket', 'expense', NULL, '1', '2026-02-16 10:42:49', '2026-02-16 10:42:49');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('60', 'narayana-hotel', '5', 'Refund Booking', 'expense', 'Refund untuk pembatalan booking', '1', '2026-02-17 07:46:27', '2026-02-17 07:46:27');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('62', 'narayana-hotel', '5', 'Pak pri', 'expense', NULL, '1', '2026-02-17 08:38:15', '2026-02-17 08:38:15');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('63', 'narayana-hotel', '12', 'Hilmi', 'expense', NULL, '1', '2026-02-18 09:10:19', '2026-02-18 09:10:19');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('64', 'narayana-hotel', '3', 'Moka', 'income', NULL, '1', '2026-02-18 10:28:51', '2026-02-18 10:28:51');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('65', 'narayana-hotel', '3', 'Room 202', 'income', NULL, '1', '2026-02-18 10:29:34', '2026-02-18 10:29:34');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('66', 'narayana-hotel', '3', 'Room 205', 'income', NULL, '1', '2026-02-18 10:30:11', '2026-02-18 10:30:11');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('67', 'narayana-hotel', '5', 'Doni', 'expense', NULL, '1', '2026-02-18 12:18:11', '2026-02-18 12:18:11');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('68', 'narayana-hotel', '7', 'Kat', 'income', NULL, '1', '2026-02-18 16:28:40', '2026-02-18 16:28:40');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('69', 'narayana-hotel', '7', 'Kat', 'expense', NULL, '1', '2026-02-18 16:28:53', '2026-02-18 16:28:53');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('70', 'narayana-hotel', '5', 'Cloud bed Feb 26', 'expense', NULL, '1', '2026-02-19 15:21:12', '2026-02-19 15:21:12');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('71', 'narayana-hotel', '1', 'Sandra', 'income', NULL, '1', '2026-02-20 11:04:10', '2026-02-20 11:04:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('72', 'narayana-hotel', '1', 'Sandra dari kas katrin', 'income', NULL, '1', '2026-02-21 08:36:57', '2026-02-21 08:36:57');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('74', 'narayana-hotel', '2', 'maul', 'expense', NULL, '1', '2026-02-21 13:39:41', '2026-02-21 13:39:41');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('75', 'narayana-hotel', '3', 'Della', 'expense', NULL, '1', '2026-02-21 13:41:08', '2026-02-21 13:41:08');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('76', 'narayana-hotel', '5', 'romm', 'income', NULL, '1', '2026-02-21 19:44:51', '2026-02-21 19:44:51');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('77', 'narayana-hotel', '1', 'kepiting', 'income', NULL, '1', '2026-02-21 19:45:48', '2026-02-21 19:45:48');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('78', 'narayana-hotel', '8', 'tambah modal', 'income', NULL, '1', '2026-02-24 14:22:39', '2026-02-24 14:22:39');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('79', 'narayana-hotel', '9', 'sabun', 'expense', NULL, '1', '2026-02-24 14:33:11', '2026-02-24 14:33:11');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('80', 'narayana-hotel', '5', 'hotel', 'income', NULL, '1', '2026-02-24 14:40:12', '2026-02-24 14:40:12');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('81', 'narayana-hotel', '9', 'sewa', 'income', NULL, '1', '2026-02-24 14:41:02', '2026-02-24 14:41:02');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('82', 'narayana-hotel', '8', 'resto', 'income', NULL, '1', '2026-02-24 14:55:33', '2026-02-24 14:55:33');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('83', 'narayana-hotel', '5', 'saldo', 'income', NULL, '1', '2026-02-25 19:28:26', '2026-02-25 19:28:26');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('84', 'narayana-hotel', '5', 'Karimunjawa vacation', 'income', NULL, '1', '2026-02-26 15:05:44', '2026-02-26 15:05:44');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('85', 'narayana-hotel', '5', 'Mrs Putri', 'income', NULL, '1', '2026-02-26 15:07:01', '2026-02-26 15:07:01');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('86', 'narayana-hotel', '5', 'Mrs tata', 'income', NULL, '1', '2026-02-26 15:08:16', '2026-02-26 15:08:16');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('87', 'narayana-hotel', '8', 'Saldo awal februari', 'income', NULL, '1', '2026-02-26 15:11:50', '2026-02-26 15:11:50');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('88', 'narayana-hotel', '8', 'sisa trf', 'income', NULL, '1', '2026-02-26 15:15:00', '2026-02-26 15:15:00');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('89', 'narayana-hotel', '8', 'Bu sita trf', 'income', NULL, '1', '2026-02-26 15:16:56', '2026-02-26 15:16:56');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('90', 'narayana-hotel', '5', 'listrik', 'expense', NULL, '1', '2026-02-26 15:18:00', '2026-02-26 15:18:00');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('91', 'narayana-hotel', '5', 'wifi', 'expense', NULL, '1', '2026-02-26 15:18:42', '2026-02-26 15:18:42');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('92', 'narayana-hotel', '5', 'cicilan motor 1', 'expense', NULL, '1', '2026-02-26 15:19:37', '2026-02-26 15:19:37');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('93', 'narayana-hotel', '5', 'cicilan motor 2', 'expense', NULL, '1', '2026-02-26 15:20:06', '2026-02-26 15:20:06');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('94', 'narayana-hotel', '5', 'cicilan motor', 'expense', NULL, '1', '2026-02-26 15:20:53', '2026-02-26 15:20:53');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('95', 'narayana-hotel', '2', 'Modal Operasional dari Bu Sita', 'income', NULL, '1', '2026-03-03 11:22:10', '2026-03-03 11:22:10');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('96', 'narayana-hotel', '1', 'bayar', 'expense', NULL, '1', '2026-03-03 11:23:51', '2026-03-03 11:23:51');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('97', 'narayana-hotel', '5', 'Dp masuk', 'income', NULL, '1', '2026-03-03 11:24:22', '2026-03-03 11:24:22');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('98', 'narayana-hotel', '12', 'Pak aan', 'expense', NULL, '1', '2026-03-03 12:45:53', '2026-03-03 12:45:53');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('99', 'narayana-hotel', '12', 'pak ipin', 'expense', NULL, '1', '2026-03-03 12:48:33', '2026-03-03 12:48:33');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('101', 'narayana-hotel', '5', 'moyong', 'expense', NULL, '1', '2026-03-05 10:29:56', '2026-03-05 10:29:56');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('105', 'narayana-hotel', '5', 'Hotel Service', 'income', NULL, '1', '2026-03-05 17:27:02', '2026-03-05 17:27:02');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('106', 'narayana-hotel', '5', 'kaleb', 'expense', NULL, '1', '2026-03-06 09:31:21', '2026-03-06 09:31:21');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('107', 'narayana-hotel', '12', 'pak habibi', 'expense', NULL, '1', '2026-03-07 08:17:22', '2026-03-07 08:17:22');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('108', 'narayana-hotel', '7', 'Rizal', 'expense', NULL, '1', '2026-03-07 15:42:52', '2026-03-07 15:42:52');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('109', 'narayana-hotel', '5', 'Arif', 'expense', NULL, '1', '2026-03-11 09:57:38', '2026-03-11 09:57:38');
INSERT INTO `categories` (`id`, `branch_id`, `division_id`, `category_name`, `category_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('110', 'narayana-hotel', '5', 'Fendi', 'expense', NULL, '1', '2026-03-11 10:05:34', '2026-03-11 10:05:34');

-- --------------------------------------------------------
-- Table structure for `divisions`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `divisions`;
CREATE TABLE `divisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` varchar(50) NOT NULL DEFAULT 'narayana-hotel',
  `division_code` varchar(20) NOT NULL,
  `division_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `division_type` enum('income','expense','both') DEFAULT 'both',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `division_code` (`division_code`),
  KEY `idx_code` (`division_code`),
  KEY `idx_type` (`division_type`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `divisions`

INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('1', 'narayana-hotel', 'KITCHEN', 'Kitchen', NULL, 'both', '1', '2026-02-08 06:59:23', '2026-02-08 06:59:23');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('2', 'narayana-hotel', 'BAR', 'Bar', NULL, 'both', '1', '2026-02-08 06:59:23', '2026-02-08 06:59:23');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('3', 'narayana-hotel', 'RESTO', 'Resto', NULL, 'both', '1', '2026-02-08 06:59:23', '2026-02-08 06:59:23');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('4', 'narayana-hotel', 'HOUSEKEEPING', 'Housekeeping', NULL, 'expense', '1', '2026-02-08 06:59:23', '2026-02-08 06:59:23');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('5', 'narayana-hotel', 'HOTEL', 'Hotel', NULL, 'income', '1', '2026-02-08 06:59:23', '2026-02-08 06:59:23');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('6', 'narayana-hotel', 'GARDENER', 'Gardener', NULL, 'expense', '1', '2026-02-08 06:59:23', '2026-02-08 06:59:23');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('7', 'narayana-hotel', 'OTHERS', 'Lain2', NULL, 'both', '1', '2026-02-08 06:59:23', '2026-02-08 06:59:23');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('8', 'narayana-hotel', 'PC', 'Petty Cash', '', 'both', '1', '2026-02-13 11:57:23', '2026-02-13 11:57:23');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('9', 'narayana-hotel', 'PL', 'Pool', '', 'both', '1', '2026-02-13 11:57:35', '2026-02-13 11:57:35');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('10', 'narayana-hotel', 'FO', 'Front office', '', 'both', '1', '2026-02-16 10:22:47', '2026-02-16 10:22:47');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('11', 'narayana-hotel', 'SC', 'Security', '', 'both', '1', '2026-02-16 10:23:20', '2026-02-16 10:23:20');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('12', 'narayana-hotel', 'PJ', 'Project', '', 'both', '1', '2026-02-16 10:36:57', '2026-02-16 10:36:57');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('14', 'narayana-hotel', '', 'Motor Rental', NULL, 'both', '1', '2026-03-05 16:17:41', '2026-03-05 16:17:41');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('18', 'narayana-hotel', 'CR', 'Drop Car', '', 'both', '1', '2026-03-06 20:57:09', '2026-03-06 20:57:09');
INSERT INTO `divisions` (`id`, `branch_id`, `division_code`, `division_name`, `description`, `division_type`, `is_active`, `created_at`, `updated_at`) VALUES ('19', 'narayana-hotel', 'SG', 'Solar Genset', '', 'both', '1', '2026-03-07 16:09:52', '2026-03-07 16:09:52');

-- --------------------------------------------------------
-- Table structure for `expense_categories`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `expense_categories`;
CREATE TABLE `expense_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `expense_categories`

INSERT INTO `expense_categories` (`id`, `name`, `description`, `created_at`) VALUES ('1', 'Material', 'Bahan baku dan material konstruksi', '2026-01-25 19:04:27');
INSERT INTO `expense_categories` (`id`, `name`, `description`, `created_at`) VALUES ('2', 'Jasa', 'Upah tenaga kerja dan jasa profesional', '2026-01-25 19:04:27');
INSERT INTO `expense_categories` (`id`, `name`, `description`, `created_at`) VALUES ('3', 'Lain-lain', 'Pengeluaran lain-lain', '2026-01-25 19:04:27');

-- --------------------------------------------------------
-- Table structure for `frontdesk_rooms`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `frontdesk_rooms`;
CREATE TABLE `frontdesk_rooms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `room_number` varchar(10) NOT NULL,
  `status` enum('available','occupied','maintenance') DEFAULT 'available',
  `capacity` int(11) DEFAULT 1,
  `price` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_number` (`room_number`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `frontdesk_rooms`

INSERT INTO `frontdesk_rooms` (`id`, `room_number`, `status`, `capacity`, `price`, `created_at`) VALUES ('1', '101', 'occupied', '2', '500000.00', '2026-02-04 18:50:03');
INSERT INTO `frontdesk_rooms` (`id`, `room_number`, `status`, `capacity`, `price`, `created_at`) VALUES ('2', '102', 'available', '2', '500000.00', '2026-02-04 18:50:03');
INSERT INTO `frontdesk_rooms` (`id`, `room_number`, `status`, `capacity`, `price`, `created_at`) VALUES ('3', '103', 'available', '2', '500000.00', '2026-02-04 18:50:03');
INSERT INTO `frontdesk_rooms` (`id`, `room_number`, `status`, `capacity`, `price`, `created_at`) VALUES ('4', '104', 'available', '1', '400000.00', '2026-02-04 18:50:03');
INSERT INTO `frontdesk_rooms` (`id`, `room_number`, `status`, `capacity`, `price`, `created_at`) VALUES ('5', '105', 'available', '2', '500000.00', '2026-02-04 18:50:03');
INSERT INTO `frontdesk_rooms` (`id`, `room_number`, `status`, `capacity`, `price`, `created_at`) VALUES ('6', '201', 'available', '3', '750000.00', '2026-02-04 18:50:03');
INSERT INTO `frontdesk_rooms` (`id`, `room_number`, `status`, `capacity`, `price`, `created_at`) VALUES ('7', '202', 'available', '3', '750000.00', '2026-02-04 18:50:03');
INSERT INTO `frontdesk_rooms` (`id`, `room_number`, `status`, `capacity`, `price`, `created_at`) VALUES ('8', '203', 'available', '2', '600000.00', '2026-02-04 18:50:03');
INSERT INTO `frontdesk_rooms` (`id`, `room_number`, `status`, `capacity`, `price`, `created_at`) VALUES ('9', '204', 'available', '2', '600000.00', '2026-02-04 18:50:03');
INSERT INTO `frontdesk_rooms` (`id`, `room_number`, `status`, `capacity`, `price`, `created_at`) VALUES ('10', '205', 'available', '2', '600000.00', '2026-02-04 18:50:03');
INSERT INTO `frontdesk_rooms` (`id`, `room_number`, `status`, `capacity`, `price`, `created_at`) VALUES ('11', '301', 'available', '1', '350000.00', '2026-02-04 18:50:03');
INSERT INTO `frontdesk_rooms` (`id`, `room_number`, `status`, `capacity`, `price`, `created_at`) VALUES ('12', '302', 'available', '1', '350000.00', '2026-02-04 18:50:03');

-- --------------------------------------------------------
-- Table structure for `guests`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `guests`;
CREATE TABLE `guests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `guest_name` varchar(200) NOT NULL,
  `id_card_type` enum('ktp','passport','sim') DEFAULT 'ktp',
  `id_card_number` varchar(50) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `nationality` varchar(50) DEFAULT 'Indonesia',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_id_card` (`id_card_number`),
  KEY `idx_phone` (`phone`)
) ENGINE=InnoDB AUTO_INCREMENT=120 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `guests`

INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('25', 'Mrs Putri', 'ktp', 'TEMP-20260216104856-6233', '+6281298661298', '', NULL, 'Indonesia', '2026-02-16 10:48:56', '2026-02-16 10:48:56');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('26', 'Mrs Putri', 'ktp', 'TEMP-20260216104856-6329', '+6281298661298', '', NULL, 'Indonesia', '2026-02-16 10:48:56', '2026-02-16 10:48:56');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('27', 'Mrs Putri', 'ktp', 'TEMP-20260216104856-8916', '+6281298661298', '', NULL, 'Indonesia', '2026-02-16 10:48:56', '2026-02-16 10:48:56');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('28', 'Meii Ratnaa', 'ktp', 'TEMP-20260216105549-3858', '0989655564', '', NULL, 'Indonesia', '2026-02-16 10:55:49', '2026-02-16 10:55:49');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('29', 'Meii Ratnaa', 'ktp', 'TEMP-20260216145018-5847', '+6281298661298', '', NULL, 'Indonesia', '2026-02-16 14:50:18', '2026-02-16 14:50:18');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('30', 'Mrs Putri', 'ktp', 'TEMP-20260216145237-5325', '+6281298661298', '', NULL, 'Indonesia', '2026-02-16 14:52:37', '2026-02-16 14:52:37');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('31', 'Mrs Putri', 'ktp', 'TEMP-20260216145237-2979', '+6281298661298', '', NULL, 'Indonesia', '2026-02-16 14:52:37', '2026-02-16 14:52:37');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('32', 'Mrs Putri', 'ktp', 'TEMP-20260216145237-4965', '+6281298661298', '', NULL, 'Indonesia', '2026-02-16 14:52:37', '2026-02-16 14:52:37');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('33', 'evelyn Lo', 'ktp', 'TEMP-20260216145739-7443', '0989655564', '', NULL, 'Indonesia', '2026-02-16 14:57:39', '2026-02-16 14:57:39');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('34', 'sapi', 'ktp', 'TEMP-20260216195517-8146', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-16 19:55:17', '2026-02-16 19:55:17');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('35', 'AJIK', 'ktp', 'TEMP-20260216200433-1835', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-16 20:04:33', '2026-02-16 20:04:33');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('36', 'AJIK', 'ktp', 'TEMP-20260216211550-6308', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-16 21:15:50', '2026-02-16 21:15:50');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('37', 'SUPRI', 'ktp', 'TEMP-20260216215858-3464', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-16 21:58:58', '2026-02-16 21:58:58');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('38', 'sapi', 'ktp', 'TEMP-20260216220008-3336', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-16 22:00:08', '2026-02-16 22:00:08');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('39', 'AJIK', 'ktp', 'TEMP-20260216222802-5660', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-16 22:28:02', '2026-02-16 22:28:02');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('40', 'SUPRI', 'ktp', 'TEMP-20260216225231-4286', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-16 22:52:31', '2026-02-16 22:52:31');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('41', 'AJIK', 'ktp', 'TEMP-20260216225915-5268', '', '', NULL, 'Indonesia', '2026-02-16 22:59:15', '2026-02-16 22:59:15');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('42', 'AJIK', 'ktp', 'TEMP-20260216230935-8293', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-16 23:09:35', '2026-02-16 23:09:35');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('43', 'AJIK', 'ktp', 'TEMP-20260216232109-5492', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-16 23:21:09', '2026-02-16 23:21:09');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('44', 'SUPRI', 'ktp', 'TEMP-20260216232813-6548', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-16 23:28:13', '2026-02-16 23:28:13');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('45', 'sapi', 'ktp', 'TEMP-20260216234255-5940', '', '', NULL, 'Indonesia', '2026-02-16 23:42:55', '2026-02-16 23:42:55');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('46', 'SANDA', 'ktp', 'TEMP-20260217000024-7909', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-17 00:00:24', '2026-02-17 00:00:24');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('47', 'AJIK', 'ktp', 'TEMP-20260217000600-3625', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-17 00:06:00', '2026-02-17 00:06:00');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('48', 'GIGOK', 'ktp', 'TEMP-20260217001830-8407', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-17 00:18:30', '2026-02-17 00:18:30');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('49', 'AJIK', 'ktp', 'TEMP-20260217064608-6647', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-17 06:46:08', '2026-02-17 06:46:08');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('50', 'GIGOK', 'ktp', 'TEMP-20260217065809-7597', '', '', NULL, 'Indonesia', '2026-02-17 06:58:09', '2026-02-17 06:58:09');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('51', 'maul', 'ktp', 'TEMP-20260217070900-8622', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-17 07:09:00', '2026-02-17 07:09:00');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('52', 'sandr', 'ktp', 'TEMP-20260217072825-2324', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-17 07:28:25', '2026-02-17 07:28:25');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('53', 'dita', 'ktp', 'TEMP-20260217073807-8067', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-17 07:38:07', '2026-02-17 07:38:07');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('54', 'dita', 'ktp', 'TEMP-20260217073808-5088', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-17 07:38:08', '2026-02-17 07:38:08');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('55', 'dita', 'ktp', 'TEMP-20260217074515-5428', '+33 2035640799', '', NULL, 'Indonesia', '2026-02-17 07:45:15', '2026-02-17 07:45:15');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('56', 'Mrs Putri', 'ktp', 'TEMP-20260217084841-4864', '+6281298661298', '', NULL, 'Indonesia', '2026-02-17 08:48:41', '2026-02-17 08:48:41');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('57', 'Mrs Putri', 'ktp', 'TEMP-20260217084841-2551', '+6281298661298', '', NULL, 'Indonesia', '2026-02-17 08:48:41', '2026-02-17 08:48:41');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('58', 'Mrs Putri', 'ktp', 'TEMP-20260217084842-8957', '+6281298661298', '', NULL, 'Indonesia', '2026-02-17 08:48:42', '2026-02-17 08:48:42');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('59', 'Meii Ratnaa', 'ktp', 'TEMP-20260217084946-3839', '+6281298661298', '', NULL, 'Indonesia', '2026-02-17 08:49:46', '2026-02-17 08:49:46');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('60', 'dita', 'ktp', 'TEMP-20260221134707-8504', '67676767667', '', NULL, 'Indonesia', '2026-02-21 13:47:07', '2026-02-21 13:47:07');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('61', 'Kumala ningrum', 'ktp', '161616161161616161', '081228338380', 'sandraasofii123@gmail.com', NULL, 'Indonesia', '2026-02-23 13:25:24', '2026-03-02 09:50:38');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('62', 'Sandra', 'ktp', '56778766', '452485', 'sandraasofii123@gmail.com', NULL, 'Indonesia', '2026-02-25 09:06:29', '2026-02-25 09:06:29');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('63', 'Nyoba', 'ktp', 'Gs26363737', '5404546166', 'hdhshshsh@gmail.com', NULL, 'Indonesia', '2026-02-25 19:16:37', '2026-02-25 19:16:37');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('64', 'evelyn Lo', 'ktp', 'TEMP-20260302093108-9409', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 09:31:08', '2026-03-02 09:31:08');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('65', 'rosellina anggreini', 'ktp', 'TEMP-20260302093321-7106', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 09:33:21', '2026-03-02 09:33:21');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('66', 'rosellina anggreini', 'ktp', 'TEMP-20260302093321-3007', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 09:33:21', '2026-03-02 09:33:21');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('67', 'PT KINGDA', 'ktp', 'TEMP-20260302093623-5830', '0989655564', '', NULL, 'Indonesia', '2026-03-02 09:36:23', '2026-03-02 09:38:15');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('68', 'PT KINGDA', 'ktp', 'TEMP-20260302093623-2451', '0989655564', '', NULL, 'Indonesia', '2026-03-02 09:36:23', '2026-03-02 09:38:44');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('69', 'PT KINGDA', 'ktp', 'TEMP-20260302093623-7561', '0989655564', '', NULL, 'Indonesia', '2026-03-02 09:36:23', '2026-03-02 09:39:19');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('70', 'PT KINGDA', 'ktp', 'TEMP-20260302093623-6013', '0989655564', '', NULL, 'Indonesia', '2026-03-02 09:36:23', '2026-03-02 09:36:23');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('71', 'PT KINGDA', 'ktp', 'TEMP-20260302094321-4578', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 09:43:21', '2026-03-02 09:43:21');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('72', 'PT KINGDA', 'ktp', 'TEMP-20260302094321-5236', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 09:43:21', '2026-03-02 09:43:21');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('73', 'PT KINGDA', 'ktp', 'TEMP-20260302094321-9206', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 09:43:21', '2026-03-02 09:43:21');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('74', 'PT KINGDA', 'ktp', 'TEMP-20260302094322-9528', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 09:43:22', '2026-03-02 09:43:22');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('75', 'PT KINGDA', 'ktp', 'TEMP-20260302094322-9078', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 09:43:22', '2026-03-02 09:43:22');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('76', 'PT KINGDA', 'ktp', 'TEMP-20260302094322-4545', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 09:43:22', '2026-03-02 09:43:22');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('77', 'PT KINGDA', 'ktp', 'TEMP-20260302094706-2824', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 09:47:06', '2026-03-02 09:47:06');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('78', 'PT KINGDA', 'ktp', 'TEMP-20260302094706-1149', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 09:47:06', '2026-03-02 09:47:06');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('79', 'Jessyca Jessyca', 'ktp', 'TEMP-20260302094921-7433', '0989655564', '', NULL, 'Indonesia', '2026-03-02 09:49:21', '2026-03-02 09:49:21');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('80', 'Clement Angkawidjaja', 'ktp', 'TEMP-20260302095041-2768', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 09:50:41', '2026-03-02 09:50:41');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('81', 'Rochim Rochim', 'ktp', 'TEMP-20260302095257-2627', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 09:52:57', '2026-03-02 09:52:57');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('82', 'Kumala Ningrum', 'ktp', 'TEMP-20260302095355-2846', '', '', NULL, 'Indonesia', '2026-03-02 09:53:55', '2026-03-02 09:53:55');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('83', 'Kumala Ningrum', 'ktp', 'TEMP-20260302095356-3563', '', '', NULL, 'Indonesia', '2026-03-02 09:53:56', '2026-03-02 09:53:56');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('84', 'Devina Rahma Aulia', 'ktp', 'TEMP-20260302095459-8487', '0989655564', '', NULL, 'Indonesia', '2026-03-02 09:54:59', '2026-03-02 09:54:59');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('85', 'Devina Rahma Aulia', 'ktp', 'TEMP-20260302095459-5926', '0989655564', '', NULL, 'Indonesia', '2026-03-02 09:54:59', '2026-03-02 09:54:59');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('86', 'kamalkrishnan mukkara meganathan', 'ktp', 'TEMP-20260302102651-7422', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 10:26:51', '2026-03-02 10:26:51');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('87', 'Patrycja Maliszewska', 'ktp', 'TEMP-20260302102759-7904', '0989655564', '', NULL, 'Indonesia', '2026-03-02 10:27:59', '2026-03-02 10:27:59');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('88', 'Daniel Muis', 'ktp', 'TEMP-20260302103958-4244', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 10:39:58', '2026-03-02 10:39:58');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('89', 'Jessyca Jessyca', 'ktp', 'TEMP-20260302114102-4598', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 11:41:02', '2026-03-02 11:41:02');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('90', 'Ananda Rizky Khairunnisa', 'ktp', 'TEMP-20260302114308-6946', '0989655564', '', NULL, 'Indonesia', '2026-03-02 11:43:08', '2026-03-02 11:43:08');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('91', 'Ananda Rizky Khairunnisa', 'ktp', 'TEMP-20260302114308-6136', '0989655564', '', NULL, 'Indonesia', '2026-03-02 11:43:08', '2026-03-02 11:43:08');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('92', 'Yo Suhendra', 'ktp', 'TEMP-20260302132213-5069', '0989655564', '', NULL, 'Indonesia', '2026-03-02 13:22:13', '2026-03-02 13:22:13');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('93', 'Nisa Zakiah', 'ktp', 'TEMP-20260302132333-9790', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 13:23:33', '2026-03-02 13:23:33');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('94', 'Muhammad Fauzan', 'ktp', 'TEMP-20260302132507-4715', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 13:25:07', '2026-03-02 13:25:07');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('95', 'Muhammad Fauzan', 'ktp', 'TEMP-20260302132507-3640', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 13:25:07', '2026-03-02 13:25:07');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('96', 'Muhammad Fauzan', 'ktp', 'TEMP-20260302132507-1907', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 13:25:07', '2026-03-02 13:25:07');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('97', 'Muhammad Fauzan', 'ktp', 'TEMP-20260302132507-8225', '+6281298661298', '', NULL, 'Indonesia', '2026-03-02 13:25:07', '2026-03-02 13:25:07');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('98', 'Denida Intania La Syifa', 'ktp', 'TEMP-20260302132608-9062', '0989655564', '', NULL, 'Indonesia', '2026-03-02 13:26:08', '2026-03-02 13:26:08');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('99', 'Denida Intania La Syifa', 'ktp', 'TEMP-20260302132608-4565', '0989655564', '', NULL, 'Indonesia', '2026-03-02 13:26:08', '2026-03-02 13:26:08');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('100', 'Marcellinus Adiatma Widyakusuma', 'ktp', 'TEMP-20260302132717-9955', '0989655564', '', NULL, 'Indonesia', '2026-03-02 13:27:17', '2026-03-02 13:27:17');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('101', 'Amy Bian', 'ktp', 'TEMP-20260303094050-4591', '+6281298661298', '', NULL, 'Indonesia', '2026-03-03 09:40:50', '2026-03-03 09:40:50');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('102', 'Karimunjawa Vocation', 'ktp', 'TEMP-20260304092650-9082', '+6281298661298', '', NULL, 'Indonesia', '2026-03-04 09:26:50', '2026-03-04 09:26:50');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('103', 'imania syarifatunisa', 'ktp', 'TEMP-20260304092917-4118', '+6281298661298', '', NULL, 'Indonesia', '2026-03-04 09:29:17', '2026-03-04 09:29:17');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('104', 'Patrycja Maliszewska', 'ktp', 'TEMP-20260304123937-8852', '+6281298661298', '', NULL, 'Indonesia', '2026-03-04 12:39:37', '2026-03-04 12:39:37');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('105', 'xie li min xie li min', 'ktp', 'TEMP-20260306092746-6862', '+6281298661298', '', NULL, 'Indonesia', '2026-03-06 09:27:46', '2026-03-06 09:27:46');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('106', 'XIAOYAN LI', 'ktp', 'TEMP-20260306092858-8927', '+6281298661298', '', NULL, 'Indonesia', '2026-03-06 09:28:58', '2026-03-06 09:28:58');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('107', 'Idam Atmojo', 'ktp', 'TEMP-20260306093735-9875', '+6281298661298', '', NULL, 'Indonesia', '2026-03-06 09:37:35', '2026-03-06 09:37:35');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('108', 'Idam Atmojo', 'ktp', 'TEMP-20260306093735-8029', '+6281298661298', '', NULL, 'Indonesia', '2026-03-06 09:37:35', '2026-03-06 09:37:35');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('109', 'test', 'ktp', 'TEMP-20260306100359-2938', '+33 2035640799', '', NULL, 'Indonesia', '2026-03-06 10:03:59', '2026-03-06 10:03:59');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('110', 'AJIK', 'ktp', 'TEMP-20260306101558-1643', '+33 2035640799', '', NULL, 'Indonesia', '2026-03-06 10:15:58', '2026-03-06 10:15:58');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('111', 'Dyah Ayu Dewanti', 'ktp', 'TEMP-20260310085431-3820', '+6281298661298', '', NULL, 'Indonesia', '2026-03-10 08:54:31', '2026-03-10 08:54:31');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('112', 'Dyah Ayu Dewanti', 'ktp', 'TEMP-20260310085431-7302', '+6281298661298', '', NULL, 'Indonesia', '2026-03-10 08:54:31', '2026-03-10 08:54:31');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('113', 'Hari Mandala putra', 'ktp', 'TEMP-20260310085721-5697', '0989655564', '', NULL, 'Indonesia', '2026-03-10 08:57:21', '2026-03-10 08:57:21');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('114', 'Alvintra Alvintra', 'ktp', 'TEMP-20260311082323-9315', '+6281298661298', '', NULL, 'Indonesia', '2026-03-11 08:23:23', '2026-03-11 08:23:23');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('115', 'Riyono Riyono', 'ktp', 'TEMP-20260311082835-3471', '+6281298661298', '', NULL, 'Indonesia', '2026-03-11 08:28:35', '2026-03-11 08:28:35');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('116', 'Calvin Calvin', 'ktp', 'TEMP-20260311083116-6105', '0989655564', '', NULL, 'Indonesia', '2026-03-11 08:31:16', '2026-03-11 08:31:16');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('117', 'Alvira Violita', 'ktp', 'TEMP-20260311083243-5109', '+6281298661298', '', NULL, 'Indonesia', '2026-03-11 08:32:43', '2026-03-11 08:32:43');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('118', 'Alvira Violita', 'ktp', 'TEMP-20260311083336-2997', '+6281298661298', '', NULL, 'Indonesia', '2026-03-11 08:33:36', '2026-03-11 08:33:36');
INSERT INTO `guests` (`id`, `guest_name`, `id_card_type`, `id_card_number`, `phone`, `email`, `address`, `nationality`, `created_at`, `updated_at`) VALUES ('119', 'Laurin Laurin', 'ktp', 'TEMP-20260311083502-8439', '+6281298661298', '', NULL, 'Indonesia', '2026-03-11 08:35:02', '2026-03-11 08:35:02');

-- --------------------------------------------------------
-- Table structure for `hotel_invoice_items`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `hotel_invoice_items`;
CREATE TABLE `hotel_invoice_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `service_type` enum('motor_rental','laundry','service','airport_drop','harbor_drop') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `start_datetime` datetime DEFAULT NULL,
  `end_datetime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inv` (`invoice_id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `hotel_invoice_items`

INSERT INTO `hotel_invoice_items` (`id`, `invoice_id`, `service_type`, `description`, `quantity`, `unit_price`, `total_price`, `start_datetime`, `end_datetime`) VALUES ('15', '9', 'motor_rental', 'Honda Beat', '3.00', '100000.00', '300000.00', NULL, NULL);
INSERT INTO `hotel_invoice_items` (`id`, `invoice_id`, `service_type`, `description`, `quantity`, `unit_price`, `total_price`, `start_datetime`, `end_datetime`) VALUES ('16', '9', 'airport_drop', 'Car', '1.00', '350000.00', '350000.00', NULL, NULL);
INSERT INTO `hotel_invoice_items` (`id`, `invoice_id`, `service_type`, `description`, `quantity`, `unit_price`, `total_price`, `start_datetime`, `end_datetime`) VALUES ('17', '10', 'service', 'Honda Beat', '1.00', '1000009.00', '1000009.00', NULL, NULL);
INSERT INTO `hotel_invoice_items` (`id`, `invoice_id`, `service_type`, `description`, `quantity`, `unit_price`, `total_price`, `start_datetime`, `end_datetime`) VALUES ('18', '10', 'motor_rental', 'Honda Beat', '1.00', '100000.00', '100000.00', NULL, NULL);

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
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `cashbook_synced` tinyint(1) NOT NULL DEFAULT 0,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `idx_biz` (`business_id`),
  KEY `idx_date` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `hotel_invoices`

INSERT INTO `hotel_invoices` (`id`, `business_id`, `invoice_number`, `booking_id`, `guest_name`, `guest_phone`, `room_number`, `total`, `paid_amount`, `payment_status`, `payment_method`, `status`, `notes`, `created_by`, `created_at`, `updated_at`, `cashbook_synced`, `tax_rate`, `tax_amount`) VALUES ('9', '1', 'HSV-202603-0001', '121', 'Patrycja Maliszewska', '+6281298661298', '201', '650000.00', '650000.00', 'paid', 'card', 'confirmed', NULL, '27', '2026-03-06 09:14:33', '2026-03-06 09:16:43', '1', '0.00', '0.00');
INSERT INTO `hotel_invoices` (`id`, `business_id`, `invoice_number`, `booking_id`, `guest_name`, `guest_phone`, `room_number`, `total`, `paid_amount`, `payment_status`, `payment_method`, `status`, `notes`, `created_by`, `created_at`, `updated_at`, `cashbook_synced`, `tax_rate`, `tax_amount`) VALUES ('10', '1', 'HSV-202603-0002', NULL, 'dita', '081330316204', NULL, '1100009.00', '0.00', 'unpaid', 'cash', 'confirmed', NULL, '8', '2026-03-09 08:32:13', NULL, '0', '0.00', '0.00');

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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `hotel_service_catalog`

INSERT INTO `hotel_service_catalog` (`id`, `business_id`, `service_type`, `item_name`, `default_price`, `unit`, `is_active`, `sort_order`, `created_at`) VALUES ('1', '1', 'motor_rental', 'Honda Beat', '100000.00', 'Day', '1', '1', '2026-03-05 15:53:17');
INSERT INTO `hotel_service_catalog` (`id`, `business_id`, `service_type`, `item_name`, `default_price`, `unit`, `is_active`, `sort_order`, `created_at`) VALUES ('2', '1', 'airport_drop', 'Car', '350000.00', 'PP', '1', '2', '2026-03-05 15:54:09');
INSERT INTO `hotel_service_catalog` (`id`, `business_id`, `service_type`, `item_name`, `default_price`, `unit`, `is_active`, `sort_order`, `created_at`) VALUES ('3', '1', 'harbor_drop', 'CAR', '65000.00', 'PP', '1', '3', '2026-03-05 15:54:46');

-- --------------------------------------------------------
-- Table structure for `hotel_service_orders`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `hotel_service_orders`;
CREATE TABLE `hotel_service_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `business_id` int(11) NOT NULL DEFAULT 1,
  `order_number` varchar(30) NOT NULL,
  `guest_name` varchar(120) NOT NULL,
  `guest_phone` varchar(30) DEFAULT NULL,
  `room_number` varchar(20) DEFAULT NULL,
  `service_type` enum('motor_rental','laundry','service','airport_drop','harbor_drop') NOT NULL,
  `description` text DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `start_datetime` datetime DEFAULT NULL,
  `end_datetime` datetime DEFAULT NULL,
  `payment_method` varchar(20) NOT NULL DEFAULT 'cash',
  `payment_status` enum('unpaid','paid','partial') NOT NULL DEFAULT 'unpaid',
  `paid_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','confirmed','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `idx_business` (`business_id`),
  KEY `idx_status` (`status`),
  KEY `idx_service` (`service_type`),
  KEY `idx_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for `investor_balances`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `investor_balances`;
CREATE TABLE `investor_balances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `investor_id` int(11) NOT NULL,
  `total_capital_idr` decimal(15,2) DEFAULT 0.00,
  `total_expenses_idr` decimal(15,2) DEFAULT 0.00,
  `remaining_balance_idr` decimal(15,2) DEFAULT 0.00,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_investor` (`investor_id`),
  CONSTRAINT `investor_balances_ibfk_1` FOREIGN KEY (`investor_id`) REFERENCES `investors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `investor_id` int(11) NOT NULL,
  `type` enum('capital','expense','return') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text DEFAULT NULL,
  `transaction_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `investor_id` (`investor_id`),
  CONSTRAINT `investor_transactions_ibfk_1` FOREIGN KEY (`investor_id`) REFERENCES `investors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `investors`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `investors`;
CREATE TABLE `investors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `contact` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `total_capital` decimal(15,2) DEFAULT 0.00,
  `total_expenses` decimal(15,2) DEFAULT 0.00,
  `remaining_balance` decimal(15,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `investors`

INSERT INTO `investors` (`id`, `name`, `contact`, `email`, `total_capital`, `total_expenses`, `remaining_balance`, `notes`, `created_at`, `updated_at`) VALUES ('1', 'Flora', NULL, 'narayanahotelkarimunjawa@gmail.com', '0.00', '0.00', '0.00', NULL, '2026-02-19 10:26:45', '2026-02-19 10:26:45');
INSERT INTO `investors` (`id`, `name`, `contact`, `email`, `total_capital`, `total_expenses`, `remaining_balance`, `notes`, `created_at`, `updated_at`) VALUES ('2', 'Mr Rob', NULL, 'narayanahotelkarimunjawa@gmail.com', '0.00', '0.00', '0.00', NULL, '2026-03-09 10:11:43', '2026-03-09 10:11:43');

-- --------------------------------------------------------
-- Table structure for `login_history`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `login_history`;
CREATE TABLE `login_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `business_id` varchar(100) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `status` enum('success','failed') DEFAULT 'success',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `login_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `logout_time` timestamp NULL DEFAULT NULL,
  `session_duration` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_login_time` (`login_time`),
  KEY `idx_business_id` (`business_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `login_history`

INSERT INTO `login_history` (`id`, `business_id`, `user_id`, `username`, `full_name`, `role`, `status`, `ip_address`, `user_agent`, `login_time`, `logout_time`, `session_duration`) VALUES ('1', 'owner-monitoring', '6', 'owner', 'Busita', 'owner', 'success', NULL, NULL, '2026-02-03 18:34:57', NULL, NULL);

-- --------------------------------------------------------
-- Table structure for `occupancy_log`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `occupancy_log`;
CREATE TABLE `occupancy_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `room_id` int(11) NOT NULL,
  `booking_id` int(11) DEFAULT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_room_id` (`room_id`),
  KEY `idx_changed_at` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_attendance` (`employee_id`,`attendance_date`),
  KEY `idx_date` (`attendance_date`),
  KEY `idx_employee` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `payroll_attendance`

INSERT INTO `payroll_attendance` (`id`, `employee_id`, `attendance_date`, `check_in_time`, `check_in_lat`, `check_in_lng`, `check_in_distance_m`, `check_in_address`, `check_in_device`, `check_out_time`, `check_out_lat`, `check_out_lng`, `check_out_distance_m`, `check_out_device`, `work_hours`, `status`, `is_outside_radius`, `notes`, `created_at`, `updated_at`) VALUES ('1', '16', '2026-03-09', '08:46:36', '-6.5645131', '110.6599077', '44', 'Bandengan, Cikal, Jepara, Central Java, Java, 59417, Indonesia', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Mobile Safari/537.36', '10:00:54', '-6.5646154', '110.6597060', '34', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Mobile Safari/537.36', '1.24', 'present', '0', NULL, '2026-03-09 08:46:36', '2026-03-09 10:00:54');

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
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `payroll_attendance_config`

INSERT INTO `payroll_attendance_config` (`id`, `office_lat`, `office_lng`, `allowed_radius_m`, `office_name`, `checkin_start`, `checkin_end`, `checkout_start`, `allow_outside`, `app_logo`, `updated_at`, `updated_by`) VALUES ('1', '-6.2000000', '106.8166700', '200', 'Kantor', '07:00:00', '10:00:00', '16:00:00', '0', NULL, '2026-03-05 11:41:19', NULL);

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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `payroll_attendance_locations`

INSERT INTO `payroll_attendance_locations` (`id`, `location_name`, `address`, `lat`, `lng`, `radius_m`, `is_active`, `created_at`) VALUES ('1', 'Lucca Resort', 'Bandengan', '-6.5643457', '110.6595427', '200', '1', '2026-03-09 08:43:13');

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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_employee_code` (`employee_code`),
  KEY `idx_active` (`is_active`),
  KEY `idx_position` (`position`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `payroll_employees`

INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`) VALUES ('1', 'EMP-001', 'Sandra Octavia', 'Front Desk', 'Front Office', '165456432102321', NULL, '2026-02-26', '1500000.00', 'BRI', '12425242427424242', '0', NULL, '8', '2026-02-26 10:22:06', '2026-02-26 11:32:13', NULL, NULL);
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`) VALUES ('2', 'EMP-002', 'Muh Arif', 'IT', 'Service', '082245132068', NULL, '2025-05-15', '6500000.00', 'Mandiri', '1380026636659', '1', NULL, '8', '2026-02-26 11:36:04', '2026-02-26 11:36:04', NULL, NULL);
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`) VALUES ('3', 'EMP-003', 'Maulana Ibrahim', 'BAR', 'Service', '087777441354', NULL, '2026-02-26', '3500000.00', 'Mandiri', '1380022898345', '1', NULL, '8', '2026-02-26 11:38:55', '2026-02-26 11:38:55', NULL, NULL);
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`) VALUES ('4', 'EMP-004', 'Sandra Sofi Octaviani', 'Front Desk', 'Front Office', '082134786932', NULL, '2026-02-26', '1800000.00', 'BRI', '758701010434539', '1', NULL, '8', '2026-02-26 11:40:08', '2026-02-26 11:40:08', NULL, NULL);
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`) VALUES ('5', 'EMP-005', 'Katrin Naura', 'Accounting', 'Admin', '05624326802', NULL, '2026-02-26', '2500000.00', 'BRI', '758701011112538', '1', NULL, '8', '2026-02-26 11:42:07', '2026-02-26 11:42:07', NULL, NULL);
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`) VALUES ('6', 'EMP-006', 'Putri Luna Ramadhani', 'Waiter', 'Admin', '081228980859', NULL, '2026-02-26', '1500000.00', 'BRI', '758701011164535', '1', NULL, '8', '2026-02-26 11:44:23', '2026-02-26 11:44:23', NULL, NULL);
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`) VALUES ('7', 'EMP-007', 'Fendy Marlianto', 'Housekeeping', 'Housekeeping', '083121139797', NULL, '2026-02-26', '1600000.00', 'BRI', '303401049734537', '1', NULL, '8', '2026-02-26 11:45:36', '2026-02-26 11:45:36', NULL, NULL);
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`) VALUES ('8', 'EMP-008', 'Kaleb', 'Housekeeping', 'Housekeeping', '0895326238855', NULL, '2026-02-26', '1500000.00', 'BRI', '609201019696532', '1', NULL, '8', '2026-02-26 11:46:53', '2026-02-26 11:46:53', NULL, NULL);
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`) VALUES ('9', 'EMP-009', 'Fenty Evi Renata', 'Cook Helper', 'Kitchen', '085642303712', NULL, '2026-02-26', '1500000.00', 'BRI', '758701011109535', '1', NULL, '8', '2026-02-26 11:47:49', '2026-02-26 11:47:49', NULL, NULL);
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`) VALUES ('10', 'EMP-010', 'Dela Auliya', 'Cook Helper', 'Kitchen', '087725804377', NULL, '2026-02-26', '1800000.00', 'BRI', '758701011163539', '1', NULL, '8', '2026-02-26 11:48:43', '2026-02-26 11:48:43', NULL, NULL);
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`) VALUES ('11', 'EMP-011', 'Sambodo Gilang Setiyaji', 'Security', 'Service', '087829819804', NULL, '2026-02-26', '2000000.00', 'BRI', '589401049746530', '1', NULL, '8', '2026-02-26 11:50:07', '2026-02-26 11:50:07', NULL, NULL);
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`) VALUES ('12', 'EMP-012', 'Nurlaila', 'Housekeeping', 'Housekeeping', '081215655349', NULL, '2026-02-26', '1500000.00', 'BRI', '758701011162533', '1', NULL, '8', '2026-02-26 11:51:15', '2026-02-26 11:51:15', NULL, NULL);
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`) VALUES ('13', 'EMP-013', 'Rizal', 'Waiter', 'Service', '081225383045', NULL, '2026-02-26', '1500000.00', 'Mandiri', '1840011900154', '1', NULL, '8', '2026-02-26 11:52:27', '2026-02-26 11:52:27', NULL, NULL);
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`) VALUES ('14', 'EMP-014', 'Habibi', 'Cook Helper', 'Kitchen', '081244008246', NULL, '2026-02-26', '1500000.00', 'Mandiri', '1740011650447', '1', NULL, '8', '2026-02-26 11:53:22', '2026-02-26 11:53:22', NULL, NULL);
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`) VALUES ('15', 'EMP-015', 'Dony', 'Gardener', 'Service', '082135412690', NULL, '2026-02-26', '1400000.00', 'BRI', '', '1', NULL, '8', '2026-02-26 11:54:10', '2026-02-26 11:54:10', NULL, NULL);
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`) VALUES ('16', 'EMP-016', 'Mb Alya', 'Accounting', 'Admin', '', NULL, '2026-03-01', '3500000.00', 'BCA', '', '1', NULL, '8', '2026-03-01 14:07:50', '2026-03-09 08:46:29', NULL, '[0.018894309177994728,0.1524713933467865,0.03294168785214424,-0.06383530795574188,-0.1787445992231369,0.04049350693821907,-0.08426102995872498,-0.13476060330867767,0.19481469690799713,-0.23575037717819214,0.1805599480867386,-0.05577109009027481,-0.19071808457374573,-0.05842580273747444,-0.054760925471782684,0.2420920431613922,-0.20539702475070953,-0.2233828902244568,0.009577198885381222,-0.024815328419208527,0.015997394919395447,0.0065687247551977634,0.0364975668489933,0.066926009953022,-0.17838361859321594,-0.35051560401916504,-0.10966984182596207,-0.04138554260134697,-0.05805332586169243,-0.11320625245571136,-0.06162126362323761,0.09650570899248123,-0.16758675873279572,0.040745776146650314,-0.03722735494375229,0.08436677604913712,-0.041889239102602005,-0.08950807899236679,0.14845342934131622,0.017957374453544617,-0.285464882850647,0.06416214257478714,0.01799316704273224,0.2983052134513855,0.1890602856874466,0.004647327587008476,0.025484230369329453,-0.13263940811157227,0.11223257333040237,-0.16070400178432465,0.017575711011886597,0.14105212688446045,0.013906694948673248,0.06662223488092422,-0.06104276329278946,-0.12990565598011017,0.033611785620450974,0.06138638034462929,-0.09750455617904663,-0.07285108417272568,0.09249003231525421,-0.053142059594392776,0.0035984208807349205,-0.08634816110134125,0.30771592259407043,0.121245838701725,-0.10633157193660736,-0.1813071370124817,0.12394559383392334,-0.17498724162578583,-0.11647877842187881,0.05021684244275093,-0.1636049896478653,-0.1501684933900833,-0.2966325879096985,-0.01623278483748436,0.39301949739456177,0.12151806056499481,-0.1632954627275467,0.04021916911005974,-0.017580997198820114,0.05988486111164093,0.10537763684988022,0.20855160057544708,-0.06513059884309769,0.0748966857790947,-0.1119251549243927,0.05592958256602287,0.26038312911987305,-0.09179572016000748,-0.07627829164266586,0.21885769069194794,0.037081390619277954,0.0669313445687294,0.057978056371212006,0.03635269030928612,-0.06397612392902374,0.07376077026128769,-0.15315887331962585,0.012243753299117088,0.04197215288877487,-0.026368167251348495,-0.05871958285570145,0.1573217511177063,-0.15460816025733948,0.22516566514968872,-0.0082088029012084,0.027065269649028778,0.006793018896132708,0.06531385332345963,-0.10450217127799988,-0.08057884126901627,0.14802314341068268,-0.2368575930595398,0.13386252522468567,0.15958884358406067,0.056715212762355804,0.15136247873306274,0.1274806410074234,0.15498824417591095,-0.08902431279420853,-0.048353612422943115,-0.21006175875663757,-0.01503694150596857,0.12775303423404694,-0.053738757967948914,0.13198329508304596,0.036707501858472824]');
INSERT INTO `payroll_employees` (`id`, `employee_code`, `full_name`, `position`, `department`, `phone`, `address`, `join_date`, `base_salary`, `bank_name`, `bank_account`, `is_active`, `notes`, `created_by`, `created_at`, `updated_at`, `attendance_pin`, `face_descriptor`) VALUES ('17', 'EMP-017', 'Mb Alya', 'Manager', 'Admin', '', NULL, '2026-03-09', '5000000.00', '', '', '1', NULL, '8', '2026-03-09 08:43:37', '2026-03-09 08:43:37', NULL, NULL);

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

INSERT INTO `payroll_periods` (`id`, `period_month`, `period_year`, `period_label`, `status`, `total_gross`, `total_deductions`, `total_net`, `total_employees`, `submitted_at`, `submitted_by`, `approved_at`, `approved_by`, `paid_at`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('1', '2', '2026', 'Februari 2026', 'submitted', '28630000.00', '0.00', '28630000.00', '15', '2026-02-26 10:24:47', '8', NULL, NULL, NULL, NULL, '8', '2026-02-26 10:22:27', '2026-03-01 14:09:29');

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
  `work_hours` decimal(10,2) NOT NULL DEFAULT 200.00,
  `actual_base` decimal(15,2) NOT NULL DEFAULT 0.00,
  `base_salary` decimal(15,2) NOT NULL DEFAULT 0.00,
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
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `payroll_slips`

INSERT INTO `payroll_slips` (`id`, `period_id`, `employee_id`, `employee_name`, `position`, `work_hours`, `actual_base`, `base_salary`, `overtime_hours`, `overtime_rate`, `overtime_amount`, `incentive`, `allowance`, `bonus`, `other_income`, `deduction_loan`, `deduction_absence`, `deduction_tax`, `deduction_bpjs`, `deduction_other`, `total_earnings`, `total_deductions`, `net_salary`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('2', '1', '2', 'Muh Arif', 'IT', '200.00', '6500000.00', '6500000.00', '0.00', '32500.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '6500000.00', '0.00', '6500000.00', NULL, NULL, '2026-02-26 12:03:32', '2026-02-26 12:06:34');
INSERT INTO `payroll_slips` (`id`, `period_id`, `employee_id`, `employee_name`, `position`, `work_hours`, `actual_base`, `base_salary`, `overtime_hours`, `overtime_rate`, `overtime_amount`, `incentive`, `allowance`, `bonus`, `other_income`, `deduction_loan`, `deduction_absence`, `deduction_tax`, `deduction_bpjs`, `deduction_other`, `total_earnings`, `total_deductions`, `net_salary`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('3', '1', '3', 'Maulana Ibrahim', 'BAR', '200.00', '3500000.00', '3500000.00', '0.00', '17500.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '3500000.00', '0.00', '3500000.00', NULL, NULL, '2026-02-26 12:03:32', '2026-02-26 12:06:31');
INSERT INTO `payroll_slips` (`id`, `period_id`, `employee_id`, `employee_name`, `position`, `work_hours`, `actual_base`, `base_salary`, `overtime_hours`, `overtime_rate`, `overtime_amount`, `incentive`, `allowance`, `bonus`, `other_income`, `deduction_loan`, `deduction_absence`, `deduction_tax`, `deduction_bpjs`, `deduction_other`, `total_earnings`, `total_deductions`, `net_salary`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('4', '1', '4', 'Sandra Sofi Octaviani', 'Front Desk', '200.00', '1800000.00', '1800000.00', '0.00', '9000.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '1800000.00', '0.00', '1800000.00', NULL, NULL, '2026-02-26 12:03:32', '2026-02-26 12:16:56');
INSERT INTO `payroll_slips` (`id`, `period_id`, `employee_id`, `employee_name`, `position`, `work_hours`, `actual_base`, `base_salary`, `overtime_hours`, `overtime_rate`, `overtime_amount`, `incentive`, `allowance`, `bonus`, `other_income`, `deduction_loan`, `deduction_absence`, `deduction_tax`, `deduction_bpjs`, `deduction_other`, `total_earnings`, `total_deductions`, `net_salary`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('5', '1', '5', 'Katrin Naura', 'Accounting', '100.00', '1250000.00', '2500000.00', '0.00', '12500.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '1250000.00', '0.00', '1250000.00', NULL, NULL, '2026-02-26 12:03:32', '2026-03-01 09:42:15');
INSERT INTO `payroll_slips` (`id`, `period_id`, `employee_id`, `employee_name`, `position`, `work_hours`, `actual_base`, `base_salary`, `overtime_hours`, `overtime_rate`, `overtime_amount`, `incentive`, `allowance`, `bonus`, `other_income`, `deduction_loan`, `deduction_absence`, `deduction_tax`, `deduction_bpjs`, `deduction_other`, `total_earnings`, `total_deductions`, `net_salary`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('6', '1', '6', 'Putri Luna Ramadhani', 'Waiter', '112.00', '840000.00', '1500000.00', '0.00', '7500.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '840000.00', '0.00', '840000.00', NULL, NULL, '2026-02-26 12:03:32', '2026-03-01 09:43:12');
INSERT INTO `payroll_slips` (`id`, `period_id`, `employee_id`, `employee_name`, `position`, `work_hours`, `actual_base`, `base_salary`, `overtime_hours`, `overtime_rate`, `overtime_amount`, `incentive`, `allowance`, `bonus`, `other_income`, `deduction_loan`, `deduction_absence`, `deduction_tax`, `deduction_bpjs`, `deduction_other`, `total_earnings`, `total_deductions`, `net_salary`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('7', '1', '7', 'Fendy Marlianto', 'Housekeeping', '200.00', '1600000.00', '1600000.00', '0.00', '8000.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '1600000.00', '0.00', '1600000.00', NULL, NULL, '2026-02-26 12:03:32', '2026-02-26 12:06:20');
INSERT INTO `payroll_slips` (`id`, `period_id`, `employee_id`, `employee_name`, `position`, `work_hours`, `actual_base`, `base_salary`, `overtime_hours`, `overtime_rate`, `overtime_amount`, `incentive`, `allowance`, `bonus`, `other_income`, `deduction_loan`, `deduction_absence`, `deduction_tax`, `deduction_bpjs`, `deduction_other`, `total_earnings`, `total_deductions`, `net_salary`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('8', '1', '8', 'Kaleb', 'Housekeeping', '201.00', '1500000.00', '1500000.00', '0.00', '7500.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '1500000.00', '0.00', '1500000.00', NULL, NULL, '2026-02-26 12:03:32', '2026-03-01 13:48:20');
INSERT INTO `payroll_slips` (`id`, `period_id`, `employee_id`, `employee_name`, `position`, `work_hours`, `actual_base`, `base_salary`, `overtime_hours`, `overtime_rate`, `overtime_amount`, `incentive`, `allowance`, `bonus`, `other_income`, `deduction_loan`, `deduction_absence`, `deduction_tax`, `deduction_bpjs`, `deduction_other`, `total_earnings`, `total_deductions`, `net_salary`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('9', '1', '9', 'Fenty Evi Renata', 'Cook Helper', '100.00', '750000.00', '1500000.00', '0.00', '7500.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '750000.00', '0.00', '750000.00', NULL, NULL, '2026-02-26 12:03:32', '2026-03-01 13:48:13');
INSERT INTO `payroll_slips` (`id`, `period_id`, `employee_id`, `employee_name`, `position`, `work_hours`, `actual_base`, `base_salary`, `overtime_hours`, `overtime_rate`, `overtime_amount`, `incentive`, `allowance`, `bonus`, `other_income`, `deduction_loan`, `deduction_absence`, `deduction_tax`, `deduction_bpjs`, `deduction_other`, `total_earnings`, `total_deductions`, `net_salary`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('10', '1', '10', 'Dela Auliya', 'Cook Helper', '100.00', '900000.00', '1800000.00', '0.00', '9000.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '900000.00', '0.00', '900000.00', NULL, NULL, '2026-02-26 12:03:32', '2026-03-01 13:48:28');
INSERT INTO `payroll_slips` (`id`, `period_id`, `employee_id`, `employee_name`, `position`, `work_hours`, `actual_base`, `base_salary`, `overtime_hours`, `overtime_rate`, `overtime_amount`, `incentive`, `allowance`, `bonus`, `other_income`, `deduction_loan`, `deduction_absence`, `deduction_tax`, `deduction_bpjs`, `deduction_other`, `total_earnings`, `total_deductions`, `net_salary`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('11', '1', '11', 'Sambodo Gilang Setiyaji', 'Security', '200.00', '2000000.00', '2000000.00', '0.00', '10000.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '2000000.00', '0.00', '2000000.00', NULL, NULL, '2026-02-26 12:03:32', '2026-02-26 12:16:51');
INSERT INTO `payroll_slips` (`id`, `period_id`, `employee_id`, `employee_name`, `position`, `work_hours`, `actual_base`, `base_salary`, `overtime_hours`, `overtime_rate`, `overtime_amount`, `incentive`, `allowance`, `bonus`, `other_income`, `deduction_loan`, `deduction_absence`, `deduction_tax`, `deduction_bpjs`, `deduction_other`, `total_earnings`, `total_deductions`, `net_salary`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('12', '1', '12', 'Nurlaila', 'Housekeeping', '200.00', '1500000.00', '1500000.00', '0.00', '7500.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '1500000.00', '0.00', '1500000.00', NULL, NULL, '2026-02-26 12:03:32', '2026-03-01 09:45:55');
INSERT INTO `payroll_slips` (`id`, `period_id`, `employee_id`, `employee_name`, `position`, `work_hours`, `actual_base`, `base_salary`, `overtime_hours`, `overtime_rate`, `overtime_amount`, `incentive`, `allowance`, `bonus`, `other_income`, `deduction_loan`, `deduction_absence`, `deduction_tax`, `deduction_bpjs`, `deduction_other`, `total_earnings`, `total_deductions`, `net_salary`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('13', '1', '13', 'Rizal', 'Waiter', '112.00', '840000.00', '1500000.00', '0.00', '7500.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '840000.00', '0.00', '840000.00', NULL, NULL, '2026-02-26 12:03:32', '2026-03-01 09:43:52');
INSERT INTO `payroll_slips` (`id`, `period_id`, `employee_id`, `employee_name`, `position`, `work_hours`, `actual_base`, `base_salary`, `overtime_hours`, `overtime_rate`, `overtime_amount`, `incentive`, `allowance`, `bonus`, `other_income`, `deduction_loan`, `deduction_absence`, `deduction_tax`, `deduction_bpjs`, `deduction_other`, `total_earnings`, `total_deductions`, `net_salary`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('14', '1', '14', 'Habibi', 'Cook Helper', '100.00', '750000.00', '1500000.00', '0.00', '7500.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '750000.00', '0.00', '750000.00', NULL, NULL, '2026-02-26 12:03:32', '2026-03-01 13:48:20');
INSERT INTO `payroll_slips` (`id`, `period_id`, `employee_id`, `employee_name`, `position`, `work_hours`, `actual_base`, `base_salary`, `overtime_hours`, `overtime_rate`, `overtime_amount`, `incentive`, `allowance`, `bonus`, `other_income`, `deduction_loan`, `deduction_absence`, `deduction_tax`, `deduction_bpjs`, `deduction_other`, `total_earnings`, `total_deductions`, `net_salary`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('15', '1', '15', 'Dony', 'Gardener', '200.00', '1400000.00', '1400000.00', '0.00', '7000.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '1400000.00', '0.00', '1400000.00', NULL, NULL, '2026-02-26 12:03:32', '2026-02-26 12:06:17');
INSERT INTO `payroll_slips` (`id`, `period_id`, `employee_id`, `employee_name`, `position`, `work_hours`, `actual_base`, `base_salary`, `overtime_hours`, `overtime_rate`, `overtime_amount`, `incentive`, `allowance`, `bonus`, `other_income`, `deduction_loan`, `deduction_absence`, `deduction_tax`, `deduction_bpjs`, `deduction_other`, `total_earnings`, `total_deductions`, `net_salary`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES ('16', '1', '16', 'Mb Alya', 'Accounting', '200.00', '3500000.00', '3500000.00', '0.00', '17500.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '3500000.00', '0.00', '3500000.00', NULL, NULL, '2026-03-01 14:09:11', '2026-03-01 14:09:32');

-- --------------------------------------------------------
-- Table structure for `project_balances`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `project_balances`;
CREATE TABLE `project_balances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `total_budget_idr` decimal(15,2) DEFAULT 0.00,
  `total_expenses_idr` decimal(15,2) DEFAULT 0.00,
  `remaining_balance_idr` decimal(15,2) DEFAULT 0.00,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_project` (`project_id`),
  CONSTRAINT `project_balances_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `project_balances`

INSERT INTO `project_balances` (`id`, `project_id`, `total_budget_idr`, `total_expenses_idr`, `remaining_balance_idr`, `last_updated`) VALUES ('1', '1', '0.00', '0.00', '0.00', '2026-01-25 18:59:03');

-- --------------------------------------------------------
-- Table structure for `project_contractors`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `project_contractors`;
CREATE TABLE `project_contractors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `bidang` varchar(100) DEFAULT '',
  `pic_name` varchar(100) DEFAULT '',
  `phone` varchar(20) DEFAULT '',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Dumping data for table `project_contractors`

INSERT INTO `project_contractors` (`id`, `project_id`, `name`, `bidang`, `pic_name`, `phone`, `status`, `created_at`) VALUES ('1', '1', 'P.MOYONG', 'Sipil', 'PAK MOYONG', '081333323155', 'active', '2026-02-16 19:23:00');
INSERT INTO `project_contractors` (`id`, `project_id`, `name`, `bidang`, `pic_name`, `phone`, `status`, `created_at`) VALUES ('2', '1', 'PAK HABIBI', 'Besi/Las', 'HABIBI', '081222228590', 'active', '2026-02-16 19:25:12');
INSERT INTO `project_contractors` (`id`, `project_id`, `name`, `bidang`, `pic_name`, `phone`, `status`, `created_at`) VALUES ('3', '1', 'PAK IPIN', 'Atap', 'IPIN', '+33 2035640799', 'active', '2026-02-16 19:25:31');
INSERT INTO `project_contractors` (`id`, `project_id`, `name`, `bidang`, `pic_name`, `phone`, `status`, `created_at`) VALUES ('4', '1', 'PAK MIN', 'Listrik', 'PAK MIN', '82332109996', 'active', '2026-02-16 19:25:45');

-- --------------------------------------------------------
-- Table structure for `project_division_expenses`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `project_division_expenses`;
CREATE TABLE `project_division_expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `division_name` varchar(100) NOT NULL,
  `contractor_name` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `expense_date` date DEFAULT NULL,
  `receipt_file` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','paid') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------
-- Table structure for `project_expenses`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `project_expenses`;
CREATE TABLE `project_expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `division_name` varchar(100) DEFAULT NULL,
  `amount_usd` decimal(15,2) NOT NULL DEFAULT 0.00,
  `amount_idr` decimal(15,2) NOT NULL DEFAULT 0.00,
  `exchange_rate` decimal(10,2) DEFAULT 15500.00,
  `amount` decimal(15,2) NOT NULL,
  `expense_date` date DEFAULT NULL,
  `receipt_number` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `project_expenses_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `project_expenses`

INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('6', '1', NULL, 'Fee Moyong Projek', 'P.MOYONG', '0.00', '0.00', '15500.00', '8296000.00', '2026-01-01', NULL, '2026-02-19 10:36:57', '2026-02-19 10:36:57');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('7', '1', NULL, 'Material New Room Narayana', 'PAK HABIBI', '0.00', '0.00', '15500.00', '10000000.00', '2026-01-01', NULL, '2026-02-19 10:37:43', '2026-02-19 10:37:43');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('8', '1', NULL, 'Material P Hotel Roby', 'Roby K', '0.00', '0.00', '15500.00', '10000000.00', '2026-01-08', NULL, '2026-02-19 10:39:37', '2026-02-19 10:39:37');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('9', '1', NULL, 'Cat Narayana Cugoku Paint', 'PAK IPIN', '0.00', '0.00', '15500.00', '3074750.00', '2026-01-09', NULL, '2026-02-19 10:40:39', '2026-02-19 10:40:39');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('10', '1', NULL, 'Moyong Projek', 'P.MOYONG', '0.00', '0.00', '15500.00', '20940000.00', '2026-01-09', NULL, '2026-02-19 10:41:20', '2026-02-19 10:41:20');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('11', '1', NULL, 'Material new room Narayaana', 'PAK HABIBI', '0.00', '0.00', '15500.00', '10000000.00', '2026-01-11', NULL, '2026-02-19 10:41:55', '2026-02-19 10:41:55');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('12', '1', NULL, 'Pelunasan Gambar 3D narayana', 'Raditya Ramadhan', '0.00', '0.00', '15500.00', '7000000.00', '2026-01-02', NULL, '2026-02-19 10:42:36', '2026-02-19 10:42:36');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('13', '1', NULL, 'Tiner Atap (Darmayatindo)', 'PAK HABIBI', '0.00', '0.00', '15500.00', '650000.00', '2026-01-05', NULL, '2026-02-19 10:43:13', '2026-02-19 10:43:13');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('14', '1', NULL, 'Material Habibi (Mojo Indah Jepara)', 'PAK HABIBI', '0.00', '0.00', '15500.00', '470000.00', '2026-01-06', NULL, '2026-02-19 10:43:56', '2026-02-19 10:43:56');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('15', '1', NULL, 'Material Electrik New room', 'PAK MIN', '0.00', '0.00', '15500.00', '24924000.00', '2026-01-13', NULL, '2026-02-19 10:44:31', '2026-02-19 10:44:31');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('16', '1', NULL, 'Material Bataringan (Muh Nasidin )', 'P.MOYONG', '0.00', '0.00', '15500.00', '1200000.00', '2026-01-14', NULL, '2026-02-19 10:45:04', '2026-02-19 10:45:04');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('17', '1', NULL, 'Material habibi', 'PAK HABIBI', '0.00', '0.00', '15500.00', '15000000.00', '2026-01-15', NULL, '2026-02-19 10:45:26', '2026-02-19 10:45:26');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('18', '1', NULL, 'Moyong Projek', 'P.MOYONG', '0.00', '0.00', '15500.00', '11555000.00', '2026-01-17', NULL, '2026-02-19 10:46:14', '2026-02-19 10:46:14');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('19', '1', NULL, 'Material New room Narayana', 'PAK HABIBI', '0.00', '0.00', '15500.00', '7000000.00', '2026-01-19', NULL, '2026-02-19 10:46:47', '2026-02-19 10:46:47');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('21', '1', NULL, 'Bayar harbel (Muh nasidin)', 'P.MOYONG', '0.00', '0.00', '15500.00', '275000.00', '2026-01-19', NULL, '2026-02-19 10:49:16', '2026-02-19 10:49:16');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('22', '1', NULL, 'Kabel Wifi', 'Arief', '0.00', '0.00', '15500.00', '1500000.00', '2026-01-19', NULL, '2026-02-19 10:49:45', '2026-02-19 10:49:45');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('23', '1', NULL, 'Bunga Taman', 'Taman', '0.00', '0.00', '15500.00', '2250000.00', '2026-01-19', NULL, '2026-02-19 10:50:20', '2026-02-19 10:50:20');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('24', '1', NULL, 'Exhousefan 4', 'Indo cool', '0.00', '0.00', '15500.00', '1400000.00', '2026-01-19', NULL, '2026-02-19 10:50:57', '2026-02-19 10:50:57');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('25', '1', NULL, 'Saklar narayana', 'PAK MIN', '0.00', '0.00', '15500.00', '1102500.00', '2026-01-20', NULL, '2026-02-19 10:51:24', '2026-02-19 10:51:24');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('27', '1', NULL, 'Resin Justus', 'PAK IPIN', '0.00', '0.00', '15500.00', '467000.00', '2026-01-20', NULL, '2026-02-19 10:52:40', '2026-02-19 10:52:40');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('28', '1', NULL, 'Cat Oil Narayana Utama Pain', 'PAK IPIN', '0.00', '0.00', '15500.00', '1185000.00', '2026-01-19', NULL, '2026-02-19 10:54:01', '2026-02-19 10:54:01');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('29', '1', NULL, 'Besi Narayana Anik', 'P.MOYONG', '0.00', '0.00', '15500.00', '797500.00', '2026-01-20', NULL, '2026-02-19 10:54:47', '2026-02-19 10:54:47');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('30', '1', NULL, 'New room Narayana (amalia)', 'Amalia', '0.00', '0.00', '15500.00', '1365000.00', '2026-01-20', NULL, '2026-02-19 10:55:25', '2026-02-19 10:55:25');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('31', '1', NULL, 'Tiner Atap (Alfin)', 'PAK IPIN', '0.00', '0.00', '15500.00', '580500.00', '2026-01-23', NULL, '2026-02-19 10:56:05', '2026-02-19 10:56:05');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('32', '1', NULL, 'Moyong Projek', 'P.MOYONG', '0.00', '0.00', '15500.00', '10520000.00', '2026-01-24', NULL, '2026-02-19 10:56:37', '2026-02-19 10:56:37');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('33', '1', NULL, 'Material Narayana', 'PAK HABIBI', '0.00', '0.00', '15500.00', '10000000.00', '2026-01-24', NULL, '2026-02-19 10:56:59', '2026-02-19 10:56:59');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('35', '1', NULL, 'Pajak  Truck Merah (Suwarno)', 'PAJAK', '0.00', '0.00', '15500.00', '5002500.00', '2026-01-26', NULL, '2026-02-19 10:59:46', '2026-02-19 10:59:46');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('36', '1', NULL, 'Rell Gorden (Asep)', 'Asep', '0.00', '0.00', '15500.00', '1950000.00', '2026-01-27', NULL, '2026-02-19 11:00:28', '2026-02-19 11:00:28');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('37', '1', NULL, 'Narayana Atap (FAFA Lanang)', 'PAK HABIBI', '0.00', '0.00', '15500.00', '7018000.00', '2026-01-27', NULL, '2026-02-19 11:01:09', '2026-02-19 11:01:09');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('38', '1', NULL, 'New ROOM narayana (Amalia)', 'Amalia', '0.00', '0.00', '15500.00', '1343300.00', '2026-01-30', NULL, '2026-02-19 11:01:42', '2026-02-19 11:01:42');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('39', '1', NULL, 'Cat Atap Narayana', 'PAK IPIN', '0.00', '0.00', '15500.00', '2916250.00', '2026-01-30', NULL, '2026-02-19 11:02:18', '2026-02-19 11:02:18');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('40', '1', NULL, 'Material Robi K', 'P.MOYONG', '0.00', '0.00', '15500.00', '15000000.00', '2026-01-30', NULL, '2026-02-19 11:02:50', '2026-02-19 11:02:50');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('41', '1', NULL, 'Moyong Projek', 'P.MOYONG', '0.00', '0.00', '15500.00', '14929500.00', '2026-01-31', NULL, '2026-02-19 11:03:22', '2026-02-19 11:03:22');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('42', '1', NULL, 'Pool Mountly desember', 'Pool', '0.00', '0.00', '15500.00', '1549500.00', '2026-01-31', NULL, '2026-02-19 11:10:36', '2026-02-19 11:10:36');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('44', '1', NULL, 'Cat Atap Narayana', 'PAK IPIN', '0.00', '0.00', '15500.00', '2913750.00', '2026-02-02', NULL, '2026-02-19 11:11:56', '2026-02-19 11:11:56');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('45', '1', NULL, 'Material new room Narayaana Roby', 'P.MOYONG', '0.00', '0.00', '15500.00', '10000000.00', '2026-02-02', NULL, '2026-02-19 11:12:24', '2026-02-19 11:12:24');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('46', '1', NULL, 'Material new room Narayaana Sarifah', 'Sarifah', '0.00', '0.00', '15500.00', '3212500.00', '2026-02-03', NULL, '2026-02-19 11:12:50', '2026-02-19 11:12:50');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('47', '1', NULL, 'Plat Bordes Narayana (Anugrah Tri tunggal)', 'PAK HABIBI', '0.00', '0.00', '15500.00', '2598600.00', '2026-02-04', NULL, '2026-02-19 11:13:29', '2026-02-19 11:13:29');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('48', '1', NULL, 'material Cabinet Nrayana', 'PAK HABIBI', '0.00', '0.00', '15500.00', '1370000.00', '2026-02-05', NULL, '2026-02-19 11:13:59', '2026-02-19 11:13:59');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('49', '1', NULL, 'Kulkas Narayana Indo Cool', 'Indor', '0.00', '0.00', '15500.00', '4600000.00', '2026-02-05', NULL, '2026-02-19 11:14:31', '2026-02-19 11:14:31');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('50', '1', NULL, 'Wall Panel Narayana (Ali Maksum)', 'PAK HABIBI', '0.00', '0.00', '15500.00', '7013000.00', '2026-02-05', NULL, '2026-02-19 11:15:12', '2026-02-19 11:15:12');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('51', '1', NULL, 'Fee Atap Projek Narayana (Amalia)', 'Amalia', '0.00', '0.00', '15500.00', '6781500.00', '2026-02-06', NULL, '2026-02-19 11:15:45', '2026-02-19 11:15:45');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('52', '1', NULL, 'Kabel lISTRIK (Andi P)', 'PAK MIN', '0.00', '0.00', '15500.00', '8475000.00', '2026-02-06', NULL, '2026-02-19 11:16:12', '2026-02-19 11:16:12');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('53', '1', NULL, 'Dempul +secrap set PT,Darma Yatin', 'PAK IPIN', '0.00', '0.00', '15500.00', '1388000.00', '2026-02-06', NULL, '2026-02-19 11:16:43', '2026-02-19 11:16:43');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('54', '1', NULL, 'AC Hairdrayer,Teki Listrik Set Narayana Indocool', 'Indor', '0.00', '0.00', '15500.00', '26030500.00', '2026-02-06', NULL, '2026-02-19 11:17:29', '2026-02-19 11:17:29');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('55', '1', NULL, 'Bahan Pembuatan Cabinet Meja Room Puji retno S', 'Puji R', '0.00', '0.00', '15500.00', '2890000.00', '2026-02-10', NULL, '2026-02-19 11:18:06', '2026-02-19 11:18:06');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('56', '1', NULL, 'Kran Bathroom Narayana (Sarifah)', 'indor', '0.00', '0.00', '15500.00', '1400000.00', '2026-02-10', NULL, '2026-02-19 11:18:51', '2026-02-19 11:18:51');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('57', '1', NULL, 'Belanja material Narayana Amalia', 'Amalia', '0.00', '0.00', '15500.00', '1000000.00', '2026-02-10', NULL, '2026-02-19 11:19:18', '2026-02-19 11:19:18');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('58', '1', NULL, 'Material Atap Narayana Amalia', 'AMALIA', '0.00', '0.00', '15500.00', '6421800.00', '2026-02-13', NULL, '2026-02-19 11:19:57', '2026-02-19 11:19:57');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('59', '1', NULL, 'gaji Team P Ipin', 'PAK IPIN', '0.00', '0.00', '15500.00', '7092000.00', '2026-02-15', NULL, '2026-02-19 11:23:53', '2026-02-19 11:23:53');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('60', '1', NULL, 'Tiket Pulang 3 Orang Team P ipin', 'PAK IPIN', '0.00', '0.00', '15500.00', '317220.00', '2026-02-21', NULL, '2026-02-21 08:31:17', '2026-02-21 08:31:17');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('61', '1', NULL, 'Bayar Cat Narayana', 'PAK IPIN', '0.00', '0.00', '15500.00', '2111500.00', '2026-02-21', NULL, '2026-02-21 08:31:46', '2026-02-21 08:31:46');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('62', '1', NULL, 'Belanja Narayana', 'Alya', '0.00', '0.00', '15500.00', '1000000.00', '2026-02-21', NULL, '2026-02-21 08:32:57', '2026-02-21 08:32:57');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('63', '1', NULL, 'Belanja Narayana', 'Alya', '0.00', '0.00', '15500.00', '2002500.00', '2026-02-21', NULL, '2026-02-21 08:35:04', '2026-02-21 08:35:04');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('64', '1', NULL, 'Belanja Narayana', 'Alya', '0.00', '0.00', '15500.00', '2002500.00', '2026-02-21', NULL, '2026-02-21 08:35:25', '2026-02-21 08:35:25');
INSERT INTO `project_expenses` (`id`, `project_id`, `category`, `description`, `division_name`, `amount_usd`, `amount_idr`, `exchange_rate`, `amount`, `expense_date`, `receipt_number`, `created_at`, `updated_at`) VALUES ('65', '1', NULL, 'Tiket Tukang P Habibi 3 Orang', 'PAK HABIBI', '0.00', '0.00', '15500.00', '643540.00', '2026-02-21', NULL, '2026-02-21 08:35:55', '2026-02-21 08:35:55');

-- --------------------------------------------------------
-- Table structure for `project_salaries`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `project_salaries`;
CREATE TABLE `project_salaries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `worker_id` int(11) NOT NULL,
  `period_type` enum('weekly','monthly') DEFAULT 'weekly',
  `period_label` varchar(50) DEFAULT NULL,
  `daily_rate` decimal(15,2) DEFAULT 0.00,
  `overtime_per_day` decimal(15,2) DEFAULT 0.00,
  `other_per_day` decimal(15,2) DEFAULT 0.00,
  `total_days` int(11) DEFAULT 0,
  `total_salary` decimal(15,2) DEFAULT 0.00,
  `status` enum('draft','submitted','approved','paid') DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Dumping data for table `project_salaries`

INSERT INTO `project_salaries` (`id`, `project_id`, `worker_id`, `period_type`, `period_label`, `daily_rate`, `overtime_per_day`, `other_per_day`, `total_days`, `total_salary`, `status`, `notes`, `created_at`, `created_by`) VALUES ('1', '1', '3', 'weekly', '1-FEBRUARI', '95000.00', '0.00', '150000.00', '6', '1470000.00', 'draft', '', '2026-02-19 11:22:09', NULL);

-- --------------------------------------------------------
-- Table structure for `project_workers`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `project_workers`;
CREATE TABLE `project_workers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `role` varchar(100) DEFAULT 'Tukang',
  `daily_rate` decimal(15,2) DEFAULT 0.00,
  `phone` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Dumping data for table `project_workers`

INSERT INTO `project_workers` (`id`, `project_id`, `name`, `role`, `daily_rate`, `phone`, `status`, `created_at`) VALUES ('1', '1', 'PAK MOYONG', 'Mandor', '200000.00', '081222228590', 'active', '2026-02-16 19:26:10');
INSERT INTO `project_workers` (`id`, `project_id`, `name`, `role`, `daily_rate`, `phone`, `status`, `created_at`) VALUES ('2', '1', 'PAK MIN', 'Tukang', '50000.00', '', 'active', '2026-02-16 19:26:38');
INSERT INTO `project_workers` (`id`, `project_id`, `name`, `role`, `daily_rate`, `phone`, `status`, `created_at`) VALUES ('3', '1', 'PAK IPIN', 'Tukang', '95000.00', '', 'active', '2026-02-19 11:21:04');

-- --------------------------------------------------------
-- Table structure for `projects`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `projects`;
CREATE TABLE `projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `budget` decimal(15,2) DEFAULT 0.00,
  `total_expenses` decimal(15,2) DEFAULT 0.00,
  `status` enum('planning','active','completed','cancelled') DEFAULT 'planning',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `projects`

INSERT INTO `projects` (`id`, `name`, `description`, `budget`, `total_expenses`, `status`, `start_date`, `end_date`, `created_at`, `updated_at`) VALUES ('1', 'Narayana Hotel G4', '', '500000000.00', '10000000.00', 'active', '2025-12-25', '2026-03-01', '2026-01-25 18:59:03', '2026-01-25 19:08:37');

-- --------------------------------------------------------
-- Table structure for `purchase_orders_detail`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `purchase_orders_detail`;
CREATE TABLE `purchase_orders_detail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_header_id` int(11) NOT NULL,
  `line_number` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_description` text DEFAULT NULL,
  `unit_of_measure` varchar(50) DEFAULT 'pcs',
  `quantity` decimal(15,2) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `subtotal` decimal(15,2) NOT NULL,
  `division_id` int(11) NOT NULL COMMENT 'Cost Center',
  `received_quantity` decimal(15,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_po_header` (`po_header_id`),
  KEY `idx_division` (`division_id`),
  CONSTRAINT `purchase_orders_detail_ibfk_1` FOREIGN KEY (`po_header_id`) REFERENCES `purchase_orders_header` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_orders_detail_ibfk_2` FOREIGN KEY (`division_id`) REFERENCES `divisions` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `purchase_orders_header`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `purchase_orders_header`;
CREATE TABLE `purchase_orders_header` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_number` varchar(50) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `po_date` date NOT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `status` enum('draft','submitted','approved','rejected','partially_received','completed','cancelled') DEFAULT 'draft',
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `grand_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `po_number` (`po_number`),
  KEY `approved_by` (`approved_by`),
  KEY `created_by` (`created_by`),
  KEY `idx_po_date` (`po_date`),
  KEY `idx_status` (`status`),
  KEY `idx_supplier` (`supplier_id`),
  CONSTRAINT `purchase_orders_header_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `purchase_orders_header_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_header_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `roles`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  `role_code` varchar(20) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_name` (`role_name`),
  UNIQUE KEY `role_code` (`role_code`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `roles`

INSERT INTO `roles` (`id`, `role_name`, `role_code`, `description`, `created_at`) VALUES ('1', 'Owner', 'owner', 'Business owner with full access', '2026-02-08 17:59:28');
INSERT INTO `roles` (`id`, `role_name`, `role_code`, `description`, `created_at`) VALUES ('2', 'Developer', 'developer', 'System developer with full access', '2026-02-08 17:59:28');
INSERT INTO `roles` (`id`, `role_name`, `role_code`, `description`, `created_at`) VALUES ('3', 'Admin', 'admin', 'Administrator', '2026-02-08 17:59:28');
INSERT INTO `roles` (`id`, `role_name`, `role_code`, `description`, `created_at`) VALUES ('4', 'Frontdesk', 'frontdesk', 'Front desk staff', '2026-02-08 17:59:28');
INSERT INTO `roles` (`id`, `role_name`, `role_code`, `description`, `created_at`) VALUES ('5', 'Manager', 'manager', 'Manager access', '2026-02-08 17:59:28');

-- --------------------------------------------------------
-- Table structure for `room_types`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `room_types`;
CREATE TABLE `room_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `base_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `max_occupancy` int(11) NOT NULL DEFAULT 2,
  `amenities` text DEFAULT NULL,
  `color_code` varchar(7) DEFAULT '#6366f1',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `room_types`

INSERT INTO `room_types` (`id`, `type_name`, `description`, `base_price`, `max_occupancy`, `amenities`, `color_code`, `created_at`, `updated_at`) VALUES ('5', 'King', NULL, '1450000.00', '4', 'Ac,Wifi Tv', '#6366f1', '2026-01-30 22:01:09', '2026-02-23 13:01:27');
INSERT INTO `room_types` (`id`, `type_name`, `description`, `base_price`, `max_occupancy`, `amenities`, `color_code`, `created_at`, `updated_at`) VALUES ('6', 'Queen', NULL, '950000.00', '3', 'Tv,Ac,Watter Heater', '#6366f1', '2026-01-30 22:02:07', '2026-02-23 13:01:27');
INSERT INTO `room_types` (`id`, `type_name`, `description`, `base_price`, `max_occupancy`, `amenities`, `color_code`, `created_at`, `updated_at`) VALUES ('7', 'Twin', NULL, '950000.00', '3', 'AC,TV', '#6366f1', '2026-01-30 22:02:41', '2026-02-23 13:01:27');

-- --------------------------------------------------------
-- Table structure for `rooms`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `rooms`;
CREATE TABLE `rooms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `room_number` varchar(20) NOT NULL,
  `room_type_id` int(11) NOT NULL,
  `floor_number` int(11) NOT NULL DEFAULT 1,
  `status` enum('available','occupied','cleaning','maintenance','blocked') DEFAULT 'available',
  `current_guest_id` int(11) DEFAULT NULL COMMENT 'ID guest yang sedang menginap',
  `notes` text DEFAULT NULL,
  `position_x` int(11) DEFAULT 0,
  `position_y` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_number` (`room_number`),
  KEY `room_type_id` (`room_type_id`),
  KEY `idx_status` (`status`),
  KEY `idx_floor` (`floor_number`),
  KEY `current_guest_id` (`current_guest_id`),
  CONSTRAINT `rooms_ibfk_1` FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rooms_ibfk_2` FOREIGN KEY (`current_guest_id`) REFERENCES `guests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `rooms`

INSERT INTO `rooms` (`id`, `room_number`, `room_type_id`, `floor_number`, `status`, `current_guest_id`, `notes`, `position_x`, `position_y`, `created_at`, `updated_at`) VALUES ('36', '102', '5', '1', 'occupied', '38', NULL, '0', '0', '2026-02-07 21:04:34', '2026-02-16 22:27:18');
INSERT INTO `rooms` (`id`, `room_number`, `room_type_id`, `floor_number`, `status`, `current_guest_id`, `notes`, `position_x`, `position_y`, `created_at`, `updated_at`) VALUES ('37', '103', '5', '1', 'available', NULL, NULL, '0', '0', '2026-02-07 21:04:34', '2026-02-11 19:56:49');
INSERT INTO `rooms` (`id`, `room_number`, `room_type_id`, `floor_number`, `status`, `current_guest_id`, `notes`, `position_x`, `position_y`, `created_at`, `updated_at`) VALUES ('38', '104', '5', '1', 'occupied', '39', NULL, '0', '0', '2026-02-07 21:04:34', '2026-02-16 22:28:29');
INSERT INTO `rooms` (`id`, `room_number`, `room_type_id`, `floor_number`, `status`, `current_guest_id`, `notes`, `position_x`, `position_y`, `created_at`, `updated_at`) VALUES ('40', '202', '7', '2', 'available', NULL, NULL, '0', '0', '2026-02-07 21:04:34', '2026-02-16 14:50:35');
INSERT INTO `rooms` (`id`, `room_number`, `room_type_id`, `floor_number`, `status`, `current_guest_id`, `notes`, `position_x`, `position_y`, `created_at`, `updated_at`) VALUES ('41', '203', '7', '2', 'available', NULL, NULL, '0', '0', '2026-02-07 21:04:34', '2026-02-08 10:04:46');
INSERT INTO `rooms` (`id`, `room_number`, `room_type_id`, `floor_number`, `status`, `current_guest_id`, `notes`, `position_x`, `position_y`, `created_at`, `updated_at`) VALUES ('42', '204', '6', '2', 'available', NULL, NULL, '0', '0', '2026-02-07 21:04:34', '2026-02-16 20:04:59');
INSERT INTO `rooms` (`id`, `room_number`, `room_type_id`, `floor_number`, `status`, `current_guest_id`, `notes`, `position_x`, `position_y`, `created_at`, `updated_at`) VALUES ('43', '105', '5', '1', 'available', NULL, NULL, '0', '0', '2026-02-08 10:03:18', '2026-03-06 10:17:52');
INSERT INTO `rooms` (`id`, `room_number`, `room_type_id`, `floor_number`, `status`, `current_guest_id`, `notes`, `position_x`, `position_y`, `created_at`, `updated_at`) VALUES ('44', '106', '5', '1', 'available', NULL, NULL, '0', '0', '2026-02-08 10:03:34', '2026-02-08 10:03:34');
INSERT INTO `rooms` (`id`, `room_number`, `room_type_id`, `floor_number`, `status`, `current_guest_id`, `notes`, `position_x`, `position_y`, `created_at`, `updated_at`) VALUES ('45', '201', '6', '2', 'available', NULL, NULL, '0', '0', '2026-02-08 10:03:53', '2026-03-06 09:19:55');
INSERT INTO `rooms` (`id`, `room_number`, `room_type_id`, `floor_number`, `status`, `current_guest_id`, `notes`, `position_x`, `position_y`, `created_at`, `updated_at`) VALUES ('46', '205', '6', '2', 'available', NULL, NULL, '0', '0', '2026-02-08 10:05:25', '2026-02-16 20:04:59');
INSERT INTO `rooms` (`id`, `room_number`, `room_type_id`, `floor_number`, `status`, `current_guest_id`, `notes`, `position_x`, `position_y`, `created_at`, `updated_at`) VALUES ('47', '206', '7', '2', 'available', NULL, NULL, '0', '0', '2026-02-08 10:05:36', '2026-02-08 10:05:36');
INSERT INTO `rooms` (`id`, `room_number`, `room_type_id`, `floor_number`, `status`, `current_guest_id`, `notes`, `position_x`, `position_y`, `created_at`, `updated_at`) VALUES ('48', '101', '5', '1', 'occupied', '40', NULL, '0', '0', '2026-02-08 11:13:40', '2026-02-16 22:52:55');

-- --------------------------------------------------------
-- Table structure for `sales_invoices_detail`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `sales_invoices_detail`;
CREATE TABLE `sales_invoices_detail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_header_id` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_description` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL COMMENT 'rental_motor, laundry, tour, rental_mobil, etc',
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(15,2) NOT NULL,
  `total_price` decimal(15,2) NOT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `invoice_header_id` (`invoice_header_id`),
  CONSTRAINT `sales_invoices_detail_ibfk_1` FOREIGN KEY (`invoice_header_id`) REFERENCES `sales_invoices_header` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `sales_invoices_detail`

INSERT INTO `sales_invoices_detail` (`id`, `invoice_header_id`, `item_name`, `item_description`, `category`, `quantity`, `unit_price`, `total_price`, `notes`) VALUES ('21', '21', 'Room Revenue - BK-20260304-2485', 'Room 201 2026-03-04 - 2026-03-06', 'Room Revenue', '1.00', '1400000.00', '1400000.00', NULL);

-- --------------------------------------------------------
-- Table structure for `sales_invoices_header`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `sales_invoices_header`;
CREATE TABLE `sales_invoices_header` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(50) NOT NULL,
  `invoice_date` date NOT NULL,
  `customer_name` varchar(255) NOT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `customer_email` varchar(255) DEFAULT NULL,
  `customer_address` text DEFAULT NULL,
  `division_id` int(11) NOT NULL,
  `payment_method` varchar(50) NOT NULL DEFAULT 'cash',
  `payment_status` enum('unpaid','paid','partial') DEFAULT 'unpaid',
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(15,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `cash_book_id` int(11) DEFAULT NULL COMMENT 'Reference to cash_book entry',
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `division_id` (`division_id`),
  KEY `created_by` (`created_by`),
  KEY `invoice_date` (`invoice_date`),
  KEY `payment_status` (`payment_status`),
  KEY `cash_book_id` (`cash_book_id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `sales_invoices_header`

INSERT INTO `sales_invoices_header` (`id`, `invoice_number`, `invoice_date`, `customer_name`, `customer_phone`, `customer_email`, `customer_address`, `division_id`, `payment_method`, `payment_status`, `subtotal`, `discount_amount`, `tax_amount`, `total_amount`, `paid_amount`, `notes`, `created_by`, `created_at`, `updated_at`, `cash_book_id`) VALUES ('21', 'INV-202603-0001', '2026-03-04', 'Patrycja Maliszewska', '+6281298661298', '', NULL, '5', 'cash', 'unpaid', '1400000.00', '0.00', '0.00', '1400000.00', '0.00', 'Auto invoice from check-in. Booking #BK-20260304-2485', '1', '2026-03-04 12:39:53', '2026-03-04 12:39:53', NULL);

-- --------------------------------------------------------
-- Table structure for `settings`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` longtext DEFAULT NULL,
  `setting_type` varchar(50) DEFAULT 'string',
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `idx_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=401 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `settings`

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('1', 'login_background', 'login-bg.png', 'string', 'Background image for login page', '2026-01-24 20:45:32', '2026-02-16 11:31:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('2', 'developer_whatsapp', '6281234567890', 'string', 'Developer WhatsApp number for support', '2026-01-24 20:45:32', '2026-01-31 09:25:11');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('3', 'company_logo_narayana-hotel', 'narayana-hotel_logo.png', 'file', 'Company logo for Narayana Hotel Jepara', '2026-01-25 00:04:58', '2026-01-25 00:04:58');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('4', 'company_name', 'Narayana Hotel', 'text', 'Company Name', '2026-01-25 00:10:50', '2026-03-05 15:52:07');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('5', 'company_tagline', 'Hotel Management System', 'text', 'Company Tagline', '2026-01-25 00:10:50', '2026-01-25 00:10:50');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('6', 'company_address', 'Jl. Sunan Nyamplungan\r\nKARIMUNJAWA, KARIMUN JAWA,Jepara, Jawatengah', 'text', 'Company Address', '2026-01-25 00:10:50', '2026-03-05 15:52:07');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('7', 'company_phone', '+62 812-2222-8590', 'text', 'Company Phone', '2026-01-25 00:10:50', '2026-02-14 18:59:57');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('8', 'company_email', 'narayanahotelkarimunjawa@gmail.com', 'text', 'Company Email', '2026-01-25 00:10:50', '2026-02-14 18:59:57');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('9', 'company_website', 'www.narayanakarimunjawa.com', 'text', 'Company Website', '2026-01-25 00:10:50', '2026-02-24 16:05:31');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('10', 'invoice_logo_narayana-hotel', 'https://res.cloudinary.com/dpdmut9ls/image/upload/v1772763279/adf_system/logos/invoice_logo_narayana-hotel.png', 'string', NULL, '2026-01-25 00:31:07', '2026-03-06 09:14:37');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('11', 'footer_copyright', '@narayana 2026 AdF System - Multi-Business Management. All rights reserved.', 'string', NULL, '2026-01-31 09:23:19', '2026-02-12 19:06:25');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('12', 'footer_version', 'Version 4.5.15', 'string', NULL, '2026-01-31 09:23:19', '2026-02-16 11:32:10');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('17', 'ota_fee_agoda', '17', 'number', NULL, '2026-02-01 06:22:13', '2026-03-05 10:12:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('18', 'ota_fee_booking.com', '19', 'number', NULL, '2026-02-01 06:22:24', '2026-03-10 18:52:13');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('19', 'ota_fee_tiket.com', '27', 'number', NULL, '2026-02-01 06:22:29', '2026-03-05 10:12:06');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('21', 'ota_fee_other_ota', '20', 'number', NULL, '2026-02-01 06:22:45', '2026-02-01 06:22:45');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('22', 'company_name_narayana-hotel', 'Narayana Hotel ', 'text', 'Company name for Narayana Hotel Jepara', '2026-02-03 15:38:52', '2026-02-03 15:38:52');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('23', 'report_show_logo', '1', 'string', NULL, '2026-02-07 23:11:15', '2026-02-07 23:11:15');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('24', 'report_show_address', '1', 'string', NULL, '2026-02-07 23:11:15', '2026-02-07 23:11:15');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('25', 'report_show_phone', '1', 'string', NULL, '2026-02-07 23:11:15', '2026-02-07 23:11:15');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('33', 'site_favicon', 'favicon.png', 'string', NULL, '2026-02-12 19:15:24', '2026-02-12 19:15:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('34', 'login_logo', 'login-logo.png', 'string', NULL, '2026-02-12 19:15:36', '2026-02-12 19:15:36');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('42', 'web_hero_accent', 'Welcome to Paradise', 'text', 'Website Hero: accent', '2026-02-22 19:03:32', '2026-02-22 19:03:32');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('43', 'web_hero_title', 'Experience Karimunjawa<br>Like Never Before', 'text', 'Website Hero: title', '2026-02-22 19:03:32', '2026-02-22 19:03:32');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('44', 'web_hero_subtitle', 'An exclusive island retreat where tropical luxury meets the pristine beauty of the Java Sea', 'text', 'Website Hero: subtitle', '2026-02-22 19:03:32', '2026-02-22 19:03:32');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('45', 'web_hero_background', 'https://res.cloudinary.com/dpdmut9ls/image/upload/v1773042687/adf_system/hero/hero_background.png', 'text', 'Website Hero Background Image', '2026-02-22 19:03:32', '2026-03-09 14:51:25');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('54', 'web_room_gallery_king', '[\"https:\\/\\/res.cloudinary.com\\/dpdmut9ls\\/image\\/upload\\/v1772739325\\/adf_system\\/website\\/rooms\\/king\\/tnqqgezxjthz4ylbpmg2.jpg\",\"https:\\/\\/res.cloudinary.com\\/dpdmut9ls\\/image\\/upload\\/v1772739334\\/adf_system\\/website\\/rooms\\/king\\/ojiw1yv6n6qbjgc5zjo8.jpg\",\"https:\\/\\/res.cloudinary.com\\/dpdmut9ls\\/image\\/upload\\/v1772742803\\/adf_system\\/rooms\\/king\\/gnrojaip487vz4sfh9oe.jpg\"]', 'text', 'Website Room Gallery', '2026-02-22 19:14:59', '2026-03-06 03:33:23');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('55', 'web_enabled', '1', 'text', 'Website: enabled', '2026-02-22 20:04:22', '2026-02-22 20:04:22');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('56', 'web_site_name', 'Narayana Karimunjawa', 'text', 'Website: site_name', '2026-02-22 20:04:22', '2026-02-22 20:04:22');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('57', 'web_tagline', 'Island Paradise Resort', 'text', 'Website: tagline', '2026-02-22 20:04:22', '2026-02-22 20:04:22');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('58', 'web_description', 'Luxury beachfront resort in the heart of Karimunjawa Islands. Premium accommodations with stunning ocean views.', 'text', 'Website: description', '2026-02-22 20:04:22', '2026-02-22 20:04:22');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('59', 'web_favicon', 'https://res.cloudinary.com/dpdmut9ls/image/upload/v1772833608/adf_system/website/web_favicon.png', 'text', 'Website Favicon Icon', '2026-02-22 20:04:22', '2026-03-07 04:46:48');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('136', 'web_logo', 'https://res.cloudinary.com/dpdmut9ls/image/upload/v1773043739/adf_system/website/web_logo.png', 'text', 'Website Logo', '2026-02-23 10:03:01', '2026-03-09 15:08:57');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('153', 'web_room_primary_king', '', 'text', 'Room Primary Image', '2026-02-23 12:47:35', '2026-03-06 03:33:23');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('155', 'web_room_gallery_queen', '[\"https:\\/\\/res.cloudinary.com\\/dpdmut9ls\\/image\\/upload\\/v1772742969\\/adf_system\\/rooms\\/queen\\/b9goflco9hqqj7tddyop.jpg\",\"https:\\/\\/res.cloudinary.com\\/dpdmut9ls\\/image\\/upload\\/v1772833698\\/adf_system\\/rooms\\/queen\\/etsmwjkocqplmokmcgje.jpg\",\"https:\\/\\/res.cloudinary.com\\/dpdmut9ls\\/image\\/upload\\/v1772833702\\/adf_system\\/rooms\\/queen\\/fl6eqk3avnsbqyfypebs.jpg\",\"https:\\/\\/res.cloudinary.com\\/dpdmut9ls\\/image\\/upload\\/v1772833705\\/adf_system\\/rooms\\/queen\\/vhii6qpcrqao2xiarvdq.jpg\",\"https:\\/\\/res.cloudinary.com\\/dpdmut9ls\\/image\\/upload\\/v1772833708\\/adf_system\\/rooms\\/queen\\/tvwsnfrkjvpmqohepml6.jpg\"]', 'text', 'Website Room Gallery', '2026-02-23 12:48:06', '2026-03-07 04:48:28');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('156', 'web_room_primary_queen', 'https://res.cloudinary.com/dpdmut9ls/image/upload/v1772833698/adf_system/rooms/queen/etsmwjkocqplmokmcgje.jpg', 'text', 'Room Primary Image', '2026-02-23 12:48:16', '2026-03-07 04:48:49');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('158', 'web_room_gallery_twin', '[\"https:\\/\\/res.cloudinary.com\\/dpdmut9ls\\/image\\/upload\\/v1772743199\\/adf_system\\/rooms\\/twin\\/a2frqczg75vcluxj6uj9.jpg\",\"https:\\/\\/res.cloudinary.com\\/dpdmut9ls\\/image\\/upload\\/v1772834044\\/adf_system\\/rooms\\/twin\\/ooyawhaltpdw13ihkzgp.jpg\"]', 'text', 'Website Room Gallery', '2026-02-23 12:50:19', '2026-03-07 04:54:04');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('159', 'web_meta_title', 'Narayana Hotel Karimunjawa - Comfortable & Strategic', 'text', 'Website SEO: title', '2026-02-23 13:40:52', '2026-02-23 16:45:26');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('160', 'web_meta_description', 'Book your tropical paradise getaway at Narayana Karimunjawa. Premium beachfront resort with King, Queen & Twin rooms on Karimunjawa Island.', 'text', 'Website SEO: description', '2026-02-23 13:40:52', '2026-02-23 13:40:52');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('161', 'web_meta_keywords', 'karimunjawa hotel, karimunjawa resort, narayana karimunjawa, island resort jepara, karimunjawa accommodation', 'text', 'Website SEO: keywords', '2026-02-23 13:40:52', '2026-02-23 13:40:52');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('162', 'web_destinations', '[{\"id\":\"dest_699bf9d57223e\",\"title\":\"Ben\\u2019s Cafe Karimunjawa\",\"subtitle\":\"The Ultimate Overwater Spot with the Best Sunset Views!\",\"content\":\"Karimunjawa never ceases to amaze. Beyond its world-class underwater beauty, there is a hidden gem for foodies and sunset seekers that must be on your travel itinerary: Ben\\u2019s Cafe.\\r\\n\\r\\nThis isn\'t just your average coffee shop. Ben\\u2019s Cafe offers a unique lounging experience and natural scenery that will make you want to stay forever.\\r\\n\\r\\n\\ud83c\\udf0a Nestled Under the Mountains, Floating Over the Sea\\r\\nHave you ever imagined sipping a drink while floating directly over the ocean, sheltered by the majesty of lush green hills? That is exactly the sensation Ben\\u2019s Cafe provides.\\r\\n\\r\\nThe location is both strategic and iconic, bridging two incredible natural elements: positioned right at the foot of the mountain and built directly over the sea. The rustic wooden design blends seamlessly with the environment. You can feel the fresh sea breeze and listen to the gentle waves lapping beneath your feet. It is pure serenity!\\r\\n\\r\\n\\ud83c\\udf05 The Best Sunset Spot in Karimunjawa\\r\\nIf you are looking for the perfect place to end your day, Ben\\u2019s Cafe is the answer. It is widely known as one of the most stunning sunset spots in the archipelago.\\r\\n\\r\\nAs evening approaches, the sky transforms into a canvas of orange, pink, and gold. Because the cafe sits over the water with an unobstructed view, you get a front-row seat to the sun dipping below the horizon. It is the perfect moment for silhouette photography or simply soaking in the beauty of nature.\\r\\n\\r\\n\\u2615 An Iconic Must-Visit Destination\\r\\nAs one of the most famous spots in Karimunjawa, Ben\\u2019s Cafe is a favorite for both local and international travelers. It\\u2019s the perfect place for:\\r\\n\\r\\nPost-Island Hopping Relaxation: Rest your weary legs after a day of snorkeling with a fresh young coconut or a warm cup of coffee.\\r\\n\\r\\nRomantic Dinners: Enjoy fresh seafood in a dreamy, dimly lit atmosphere over the water.\\r\\n\\r\\nAesthetic Photo Hunting: Every corner of the cafe, with the backdrop of the open sea and rolling hills, is incredibly Instagrammable.\\r\\n\\r\\nConclusion\\r\\nBen\\u2019s Cafe is more than just a place to eat and drink; it is a destination where you can fully immerse yourself in the beauty of Karimunjawa. The combination of blue waters, green hills, and golden sunsets makes this place truly special. Make sure Ben\\u2019s Cafe is on your list when you visit this little piece of paradise in the Java Sea!\",\"image\":\"uploads\\/destinations\\/dest-1771829953-0.JPG\",\"order\":1,\"active\":true},{\"id\":\"dest_699bf8de2b864\",\"title\":\"Bukit Love\",\"subtitle\":\"This tourist destination is easily one of the most captivating spots in Karimunjawa\",\"content\":\"A Paradise for Photography Lovers\\r\\n\\r\\nThis tourist destination is easily one of the most captivating spots in Karimunjawa. Offering breathtaking views of the vast ocean and the pristine, natural ambiance of the Karimunjawa hills, this place is a true haven for nature enthusiasts and photography lovers alike.\\r\\n\\r\\nStanding at an elevation of 150 meters above sea level, you can enjoy spectacular, sweeping views from the hilltop. Once you arrive in Karimunjawa, make sure to visit right away and immerse yourself in the mesmerizing natural charm of Bukit Love.\",\"image\":\"uploads\\/destinations\\/dest-1771829470.jpeg\",\"order\":2,\"active\":true},{\"id\":\"dest_699c30faa21d9\",\"title\":\"Boby Beach\",\"subtitle\":\"The Golden Sunrise Gateway of Karimunjawa\",\"content\":\"If there is one place that defines a perfect morning in the Karimunjawa tropics, it is undoubtedly Boby Beach (Pantai Boby). While many head to the west for the sunset, seasoned travelers know that the real magic happens at dawn on this stunning eastern coastline.\\r\\n\\r\\n\\ud83c\\udf05 The Best Sunrise Spot in the Archipelago\\r\\nBoby Beach is widely celebrated as the top sunrise destination in Karimunjawa. Thanks to its wide-stretching shoreline facing directly east, visitors are treated to an unobstructed view of the sun emerging from the Java Sea.\\r\\n\\r\\nAs the first light breaks, the sky transforms into a masterpiece of deep purples, fiery oranges, and soft golds, reflecting perfectly off the calm, crystal-clear water.\\r\\n\\r\\n\\ud83c\\udf34 White Sands and Iconic Swings\\r\\nBeyond the sunrise, Boby Beach offers a quintessential tropical vibe:\\r\\n\\r\\nPowdery White Sand: The beach features a broad area of soft, clean sand, perfect for a morning jog or a relaxed picnic.\\r\\n\\r\\nIconic Photo Spots: You will find the famous wooden swings and hammocks set up along the shore. Taking a photo on the swing with the rising sun in the background is a \\\"must-do\\\" for every visitor.\\r\\n\\r\\nLush Coconut Groves: The backdrop of towering coconut trees adds a serene, rustic feel to your morning adventure.\\r\\n\\r\\n\\ud83e\\udd65 A Perfect Start to Your Day\\r\\nThe atmosphere at Boby Beach is remarkably peaceful. Unlike the busier daytime spots, early mornings here offer a sense of solitude. You can enjoy a fresh young coconut from local vendors or simply sit on the sand and listen to the gentle waves. It is the ultimate way to recharge your soul before starting your island-hopping tour.\\r\\n\\r\\n\\ud83d\\udccd Travel Tips:\\r\\nArrival Time: Aim to be there by 5:15 AM to catch the \\\"blue hour\\\" before the actual sunrise.\\r\\n\\r\\nAccessibility: It is easily reachable by motorbike or car from the main town (Karimunjawa village), taking only about 10\\u201315 minutes.\\r\\n\\r\\nPro Tip: After the sunrise, the lighting is perfect for a photoshoot before the tropical sun gets too bright and hot.\",\"image\":\"uploads\\/destinations\\/dest-1771843834.jpeg\",\"order\":3,\"active\":true},{\"id\":\"dest_69ae2984e0100\",\"title\":\"bens\",\"subtitle\":\"\",\"content\":\"\",\"image\":\"https:\\/\\/res.cloudinary.com\\/dpdmut9ls\\/image\\/upload\\/v1773021577\\/adf_system\\/destinations\\/dest_dest_69ae2984e0100.jpg\",\"order\":4,\"active\":true}]', 'text', 'Website Destinations', '2026-02-23 13:51:10', '2026-03-09 08:59:36');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('168', 'web_footer_logo', 'uploads/logo/footer-logo-1771831125.png', 'text', 'Footer Logo', '2026-02-23 14:18:45', '2026-02-23 14:18:45');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('169', 'web_footer_text', '', 'text', 'Footer: text', '2026-02-23 14:18:45', '2026-02-23 14:18:45');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('170', 'web_footer_show_logo', '1', 'text', 'Footer: show_logo', '2026-02-23 14:18:45', '2026-02-23 14:18:45');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('171', 'web_footer_copyright', 'narayanakarimunjawa ,All rights reserved', 'text', 'Footer: copyright', '2026-02-23 14:18:45', '2026-02-23 14:18:59');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('181', 'web_og_type', 'website', 'text', 'SEO: og_type', '2026-02-23 16:26:45', '2026-02-23 16:26:45');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('182', 'web_og_locale', 'en_US', 'text', 'SEO: og_locale', '2026-02-23 16:26:45', '2026-02-23 16:26:45');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('183', 'web_ga_id', '', 'text', 'SEO: ga_id', '2026-02-23 16:26:45', '2026-02-23 16:26:45');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('184', 'web_gtm_id', 'G-1MKVCR184S', 'text', 'SEO: gtm_id', '2026-02-23 16:26:45', '2026-02-23 16:36:12');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('185', 'web_google_verification', '\"v=spf1 +a +mx +ip4:203.175.8.47 ~all\"', 'text', 'SEO: google_verification', '2026-02-23 16:26:45', '2026-02-23 16:41:27');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('186', 'web_bing_verification', '', 'text', 'SEO: bing_verification', '2026-02-23 16:26:45', '2026-02-23 16:26:45');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('187', 'web_schema_star_rating', '5', 'text', 'SEO: schema_star_rating', '2026-02-23 16:26:45', '2026-02-23 16:26:45');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('188', 'web_schema_price_range', 'Rp 600.000 - Rp 1.750.000', 'text', 'SEO: schema_price_range', '2026-02-23 16:26:45', '2026-02-23 16:45:57');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('189', 'web_schema_latitude', '-5.867262353713739', 'text', 'SEO: schema_latitude', '2026-02-23 16:26:45', '2026-02-23 16:26:45');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('190', 'web_schema_longitude', '110.43118335689717', 'text', 'SEO: schema_longitude', '2026-02-23 16:26:45', '2026-02-23 16:26:45');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('191', 'web_schema_checkin', '13:00', 'text', 'SEO: schema_checkin', '2026-02-23 16:26:45', '2026-02-23 16:26:45');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('192', 'web_schema_checkout', '11:00', 'text', 'SEO: schema_checkout', '2026-02-23 16:26:45', '2026-02-23 16:26:45');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('193', 'web_robots_index', '1', 'text', 'SEO: robots_index', '2026-02-23 16:26:45', '2026-02-23 16:26:45');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('194', 'web_robots_follow', '1', 'text', 'SEO: robots_follow', '2026-02-23 16:26:45', '2026-02-23 16:26:45');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('195', 'web_canonical_url', 'https://narayanakarimunjawa.com', 'text', 'SEO: canonical_url', '2026-02-23 16:26:45', '2026-02-23 16:26:45');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('196', 'web_og_image', 'uploads/seo/og-image-1771838805.png', 'text', 'OG Share Image', '2026-02-23 16:26:45', '2026-02-23 16:26:45');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('269', 'web_hero_rooms_eyebrow', 'Accommodations', 'text', 'Hero Rooms: rooms_eyebrow', '2026-02-23 17:36:54', '2026-02-23 17:36:54');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('270', 'web_hero_rooms_title', 'Our Rooms', 'text', 'Hero Rooms: rooms_title', '2026-02-23 17:36:54', '2026-02-23 17:36:54');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('271', 'web_hero_rooms_subtitle', 'Thoughtfully designed spaces where island comfort meets refined elegance.', 'text', 'Hero Rooms: rooms_subtitle', '2026-02-23 17:36:54', '2026-02-23 17:36:54');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('272', 'web_hero_rooms_background', 'uploads/hero/hero-rooms-bg-1771843014.jpeg', 'text', 'Hero Rooms Background Image', '2026-02-23 17:36:54', '2026-02-23 17:36:54');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('273', 'web_hero_dest_eyebrow', 'Explore Karimunjawa', 'text', 'Hero Destinations: dest_eyebrow', '2026-02-23 17:38:24', '2026-02-23 17:38:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('274', 'web_hero_dest_title', 'Discover the Island', 'text', 'Hero Destinations: dest_title', '2026-02-23 17:38:24', '2026-02-23 17:38:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('275', 'web_hero_dest_subtitle', 'Your guide to the most breathtaking destinations and hidden gems of Karimunjawa — from pristine beaches and vibrant coral reefs to lush mangrove forests and unforgettable sunset spots.', 'text', 'Hero Destinations: dest_subtitle', '2026-02-23 17:38:24', '2026-02-23 17:38:24');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('276', 'web_hero_dest_background', 'uploads/hero/hero-destinations-bg-1771843168.JPG', 'text', 'Hero Destinations Background Image', '2026-02-23 17:38:24', '2026-02-23 17:39:28');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('281', 'web_whatsapp', '6281222228590', 'text', 'Website: whatsapp', '2026-02-23 17:43:32', '2026-02-23 17:43:32');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('282', 'web_instagram', 'narayanakarimunjawa', 'text', 'Website: instagram', '2026-02-23 17:43:32', '2026-02-23 17:43:32');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('283', 'web_email', 'narayanahotelkarimunjawa@gmail.com', 'text', 'Website: email', '2026-02-23 17:43:32', '2026-02-23 17:43:32');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('284', 'web_phone', '+62 812-2222-8590', 'text', 'Website: phone', '2026-02-23 17:43:32', '2026-02-23 17:43:32');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('285', 'web_address', 'Jl. Sunan Nyamplungan, Karimunjawa, Kabupaten Jepara, Jawa Tengah 59455', 'text', 'Website: address', '2026-02-23 17:43:32', '2026-02-23 17:43:32');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('286', 'web_checkin_time', '12:00', 'text', 'Website: checkin_time', '2026-02-23 17:43:32', '2026-02-23 17:43:32');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('287', 'web_checkout_time', '11:00', 'text', 'Website: checkout_time', '2026-02-23 17:43:32', '2026-02-23 17:43:32');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('289', 'web_room_primary_twin', 'https://res.cloudinary.com/dpdmut9ls/image/upload/v1772743199/adf_system/rooms/twin/a2frqczg75vcluxj6uj9.jpg', 'text', 'Room Primary Image', '2026-02-23 17:44:27', '2026-03-06 03:40:07');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('315', 'company_logo', 'https://adfsystem.online/uploads/logos/logo_hotel_svc_69a9444d4a127.png', 'string', NULL, '2026-03-05 15:52:29', '2026-03-05 15:52:29');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('316', 'payment_info_bank', 'BNI', 'string', NULL, '2026-03-05 16:47:23', '2026-03-05 16:47:23');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('317', 'payment_info_account', '2254542544245', 'string', NULL, '2026-03-05 16:47:23', '2026-03-05 16:47:23');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('318', 'payment_info_name', 'Narayana Karimunjawa', 'string', NULL, '2026-03-05 16:47:23', '2026-03-05 16:47:23');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('319', 'payment_info_note', 'Tanks', 'string', NULL, '2026-03-05 16:47:23', '2026-03-05 16:47:23');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('370', 'agent_api_key', 'nryn-114ec0f962411672672a58151392b73290c53432', 'text', 'AI Agent API Key', '2026-03-07 18:42:57', '2026-03-07 19:36:29');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('400', 'ota_fee_direct', '0', 'number', NULL, '2026-03-10 18:52:14', '2026-03-10 18:52:14');

-- --------------------------------------------------------
-- Table structure for `suppliers`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `suppliers`;
CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_code` varchar(50) NOT NULL,
  `supplier_name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `tax_number` varchar(100) DEFAULT NULL,
  `payment_terms` enum('cash','net_7','net_14','net_30','net_45','net_60') DEFAULT 'net_30',
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_code` (`supplier_code`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `suppliers_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `suppliers`

INSERT INTO `suppliers` (`id`, `supplier_code`, `supplier_name`, `contact_person`, `phone`, `email`, `address`, `tax_number`, `payment_terms`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES ('1', 'BWK', 'BOWO', 'HUNGER RESTO', '081330316204', NULL, NULL, NULL, 'net_30', '1', '1', '2026-01-25 06:50:44', '2026-01-25 06:50:44');
INSERT INTO `suppliers` (`id`, `supplier_code`, `supplier_name`, `contact_person`, `phone`, `email`, `address`, `tax_number`, `payment_terms`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES ('3', 'UD1', 'udin', NULL, NULL, NULL, NULL, NULL, 'net_30', '1', '4', '2026-02-08 04:49:15', '2026-02-08 04:49:15');

-- --------------------------------------------------------
-- Table structure for `transaction_attachments`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `transaction_attachments`;
CREATE TABLE `transaction_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_type` varchar(50) NOT NULL COMMENT 'e.g., purchase_order, sales_invoice',
  `transaction_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `uploaded_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_transaction` (`transaction_type`,`transaction_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `transaction_attachments`

INSERT INTO `transaction_attachments` (`id`, `transaction_type`, `transaction_id`, `file_path`, `file_name`, `file_type`, `uploaded_at`, `uploaded_by`) VALUES ('1', 'purchase_order', '5', 'uploads/purchase_attachments/PO_PO-202602-0002_1770501736.jpeg', 'PO_PO-202602-0002_1770501736.jpeg', 'jpeg', '2026-02-08 05:02:16', '3');
INSERT INTO `transaction_attachments` (`id`, `transaction_type`, `transaction_id`, `file_path`, `file_name`, `file_type`, `uploaded_at`, `uploaded_by`) VALUES ('2', 'purchase_order', '5', 'uploads/purchase_attachments/PO_PO-202602-0002_1770502252.jpeg', 'PO_PO-202602-0002_1770502252.jpeg', 'jpeg', '2026-02-08 05:10:52', '1');
INSERT INTO `transaction_attachments` (`id`, `transaction_type`, `transaction_id`, `file_path`, `file_name`, `file_type`, `uploaded_at`, `uploaded_by`) VALUES ('3', 'purchase_order', '5', 'uploads/purchase_attachments/PO_PO-202602-0002_1770502700.jpeg', 'PO_PO-202602-0002_1770502700.jpeg', 'jpeg', '2026-02-08 05:18:20', '1');
INSERT INTO `transaction_attachments` (`id`, `transaction_type`, `transaction_id`, `file_path`, `file_name`, `file_type`, `uploaded_at`, `uploaded_by`) VALUES ('4', 'purchase_order', '5', 'uploads/purchase_attachments/PO_PO-202602-0002_1770502771.jpeg', 'PO_PO-202602-0002_1770502771.jpeg', 'jpeg', '2026-02-08 05:19:31', '1');
INSERT INTO `transaction_attachments` (`id`, `transaction_type`, `transaction_id`, `file_path`, `file_name`, `file_type`, `uploaded_at`, `uploaded_by`) VALUES ('5', 'purchase_order', '6', 'uploads/purchase_attachments/PO_PO-202602-0003_1770504483.png', 'PO_PO-202602-0003_1770504483.png', 'png', '2026-02-08 05:48:03', '1');
INSERT INTO `transaction_attachments` (`id`, `transaction_type`, `transaction_id`, `file_path`, `file_name`, `file_type`, `uploaded_at`, `uploaded_by`) VALUES ('6', 'purchase_order', '3', 'uploads/purchase_attachments/PO_PO-202602-0001_1770504505.jpeg', 'PO_PO-202602-0001_1770504505.jpeg', 'jpeg', '2026-02-08 05:48:25', '1');
INSERT INTO `transaction_attachments` (`id`, `transaction_type`, `transaction_id`, `file_path`, `file_name`, `file_type`, `uploaded_at`, `uploaded_by`) VALUES ('7', 'purchase_order', '8', 'uploads/purchase_attachments/PO_PO-202602-0005_1770949835.jpeg', 'PO_PO-202602-0005_1770949835.jpeg', 'jpeg', '2026-02-13 09:30:35', '1');
INSERT INTO `transaction_attachments` (`id`, `transaction_type`, `transaction_id`, `file_path`, `file_name`, `file_type`, `uploaded_at`, `uploaded_by`) VALUES ('8', 'purchase_order', '9', 'uploads/purchase_attachments/PO_PO-202602-0006_1771289386.png', 'PO_PO-202602-0006_1771289386.png', 'png', '2026-02-17 07:49:46', '1');

-- --------------------------------------------------------
-- Table structure for `user_permissions`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `user_permissions`;
CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `permission` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_permission` (`user_id`,`permission`),
  CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=79 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `user_permissions`

INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('57', '1', 'dashboard', '2026-01-25 13:44:26');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('58', '1', 'cashbook', '2026-01-25 13:44:26');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('59', '1', 'divisions', '2026-01-25 13:44:26');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('60', '1', 'frontdesk', '2026-01-25 13:44:26');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('61', '1', 'sales_invoice', '2026-01-25 13:44:26');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('62', '1', 'procurement', '2026-01-25 13:44:26');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('63', '1', 'reports', '2026-01-25 13:44:26');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('64', '1', 'users', '2026-01-25 13:44:26');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('65', '1', 'settings', '2026-01-25 13:44:26');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('66', '1', 'investor', '2026-01-25 13:44:26');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('67', '1', 'project', '2026-01-25 13:44:26');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('68', '4', 'dashboard', '2026-02-03 15:40:43');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('69', '4', 'cashbook', '2026-02-03 15:40:43');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('70', '4', 'divisions', '2026-02-03 15:40:43');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('71', '4', 'frontdesk', '2026-02-03 15:40:43');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('72', '4', 'sales_invoice', '2026-02-03 15:40:43');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('73', '4', 'procurement', '2026-02-03 15:40:43');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('74', '4', 'reports', '2026-02-03 15:40:43');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('75', '4', 'investor', '2026-02-03 15:40:43');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('76', '4', 'project', '2026-02-03 15:40:43');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('77', '6', 'dashboard', '2026-02-03 18:50:04');
INSERT INTO `user_permissions` (`id`, `user_id`, `permission`, `created_at`) VALUES ('78', '6', 'cashbook', '2026-02-03 18:50:04');

-- --------------------------------------------------------
-- Table structure for `user_preferences`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `user_preferences`;
CREATE TABLE `user_preferences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `branch_id` varchar(50) NOT NULL DEFAULT 'narayana-hotel',
  `theme` varchar(20) DEFAULT 'dark',
  `language` varchar(10) DEFAULT 'id',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_branch` (`user_id`,`branch_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_branch` (`branch_id`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `user_preferences`

INSERT INTO `user_preferences` (`id`, `user_id`, `branch_id`, `theme`, `language`, `created_at`, `updated_at`) VALUES ('1', '1', 'narayana-hotel', 'light', 'en', '2026-01-27 11:58:21', '2026-02-04 12:33:32');
INSERT INTO `user_preferences` (`id`, `user_id`, `branch_id`, `theme`, `language`, `created_at`, `updated_at`) VALUES ('30', '3', 'narayana-hotel', 'light', 'id', '2026-02-07 20:03:38', '2026-02-07 20:03:38');
INSERT INTO `user_preferences` (`id`, `user_id`, `branch_id`, `theme`, `language`, `created_at`, `updated_at`) VALUES ('31', '7', 'narayana-hotel', 'light', 'id', '2026-02-10 11:47:14', '2026-02-10 11:47:14');
INSERT INTO `user_preferences` (`id`, `user_id`, `branch_id`, `theme`, `language`, `created_at`, `updated_at`) VALUES ('32', '8', 'narayana-hotel', 'light', 'en', '2026-02-11 22:15:19', '2026-03-03 19:56:22');
INSERT INTO `user_preferences` (`id`, `user_id`, `branch_id`, `theme`, `language`, `created_at`, `updated_at`) VALUES ('33', '11', 'narayana-hotel', 'light', 'id', '2026-02-19 12:43:52', '2026-02-19 12:43:52');
INSERT INTO `user_preferences` (`id`, `user_id`, `branch_id`, `theme`, `language`, `created_at`, `updated_at`) VALUES ('34', '25', 'narayana-hotel', 'light', 'id', '2026-02-28 19:51:20', '2026-02-28 19:51:20');
INSERT INTO `user_preferences` (`id`, `user_id`, `branch_id`, `theme`, `language`, `created_at`, `updated_at`) VALUES ('35', '27', 'narayana-hotel', 'light', 'id', '2026-03-02 09:11:41', '2026-03-02 09:11:41');

-- --------------------------------------------------------
-- Table structure for `users`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `role_id` int(11) DEFAULT 1,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('admin','manager','cashier','accountant','staff','owner') DEFAULT 'staff',
  `business_access` varchar(255) DEFAULT 'all',
  `phone` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_trial` tinyint(1) DEFAULT 0,
  `trial_expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_username` (`username`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `users`

INSERT INTO `users` (`id`, `username`, `role_id`, `password`, `full_name`, `email`, `role`, `business_access`, `phone`, `is_active`, `created_at`, `updated_at`, `is_trial`, `trial_expires_at`) VALUES ('1', 'admin', '2', '$2y$10$KZV1FfE8ODG/9x.KA.zssu6ohitwqAg.BeY2I3fH8XS2ONmRRtd9K', 'Administrator', 'admin@system.local', 'admin', NULL, NULL, '1', '2026-01-24 23:33:10', '2026-02-25 12:33:58', '0', NULL);
INSERT INTO `users` (`id`, `username`, `role_id`, `password`, `full_name`, `email`, `role`, `business_access`, `phone`, `is_active`, `created_at`, `updated_at`, `is_trial`, `trial_expires_at`) VALUES ('4', 'sandra', '4', '$2y$10$BcK8GMylQ3nj1plhFd1LR.Q10ZIqQzrVLqiXHH.jacv/8tDoX2.8a', 'Sandra Octavia', 'hungersales01@gmail.com', 'staff', '[3]', NULL, '0', '2026-02-03 15:40:43', '2026-03-02 09:08:46', '0', NULL);
INSERT INTO `users` (`id`, `username`, `role_id`, `password`, `full_name`, `email`, `role`, `business_access`, `phone`, `is_active`, `created_at`, `updated_at`, `is_trial`, `trial_expires_at`) VALUES ('6', 'owner', '1', '$2y$10$LdfaGwBS8TFKVl69sAVMJ.tURMUSitZj805DiwEQhKipfR/zG2mni', 'Busita', 'info@narayanahotel.com', 'owner', 'all', NULL, '1', '2026-02-03 17:57:38', '2026-02-07 17:42:42', '0', NULL);
INSERT INTO `users` (`id`, `username`, `role_id`, `password`, `full_name`, `email`, `role`, `business_access`, `phone`, `is_active`, `created_at`, `updated_at`, `is_trial`, `trial_expires_at`) VALUES ('7', 'dev', '2', '$2y$10$CwTycUXWue0Thq9StjUM0uJ8Z4p.v.k.YzA.xJw5pU8BCyVV3.hCm', 'Developer Admin', 'dev@system.local', 'staff', 'all', NULL, '0', '2026-02-08 17:59:28', '2026-02-08 22:32:20', '0', NULL);

-- --------------------------------------------------------
-- Table structure for `view_daily_summary`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `view_daily_summary`;
CREATE ALGORITHM=UNDEFINED DEFINER=`adfb2574`@`localhost` SQL SECURITY DEFINER VIEW `view_daily_summary` AS select `cb`.`transaction_date` AS `transaction_date`,`d`.`division_name` AS `division_name`,`c`.`category_name` AS `category_name`,`cb`.`transaction_type` AS `transaction_type`,sum(`cb`.`amount`) AS `total_amount`,count(0) AS `transaction_count` from ((`cash_book` `cb` join `divisions` `d` on(`cb`.`division_id` = `d`.`id`)) join `categories` `c` on(`cb`.`category_id` = `c`.`id`)) group by `cb`.`transaction_date`,`d`.`division_name`,`c`.`category_name`,`cb`.`transaction_type` order by `cb`.`transaction_date` desc;

-- Dumping data for table `view_daily_summary`

INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-11', 'Hotel', 'Arif', 'expense', '77000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-11', 'Hotel', 'Fendi', 'expense', '24000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-11', 'Hotel', 'Sandra', 'expense', '250000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-11', 'Housekeeping', 'Fendi', 'expense', '423000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-10', 'Bar', 'Putri', 'expense', '14000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-10', 'Hotel', 'Sandra', 'expense', '11543630.00', '4');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-10', 'Lain2', 'Sandra', 'expense', '120000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-10', 'Petty Cash', 'Modal Operasional dari Bu Sita', 'income', '18000000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-10', 'Pool', 'Sandra', 'expense', '350000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-10', 'Project', 'Sandra', 'expense', '3572498.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-10', 'Security', 'Sandra', 'expense', '55000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-09', 'Security', 'Sandra', 'expense', '103000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-08', 'Project', 'Hilmi', 'expense', '53000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-07', 'Housekeeping', 'Sandra', 'expense', '74000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-07', 'Project', 'pak habibi', 'expense', '203000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-07', 'Solar Genset', 'Rizal', 'expense', '144500.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-06', 'Drop Car', 'Hotel Service', 'income', '350000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-06', 'Hotel', 'kaleb', 'expense', '10000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-06', 'Hotel', 'Sandra', 'expense', '350000.00', '2');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-06', 'Motor Rental', 'Hotel Service', 'income', '300000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-05', 'Hotel', 'moyong', 'expense', '50000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-05', 'Project', 'Hilmi', 'expense', '210500.00', '3');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-05', 'Resto', 'Dela', 'expense', '61700.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-04', 'Hotel', 'Room Rental', 'income', '2257500.00', '2');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-03', 'Hotel', 'Sandra', 'expense', '130000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-03', 'Lain2', 'arief', 'expense', '38000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-03', 'Petty Cash', 'Modal Operasional dari Bu Sita', 'income', '2550000.00', '2');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-03', 'Pool', 'Pak widi', 'expense', '350000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-03', 'Project', 'Doni', 'expense', '14000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-03', 'Project', 'Pak aan', 'expense', '500000.00', '1');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-03', 'Project', 'pak ipin', 'expense', '161000.00', '5');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-03', 'Resto', 'Dela', 'expense', '66000.00', '2');
INSERT INTO `view_daily_summary` (`transaction_date`, `division_name`, `category_name`, `transaction_type`, `total_amount`, `transaction_count`) VALUES ('2026-03-02', 'Hotel', 'Room Service Charges', 'income', '17317999.98', '9');

-- --------------------------------------------------------
-- Table structure for `view_division_summary`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `view_division_summary`;
CREATE ALGORITHM=UNDEFINED DEFINER=`adfb2574`@`localhost` SQL SECURITY DEFINER VIEW `view_division_summary` AS select `d`.`id` AS `id`,`d`.`division_code` AS `division_code`,`d`.`division_name` AS `division_name`,coalesce(sum(case when `cb`.`transaction_type` = 'income' then `cb`.`amount` else 0 end),0) AS `total_income`,coalesce(sum(case when `cb`.`transaction_type` = 'expense' then `cb`.`amount` else 0 end),0) AS `total_expense`,coalesce(sum(case when `cb`.`transaction_type` = 'income' then `cb`.`amount` else 0 end),0) - coalesce(sum(case when `cb`.`transaction_type` = 'expense' then `cb`.`amount` else 0 end),0) AS `net_profit` from (`divisions` `d` left join `cash_book` `cb` on(`d`.`id` = `cb`.`division_id`)) group by `d`.`id`,`d`.`division_code`,`d`.`division_name`;

-- Dumping data for table `view_division_summary`

INSERT INTO `view_division_summary` (`id`, `division_code`, `division_name`, `total_income`, `total_expense`, `net_profit`) VALUES ('1', 'KITCHEN', 'Kitchen', '0.00', '0.00', '0.00');
INSERT INTO `view_division_summary` (`id`, `division_code`, `division_name`, `total_income`, `total_expense`, `net_profit`) VALUES ('2', 'BAR', 'Bar', '0.00', '14000.00', '-14000.00');
INSERT INTO `view_division_summary` (`id`, `division_code`, `division_name`, `total_income`, `total_expense`, `net_profit`) VALUES ('3', 'RESTO', 'Resto', '0.00', '127700.00', '-127700.00');
INSERT INTO `view_division_summary` (`id`, `division_code`, `division_name`, `total_income`, `total_expense`, `net_profit`) VALUES ('4', 'HOUSEKEEPING', 'Housekeeping', '0.00', '497000.00', '-497000.00');
INSERT INTO `view_division_summary` (`id`, `division_code`, `division_name`, `total_income`, `total_expense`, `net_profit`) VALUES ('5', 'HOTEL', 'Hotel', '19575499.98', '12434630.00', '7140869.98');
INSERT INTO `view_division_summary` (`id`, `division_code`, `division_name`, `total_income`, `total_expense`, `net_profit`) VALUES ('6', 'GARDENER', 'Gardener', '0.00', '0.00', '0.00');
INSERT INTO `view_division_summary` (`id`, `division_code`, `division_name`, `total_income`, `total_expense`, `net_profit`) VALUES ('7', 'OTHERS', 'Lain2', '0.00', '158000.00', '-158000.00');
INSERT INTO `view_division_summary` (`id`, `division_code`, `division_name`, `total_income`, `total_expense`, `net_profit`) VALUES ('8', 'PC', 'Petty Cash', '20550000.00', '0.00', '20550000.00');
INSERT INTO `view_division_summary` (`id`, `division_code`, `division_name`, `total_income`, `total_expense`, `net_profit`) VALUES ('9', 'PL', 'Pool', '0.00', '700000.00', '-700000.00');
INSERT INTO `view_division_summary` (`id`, `division_code`, `division_name`, `total_income`, `total_expense`, `net_profit`) VALUES ('10', 'FO', 'Front office', '0.00', '0.00', '0.00');
INSERT INTO `view_division_summary` (`id`, `division_code`, `division_name`, `total_income`, `total_expense`, `net_profit`) VALUES ('11', 'SC', 'Security', '0.00', '158000.00', '-158000.00');
INSERT INTO `view_division_summary` (`id`, `division_code`, `division_name`, `total_income`, `total_expense`, `net_profit`) VALUES ('12', 'PJ', 'Project', '0.00', '4713998.00', '-4713998.00');
INSERT INTO `view_division_summary` (`id`, `division_code`, `division_name`, `total_income`, `total_expense`, `net_profit`) VALUES ('14', '', 'Motor Rental', '300000.00', '0.00', '300000.00');
INSERT INTO `view_division_summary` (`id`, `division_code`, `division_name`, `total_income`, `total_expense`, `net_profit`) VALUES ('18', 'CR', 'Drop Car', '350000.00', '0.00', '350000.00');
INSERT INTO `view_division_summary` (`id`, `division_code`, `division_name`, `total_income`, `total_expense`, `net_profit`) VALUES ('19', 'SG', 'Solar Genset', '0.00', '144500.00', '-144500.00');

SET FOREIGN_KEY_CHECKS=1;
