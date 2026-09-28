-- Drop only the metadata cache table. We do NOT delete files from
-- uploads/shop/products/ on uninstall — those belong to the Shop
-- plugin's products and would orphan every image if we touched them.
DROP TABLE IF EXISTS `medialibrary_files`;
