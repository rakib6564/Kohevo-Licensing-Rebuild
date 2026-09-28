-- Membership plugin — uninstall. Drops every membership_* table.
-- Core `customers` are left untouched (membership never owned identity).
DROP TABLE IF EXISTS `membership_wallet_txns`;
DROP TABLE IF EXISTS `membership_wallet`;
DROP TABLE IF EXISTS `membership_profiles`;
DROP TABLE IF EXISTS `membership_subscriptions`;
DROP TABLE IF EXISTS `membership_plans`;
