-- transfers.php needs the quote's delivery estimate to carry through onto
-- the created transaction, which migration_002's nium_quotes table missed.
USE wambely_api;
ALTER TABLE nium_quotes ADD COLUMN delivery_estimate VARCHAR(100) NULL AFTER fee;
