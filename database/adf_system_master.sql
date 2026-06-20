-- ============================================
-- ADF SYSTEM - MASTER DATABASE
-- Clean Architecture Version 2.0
-- Created: 2026-02-07
-- ============================================

-- Drop existing database if needed (CAUTION!)
-- DROP DATABASE IF EXISTS adf_system;

CREATE DATABASE IF NOT EXISTS adf_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE adf_system;

-- ============================================
-- ROLES TABLE - Master Roles Definition
-- ============================================
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) UNIQUE NOT NULL,
    role_code VARCHAR(20) UNIQUE NOT NULL,
    description TEXT,
    is_system_role TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_role_code (role_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- USERS TABLE - All System Users
-- ============================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    role_id INT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role_id),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- BUSINESSES TABLE - Master Business Data
-- ============================================
CREATE TABLE IF NOT EXISTS businesses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_code VARCHAR(30) UNIQUE NOT NULL,
    business_name VARCHAR(100) NOT NULL,
    business_type ENUM('hotel', 'restaurant', 'retail', 'manufacture', 'tourism', 'travel_bureau', 'other') DEFAULT 'other',
    database_name VARCHAR(100) NOT NULL UNIQUE,
    owner_id INT NOT NULL,
    description TEXT,
    logo_url VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_business_code (business_code),
    INDEX idx_owner (owner_id),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- MENU ITEMS TABLE - System Menu Definition
-- ============================================
CREATE TABLE IF NOT EXISTS menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    menu_code VARCHAR(50) UNIQUE NOT NULL,
    menu_name VARCHAR(100) NOT NULL,
    menu_icon VARCHAR(50),
    menu_url VARCHAR(255),
    menu_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_menu_code (menu_code),
    INDEX idx_is_active (is_active),
    INDEX idx_menu_order (menu_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- BUSINESS MENU CONFIGURATION
-- Which menus are enabled for which business
-- ============================================
CREATE TABLE IF NOT EXISTS business_menu_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_id INT NOT NULL,
    menu_id INT NOT NULL,
    is_enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_business_menu (business_id, menu_id),
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    INDEX idx_business (business_id),
    INDEX idx_menu (menu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- USER BUSINESS ASSIGNMENT
-- Which users belong to which businesses
-- ============================================
CREATE TABLE IF NOT EXISTS user_business_assignment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    business_id INT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_user_business (user_id, business_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_business (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- USER MENU PERMISSIONS
-- Fine-grained permission per user per menu per business
-- ============================================
CREATE TABLE IF NOT EXISTS user_menu_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    business_id INT NOT NULL,
    menu_id INT NOT NULL,
    can_view TINYINT(1) DEFAULT 1,
    can_create TINYINT(1) DEFAULT 0,
    can_edit TINYINT(1) DEFAULT 0,
    can_delete TINYINT(1) DEFAULT 0,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    granted_by INT,
    
    UNIQUE KEY unique_user_menu_business (user_id, menu_id, business_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_business (business_id),
    INDEX idx_menu (menu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SYSTEM SETTINGS
-- ============================================
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value LONGTEXT,
    setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- AUDIT LOG - For tracking changes
-- ============================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50),
    entity_id INT,
    old_value LONGTEXT,
    new_value LONGTEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INSERT DEFAULT ROLES
-- ============================================
INSERT INTO roles (role_name, role_code, description, is_system_role) VALUES
('Developer Admin', 'developer', 'System developer dengan akses penuh', 1),
('Owner', 'owner', 'Pemilik bisnis dengan akses ke semua menu bisnis mereka', 1),
('Staff', 'staff', 'Staff biasa dengan akses terbatas sesuai permission', 1)
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

-- ============================================
-- INSERT DEFAULT MENU ITEMS
-- ============================================
INSERT INTO menu_items (menu_code, menu_name, menu_icon, menu_url, menu_order, is_active) VALUES
('dashboard', 'Dashboard', 'bi-speedometer2', 'index.php', 1, 1),
('cashbook', 'Cashbook', 'bi-journal-text', 'modules/cashbook/index.php', 2, 1),
('inventory', 'Inventory', 'bi-box2', 'modules/inventory/index.php', 3, 1),
('sales', 'Sales', 'bi-graph-up', 'modules/sales/index.php', 4, 1),
('users', 'User Management', 'bi-people', 'modules/users/index.php', 5, 1),
('reports', 'Reports', 'bi-file-earmark-pdf', 'modules/reports/index.php', 6, 1),
('settings', 'Settings', 'bi-gear', 'modules/settings/index.php', 7, 1)
ON DUPLICATE KEY UPDATE menu_name = VALUES(menu_name);

-- ============================================
-- INSERT DEFAULT SETTINGS
-- ============================================
INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES
('app_name', 'ADF System', 'string', 'Application name'),
('app_version', '2.0.0', 'string', 'Application version'),
('currency', 'IDR', 'string', 'Default currency'),
('timezone', 'Asia/Jakarta', 'string', 'System timezone'),
('session_timeout', '28800', 'number', 'Session timeout in seconds (8 hours)')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ============================================
-- SAMPLE DATA - Developer User
-- ============================================
-- Default password: admin123 (hashed with password_hash)
INSERT INTO users (username, email, password, full_name, phone, role_id, is_active) VALUES
('developer', 'developer@adf.local', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36P4/KFm', 'Developer Admin', '0811111111', 
(SELECT id FROM roles WHERE role_code = 'developer' LIMIT 1), 1)
ON DUPLICATE KEY UPDATE username = VALUES(username);

COMMIT;

-- ============================================
-- DATABASE SUMMARY
-- ============================================
-- This master database handles:
-- 1. User authentication & role management
-- 2. Business management (create, update, delete)
-- 3. Menu item definitions
-- 4. Business-specific menu configuration
-- 5. Fine-grained user permissions per business
-- 6. System settings
-- 7. Audit logging
--
-- Each business gets its own database with the same schema (business_template.sql)
-- ============================================
