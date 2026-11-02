-- Restaurant plugin — uninstall. Drops every restaurant_* table created by
-- install.sql (Phase 1). Child tables first to respect logical FK ordering.
-- Plugin settings under the `restaurant.` key are cleared by the core
-- uninstaller; this file only owns the plugin's own tables.

DROP TABLE IF EXISTS `restaurant_order_item_modifiers`;
DROP TABLE IF EXISTS `restaurant_order_items`;
DROP TABLE IF EXISTS `restaurant_payments`;
DROP TABLE IF EXISTS `restaurant_orders`;
DROP TABLE IF EXISTS `restaurant_item_modifier_groups`;
DROP TABLE IF EXISTS `restaurant_modifiers`;
DROP TABLE IF EXISTS `restaurant_modifier_groups`;
DROP TABLE IF EXISTS `restaurant_items`;
DROP TABLE IF EXISTS `restaurant_menu_categories`;
DROP TABLE IF EXISTS `restaurant_tables`;
DROP TABLE IF EXISTS `restaurant_sections`;
DROP TABLE IF EXISTS `restaurant_customers`;
DROP TABLE IF EXISTS `restaurant_readers`;
