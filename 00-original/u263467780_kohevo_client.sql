-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Sep 25, 2026 at 03:34 AM
-- Server version: 11.8.9-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u263467780_kohevo_client`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_sessions`
--

CREATE TABLE `admin_sessions` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `session_hash` char(64) NOT NULL,
  `device_label` varchar(190) NOT NULL DEFAULT '',
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(500) NOT NULL DEFAULT '',
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admin_sessions`
--

INSERT INTO `admin_sessions` (`id`, `tenant_id`, `user_id`, `session_hash`, `device_label`, `ip_address`, `user_agent`, `last_seen_at`, `expires_at`, `revoked_at`, `created_at`) VALUES
(1, 1, 1, '662a92433634f11fe5e1b7d0cc4039183c59e428f7637a0bdc35199b09ee2d89', 'Admin browser', '27.147.207.58', 'Mozilla/5.0 (Linux; Android 13; M2102J20SG Build/TKQ1.221013.002) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.8010.36 Mobile Safari/537.36', '2026-09-24 23:28:44', '2026-09-25 07:02:34', NULL, '2026-09-24 23:02:34'),
(2, 1, 1, 'e79108459c611bf6004b6884e2debf7e6cddd979b7e75c89db6c5fc4812cc865', 'Admin browser', '27.147.207.58', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-25 03:34:01', '2026-09-25 08:57:46', NULL, '2026-09-25 00:57:46');

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(120) NOT NULL,
  `target` varchar(190) DEFAULT NULL,
  `meta_json` longtext DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id`, `tenant_id`, `user_id`, `action`, `target`, `meta_json`, `ip`, `created_at`) VALUES
(1, 1, 1, 'login.success', 'fahimtf1@gmail.com', NULL, '27.147.207.58', '2026-09-24 23:02:34'),
(2, 1, 1, 'login.success', 'fahimtf1@gmail.com', NULL, '27.147.207.58', '2026-09-25 00:57:46');

-- --------------------------------------------------------

--
-- Table structure for table `contacts`
--

CREATE TABLE `contacts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `kind` enum('person','organization') NOT NULL DEFAULT 'person',
  `display_name` varchar(190) DEFAULT NULL,
  `primary_email` varchar(190) DEFAULT NULL,
  `primary_phone` varchar(40) DEFAULT NULL,
  `status` enum('active','archived','merged') NOT NULL DEFAULT 'active',
  `merged_into_id` bigint(20) UNSIGNED DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contact_emails`
--

CREATE TABLE `contact_emails` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `contact_id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `email` varchar(190) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contact_forms`
--

CREATE TABLE `contact_forms` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `title` varchar(190) NOT NULL,
  `slug` varchar(64) NOT NULL,
  `fields` longtext NOT NULL,
  `settings` longtext DEFAULT NULL,
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contact_form_submissions`
--

CREATE TABLE `contact_form_submissions` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `form_id` int(10) UNSIGNED NOT NULL,
  `data_json` longtext NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contact_phones`
--

CREATE TABLE `contact_phones` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `contact_id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `phone` varchar(40) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `name` varchar(120) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `status` enum('active','suspended','guest') NOT NULL DEFAULT 'active',
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_auth_tokens`
--

CREATE TABLE `customer_auth_tokens` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `purpose` varchar(32) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `identities`
--

CREATE TABLE `identities` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `contact_id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `provider` enum('password') NOT NULL DEFAULT 'password',
  `credential_ref` varchar(190) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `identity_tokens`
--

CREATE TABLE `identity_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `identity_id` bigint(20) UNSIGNED NOT NULL,
  `purpose` varchar(32) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `installation_identity`
--

CREATE TABLE `installation_identity` (
  `singleton_id` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `installation_id` char(32) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `installation_identity`
--

INSERT INTO `installation_identity` (`singleton_id`, `tenant_id`, `installation_id`, `created_at`, `updated_at`) VALUES
(1, 1, 'dd67d8e7604e17f4af4727f8df7596f0', '2026-09-24 23:01:43', '2026-09-24 23:01:43');

-- --------------------------------------------------------

--
-- Table structure for table `lang_overrides`
--

CREATE TABLE `lang_overrides` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `locale` varchar(10) NOT NULL,
  `lang_key` varchar(191) NOT NULL,
  `lang_value` text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `scope` varchar(16) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `identifier` varchar(190) NOT NULL DEFAULT '',
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `media_files`
--

CREATE TABLE `media_files` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `path` varchar(500) NOT NULL,
  `kind` enum('image','document','other') NOT NULL DEFAULT 'image',
  `mime` varchar(120) NOT NULL DEFAULT '',
  `size_bytes` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `width` int(10) UNSIGNED DEFAULT NULL,
  `height` int(10) UNSIGNED DEFAULT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `folder` varchar(190) NOT NULL DEFAULT '',
  `uploaded_by` int(10) UNSIGNED DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `media_usage`
--

CREATE TABLE `media_usage` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `media_id` int(10) UNSIGNED NOT NULL,
  `context` varchar(64) NOT NULL,
  `object_type` varchar(64) NOT NULL DEFAULT '',
  `object_id` varchar(64) NOT NULL DEFAULT '',
  `field` varchar(64) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(10) UNSIGNED NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`, `applied_at`) VALUES
(1, '0001_core_init', 1, '2026-09-24 23:01:42'),
(2, '0002_identity_core', 1, '2026-09-24 23:01:43'),
(3, '0011_login_attempts', 1, '2026-09-24 23:01:43'),
(4, '0014_tenant_profiles', 1, '2026-09-24 23:01:43'),
(5, '0023_installation_identity', 1, '2026-09-24 23:01:43');

-- --------------------------------------------------------

--
-- Table structure for table `plugins`
--

CREATE TABLE `plugins` (
  `id` int(10) UNSIGNED NOT NULL,
  `slug` varchar(64) NOT NULL,
  `name` varchar(120) NOT NULL,
  `version` varchar(20) NOT NULL,
  `status` enum('installed','active','inactive') NOT NULL DEFAULT 'installed',
  `manifest_json` longtext NOT NULL,
  `installed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `activated_at` datetime DEFAULT NULL,
  `deactivated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `name` varchar(64) NOT NULL,
  `slug` varchar(64) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `tenant_id`, `name`, `slug`, `description`, `is_system`, `created_at`) VALUES
(1, 1, 'Super Admin', 'super-admin', 'Full access. Cannot be edited.', 1, '2026-09-24 23:01:42'),
(2, 1, 'Manager', 'manager', 'Operational management.', 1, '2026-09-24 23:01:42'),
(3, 1, 'Editor', 'editor', 'Edit content, forms, and settings.', 1, '2026-09-24 23:01:42'),
(4, 1, 'Viewer', 'viewer', 'Read-only access to admin.', 1, '2026-09-24 23:01:42');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `id` int(10) UNSIGNED NOT NULL,
  `role_id` int(10) UNSIGNED NOT NULL,
  `perm_key` varchar(128) NOT NULL,
  `granted` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`id`, `role_id`, `perm_key`, `granted`) VALUES
(1, 2, 'users.view', 1),
(2, 2, 'users.edit', 1),
(3, 2, 'customers.view', 1),
(4, 2, 'customers.edit', 1),
(5, 2, 'contact.view', 1),
(6, 2, 'contact.manage', 1),
(7, 2, 'settings.view', 1),
(8, 2, 'settings.edit', 1),
(9, 2, 'audit.view', 1),
(10, 2, 'media.view', 1),
(11, 2, 'media.upload', 1),
(12, 2, 'media.delete', 1),
(13, 3, 'contact.view', 1),
(14, 3, 'contact.manage', 1),
(15, 3, 'settings.view', 1),
(16, 3, 'media.view', 1),
(17, 3, 'media.upload', 1),
(18, 4, 'users.view', 1),
(19, 4, 'customers.view', 1),
(20, 4, 'contact.view', 1),
(21, 4, 'settings.view', 1),
(22, 4, 'media.view', 1);

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `setting_key` varchar(190) NOT NULL,
  `setting_value` longtext DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `tenant_id`, `setting_key`, `setting_value`, `updated_at`) VALUES
(1, 1, 'media_schema_v', '1', '2026-09-24 23:01:44');

-- --------------------------------------------------------

--
-- Table structure for table `slate_notifications`
--

CREATE TABLE `slate_notifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `title` varchar(200) NOT NULL,
  `body` varchar(500) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `icon` varchar(40) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tenants`
--

CREATE TABLE `tenants` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `slug` varchar(64) NOT NULL,
  `status` enum('active','suspended','deleted') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tenants`
--

INSERT INTO `tenants` (`id`, `name`, `slug`, `status`, `created_at`) VALUES
(1, 'Default', 'default', 'active', '2026-09-24 23:01:42');

-- --------------------------------------------------------

--
-- Table structure for table `tenant_profiles`
--

CREATE TABLE `tenant_profiles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `owner_name` varchar(120) DEFAULT NULL,
  `owner_email` varchar(190) DEFAULT NULL,
  `lifecycle_status` enum('trial','active','suspended','deactivated','archived') NOT NULL DEFAULT 'trial',
  `trial_ends_at` datetime DEFAULT NULL,
  `timezone` varchar(64) NOT NULL DEFAULT 'UTC',
  `locale` varchar(16) NOT NULL DEFAULT 'en',
  `default_plugins` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`default_plugins`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tenant_profiles`
--

INSERT INTO `tenant_profiles` (`id`, `tenant_id`, `owner_name`, `owner_email`, `lifecycle_status`, `trial_ends_at`, `timezone`, `locale`, `default_plugins`, `created_at`, `updated_at`) VALUES
(1, 1, 'Kohevo Client', 'fahimtf1@gmail.com', 'active', NULL, 'UTC', 'en', NULL, '2026-09-24 23:01:43', '2026-09-24 23:01:43');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(120) NOT NULL,
  `role_id` int(10) UNSIGNED NOT NULL,
  `status` enum('active','suspended','invited') NOT NULL DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `tenant_id`, `email`, `password_hash`, `name`, `role_id`, `status`, `last_login_at`, `created_at`) VALUES
(1, 1, 'fahimtf1@gmail.com', '$2y$10$1FtbCttOSoL71KtBllGQpeFhQt3Rw6WzItj0nQ09MEBriCnCtu2SG', 'Kohevo Client', 1, 'active', '2026-09-25 00:57:46', '2026-09-24 23:01:43');

-- --------------------------------------------------------

--
-- Table structure for table `user_mfa_factors`
--

CREATE TABLE `user_mfa_factors` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `secret` varchar(128) NOT NULL,
  `enabled_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_mfa_recovery_codes`
--

CREATE TABLE `user_mfa_recovery_codes` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_sessions`
--
ALTER TABLE `admin_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tenant_session` (`tenant_id`,`session_hash`),
  ADD KEY `tenant_user_active` (`tenant_id`,`user_id`,`revoked_at`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_user_time` (`tenant_id`,`user_id`,`created_at`),
  ADD KEY `action` (`action`);

--
-- Indexes for table `contacts`
--
ALTER TABLE `contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant_email` (`tenant_id`,`primary_email`),
  ADD KEY `idx_tenant_status` (`tenant_id`,`status`);

--
-- Indexes for table `contact_emails`
--
ALTER TABLE `contact_emails`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_tenant_email` (`tenant_id`,`email`),
  ADD KEY `idx_contact` (`contact_id`);

--
-- Indexes for table `contact_forms`
--
ALTER TABLE `contact_forms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tenant_slug` (`tenant_id`,`slug`);

--
-- Indexes for table `contact_form_submissions`
--
ALTER TABLE `contact_form_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_form_time` (`tenant_id`,`form_id`,`created_at`),
  ADD KEY `fk_cfs_form` (`form_id`);

--
-- Indexes for table `contact_phones`
--
ALTER TABLE `contact_phones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant_phone` (`tenant_id`,`phone`),
  ADD KEY `idx_contact` (`contact_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tenant_email` (`tenant_id`,`email`);

--
-- Indexes for table `customer_auth_tokens`
--
ALTER TABLE `customer_auth_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token_purpose` (`token_hash`,`purpose`),
  ADD KEY `idx_customer_purpose` (`customer_id`,`purpose`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `identities`
--
ALTER TABLE `identities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_tenant_provider_cred` (`tenant_id`,`provider`,`credential_ref`),
  ADD KEY `idx_contact` (`contact_id`);

--
-- Indexes for table `identity_tokens`
--
ALTER TABLE `identity_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token_purpose` (`token_hash`,`purpose`),
  ADD KEY `idx_identity_purpose` (`identity_id`,`purpose`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `installation_identity`
--
ALTER TABLE `installation_identity`
  ADD PRIMARY KEY (`singleton_id`),
  ADD UNIQUE KEY `uniq_installation_identity_tenant` (`tenant_id`),
  ADD UNIQUE KEY `uniq_installation_identity_value` (`installation_id`);

--
-- Indexes for table `lang_overrides`
--
ALTER TABLE `lang_overrides`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tenant_locale_key` (`tenant_id`,`locale`,`lang_key`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_scope_ip` (`scope`,`ip`,`attempted_at`),
  ADD KEY `idx_tenant_time` (`tenant_id`,`attempted_at`);

--
-- Indexes for table `media_files`
--
ALTER TABLE `media_files`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tenant_path` (`tenant_id`,`path`),
  ADD KEY `tenant_kind` (`tenant_id`,`kind`),
  ADD KEY `tenant_uploaded_at` (`tenant_id`,`uploaded_at`);

--
-- Indexes for table `media_usage`
--
ALTER TABLE `media_usage`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `usage_unique` (`tenant_id`,`media_id`,`context`,`object_type`,`object_id`,`field`),
  ADD KEY `media` (`media_id`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_migration` (`migration`);

--
-- Indexes for table `plugins`
--
ALTER TABLE `plugins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tenant_slug` (`tenant_id`,`slug`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_perm` (`role_id`,`perm_key`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tenant_key` (`tenant_id`,`setting_key`);

--
-- Indexes for table `slate_notifications`
--
ALTER TABLE `slate_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant_read` (`tenant_id`,`is_read`,`id`),
  ADD KEY `idx_tenant_created` (`tenant_id`,`created_at`);

--
-- Indexes for table `tenants`
--
ALTER TABLE `tenants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `tenant_profiles`
--
ALTER TABLE `tenant_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_tenant_profile` (`tenant_id`),
  ADD KEY `idx_lifecycle_status` (`lifecycle_status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tenant_email` (`tenant_id`,`email`),
  ADD KEY `role_id` (`role_id`);

--
-- Indexes for table `user_mfa_factors`
--
ALTER TABLE `user_mfa_factors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tenant_user` (`tenant_id`,`user_id`);

--
-- Indexes for table `user_mfa_recovery_codes`
--
ALTER TABLE `user_mfa_recovery_codes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_user_used` (`tenant_id`,`user_id`,`used_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_sessions`
--
ALTER TABLE `admin_sessions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `contacts`
--
ALTER TABLE `contacts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contact_emails`
--
ALTER TABLE `contact_emails`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contact_forms`
--
ALTER TABLE `contact_forms`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contact_form_submissions`
--
ALTER TABLE `contact_form_submissions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contact_phones`
--
ALTER TABLE `contact_phones`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_auth_tokens`
--
ALTER TABLE `customer_auth_tokens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `identities`
--
ALTER TABLE `identities`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `identity_tokens`
--
ALTER TABLE `identity_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lang_overrides`
--
ALTER TABLE `lang_overrides`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `media_files`
--
ALTER TABLE `media_files`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `media_usage`
--
ALTER TABLE `media_usage`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `plugins`
--
ALTER TABLE `plugins`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `slate_notifications`
--
ALTER TABLE `slate_notifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tenants`
--
ALTER TABLE `tenants`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tenant_profiles`
--
ALTER TABLE `tenant_profiles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `user_mfa_factors`
--
ALTER TABLE `user_mfa_factors`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_mfa_recovery_codes`
--
ALTER TABLE `user_mfa_recovery_codes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `contact_form_submissions`
--
ALTER TABLE `contact_form_submissions`
  ADD CONSTRAINT `fk_cfs_form` FOREIGN KEY (`form_id`) REFERENCES `contact_forms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `media_usage`
--
ALTER TABLE `media_usage`
  ADD CONSTRAINT `fk_media_usage_media` FOREIGN KEY (`media_id`) REFERENCES `media_files` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
