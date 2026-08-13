-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 11, 2026 at 05:18 AM
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
-- Database: `itpms2`
--

-- --------------------------------------------------------

--
-- Table structure for table `it_requests`
--

CREATE TABLE `it_requests` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `requester` varchar(150) DEFAULT '',
  `category` enum('Hardware','Software','Account/Access','Network','Other') NOT NULL DEFAULT 'Other',
  `status` enum('Open','In Progress','Done') NOT NULL DEFAULT 'Open',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `it_requests`
--

INSERT INTO `it_requests` (`id`, `title`, `requester`, `category`, `status`, `notes`, `created_at`, `resolved_at`) VALUES
(7, 'CONNECT ETHERNET CABLE FOR INTERNET', 'ALL FINANCE STAFF', 'Network', 'Done', 'INTERNET CONNECTION LOST DUE TO TABLE RE-ARRANGEMENT.', '2026-07-31 07:36:00', '2026-07-30 20:55:28'),
(8, 'CAN\'T OPEN THE DESKTOP', 'BENEFITS STAFF - DAISY', 'Hardware', 'Done', 'CABLE CONNECTION LOST DUE TO TABLE RE-ARRANGEMENT.', '2026-07-31 07:36:00', '2026-07-31 20:57:00'),
(9, 'PRINTER CLEAN', 'FINANCE STAFF - MARIFE', 'Software', 'Done', 'NEED TO CLEAN EVERY MINUTE AND NEED TO WASTE PAD INK EVERY 2 DAYS', '2026-08-04 15:30:00', '2026-08-05 03:05:00'),
(10, 'EXPORT DTR FOR CANARY BATAAN', 'ERLYN', 'Software', 'Done', 'FOR AUGUST 03 - 06', '2026-08-06 10:04:00', '2026-08-06 10:22:00'),
(11, 'PRINTER CLEAN AND WASTE PAD INK', 'FINANCE ASST - ZYRA', 'Software', 'Done', 'THE PRINT IS FADE', '2026-08-10 09:18:00', '2026-08-10 09:45:00'),
(12, 'EXPORT DTR FOR CANARY BATAAN', 'ACCOUNT OFFICER - ERLYN', 'Software', 'Done', 'FOR AUGUST 04-09', '2026-08-10 09:47:00', '2026-08-10 09:44:00');

-- --------------------------------------------------------

--
-- Table structure for table `progress_history`
--

CREATE TABLE `progress_history` (
  `id` int(10) UNSIGNED NOT NULL,
  `project_id` varchar(20) NOT NULL,
  `entry_date` date NOT NULL,
  `progress` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `progress_history`
--

INSERT INTO `progress_history` (`id`, `project_id`, `entry_date`, `progress`, `notes`) VALUES
(1, 'PRJ-1000', '2026-06-04', 20, NULL),
(2, 'PRJ-1000', '2026-06-11', 30, NULL),
(3, 'PRJ-1000', '2026-06-18', 38, NULL),
(4, 'PRJ-1000', '2026-06-25', 48, NULL),
(5, 'PRJ-1000', '2026-07-02', 55, NULL),
(6, 'PRJ-1000', '2026-07-09', 100, NULL),
(7, 'PRJ-1001', '2026-06-04', 55, NULL),
(8, 'PRJ-1001', '2026-06-11', 68, NULL),
(9, 'PRJ-1001', '2026-06-18', 80, NULL),
(10, 'PRJ-1001', '2026-06-25', 90, NULL),
(11, 'PRJ-1001', '2026-07-02', 97, NULL),
(12, 'PRJ-1001', '2026-07-09', 100, NULL),
(13, 'PRJ-1002', '2026-06-04', 10, NULL),
(14, 'PRJ-1002', '2026-06-11', 18, NULL),
(15, 'PRJ-1002', '2026-06-18', 25, NULL),
(16, 'PRJ-1002', '2026-06-25', 30, NULL),
(17, 'PRJ-1002', '2026-07-02', 33, NULL),
(18, 'PRJ-1002', '2026-07-09', 100, NULL),
(19, 'PRJ-1003', '2026-06-04', 5, NULL),
(20, 'PRJ-1003', '2026-06-11', 8, NULL),
(21, 'PRJ-1003', '2026-06-18', 12, NULL),
(22, 'PRJ-1003', '2026-06-25', 15, NULL),
(23, 'PRJ-1003', '2026-07-02', 15, NULL),
(24, 'PRJ-1003', '2026-07-09', 100, NULL),
(25, 'PRJ-1004', '2026-07-09', 50, NULL),
(26, 'PRJ-1005', '2026-06-11', 5, NULL),
(27, 'PRJ-1005', '2026-06-18', 15, NULL),
(28, 'PRJ-1005', '2026-06-25', 25, NULL),
(29, 'PRJ-1005', '2026-07-02', 34, NULL),
(30, 'PRJ-1005', '2026-07-09', 50, NULL),
(31, 'PRJ-1006', '2026-06-04', 55, NULL),
(32, 'PRJ-1006', '2026-06-11', 62, NULL),
(33, 'PRJ-1006', '2026-06-18', 68, NULL),
(34, 'PRJ-1006', '2026-06-25', 72, NULL),
(35, 'PRJ-1006', '2026-07-02', 76, NULL),
(36, 'PRJ-1006', '2026-07-09', 100, NULL),
(41, 'PRJ-1007', '2026-07-09', 0, NULL),
(54, 'PRJ-1008', '2026-07-09', 0, NULL),
(55, 'PRJ-1009', '2026-07-09', 0, NULL),
(56, 'PRJ-1004', '2026-07-24', 100, NULL),
(57, 'PRJ-1010', '2026-07-24', 1, NULL),
(58, 'PRJ-1006', '2026-07-24', 100, NULL),
(60, 'PRJ-1000', '2026-07-27', 100, NULL),
(61, 'PRJ-1001', '2026-07-27', 100, NULL),
(62, 'PRJ-1005', '2026-07-27', 50, NULL),
(63, 'PRJ-1003', '2026-07-27', 100, NULL),
(64, 'PRJ-1002', '2026-07-27', 100, NULL),
(65, 'PRJ-1004', '2026-07-27', 100, NULL),
(66, 'PRJ-1006', '2026-07-27', 100, NULL),
(67, 'PRJ-1007', '2026-07-27', 0, NULL),
(78, 'PRJ-1010', '2026-07-27', 1, NULL),
(79, 'PRJ-1010', '2026-07-29', 1, NULL),
(80, 'PRJ-1007', '2026-07-29', 0, NULL),
(82, 'PRJ-1001', '2026-07-29', 100, NULL),
(83, 'PRJ-1002', '2026-07-29', 100, NULL),
(84, 'PRJ-1003', '2026-07-29', 100, NULL),
(85, 'PRJ-1004', '2026-07-29', 100, NULL),
(86, 'PRJ-1005', '2026-07-29', 50, NULL),
(87, 'PRJ-1006', '2026-07-29', 100, NULL),
(88, 'PRJ-1011', '2026-07-30', 40, NULL),
(90, 'PRJ-1011', '2026-08-05', 80, NULL),
(91, 'PRJ-1010', '2026-08-05', 3, NULL),
(92, 'PRJ-1010', '2026-08-06', 3, NULL),
(93, 'PRJ-1011', '2026-08-06', 80, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `id` varchar(20) NOT NULL,
  `name` varchar(255) NOT NULL,
  `status` enum('Completed','Ongoing','Onhold','Cancelled','Not Started') NOT NULL DEFAULT 'Not Started',
  `progress` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `owner` varchar(150) DEFAULT '',
  `priority` enum('Low','Medium','High','Critical') NOT NULL DEFAULT 'Medium',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `budget` decimal(14,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `file_link` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`id`, `name`, `status`, `progress`, `owner`, `priority`, `start_date`, `end_date`, `budget`, `description`, `file_link`, `created_at`, `updated_at`) VALUES
('PRJ-1000', 'Shared calendar', 'Completed', 100, 'Eris', 'Low', '2026-05-05', '2026-05-18', 0.00, 'Shared calendar for All leads and All operations.', 'https://calendar.google.com/calendar/u/0/r?msg=Could+not+find+the+requested+event.&msgtok=479d2ed62d8019e81cedc625817367520f7258eb', '2026-07-08 23:56:28', '2026-07-28 18:12:08'),
('PRJ-1001', 'Calling card', 'Completed', 100, 'Eris', 'Low', '2026-02-24', '2026-05-12', 0.00, 'Calling card design for all leads', 'https://www.canva.com/design/DAHB7FrSOoY/TmTjaPgk8b3CQ6Su46QGOw/edit', '2026-07-08 23:56:28', '2026-07-28 18:30:29'),
('PRJ-1002', 'PBSI pvc id', 'Completed', 100, 'Eris', 'Medium', '2026-06-22', '2026-06-26', 0.00, 'PBSI pvc id - 32 Employees', 'https://drive.google.com/drive/folders/1O9m_Mz5joGAHCBGVJOHjiQkzCIlPuf-5?usp=drive_link', '2026-07-08 23:56:28', '2026-07-28 18:31:16'),
('PRJ-1003', 'Company printer', 'Completed', 100, 'Eris', 'Low', '2026-04-27', '2026-06-10', 56000.00, 'Company printer for unit 1c and unit 2i', 'https://drive.google.com/file/d/1it5TmN42K8reZAuw8N2KD_-LdksoSk-S/view?usp=drive_link', '2026-07-08 23:56:28', '2026-07-28 18:32:37'),
('PRJ-1004', 'Canva, Gdrive, Zoom implementation', 'Completed', 100, 'Eris', 'Medium', '2026-04-29', '2026-07-16', 19014.90, 'Apps for all employees', 'https://docs.google.com/document/d/1dn-BVoScNmiRIr-gom-egkPtNAqq4pQpzHXLUqYZIHk/edit?usp=drive_link', '2026-07-08 23:56:28', '2026-07-28 18:37:56'),
('PRJ-1005', 'LinisPlus / Cleaning App and Management', 'Onhold', 50, 'Eris', 'High', '2024-11-22', '2026-12-31', 1000000.00, 'Website for admin and cleaners. App for customers.', 'https://drive.google.com/drive/folders/1jZ1zZO2l0JV42Ijg-JgUIk8OllQuaCSb?usp=drive_link', '2026-07-08 23:56:28', '2026-07-28 18:38:41'),
('PRJ-1006', 'Employee Attendance Monitoring System(EAMS)', 'Completed', 100, 'Eris', 'High', '2025-12-09', '2026-07-02', 120000.00, 'Attendance app and monitoring for all employees', 'https://hris.saveplusph.com/dashboard/overview', '2026-07-08 23:56:28', '2026-07-28 18:39:57'),
('PRJ-1007', 'Data banking', 'Not Started', 0, 'Eris', 'Low', NULL, NULL, 0.00, 'Data banking storage for all employees', NULL, '2026-07-09 00:10:28', '2026-07-28 18:28:39'),
('PRJ-1008', 'Network setup', 'Onhold', 0, 'Eris', 'Low', NULL, NULL, 0.00, 'Plan to 1 PLDT network only with backup and wired connections', NULL, '2026-07-09 00:38:20', '2026-07-09 00:38:20'),
('PRJ-1009', 'Canary biometric wired internet connection', 'Not Started', 0, 'Eris', 'Low', NULL, NULL, 0.00, 'For internet speed to reach public IP', NULL, '2026-07-09 00:41:07', '2026-07-09 00:41:07'),
('PRJ-1010', 'Backup/Archive files', 'Ongoing', 3, 'ERIS', 'Medium', '2026-07-23', NULL, 0.00, 'Google drive for Backup/Archive files', 'https://docs.google.com/spreadsheets/d/1yhc5UYw9jlKnMupqqSNyXNnpzd1lNsePs7m1fZ3c4Dg/edit?usp=sharing', '2026-07-24 01:38:26', '2026-08-06 01:20:11'),
('PRJ-1011', '3 ID CARD DESIGN RENDERS', 'Ongoing', 80, 'ERIS', 'Medium', '2026-07-30', NULL, 0.00, 'NEW SP ID DESIGN', 'https://canva.link/9hejb11fu4xj6nk', '2026-07-30 18:01:45', '2026-08-06 01:20:24');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(60) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `created_at`) VALUES
(1, 'admin', '$2y$10$ep4jkM.yXzK4JSYrQK1V.O8dRP.rTNkrOR/8f8ZKmdHGtYA2VdOXe', '2026-07-28 17:29:45');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `it_requests`
--
ALTER TABLE `it_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `progress_history`
--
ALTER TABLE `progress_history`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `project_date` (`project_id`,`entry_date`),
  ADD KEY `project_id` (`project_id`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `it_requests`
--
ALTER TABLE `it_requests`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `progress_history`
--
ALTER TABLE `progress_history`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=94;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `progress_history`
--
ALTER TABLE `progress_history`
  ADD CONSTRAINT `progress_history_project_fk` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
