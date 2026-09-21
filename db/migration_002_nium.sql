-- Phase 1 Nium integration (US -> India corridor).
-- Run once: mysql -u root wambely_api < db/migration_002_nium.sql

USE wambely_api;

ALTER TABLE users
  ADD COLUMN nium_customer_hash_id VARCHAR(64) NULL AFTER kyc_state,
  ADD COLUMN nium_kyc_status VARCHAR(30) NOT NULL DEFAULT 'notStarted' AFTER nium_customer_hash_id,
  ADD COLUMN nium_compliance_status VARCHAR(30) NULL AFTER nium_kyc_status;

CREATE INDEX idx_users_nium_customer_hash_id ON users (nium_customer_hash_id);

-- One row per Nium wallet (one per currency) mapped to a local user. No
-- `balance` column on purpose — balance is always fetched live from Nium so
-- it can never drift from the source of truth.
CREATE TABLE wallets (
  id VARCHAR(32) PRIMARY KEY,
  user_id VARCHAR(32) NOT NULL,
  nium_wallet_hash_id VARCHAR(64) NOT NULL,
  currency_code CHAR(3) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_wallets_nium_wallet_hash_id (nium_wallet_hash_id)
) ENGINE=InnoDB;

CREATE INDEX idx_wallets_user_id ON wallets (user_id);

-- Maps directly onto the Flutter app's `Recipient` model (see
-- lib/models/recipient.dart) — bank_details holds whatever corridor-specific
-- fields (account number, IFSC, etc.) the destination country required.
CREATE TABLE beneficiaries (
  id VARCHAR(32) PRIMARY KEY,
  user_id VARCHAR(32) NOT NULL,
  nium_beneficiary_hash_id VARCHAR(64) NOT NULL,
  nium_payout_hash_id VARCHAR(64) NULL,
  full_name VARCHAR(150) NOT NULL,
  country_code CHAR(2) NOT NULL,
  currency_code CHAR(3) NOT NULL,
  bank_details JSON NOT NULL,
  is_favorite TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_beneficiaries_nium_beneficiary_hash_id (nium_beneficiary_hash_id)
) ENGINE=InnoDB;

CREATE INDEX idx_beneficiaries_user_id ON beneficiaries (user_id);

-- Server-side quote validity check — quotes.php writes here, transfers.php
-- re-validates against this table (ownership + not consumed + not expired)
-- rather than trusting the quote_id/amount the client sends back.
CREATE TABLE nium_quotes (
  quote_id VARCHAR(64) PRIMARY KEY,
  user_id VARCHAR(32) NOT NULL,
  source_currency CHAR(3) NOT NULL,
  destination_currency CHAR(3) NOT NULL,
  source_amount DECIMAL(14,2) NOT NULL,
  destination_amount DECIMAL(14,2) NOT NULL,
  exchange_rate DECIMAL(18,6) NOT NULL,
  fee DECIMAL(14,2) NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  consumed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_nium_quotes_expires_at ON nium_quotes (expires_at);

ALTER TABLE transactions
  ADD COLUMN nium_transaction_id VARCHAR(64) NULL AFTER reference,
  ADD COLUMN quote_id VARCHAR(64) NULL AFTER nium_transaction_id,
  ADD COLUMN destination_amount DECIMAL(14,2) NULL AFTER quote_id,
  ADD COLUMN metadata JSON NULL AFTER compliance_status,
  ADD COLUMN idempotency_key VARCHAR(100) NULL AFTER metadata;

CREATE INDEX idx_transactions_nium_transaction_id ON transactions (nium_transaction_id);

-- One user can't submit the same transfer attempt twice, even if the
-- client's own dedupe (SendMoneyNotifier's idempotencyKey) somehow doesn't
-- catch it — this is the actual backend-side guarantee.
CREATE UNIQUE INDEX uq_transactions_user_idempotency ON transactions (user_id, idempotency_key);

-- Every inbound Nium webhook call, keyed by Nium's own event id so a
-- redelivered webhook is a no-op instead of double-applying a status update.
CREATE TABLE nium_webhook_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id VARCHAR(100) NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  payload JSON NOT NULL,
  processed_at DATETIME NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'received',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_webhook_events_event_id (event_id)
) ENGINE=InnoDB;
