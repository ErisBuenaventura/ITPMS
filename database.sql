-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 24, 2026 at 12:15 PM
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
-- Database: `itpms`
--

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
('PRJ-1000', 'Shared calendar', 'Completed', 100, 'Eris', 'Low', '2026-05-05', '2026-05-18', 0.00, 'Shared calendar for All leads and All operations.', NULL, '2026-07-09 07:56:28', '2026-07-09 08:13:17'),
('PRJ-1001', 'Calling card', 'Completed', 100, 'Eris', 'Low', '2026-02-24', '2026-05-12', 0.00, 'Calling card design for all leads', NULL, '2026-07-09 07:56:28', '2026-07-09 08:15:40'),
('PRJ-1002', 'PBSI pvc id', 'Completed', 100, 'Eris', 'Medium', '2026-06-22', '2026-06-26', 0.00, 'PBSI pvc id - 32 Employees', NULL, '2026-07-09 07:56:28', '2026-07-09 08:18:00'),
('PRJ-1003', 'Company printer', 'Completed', 100, 'Eris', 'Low', '2026-04-27', '2026-06-10', 56000.00, 'Company printer for unit 1c and unit 2i', NULL, '2026-07-09 07:56:28', '2026-07-09 08:20:51'),
('PRJ-1004', 'Canva, Gdrive, Zoom implementation', 'Completed', 100, 'Eris', 'Medium', '2026-04-29', '2026-07-16', 19014.90, 'Apps for all employees', NULL, '2026-07-09 07:56:28', '2026-07-24 09:57:17'),
('PRJ-1005', 'LinisPlus / Cleaning App and Management', 'Onhold', 50, 'Eris', 'High', '2024-11-22', '2026-12-31', 1000000.00, 'Website for admin and cleaners. App for customers.', NULL, '2026-07-09 07:56:28', '2026-07-09 08:34:04'),
('PRJ-1006', 'Employee Attendance Monitoring System(EAMS)', 'Completed', 100, 'Eris', 'High', '2025-12-09', '2026-07-02', 120000.00, 'Attendance app and monitoring for all employees', NULL, '2026-07-09 07:56:28', '2026-07-24 09:39:18'),
('PRJ-1007', 'Data banking', 'Not Started', 0, 'Eris', 'Low', NULL, NULL, 0.00, 'Data banking storage for all employees', NULL, '2026-07-09 08:10:28', '2026-07-09 08:35:45'),
('PRJ-1008', 'Network setup', 'Onhold', 0, 'Eris', 'Low', NULL, NULL, 0.00, 'Plan to 1 PLDT network only with backup and wired connections', NULL, '2026-07-09 08:38:20', '2026-07-09 08:38:20'),
('PRJ-1009', 'Canary biometric wired internet connection', 'Not Started', 0, 'Eris', 'Low', NULL, NULL, 0.00, 'For internet speed to reach public IP', NULL, '2026-07-09 08:41:07', '2026-07-09 08:41:07'),
('PRJ-1010', 'Backup/Archive files', 'Ongoing', 1, 'ERIS', 'Medium', '2026-07-23', '2026-07-31', 0.00, 'Google drive for Backup/Archive files', 'https://docs.google.com/spreadsheets/d/1yhc5UYw9jlKnMupqqSNyXNnpzd1lNsePs7m1fZ3c4Dg/edit?usp=sharing', '2026-07-24 09:38:26', '2026-07-24 09:38:26');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
