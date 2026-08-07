-- Migration: add notes column to projects table
-- Safe/idempotent pattern: check information_schema, add only if missing.

USE `itpms2`; -- change to your database name if different

SET @cnt = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'projects'
    AND COLUMN_NAME = 'notes'
);

SET @stmt = IF(
  @cnt = 0,
  'ALTER TABLE `projects` ADD COLUMN `notes` TEXT DEFAULT NULL',
  'SELECT "projects.notes already exists" AS msg'
);

PREPARE s FROM @stmt;
EXECUTE s;
DEALLOCATE PREPARE s;

-- verify
SHOW COLUMNS FROM `projects` LIKE 'notes';
