-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 22, 2026 at 09:47 AM
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
-- Database: `gupta_dental_payments`
--

-- --------------------------------------------------------

--
-- Table structure for table `enquiry_sequences`
--

CREATE TABLE `enquiry_sequences` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `payment_record_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `enquiry_sequences`
--

INSERT INTO `enquiry_sequences` (`id`, `payment_record_id`, `created_at`) VALUES
(1, 9, '2026-11-22 02:23:36'),
(2, 10, '2026-11-22 02:24:48'),
(3, 11, '2026-11-22 02:31:46'),
(4, 12, '2026-11-22 02:33:17'),
(5, 14, '2026-11-22 06:40:54'),
(6, 13, '2026-11-22 06:41:23');

-- --------------------------------------------------------

--
-- Table structure for table `payment_attempts`
--

CREATE TABLE `payment_attempts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `payment_record_id` bigint(20) UNSIGNED NOT NULL,
  `razorpay_payment_id` varchar(100) NOT NULL,
  `payment_status` varchar(20) NOT NULL,
  `amount_paise` int(10) UNSIGNED NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'INR',
  `method` varchar(30) DEFAULT NULL,
  `error_code` varchar(100) DEFAULT NULL,
  `error_description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `verified_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payment_attempts`
--

INSERT INTO `payment_attempts` (`id`, `payment_record_id`, `razorpay_payment_id`, `payment_status`, `amount_paise`, `currency`, `method`, `error_code`, `error_description`, `created_at`, `updated_at`, `verified_at`) VALUES
(1, 9, 'pay_U33STawUy7oFBL', 'captured', 500, 'INR', 'netbanking', NULL, NULL, '2026-11-22 02:23:36', '2026-11-22 02:23:36', '2026-11-21 21:53:36'),
(2, 10, 'pay_U33Tk5MaXyJ8Yn', 'captured', 500, 'INR', 'netbanking', NULL, NULL, '2026-11-22 02:24:48', '2026-11-22 02:24:48', '2026-11-21 21:54:48'),
(3, 11, 'pay_U33b68N7950DL2', 'captured', 500, 'INR', 'netbanking', NULL, NULL, '2026-11-22 02:31:46', '2026-11-22 02:31:46', '2026-11-21 22:01:46'),
(4, 12, 'pay_U33cjBKC7WhHQ7', 'captured', 500, 'INR', 'netbanking', NULL, NULL, '2026-11-22 02:33:17', '2026-11-22 02:33:17', '2026-11-21 22:03:17'),
(5, 14, 'pay_U37qVDXyBDeDpE', 'captured', 500, 'INR', 'netbanking', NULL, NULL, '2026-11-22 06:40:54', '2026-11-22 06:41:06', '2026-11-22 06:41:06'),
(6, 13, 'pay_U37nrhmQIHHcYL', 'captured', 500, 'INR', 'netbanking', NULL, NULL, '2026-11-22 06:41:23', '2026-11-22 06:41:23', NULL),
(7, 15, 'pay_U37r4KHL0pHQPt', 'failed', 500, 'INR', 'netbanking', 'BAD_REQUEST_ERROR', 'Your payment didn\'t go through as it was declined by the bank. Try another payment method or contact your bank.', '2026-11-22 06:41:26', '2026-11-22 06:41:26', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `payment_records`
--

CREATE TABLE `payment_records` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `enquiry_reference` varchar(20) DEFAULT NULL,
  `razorpay_order_id` varchar(100) NOT NULL,
  `razorpay_payment_id` varchar(100) DEFAULT NULL,
  `amount_paise` int(10) UNSIGNED NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'INR',
  `payment_status` varchar(20) NOT NULL DEFAULT 'created',
  `signature_verified` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `verified_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payment_records`
--

INSERT INTO `payment_records` (`id`, `enquiry_reference`, `razorpay_order_id`, `razorpay_payment_id`, `amount_paise`, `currency`, `payment_status`, `signature_verified`, `created_at`, `updated_at`, `verified_at`) VALUES
(1, 'GDC-FF42484986F9', 'order_U326DGFDezZckr', NULL, 500, 'INR', 'created', 0, '2026-11-22 01:03:32', '2026-11-22 01:03:32', NULL),
(2, 'GDC-326435719087', 'order_U32EtDPQC5RA2u', 'pay_U32FZj56UuB54p', 500, 'INR', 'captured', 1, '2026-11-22 01:11:45', '2026-11-22 01:12:43', '2026-11-22 01:12:43'),
(3, 'GDC-6C0EDAA9C1FB', 'order_U32HwxAa4U6UlQ', 'pay_U32IGdhEUSd6Se', 500, 'INR', 'captured', 1, '2026-11-22 01:14:39', '2026-11-22 01:15:18', '2026-11-22 01:15:18'),
(4, 'GDC-857BD610D22A', 'order_U32JcZOt6IIkZ2', NULL, 500, 'INR', 'created', 0, '2026-11-22 01:16:13', '2026-11-22 01:16:13', NULL),
(5, 'GDC-D258A7A29B25', 'order_U32QfAkbNkkHk9', NULL, 500, 'INR', 'created', 0, '2026-11-22 01:22:53', '2026-11-22 01:22:53', NULL),
(6, 'GDC-A2D7EA06F274', 'order_U32UF006f1EFIN', NULL, 500, 'INR', 'created', 0, '2026-11-22 01:26:17', '2026-11-22 01:26:17', NULL),
(7, 'GDC-3B2732FCA521', 'order_U32VLOdS7bhV0D', 'pay_U32Ws2XnWOn20T', 500, 'INR', 'captured', 1, '2026-11-22 01:27:19', '2026-11-22 01:29:05', '2026-11-22 01:29:05'),
(8, NULL, 'order_U33LXSr1pSwvto', NULL, 500, 'INR', 'created', 0, '2026-11-22 02:16:44', '2026-11-22 02:16:44', NULL),
(9, 'GDC-000001', 'order_U33RIDnYDUKKLb', 'pay_U33STawUy7oFBL', 500, 'INR', 'captured', 1, '2026-11-22 02:22:11', '2026-11-22 02:23:36', '2026-11-22 02:23:36'),
(10, 'GDC-000002', 'order_U33TbtRTJyr7Z4', 'pay_U33Tk5MaXyJ8Yn', 500, 'INR', 'captured', 1, '2026-11-22 02:24:23', '2026-11-22 02:24:48', '2026-11-22 02:24:48'),
(11, 'GDC-000003', 'order_U33av6okmsGu1d', 'pay_U33b68N7950DL2', 500, 'INR', 'captured', 1, '2026-11-22 02:31:18', '2026-11-22 02:31:46', '2026-11-22 02:31:46'),
(12, 'GDC-000004', 'order_U33bgogmKW4Ope', 'pay_U33cjBKC7WhHQ7', 500, 'INR', 'captured', 1, '2026-11-22 02:32:01', '2026-11-22 02:33:17', '2026-11-22 02:33:17'),
(13, 'GDC-000006', 'order_U37njEsmzNSt7P', 'pay_U37nrhmQIHHcYL', 500, 'INR', 'captured', 0, '2026-11-22 06:38:12', '2026-11-22 06:41:23', '2026-11-22 06:41:23'),
(14, 'GDC-000005', 'order_U37qNkBwYI6HoN', 'pay_U37qVDXyBDeDpE', 500, 'INR', 'captured', 1, '2026-11-22 06:40:42', '2026-11-22 06:41:06', '2026-11-22 06:41:06'),
(15, NULL, 'order_U37qyaEPX14MRa', NULL, 500, 'INR', 'created', 0, '2026-11-22 06:41:16', '2026-11-22 06:41:16', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `webhook_events`
--

CREATE TABLE `webhook_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `razorpay_event_id` varchar(100) NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `payment_id` varchar(100) DEFAULT NULL,
  `order_id` varchar(100) DEFAULT NULL,
  `processing_status` varchar(20) NOT NULL DEFAULT 'processed',
  `received_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `webhook_events`
--

INSERT INTO `webhook_events` (`id`, `razorpay_event_id`, `event_type`, `payment_id`, `order_id`, `processing_status`, `received_at`, `processed_at`) VALUES
(1, 'U37qaK5eLoTXI2', 'payment.captured', 'pay_U37qVDXyBDeDpE', 'order_U37qNkBwYI6HoN', 'processed', '2026-11-22 06:40:54', '2026-11-22 06:40:54'),
(2, 'U37nxQhFVAA9eM', 'payment.captured', 'pay_U37nrhmQIHHcYL', 'order_U37njEsmzNSt7P', 'processed', '2026-11-22 06:41:23', '2026-11-22 06:41:23'),
(3, 'U37r8hfkDnrSOo', 'payment.failed', 'pay_U37r4KHL0pHQPt', 'order_U37qyaEPX14MRa', 'processed', '2026-11-22 06:41:26', '2026-11-22 06:41:26');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `enquiry_sequences`
--
ALTER TABLE `enquiry_sequences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payment_record_id` (`payment_record_id`);

--
-- Indexes for table `payment_attempts`
--
ALTER TABLE `payment_attempts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `razorpay_payment_id` (`razorpay_payment_id`),
  ADD KEY `idx_attempt_record_status` (`payment_record_id`,`payment_status`);

--
-- Indexes for table `payment_records`
--
ALTER TABLE `payment_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `enquiry_reference` (`enquiry_reference`),
  ADD UNIQUE KEY `razorpay_order_id` (`razorpay_order_id`),
  ADD UNIQUE KEY `razorpay_payment_id` (`razorpay_payment_id`),
  ADD KEY `idx_payment_status` (`payment_status`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `webhook_events`
--
ALTER TABLE `webhook_events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `razorpay_event_id` (`razorpay_event_id`),
  ADD KEY `idx_webhook_order_id` (`order_id`),
  ADD KEY `idx_webhook_payment_id` (`payment_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `enquiry_sequences`
--
ALTER TABLE `enquiry_sequences`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `payment_attempts`
--
ALTER TABLE `payment_attempts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `payment_records`
--
ALTER TABLE `payment_records`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `webhook_events`
--
ALTER TABLE `webhook_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `enquiry_sequences`
--
ALTER TABLE `enquiry_sequences`
  ADD CONSTRAINT `fk_sequence_payment` FOREIGN KEY (`payment_record_id`) REFERENCES `payment_records` (`id`);

--
-- Constraints for table `payment_attempts`
--
ALTER TABLE `payment_attempts`
  ADD CONSTRAINT `fk_attempt_payment_record` FOREIGN KEY (`payment_record_id`) REFERENCES `payment_records` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
