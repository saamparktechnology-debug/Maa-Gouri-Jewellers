-- =====================================================
-- Maa Gouri Jewellers / Jeweller Pro +  --  database.sql
-- Full schema, matching the live `gouri` database
-- Regenerated: 2026-07-17
-- =====================================================

CREATE DATABASE IF NOT EXISTS `gouri` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `gouri`;

-- --------------------------------------------------------
-- Users (login accounts)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `mobile` VARCHAR(15) NOT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mobile` (`mobile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Customers
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `mobile` VARCHAR(15) NOT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT '',
  `gst_number` VARCHAR(20) DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mobile` (`mobile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Advance / due-tracking customers
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `advance_customers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(11) DEFAULT NULL,
  `customer_name` VARCHAR(100) DEFAULT NULL,
  `customer_mobile` VARCHAR(15) DEFAULT NULL,
  `advance_amount` DECIMAL(10,2) DEFAULT 0.00,
  `advance_date` DATE DEFAULT NULL,
  `due_date` DATE DEFAULT NULL,
  `reminder_days` INT(11) DEFAULT 3,
  `status` VARCHAR(20) DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Products (stock items)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `products` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `serial_no` VARCHAR(50) DEFAULT NULL,
  `name` VARCHAR(200) NOT NULL,
  `item_name` VARCHAR(255) DEFAULT '',
  `weight` VARCHAR(20) DEFAULT NULL,
  `category` VARCHAR(50) DEFAULT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `quantity` INT(11) DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `serial_no` (`serial_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Invoices
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `invoice_no` VARCHAR(50) NOT NULL,
  `customer_name` VARCHAR(100) DEFAULT NULL,
  `customer_mobile` VARCHAR(15) DEFAULT NULL,
  `gst_type` ENUM('gst_3','gst_18','non_gst') DEFAULT 'non_gst',
  `subtotal` DECIMAL(10,2) DEFAULT NULL,
  `gst_amount` DECIMAL(10,2) DEFAULT NULL,
  `total_amount` DECIMAL(10,2) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `payment_status` VARCHAR(20) DEFAULT 'pending',
  `account_paid` DECIMAL(10,2) DEFAULT 0.00,
  `paid_amount` DECIMAL(10,2) DEFAULT 0.00,
  `balance_amount` DECIMAL(10,2) DEFAULT 0.00,
  `payment_method` VARCHAR(20) DEFAULT 'Cash',
  `reminder_sent` TINYINT(1) DEFAULT 0,
  `pdf_file` LONGBLOB DEFAULT NULL,
  `pdf_file_name` VARCHAR(255) DEFAULT NULL,
  `cash_paid` DECIMAL(10,2) DEFAULT 0.00,
  `upi_paid` DECIMAL(10,2) DEFAULT 0.00,
  `cheque_paid` DECIMAL(10,2) DEFAULT 0.00,
  `old_gold_value` DECIMAL(10,2) DEFAULT 0.00,
  `due_date` DATE DEFAULT NULL,
  `customer_address` VARCHAR(255) DEFAULT '',
  `customer_gstin` VARCHAR(20) DEFAULT '',
  `round_off` DECIMAL(10,2) DEFAULT 0.00,
  `is_split` TINYINT(1) DEFAULT 0,
  `bill_type` VARCHAR(10) DEFAULT 'invoice',
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_no` (`invoice_no`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Invoice Items
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` INT(11) DEFAULT NULL,
  `product_id` INT(11) DEFAULT NULL,
  `quantity` DECIMAL(10,3) DEFAULT NULL,
  `price` DECIMAL(10,2) DEFAULT NULL,
  `total` DECIMAL(10,2) DEFAULT NULL,
  `product_name` VARCHAR(200) DEFAULT NULL,
  `serial_no` VARCHAR(100) DEFAULT NULL,
  `hsn_code` VARCHAR(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `invoice_id` (`invoice_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `invoice_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`),
  CONSTRAINT `invoice_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Expenses
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `expenses` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `expense_date` DATE NOT NULL,
  `category` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `payment_method` ENUM('cash','card','upi','bank') DEFAULT 'cash',
  `bill_no` VARCHAR(50) DEFAULT NULL,
  `vendor_name` VARCHAR(100) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_date` (`expense_date`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Expense Categories
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `category_name` VARCHAR(50) NOT NULL,
  `status` ENUM('active','inactive') DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_name` (`category_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `expense_categories` (`id`, `category_name`, `status`) VALUES
(1, 'Purchase', 'active'),
(2, 'Rent', 'active'),
(3, 'Electricity Bill', 'active'),
(4, 'Salary', 'active'),
(5, 'Marketing', 'active'),
(6, 'Maintenance', 'active'),
(7, 'Tax Payment', 'active'),
(8, 'Transportation', 'active'),
(9, 'Other Expenses', 'active');

-- --------------------------------------------------------
-- Income
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `income` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `income_date` DATE NOT NULL,
  `source` VARCHAR(100) NOT NULL,
  `category` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `payment_method` ENUM('cash','card','upi','bank') DEFAULT 'cash',
  `invoice_no` VARCHAR(50) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_date` (`income_date`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Income Categories
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `income_categories` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `category_name` VARCHAR(50) NOT NULL,
  `status` ENUM('active','inactive') DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_name` (`category_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `income_categories` (`id`, `category_name`, `status`) VALUES
(1, 'Sales Income', 'active'),
(2, 'Interest Income', 'active'),
(3, 'Rental Income', 'active'),
(4, 'Commission Income', 'active'),
(5, 'Other Income', 'active');

-- --------------------------------------------------------
-- OTP Logins
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `otp_logins` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(100) NOT NULL,
  `otp` VARCHAR(6) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `is_used` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_otp` (`otp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Password Resets
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(100) NOT NULL,
  `otp` VARCHAR(6) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `is_used` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_otp` (`otp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Purchase Entries
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_entries` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `purchase_no` VARCHAR(50) NOT NULL,
  `purchase_date` DATE NOT NULL,
  `invoice_no` VARCHAR(100) NOT NULL,
  `invoice_date` DATE NOT NULL,
  `ref_no` VARCHAR(100) DEFAULT NULL,
  `ref_date` DATE DEFAULT NULL,
  `payment_mode` VARCHAR(50) DEFAULT 'NEFT/RTGS',
  `supplier_name` VARCHAR(200) NOT NULL,
  `supplier_addr` VARCHAR(500) DEFAULT NULL,
  `supplier_gstin` VARCHAR(20) DEFAULT NULL,
  `supplier_pan` VARCHAR(20) DEFAULT NULL,
  `supplier_state` VARCHAR(100) DEFAULT NULL,
  `supplier_state_code` VARCHAR(5) DEFAULT NULL,
  `supplier_mobile` VARCHAR(20) DEFAULT NULL,
  `supplier_email` VARCHAR(100) DEFAULT NULL,
  `buyer_name` VARCHAR(200) DEFAULT ' GOURI JEWELLERS',
  `buyer_addr` VARCHAR(500) DEFAULT NULL,
  `buyer_gstin` VARCHAR(20) DEFAULT NULL,
  `buyer_pan` VARCHAR(20) DEFAULT NULL,
  `material_type` ENUM('Gold','Silver','Diamond','Platinum') NOT NULL,
  `description` VARCHAR(300) DEFAULT NULL,
  `hsn_sac` VARCHAR(20) DEFAULT NULL,
  `qty` DECIMAL(12,4) NOT NULL,
  `unit` VARCHAR(10) DEFAULT 'gm',
  `rate_per_unit` DECIMAL(12,4) NOT NULL,
  `tax_type` ENUM('CGST_SGST','IGST') DEFAULT 'CGST_SGST',
  `cgst_pct` DECIMAL(5,2) DEFAULT 1.50,
  `sgst_pct` DECIMAL(5,2) DEFAULT 1.50,
  `igst_pct` DECIMAL(5,2) DEFAULT 3.00,
  `subtotal` DECIMAL(14,2) DEFAULT NULL,
  `cgst_amt` DECIMAL(14,2) DEFAULT 0.00,
  `sgst_amt` DECIMAL(14,2) DEFAULT 0.00,
  `igst_amt` DECIMAL(14,2) DEFAULT 0.00,
  `gst_total` DECIMAL(14,2) DEFAULT NULL,
  `total_amount` DECIMAL(14,2) DEFAULT NULL,
  `amount_words` VARCHAR(500) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_no` (`purchase_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Purchase Items
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` INT(11) NOT NULL,
  `material_type` ENUM('Gold','Silver','Diamond','Platinum') NOT NULL,
  `huid_code` VARCHAR(50) DEFAULT '',
  `description` VARCHAR(300) DEFAULT NULL,
  `hsn_sac` VARCHAR(20) DEFAULT NULL,
  `qty` DECIMAL(12,4) NOT NULL,
  `unit` VARCHAR(10) DEFAULT 'gm',
  `rate_per_unit` DECIMAL(12,4) NOT NULL,
  `tax_type` ENUM('CGST_SGST','IGST') DEFAULT 'CGST_SGST',
  `cgst_pct` DECIMAL(5,2) DEFAULT 1.50,
  `sgst_pct` DECIMAL(5,2) DEFAULT 1.50,
  `igst_pct` DECIMAL(5,2) DEFAULT 3.00,
  `subtotal` DECIMAL(14,2) DEFAULT NULL,
  `cgst_amt` DECIMAL(14,2) DEFAULT 0.00,
  `sgst_amt` DECIMAL(14,2) DEFAULT 0.00,
  `igst_amt` DECIMAL(14,2) DEFAULT 0.00,
  `gst_total` DECIMAL(14,2) DEFAULT NULL,
  `total_amount` DECIMAL(14,2) DEFAULT NULL,
  `amount_words` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_purchase_id` (`purchase_id`),
  CONSTRAINT `purchase_items_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `purchase_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Reminder Settings
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reminder_settings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `reminder_type` VARCHAR(50) DEFAULT 'due',
  `days_before` INT(11) DEFAULT 0,
  `reminder_time` TIME DEFAULT '10:00:00',
  `is_active` INT(11) DEFAULT 1,
  `template_id` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `reminder_settings` (`id`, `reminder_type`, `days_before`, `reminder_time`, `is_active`, `template_id`) VALUES
(1, 'due', -7, '10:00:00', 1, 1),
(2, 'due', -3, '10:00:00', 1, 2),
(3, 'due', 0, '10:00:00', 1, 3);

-- --------------------------------------------------------
-- Stock Metal (gold/silver/diamond/platinum stock ledger)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stock_metal` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `material_type` ENUM('Gold','Silver','Diamond','Platinum') NOT NULL,
  `unit` VARCHAR(10) DEFAULT 'gm',
  `qty_available` DECIMAL(14,4) DEFAULT 0.0000,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_material` (`material_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `stock_metal` (`material_type`, `unit`, `qty_available`) VALUES
('Gold', 'gm', 0.0000),
('Silver', 'gm', 0.0000),
('Diamond', 'gm', 0.0000),
('Platinum', 'gm', 0.0000);

-- --------------------------------------------------------
-- WhatsApp Logs
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `whatsapp_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `recipient_number` VARCHAR(20) NOT NULL,
  `recipient_name` VARCHAR(100) DEFAULT NULL,
  `message_type` VARCHAR(50) DEFAULT NULL,
  `message_content` TEXT DEFAULT NULL,
  `status` VARCHAR(20) DEFAULT 'pending',
  `api_response` TEXT DEFAULT NULL,
  `sent_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `media_file_path` TEXT DEFAULT NULL,
  `media_file_name` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_recipient` (`recipient_number`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- WhatsApp Settings
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `whatsapp_settings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `api_type` VARCHAR(50) DEFAULT 'greenapi',
  `api_url` VARCHAR(255) DEFAULT NULL,
  `api_token` VARCHAR(255) DEFAULT NULL,
  `instance_id` VARCHAR(100) DEFAULT NULL,
  `phone_number_id` VARCHAR(100) DEFAULT NULL,
  `access_token` TEXT DEFAULT NULL,
  `status` VARCHAR(20) DEFAULT 'inactive',
  `last_updated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `reminder_days` INT(11) DEFAULT 3,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- WhatsApp Templates
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `whatsapp_templates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `template_name` VARCHAR(100) NOT NULL,
  `template_type` VARCHAR(50) DEFAULT 'custom',
  `message_content` TEXT NOT NULL,
  `variables` TEXT DEFAULT NULL,
  `status` VARCHAR(20) DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `whatsapp_templates` (`id`, `template_name`, `template_type`, `message_content`, `status`) VALUES
(1, 'Due Reminder - 7 Days', 'due_reminder', 'Dear {name}, this is a friendly reminder that you have a pending payment of Rs.{amount} from Gouri Jewellers. Please clear your dues within 7 days to avoid late fees. Thank you!', 'active'),
(2, 'Due Reminder - 3 Days', 'due_reminder', 'URGENT: Dear {name}, your payment of Rs.{amount} at Gouri Jewellers is due in 3 days. Late payment charges may apply. Please settle at your earliest convenience.', 'active'),
(3, 'Due Reminder - Overdue', 'due_reminder', 'OVERDUE ALERT: Dear {name}, your payment of Rs.{amount} at Gouri Jewellers is now OVERDUE by {days} days. Please make the payment immediately. Contact us for assistance.', 'active'),
(4, 'Payment Received', 'custom', 'Dear {name}, thank you for your payment of Rs.{amount}. Your account is now up to date. Thank you for choosing Gouri Jewellers!', 'active'),
(5, 'Festival Greeting', 'festival_greeting', 'Wishing you and your family a very Happy {festival}! Enjoy special discounts on your next purchase at Gouri Jewellers.', 'active');

-- --------------------------------------------------------
-- View: customer_due_summary
-- (Pending-payment summary per customer, used by due_list.php)
-- --------------------------------------------------------
DROP VIEW IF EXISTS `customer_due_summary`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `customer_due_summary` AS
SELECT
  `c`.`id` AS `customer_id`,
  `c`.`name` AS `customer_name`,
  `c`.`mobile` AS `customer_mobile`,
  COUNT(`i`.`id`) AS `total_invoices`,
  SUM(`i`.`total_amount`) AS `total_due`,
  MAX(`i`.`created_at`) AS `last_invoice_date`,
  TO_DAYS(CURDATE()) - TO_DAYS(IFNULL(MAX(`i`.`created_at`), CURDATE())) AS `days_overdue`
FROM `customers` `c`
LEFT JOIN `invoices` `i` ON `c`.`mobile` = `i`.`customer_mobile`
WHERE `i`.`payment_status` IS NULL OR `i`.`payment_status` = 'pending'
GROUP BY `c`.`id`, `c`.`name`, `c`.`mobile`
HAVING `total_due` > 0;

-- --------------------------------------------------------
-- Default admin login (mobile: 96472 91299, password: 123456)
-- Change this password after first login.
-- --------------------------------------------------------
INSERT IGNORE INTO `users` (`name`, `mobile`, `email`, `password`) VALUES
('Admin User', '96472 91299', 'admin@gourijewellers.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');