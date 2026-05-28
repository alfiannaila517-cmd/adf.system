-- ============================================
-- Migration: addon_domain + production menu
-- Date: 2026-05-28
-- Run on: adf_system (master database)
-- ============================================

-- 1. Add addon_domain column to businesses table
ALTER TABLE businesses 
ADD COLUMN IF NOT EXISTS addon_domain VARCHAR(255) NULL DEFAULT NULL 
COMMENT 'Addon domain cPanel untuk bisnis ini' 
AFTER website;

-- 2. Add production menu to menu_items
INSERT IGNORE INTO menu_items (menu_code, menu_name, menu_icon, menu_url, menu_order, is_active, description)
VALUES ('production', 'Produksi', 'tool', 'modules/production/', 11, 1, 'Modul manajemen produksi furniture');

-- 3. Enable production menu for PWF Furniture (id=9)
-- Get menu id for production first, then assign to PWF
INSERT IGNORE INTO business_menu_config (business_id, menu_id, is_enabled)
SELECT 9, id, 1 FROM menu_items WHERE menu_code = 'production';
