-- AI Form Builder Database Schema
-- Generated for MySQL 8.0+
-- Run: mysql -u root -p ai_form_builder < database/dumps/schema.sql

SET FOREIGN_KEY_CHECKS=0;

-- Tenants table
CREATE TABLE IF NOT EXISTS `tenants` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(255) NOT NULL UNIQUE,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Users table
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `email_verified_at` TIMESTAMP NULL,
    `password` VARCHAR(255) NOT NULL,
    `remember_token` VARCHAR(100) NULL,
    `current_tenant_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    FOREIGN KEY (`current_tenant_id`) REFERENCES `tenants`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tenant-User pivot table
CREATE TABLE IF NOT EXISTS `tenant_user` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `role` VARCHAR(255) NOT NULL DEFAULT 'member',
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    UNIQUE KEY `tenant_user_unique` (`tenant_id`, `user_id`),
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Personal Access Tokens (Sanctum)
CREATE TABLE IF NOT EXISTS `personal_access_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `tokenable_type` VARCHAR(255) NOT NULL,
    `tokenable_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `token` VARCHAR(64) NOT NULL UNIQUE,
    `abilities` TEXT NULL,
    `last_used_at` TIMESTAMP NULL,
    `expires_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    INDEX `personal_access_tokens_tokenable_index` (`tokenable_type`, `tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Forms table
CREATE TABLE IF NOT EXISTS `forms` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `current_version_id` BIGINT UNSIGNED NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `slug` VARCHAR(255) NOT NULL UNIQUE,
    `status` ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    `success_message` TEXT NULL,
    `settings` JSON NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    INDEX `forms_tenant_updated` (`tenant_id`, `updated_at`),
    INDEX `forms_slug_status` (`slug`, `status`),
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Form Versions table
CREATE TABLE IF NOT EXISTS `form_versions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `form_id` BIGINT UNSIGNED NOT NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `version_number` INT UNSIGNED NOT NULL DEFAULT 1,
    `schema_version` VARCHAR(20) NOT NULL DEFAULT '1.0',
    `schema` JSON NOT NULL,
    `change_type` ENUM('created', 'updated', 'published', 'restored') NOT NULL DEFAULT 'created',
    `change_summary` JSON NULL,
    `is_published` BOOLEAN NOT NULL DEFAULT FALSE,
    `published_at` TIMESTAMP NULL,
    `restored_from_version_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    UNIQUE KEY `form_version_unique` (`form_id`, `version_number`),
    INDEX `form_versions_published` (`form_id`, `is_published`),
    FOREIGN KEY (`form_id`) REFERENCES `forms`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`restored_from_version_id`) REFERENCES `form_versions`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add foreign key for current_version_id after form_versions exists
ALTER TABLE `forms` ADD FOREIGN KEY (`current_version_id`) REFERENCES `form_versions`(`id`) ON DELETE SET NULL;

-- Form Submissions table
CREATE TABLE IF NOT EXISTS `form_submissions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `form_id` BIGINT UNSIGNED NOT NULL,
    `form_version_id` BIGINT UNSIGNED NOT NULL,
    `data` JSON NOT NULL,
    `status` VARCHAR(255) NOT NULL DEFAULT 'completed',
    `submission_token` VARCHAR(64) NOT NULL UNIQUE,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `submitted_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    INDEX `submissions_form_ip_time` (`form_id`, `ip_address`, `submitted_at`),
    FOREIGN KEY (`form_id`) REFERENCES `forms`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`form_version_id`) REFERENCES `form_versions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Submission Files table
CREATE TABLE IF NOT EXISTS `submission_files` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `submission_id` BIGINT UNSIGNED NOT NULL,
    `field_key` VARCHAR(255) NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `stored_path` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(255) NOT NULL,
    `size` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    FOREIGN KEY (`submission_id`) REFERENCES `form_submissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI Jobs table
CREATE TABLE IF NOT EXISTS `ai_jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `job_uuid` CHAR(36) NOT NULL UNIQUE,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `form_id` BIGINT UNSIGNED NULL,
    `request_type` ENUM('generate', 'modify') NOT NULL,
    `status` ENUM('queued', 'running', 'succeeded', 'failed') NOT NULL DEFAULT 'queued',
    `provider` VARCHAR(50) NOT NULL,
    `model` VARCHAR(100) NOT NULL,
    `prompt` TEXT NOT NULL,
    `options` JSON NULL,
    `result_schema` JSON NULL,
    `validation_errors` JSON NULL,
    `repair_log` JSON NULL,
    `error_type` VARCHAR(50) NULL,
    `error_message` TEXT NULL,
    `input_tokens` INT UNSIGNED NULL,
    `output_tokens` INT UNSIGNED NULL,
    `latency_ms` INT UNSIGNED NULL,
    `retry_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `started_at` TIMESTAMP NULL,
    `completed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    INDEX `ai_jobs_tenant_user_created` (`tenant_id`, `user_id`, `created_at`),
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`form_id`) REFERENCES `forms`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Import Jobs table
CREATE TABLE IF NOT EXISTS `import_jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `job_uuid` CHAR(36) NOT NULL UNIQUE,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `form_id` BIGINT UNSIGNED NULL,
    `import_type` ENUM('docx', 'xlsx') NOT NULL,
    `status` ENUM('queued', 'running', 'parsed', 'succeeded', 'failed') NOT NULL DEFAULT 'queued',
    `original_filename` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `file_size` BIGINT UNSIGNED NOT NULL,
    `parsed_elements` JSON NULL,
    `corrected_elements` JSON NULL,
    `result_schema` JSON NULL,
    `validation_errors` JSON NULL,
    `warnings` JSON NULL,
    `error_message` TEXT NULL,
    `use_ai_classification` BOOLEAN NOT NULL DEFAULT FALSE,
    `started_at` TIMESTAMP NULL,
    `completed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    INDEX `import_jobs_uuid_tenant` (`job_uuid`, `tenant_id`),
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`form_id`) REFERENCES `forms`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cache table
CREATE TABLE IF NOT EXISTS `cache` (
    `key` VARCHAR(255) NOT NULL PRIMARY KEY,
    `value` MEDIUMTEXT NOT NULL,
    `expiration` INT NOT NULL,
    INDEX `cache_expiration` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cache Locks table
CREATE TABLE IF NOT EXISTS `cache_locks` (
    `key` VARCHAR(255) NOT NULL PRIMARY KEY,
    `owner` VARCHAR(255) NOT NULL,
    `expiration` INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jobs table (Laravel Queue)
CREATE TABLE IF NOT EXISTS `jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `queue` VARCHAR(255) NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `attempts` TINYINT UNSIGNED NOT NULL,
    `reserved_at` INT UNSIGNED NULL,
    `available_at` INT UNSIGNED NOT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    INDEX `jobs_queue` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed Jobs table
CREATE TABLE IF NOT EXISTS `failed_jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `uuid` VARCHAR(255) NOT NULL UNIQUE,
    `connection` TEXT NOT NULL,
    `queue` TEXT NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `exception` LONGTEXT NOT NULL,
    `failed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;

-- Demo Data Seeder
INSERT INTO `tenants` (`id`, `name`, `slug`, `created_at`, `updated_at`) VALUES
(1, 'Demo Organization', 'demo-org', NOW(), NOW());

INSERT INTO `users` (`id`, `name`, `email`, `password`, `current_tenant_id`, `created_at`, `updated_at`) VALUES
(1, 'Demo User', 'demo@example.com', '$2y$12$example.hashed.password.here', 1, NOW(), NOW());

INSERT INTO `tenant_user` (`tenant_id`, `user_id`, `role`, `created_at`, `updated_at`) VALUES
(1, 1, 'owner', NOW(), NOW());
