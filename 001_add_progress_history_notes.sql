-- Migration: add notes column to progress_history
-- Safe to run multiple times on MySQL/MariaDB that support IF NOT EXISTS.

ALTER TABLE `progress_history`
  ADD COLUMN IF NOT EXISTS `notes` TEXT DEFAULT NULL;

-- If your server/version does not support ALTER TABLE ... ADD COLUMN IF NOT EXISTS,
-- run this alternative manually (wrap in a script or run interactively):
--
-- SET @cnt = (SELECT COUNT(*) FROM information_schema.COLUMNS
--              WHERE TABLE_SCHEMA = DATABASE()
--                AND TABLE_NAME = 'progress_history'
--                AND COLUMN_NAME = 'notes');
--
-- /* Only run the ALTER if the column is missing */
-- PREPARE stm FROM 'ALTER TABLE `progress_history` ADD COLUMN `notes` TEXT DEFAULT NULL';
-- IF @cnt = 0 THEN
--   EXECUTE stm;
-- END IF;
-- DEALLOCATE PREPARE stm;
