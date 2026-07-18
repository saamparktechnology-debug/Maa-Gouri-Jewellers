-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 18, 2026 at 05:34 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `gouri`
--

-- --------------------------------------------------------

--
-- Table structure for table `advance_customers`
--

CREATE TABLE `advance_customers` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `customer_mobile` varchar(15) DEFAULT NULL,
  `advance_amount` decimal(10,2) DEFAULT 0.00,
  `advance_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `reminder_days` int(11) DEFAULT 3,
  `status` varchar(20) DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `mobile` varchar(15) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT '',
  `gst_number` varchar(20) DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `customer_due_summary`
-- (See below for the actual view)
--
CREATE TABLE `customer_due_summary` (
`customer_id` int(11)
,`customer_name` varchar(100)
,`customer_mobile` varchar(15)
,`total_invoices` bigint(21)
,`total_due` decimal(32,2)
,`last_invoice_date` timestamp
,`days_overdue` int(7)
);

-- --------------------------------------------------------

--
-- Table structure for table `due_update_history`
--

CREATE TABLE `due_update_history` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `previous_balance` decimal(10,2) NOT NULL,
  `new_balance` decimal(10,2) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `payment_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
  `expense_date` date NOT NULL,
  `category` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  `payment_method` enum('cash','card','upi','bank') DEFAULT 'cash',
  `bill_no` varchar(50) DEFAULT NULL,
  `vendor_name` varchar(100) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expense_categories`
--

CREATE TABLE `expense_categories` (
  `id` int(11) NOT NULL,
  `category_name` varchar(50) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `expense_categories`
--

INSERT INTO `expense_categories` (`id`, `category_name`, `status`) VALUES
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

--
-- Table structure for table `income`
--

CREATE TABLE `income` (
  `id` int(11) NOT NULL,
  `income_date` date NOT NULL,
  `source` varchar(100) NOT NULL,
  `category` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  `payment_method` enum('cash','card','upi','bank') DEFAULT 'cash',
  `invoice_no` varchar(50) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `income_categories`
--

CREATE TABLE `income_categories` (
  `id` int(11) NOT NULL,
  `category_name` varchar(50) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `income_categories`
--

INSERT INTO `income_categories` (`id`, `category_name`, `status`) VALUES
(1, 'Sales Income', 'active'),
(2, 'Interest Income', 'active'),
(3, 'Rental Income', 'active'),
(4, 'Commission Income', 'active'),
(5, 'Other Income', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL,
  `invoice_no` varchar(50) NOT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `customer_mobile` varchar(15) DEFAULT NULL,
  `gst_type` enum('gst_3','gst_18','non_gst') DEFAULT 'non_gst',
  `subtotal` decimal(10,2) DEFAULT NULL,
  `gst_amount` decimal(10,2) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_status` varchar(20) DEFAULT 'pending',
  `account_paid` decimal(10,2) DEFAULT 0.00,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `balance_amount` decimal(10,2) DEFAULT 0.00,
  `payment_method` varchar(20) DEFAULT 'Cash',
  `reminder_sent` tinyint(1) DEFAULT 0,
  `pdf_file` longblob DEFAULT NULL,
  `pdf_file_name` varchar(255) DEFAULT NULL,
  `cash_paid` decimal(10,2) DEFAULT 0.00,
  `upi_paid` decimal(10,2) DEFAULT 0.00,
  `cheque_paid` decimal(10,2) DEFAULT 0.00,
  `old_gold_value` decimal(10,2) DEFAULT 0.00,
  `due_date` date DEFAULT NULL,
  `customer_address` varchar(255) DEFAULT '',
  `customer_gstin` varchar(20) DEFAULT '',
  `round_off` decimal(10,2) DEFAULT 0.00,
  `is_split` tinyint(1) DEFAULT 0,
  `bill_type` varchar(10) DEFAULT 'invoice'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` decimal(10,3) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `total` decimal(10,2) DEFAULT NULL,
  `product_name` varchar(200) DEFAULT NULL,
  `serial_no` varchar(100) DEFAULT NULL,
  `hsn_code` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otp_logins`
--

CREATE TABLE `otp_logins` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `otp` varchar(6) NOT NULL,
  `expires_at` datetime NOT NULL,
  `is_used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `otp_logins`
--

INSERT INTO `otp_logins` (`id`, `email`, `otp`, `expires_at`, `is_used`, `created_at`) VALUES
(2, 'csuraj156@gmail.com', '929524', '2026-07-14 11:05:38', 1, '2026-07-14 05:25:38'),
(4, 'csuraj156@gmail.com', '273225', '2026-07-14 11:39:15', 1, '2026-07-14 05:59:15'),
(7, 'csuraj156@gmail.com', '859690', '2026-07-15 00:01:30', 1, '2026-07-14 18:21:30'),
(8, 'csuraj156@gmail.com', '958238', '2026-07-15 00:07:50', 0, '2026-07-14 18:27:50'),
(9, 'skmehebubali34@gmail.com', '709954', '2026-07-15 00:13:51', 1, '2026-07-14 18:33:51');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `otp` varchar(6) NOT NULL,
  `expires_at` datetime NOT NULL,
  `is_used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `serial_no` varchar(50) DEFAULT NULL,
  `name` varchar(200) NOT NULL,
  `item_name` varchar(255) DEFAULT '',
  `weight` varchar(20) DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `quantity` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `serial_no`, `name`, `item_name`, `weight`, `category`, `price`, `quantity`, `created_at`) VALUES
(1, 'SN0001', 'Gold Necklace', '', NULL, 'Gold', 45000.00, 10, '2026-07-14 03:20:05'),
(2, 'SN0002', 'Diamond Ring', '', NULL, 'Diamond', 85000.00, 5, '2026-07-14 03:20:05'),
(3, 'SN0003', 'Silver Earrings', '', NULL, 'Silver', 3500.00, 25, '2026-07-14 03:20:05'),
(4, 'SN0004', 'Gold Bangles', '', NULL, 'Gold', 28000.00, 8, '2026-07-14 03:20:05'),
(5, 'SN0005', 'Platinum Chain', '', NULL, 'Platinum', 125000.00, 3, '2026-07-14 03:20:05'),
(6, 'SN0006', 'Ruby Pendant', '', NULL, 'Gemstone', 32000.00, 6, '2026-07-14 03:20:05'),
(7, 'SN0007', 'Gold Mangalsutra', '', NULL, 'Gold', 65000.00, 4, '2026-07-14 03:20:05');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_entries`
--

CREATE TABLE `purchase_entries` (
  `id` int(11) NOT NULL,
  `purchase_no` varchar(50) NOT NULL,
  `purchase_date` date NOT NULL,
  `invoice_no` varchar(100) NOT NULL,
  `invoice_date` date NOT NULL,
  `ref_no` varchar(100) DEFAULT NULL,
  `ref_date` date DEFAULT NULL,
  `payment_mode` varchar(50) DEFAULT 'NEFT/RTGS',
  `supplier_name` varchar(200) NOT NULL,
  `supplier_addr` varchar(500) DEFAULT NULL,
  `supplier_gstin` varchar(20) DEFAULT NULL,
  `supplier_pan` varchar(20) DEFAULT NULL,
  `supplier_state` varchar(100) DEFAULT NULL,
  `supplier_state_code` varchar(5) DEFAULT NULL,
  `supplier_mobile` varchar(20) DEFAULT NULL,
  `supplier_email` varchar(100) DEFAULT NULL,
  `buyer_name` varchar(200) DEFAULT ' GOURI JEWELLERS',
  `buyer_addr` varchar(500) DEFAULT NULL,
  `buyer_gstin` varchar(20) DEFAULT NULL,
  `buyer_pan` varchar(20) DEFAULT NULL,
  `material_type` enum('Gold','Silver','Diamond','Platinum') NOT NULL,
  `description` varchar(300) DEFAULT NULL,
  `hsn_sac` varchar(20) DEFAULT NULL,
  `qty` decimal(12,4) NOT NULL,
  `unit` varchar(10) DEFAULT 'gm',
  `rate_per_unit` decimal(12,4) NOT NULL,
  `tax_type` enum('CGST_SGST','IGST') DEFAULT 'CGST_SGST',
  `cgst_pct` decimal(5,2) DEFAULT 1.50,
  `sgst_pct` decimal(5,2) DEFAULT 1.50,
  `igst_pct` decimal(5,2) DEFAULT 3.00,
  `subtotal` decimal(14,2) DEFAULT NULL,
  `cgst_amt` decimal(14,2) DEFAULT 0.00,
  `sgst_amt` decimal(14,2) DEFAULT 0.00,
  `igst_amt` decimal(14,2) DEFAULT 0.00,
  `gst_total` decimal(14,2) DEFAULT NULL,
  `total_amount` decimal(14,2) DEFAULT NULL,
  `amount_words` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_items`
--

CREATE TABLE `purchase_items` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `material_type` enum('Gold','Silver','Diamond','Platinum') NOT NULL,
  `huid_code` varchar(50) DEFAULT '',
  `description` varchar(300) DEFAULT NULL,
  `hsn_sac` varchar(20) DEFAULT NULL,
  `qty` decimal(12,4) NOT NULL,
  `unit` varchar(10) DEFAULT 'gm',
  `rate_per_unit` decimal(12,4) NOT NULL,
  `tax_type` enum('CGST_SGST','IGST') DEFAULT 'CGST_SGST',
  `cgst_pct` decimal(5,2) DEFAULT 1.50,
  `sgst_pct` decimal(5,2) DEFAULT 1.50,
  `igst_pct` decimal(5,2) DEFAULT 3.00,
  `subtotal` decimal(14,2) DEFAULT NULL,
  `cgst_amt` decimal(14,2) DEFAULT 0.00,
  `sgst_amt` decimal(14,2) DEFAULT 0.00,
  `igst_amt` decimal(14,2) DEFAULT 0.00,
  `gst_total` decimal(14,2) DEFAULT NULL,
  `total_amount` decimal(14,2) DEFAULT NULL,
  `amount_words` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reminder_settings`
--

CREATE TABLE `reminder_settings` (
  `id` int(11) NOT NULL,
  `reminder_type` varchar(50) DEFAULT 'due',
  `days_before` int(11) DEFAULT 0,
  `reminder_time` time DEFAULT '10:00:00',
  `is_active` int(11) DEFAULT 1,
  `template_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reminder_settings`
--

INSERT INTO `reminder_settings` (`id`, `reminder_type`, `days_before`, `reminder_time`, `is_active`, `template_id`, `created_at`) VALUES
(1, 'due', -7, '10:00:00', 1, 1, '2026-07-14 03:20:06'),
(2, 'due', -3, '10:00:00', 1, 2, '2026-07-14 03:20:06'),
(3, 'due', 0, '10:00:00', 1, 3, '2026-07-14 03:20:06');

-- --------------------------------------------------------

--
-- Table structure for table `stock_metal`
--

CREATE TABLE `stock_metal` (
  `id` int(11) NOT NULL,
  `material_type` enum('Gold','Silver','Diamond','Platinum') NOT NULL,
  `unit` varchar(10) DEFAULT 'gm',
  `qty_available` decimal(14,4) DEFAULT 0.0000,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_metal`
--

INSERT INTO `stock_metal` (`id`, `material_type`, `unit`, `qty_available`, `updated_at`) VALUES
(1, 'Gold', 'gm', 0.0000, '2026-07-17 06:33:22'),
(2, 'Silver', 'gm', 0.0000, '2026-07-17 06:33:22'),
(3, 'Diamond', 'gm', 0.0000, '2026-07-17 06:33:22'),
(4, 'Platinum', 'gm', 0.0000, '2026-07-17 06:33:22');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `mobile` varchar(15) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `mobile`, `email`, `password`, `created_at`) VALUES
(1, 'Admin User', '9876543210', 'supriyodas@gmail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-07-14 03:20:05'),
(3, 'Admin User', '96472 91299', 'skmehebubali34@gmail.com', '$2y$10$GGITBjRGqCcthD8EC0Yodewm50mdCped8VrYKX.yA8m/sSHzf6ZQK', '2026-07-14 03:24:07'),
(4, 'Admin User', '9064292987', 'csuraj156@gmail.com', '$2y$10$Bw/gQSwmLrhHMXDTAz1MteP1DoVYGi7/AtYUNTU1EVq5/yn4P5afu', '2026-07-17 05:26:44');

-- --------------------------------------------------------

--
-- Table structure for table `whatsapp_logs`
--

CREATE TABLE `whatsapp_logs` (
  `id` int(11) NOT NULL,
  `recipient_number` varchar(20) NOT NULL,
  `recipient_name` varchar(100) DEFAULT NULL,
  `message_type` varchar(50) DEFAULT NULL,
  `message_content` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `api_response` text DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `media_file_path` text DEFAULT NULL,
  `media_file_name` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `whatsapp_settings`
--

CREATE TABLE `whatsapp_settings` (
  `id` int(11) NOT NULL,
  `api_type` varchar(50) DEFAULT 'greenapi',
  `api_url` varchar(255) DEFAULT NULL,
  `api_token` varchar(255) DEFAULT NULL,
  `instance_id` varchar(100) DEFAULT NULL,
  `phone_number_id` varchar(100) DEFAULT NULL,
  `access_token` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'inactive',
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reminder_days` int(11) DEFAULT 3
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `whatsapp_templates`
--

CREATE TABLE `whatsapp_templates` (
  `id` int(11) NOT NULL,
  `template_name` varchar(100) NOT NULL,
  `template_type` varchar(50) DEFAULT 'custom',
  `message_content` text NOT NULL,
  `variables` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `whatsapp_templates`
--

INSERT INTO `whatsapp_templates` (`id`, `template_name`, `template_type`, `message_content`, `variables`, `status`, `created_at`) VALUES
(1, 'Due Reminder - 7 Days', 'due_reminder', 'Dear {name}, this is a friendly reminder that you have a pending payment of ₹{amount} from Moti Jewellers. Please clear your dues within 7 days to avoid late fees. Thank you!', NULL, 'active', '2026-07-14 03:20:06'),
(2, 'Due Reminder - 3 Days', 'due_reminder', 'URGENT: Dear {name}, your payment of ₹{amount} at Moti Jewellers is due in 3 days. Late payment charges may apply. Please settle at your earliest convenience.', NULL, 'active', '2026-07-14 03:20:06'),
(3, 'Due Reminder - Overdue', 'due_reminder', 'OVERDUE ALERT: Dear {name}, your payment of ₹{amount} at Moti Jewellers is now OVERDUE by {days} days. Please make the payment immediately. Contact us for assistance.', NULL, 'active', '2026-07-14 03:20:06'),
(4, 'Payment Received', 'custom', 'Dear {name}, thank you for your payment of ₹{amount}. Your account is now up to date. Thank you for choosing Moti Jewellers!', NULL, 'active', '2026-07-14 03:20:06'),
(5, 'Festival Greeting', 'festival_greeting', 'Wishing you and your family a very Happy {festival}! Enjoy special discounts on your next purchase at Moti Jewellers.', NULL, 'active', '2026-07-14 03:20:06');

-- --------------------------------------------------------

--
-- Structure for view `customer_due_summary`
--
DROP TABLE IF EXISTS `customer_due_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `customer_due_summary`  AS SELECT `c`.`id` AS `customer_id`, `c`.`name` AS `customer_name`, `c`.`mobile` AS `customer_mobile`, count(`i`.`id`) AS `total_invoices`, sum(`i`.`total_amount`) AS `total_due`, max(`i`.`created_at`) AS `last_invoice_date`, to_days(curdate()) - to_days(ifnull(max(`i`.`created_at`),curdate())) AS `days_overdue` FROM (`customers` `c` left join `invoices` `i` on(`c`.`mobile` = `i`.`customer_mobile`)) WHERE `i`.`payment_status` is null OR `i`.`payment_status` = 'pending' GROUP BY `c`.`id`, `c`.`name`, `c`.`mobile` HAVING `total_due` > 0 ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `advance_customers`
--
ALTER TABLE `advance_customers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `mobile` (`mobile`);

--
-- Indexes for table `due_update_history`
--
ALTER TABLE `due_update_history`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_date` (`expense_date`),
  ADD KEY `idx_category` (`category`);

--
-- Indexes for table `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `category_name` (`category_name`);

--
-- Indexes for table `income`
--
ALTER TABLE `income`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_date` (`income_date`),
  ADD KEY `idx_category` (`category`);

--
-- Indexes for table `income_categories`
--
ALTER TABLE `income_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `category_name` (`category_name`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_no` (`invoice_no`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `otp_logins`
--
ALTER TABLE `otp_logins`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_otp` (`otp`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_otp` (`otp`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `serial_no` (`serial_no`);

--
-- Indexes for table `purchase_entries`
--
ALTER TABLE `purchase_entries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `purchase_no` (`purchase_no`);

--
-- Indexes for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchase_id` (`purchase_id`);

--
-- Indexes for table `reminder_settings`
--
ALTER TABLE `reminder_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `stock_metal`
--
ALTER TABLE `stock_metal`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_material` (`material_type`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `mobile` (`mobile`);

--
-- Indexes for table `whatsapp_logs`
--
ALTER TABLE `whatsapp_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_recipient` (`recipient_number`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `whatsapp_settings`
--
ALTER TABLE `whatsapp_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `whatsapp_templates`
--
ALTER TABLE `whatsapp_templates`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `advance_customers`
--
ALTER TABLE `advance_customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `due_update_history`
--
ALTER TABLE `due_update_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expense_categories`
--
ALTER TABLE `expense_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `income`
--
ALTER TABLE `income`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `income_categories`
--
ALTER TABLE `income_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `otp_logins`
--
ALTER TABLE `otp_logins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `purchase_entries`
--
ALTER TABLE `purchase_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_items`
--
ALTER TABLE `purchase_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reminder_settings`
--
ALTER TABLE `reminder_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `stock_metal`
--
ALTER TABLE `stock_metal`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `whatsapp_logs`
--
ALTER TABLE `whatsapp_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `whatsapp_settings`
--
ALTER TABLE `whatsapp_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `whatsapp_templates`
--
ALTER TABLE `whatsapp_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `invoice_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`),
  ADD CONSTRAINT `invoice_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD CONSTRAINT `purchase_items_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `purchase_entries` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
