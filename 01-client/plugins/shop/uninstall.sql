-- Shop plugin — uninstall. Drops every shop_* table.
-- Charges in the shared `stripepayment_charges` ledger are kept (financial record).
DROP TABLE IF EXISTS `shop_coupons`;
DROP TABLE IF EXISTS `shop_tax_rates`;
DROP TABLE IF EXISTS `shop_shipping_methods`;
DROP TABLE IF EXISTS `shop_shipping_zones`;
DROP TABLE IF EXISTS `shop_downloads`;
DROP TABLE IF EXISTS `shop_product_files`;
DROP TABLE IF EXISTS `shop_product_links`;
DROP TABLE IF EXISTS `shop_product_tags`;
DROP TABLE IF EXISTS `shop_tags`;
DROP TABLE IF EXISTS `shop_order_items`;
DROP TABLE IF EXISTS `shop_orders`;
DROP TABLE IF EXISTS `shop_variants`;
DROP TABLE IF EXISTS `shop_product_categories`;
DROP TABLE IF EXISTS `shop_categories`;
DROP TABLE IF EXISTS `shop_products`;
