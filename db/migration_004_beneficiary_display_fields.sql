-- Two Flutter-display-only fields on `Recipient` that migration_002 missed
-- (bankName/accountNumberMasked are derived from bank_details JSON at read
-- time in recipients.php, but these two have no other source).

USE wambely_api;

ALTER TABLE beneficiaries
  ADD COLUMN country_flag_emoji VARCHAR(8) NULL AFTER country_code,
  ADD COLUMN phone VARCHAR(30) NULL AFTER currency_code;
