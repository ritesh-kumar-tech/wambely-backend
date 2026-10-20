-- Real Nium customer onboarding needs much more than fullName/email/phone —
-- personal details (DOB, nationality, address) and the submitted ID
-- document's metadata (not the file itself, which is relayed straight to
-- Nium and never persisted here — no reason to retain a copy of a user's ID
-- document longer than the single request that needs it).
USE wambely_api;

ALTER TABLE users
  ADD COLUMN date_of_birth DATE NULL AFTER phone,
  ADD COLUMN nationality CHAR(2) NULL AFTER date_of_birth,
  ADD COLUMN mobile_country_code VARCHAR(5) NULL AFTER nationality,
  ADD COLUMN address_line1 VARCHAR(200) NULL AFTER mobile_country_code,
  ADD COLUMN address_city VARCHAR(100) NULL AFTER address_line1,
  ADD COLUMN address_state VARCHAR(100) NULL AFTER address_city,
  ADD COLUMN address_postal_code VARCHAR(20) NULL AFTER address_state,
  ADD COLUMN address_country CHAR(2) NULL AFTER address_postal_code,
  ADD COLUMN kyc_document_type VARCHAR(30) NULL AFTER address_country,
  ADD COLUMN kyc_document_number VARCHAR(50) NULL AFTER kyc_document_type,
  ADD COLUMN kyc_document_issuance_country CHAR(2) NULL AFTER kyc_document_number,
  ADD COLUMN kyc_document_expiry DATE NULL AFTER kyc_document_issuance_country;
