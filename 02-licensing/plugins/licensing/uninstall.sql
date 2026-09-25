-- Licensing plugin — uninstall. Drops every licensing_* table.
-- Dropped in child-before-parent order (checkins/installs reference plans/
-- products/clients only by plain INT column, no FK, but this order keeps
-- the file readable as a mirror of the dependency direction).
--
-- The Phase 2 (0025_commercial_licensing_rebuild) tables carry real FK
-- constraints (docs/02-architecture/09-CENTRAL-DATABASE-DESIGN.md §12), so
-- these three MUST be dropped before `licensing_licenses`.
DROP TABLE IF EXISTS `licensing_license_events`;
DROP TABLE IF EXISTS `licensing_license_modules`;
DROP TABLE IF EXISTS `licensing_installations`;
DROP TABLE IF EXISTS `licensing_licenses`;
DROP TABLE IF EXISTS `licensing_plan_modules`;
DROP TABLE IF EXISTS `licensing_checkins`;
DROP TABLE IF EXISTS `licensing_installation_bindings`;
DROP TABLE IF EXISTS `licensing_installs`;
DROP TABLE IF EXISTS `licensing_plans`;
DROP TABLE IF EXISTS `licensing_clients`;
DROP TABLE IF EXISTS `licensing_products`;
