-- Coaching plugin — uninstall. Drops every coaching_* table.
-- Core `customers` are left untouched (coaching never owns identity).
DROP TABLE IF EXISTS `coaching_summary`;
DROP TABLE IF EXISTS `coaching_challenge`;
DROP TABLE IF EXISTS `coaching_recipe`;
DROP TABLE IF EXISTS `coaching_shopping_list`;
DROP TABLE IF EXISTS `coaching_meal_structure`;
DROP TABLE IF EXISTS `coaching_message`;
DROP TABLE IF EXISTS `coaching_thread`;
DROP TABLE IF EXISTS `coaching_activity`;
DROP TABLE IF EXISTS `coaching_hydration`;
DROP TABLE IF EXISTS `coaching_diary_photo`;
DROP TABLE IF EXISTS `coaching_diary_food`;
DROP TABLE IF EXISTS `coaching_diary_entry`;
DROP TABLE IF EXISTS `coaching_extra_action`;
DROP TABLE IF EXISTS `coaching_goal_checkin`;
DROP TABLE IF EXISTS `coaching_goal`;
DROP TABLE IF EXISTS `coaching_profile`;
