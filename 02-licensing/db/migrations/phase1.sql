-- ─────────────────────────────────────────────────────────────
-- Slate — Phase 1 migration
-- Adds verification and password-reset token columns to `customers`.
-- Safe to re-run: every ALTER uses IF NOT EXISTS.
-- ─────────────────────────────────────────────────────────────

-- Tokens for email verification (sent on register and re-verify).
ALTER TABLE `customers`
    ADD COLUMN IF NOT EXISTS `verify_token` VARCHAR(64) NULL AFTER `email_verified`,
    ADD COLUMN IF NOT EXISTS `verify_token_expires_at` DATETIME NULL AFTER `verify_token`;

-- Tokens for password reset (sent on "forgot password" request).
ALTER TABLE `customers`
    ADD COLUMN IF NOT EXISTS `reset_token` VARCHAR(64) NULL AFTER `verify_token_expires_at`,
    ADD COLUMN IF NOT EXISTS `reset_token_expires_at` DATETIME NULL AFTER `reset_token`;

-- Indexes for fast token lookups. Both are sparse — most rows have NULL.
-- (MySQL 8.0+ supports IF NOT EXISTS on ADD INDEX; if your version doesn't,
-- run these manually and ignore "Duplicate key name" errors on re-run.)
ALTER TABLE `customers`
    ADD INDEX IF NOT EXISTS `idx_customers_verify_token` (`verify_token`),
    ADD INDEX IF NOT EXISTS `idx_customers_reset_token`  (`reset_token`);
