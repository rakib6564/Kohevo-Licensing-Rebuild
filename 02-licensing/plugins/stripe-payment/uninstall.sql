-- Drop the stripe-payment table when the plugin is uninstalled.
-- The settings under 'stripe-payment.*' in the core settings table
-- are dropped by Slate's plugin uninstaller automatically.

DROP TABLE IF EXISTS stripepayment_sessions;
