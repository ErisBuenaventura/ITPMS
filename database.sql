-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 29, 2026 at 04:43 AM
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
(1, 'Laptop', 'Anj', 'Hardware', 'Open', 'Can\'t open', '2026-07-29 01:29:00', NULL),
(2, 'Reset email password', 'J. Cruz (HR)', 'Account/Access', 'Done', 'Reset via webmail admin panel.', '2026-07-20 09:10:00', '2026-07-20 09:20:00'),
(3, 'Printer jam — unit 2i', 'Front desk', 'Hardware', 'Done', 'Cleared paper jam, replaced fuser roller.', '2026-07-22 13:05:00', '2026-07-22 13:40:00'),
(4, 'Install Zoom for new hire', 'M. Santos (Ops)', 'Software', 'Open', NULL, '2026-07-27 10:00:00', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `progress_history`
--

CREATE TABLE `progress_history` (
  `id` int(10) UNSIGNED NOT NULL,
  `project_id` varchar(20) NOT NULL,
  `entry_date` date NOT NULL,
  `progress` tinyint(3) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `progress_history`
--

INSERT INTO `progress_history` (`id`, `project_id`, `entry_date`, `progress`) VALUES
(1, 'PRJ-1000', '2026-06-04', 20),
(2, 'PRJ-1000', '2026-06-11', 30),
(3, 'PRJ-1000', '2026-06-18', 38),
(4, 'PRJ-1000', '2026-06-25', 48),
(5, 'PRJ-1000', '2026-07-02', 55),
(6, 'PRJ-1000', '2026-07-09', 100),
(7, 'PRJ-1001', '2026-06-04', 55),
(8, 'PRJ-1001', '2026-06-11', 68),
(9, 'PRJ-1001', '2026-06-18', 80),
(10, 'PRJ-1001', '2026-06-25', 90),
(11, 'PRJ-1001', '2026-07-02', 97),
(12, 'PRJ-1001', '2026-07-09', 100),
(13, 'PRJ-1002', '2026-06-04', 10),
(14, 'PRJ-1002', '2026-06-11', 18),
(15, 'PRJ-1002', '2026-06-18', 25),
(16, 'PRJ-1002', '2026-06-25', 30),
(17, 'PRJ-1002', '2026-07-02', 33),
(18, 'PRJ-1002', '2026-07-09', 100),
(19, 'PRJ-1003', '2026-06-04', 5),
(20, 'PRJ-1003', '2026-06-11', 8),
(21, 'PRJ-1003', '2026-06-18', 12),
(22, 'PRJ-1003', '2026-06-25', 15),
(23, 'PRJ-1003', '2026-07-02', 15),
(24, 'PRJ-1003', '2026-07-09', 100),
(25, 'PRJ-1004', '2026-07-09', 50),
(26, 'PRJ-1005', '2026-06-11', 5),
(27, 'PRJ-1005', '2026-06-18', 15),
(28, 'PRJ-1005', '2026-06-25', 25),
(29, 'PRJ-1005', '2026-07-02', 34),
(30, 'PRJ-1005', '2026-07-09', 50),
(31, 'PRJ-1006', '2026-06-04', 55),
(32, 'PRJ-1006', '2026-06-11', 62),
(33, 'PRJ-1006', '2026-06-18', 68),
(34, 'PRJ-1006', '2026-06-25', 72),
(35, 'PRJ-1006', '2026-07-02', 76),
(36, 'PRJ-1006', '2026-07-09', 100),
(41, 'PRJ-1007', '2026-07-09', 0),
(54, 'PRJ-1008', '2026-07-09', 0),
(55, 'PRJ-1009', '2026-07-09', 0),
(56, 'PRJ-1004', '2026-07-24', 100),
(57, 'PRJ-1010', '2026-07-24', 1),
(58, 'PRJ-1006', '2026-07-24', 100),
(60, 'PRJ-1000', '2026-07-27', 100),
(61, 'PRJ-1001', '2026-07-27', 100),
(62, 'PRJ-1005', '2026-07-27', 50),
(63, 'PRJ-1003', '2026-07-27', 100),
(64, 'PRJ-1002', '2026-07-27', 100),
(65, 'PRJ-1004', '2026-07-27', 100),
(66, 'PRJ-1006', '2026-07-27', 100),
(67, 'PRJ-1007', '2026-07-27', 0),
(78, 'PRJ-1010', '2026-07-27', 1),
(79, 'PRJ-1010', '2026-07-29', 1),
(80, 'PRJ-1007', '2026-07-29', 0),
(82, 'PRJ-1001', '2026-07-29', 100),
(83, 'PRJ-1002', '2026-07-29', 100),
(84, 'PRJ-1003', '2026-07-29', 100),
(85, 'PRJ-1004', '2026-07-29', 100),
(86, 'PRJ-1005', '2026-07-29', 50),
(87, 'PRJ-1006', '2026-07-29', 100);

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
('PRJ-1000', 'Shared calendar', 'Completed', 100, 'Eris', 'Low', '2026-05-05', '2026-05-18', 0.00, 'Shared calendar for All leads and All operations.', 'https://calendar.google.com/calendar/u/0/r?msg=Could+not+find+the+requested+event.&msgtok=479d2ed62d8019e81cedc625817367520f7258eb', '2026-07-09 07:56:28', '2026-07-29 02:12:08'),
('PRJ-1001', 'Calling card', 'Completed', 100, 'Eris', 'Low', '2026-02-24', '2026-05-12', 0.00, 'Calling card design for all leads', 'https://www.canva.com/design/DAHB7FrSOoY/TmTjaPgk8b3CQ6Su46QGOw/edit', '2026-07-09 07:56:28', '2026-07-29 02:30:29'),
('PRJ-1002', 'PBSI pvc id', 'Completed', 100, 'Eris', 'Medium', '2026-06-22', '2026-06-26', 0.00, 'PBSI pvc id - 32 Employees', 'https://drive.google.com/drive/folders/1O9m_Mz5joGAHCBGVJOHjiQkzCIlPuf-5?usp=drive_link', '2026-07-09 07:56:28', '2026-07-29 02:31:16'),
('PRJ-1003', 'Company printer', 'Completed', 100, 'Eris', 'Low', '2026-04-27', '2026-06-10', 56000.00, 'Company printer for unit 1c and unit 2i', 'https://drive.google.com/file/d/1it5TmN42K8reZAuw8N2KD_-LdksoSk-S/view?usp=drive_link', '2026-07-09 07:56:28', '2026-07-29 02:32:37'),
('PRJ-1004', 'Canva, Gdrive, Zoom implementation', 'Completed', 100, 'Eris', 'Medium', '2026-04-29', '2026-07-16', 19014.90, 'Apps for all employees', 'https://docs.google.com/document/d/1dn-BVoScNmiRIr-gom-egkPtNAqq4pQpzHXLUqYZIHk/edit?usp=drive_link', '2026-07-09 07:56:28', '2026-07-29 02:37:56'),
('PRJ-1005', 'LinisPlus / Cleaning App and Management', 'Onhold', 50, 'Eris', 'High', '2024-11-22', '2026-12-31', 1000000.00, 'Website for admin and cleaners. App for customers.', 'https://drive.google.com/drive/folders/1jZ1zZO2l0JV42Ijg-JgUIk8OllQuaCSb?usp=drive_link', '2026-07-09 07:56:28', '2026-07-29 02:38:41'),
('PRJ-1006', 'Employee Attendance Monitoring System(EAMS)', 'Completed', 100, 'Eris', 'High', '2025-12-09', '2026-07-02', 120000.00, 'Attendance app and monitoring for all employees', 'https://hris.saveplusph.com/dashboard/overview', '2026-07-09 07:56:28', '2026-07-29 02:39:57'),
('PRJ-1007', 'Data banking', 'Not Started', 0, 'Eris', 'Low', NULL, NULL, 0.00, 'Data banking storage for all employees', NULL, '2026-07-09 08:10:28', '2026-07-29 02:28:39'),
('PRJ-1008', 'Network setup', 'Onhold', 0, 'Eris', 'Low', NULL, NULL, 0.00, 'Plan to 1 PLDT network only with backup and wired connections', NULL, '2026-07-09 08:38:20', '2026-07-09 08:38:20'),
('PRJ-1009', 'Canary biometric wired internet connection', 'Not Started', 0, 'Eris', 'Low', NULL, NULL, 0.00, 'For internet speed to reach public IP', NULL, '2026-07-09 08:41:07', '2026-07-09 08:41:07'),
('PRJ-1010', 'Backup/Archive files', 'Ongoing', 1, 'ERIS', 'Medium', '2026-07-23', '2026-07-31', 0.00, 'Google drive for Backup/Archive files', 'https://docs.google.com/spreadsheets/d/1yhc5UYw9jlKnMupqqSNyXNnpzd1lNsePs7m1fZ3c4Dg/edit?usp=sharing', '2026-07-24 09:38:26', '2026-07-29 02:29:01');

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
(1, 'admin', '$2y$10$ep4jkM.yXzK4JSYrQK1V.O8dRP.rTNkrOR/8f8ZKmdHGtYA2VdOXe', '2026-07-29 01:29:45');

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
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `progress_history`
--
ALTER TABLE `progress_history`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=88;

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
