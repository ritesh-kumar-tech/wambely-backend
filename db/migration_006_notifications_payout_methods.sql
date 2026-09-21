-- Completes the remaining Flutter-app-facing contracts that don't touch
-- Nium at all: notifications, notification preferences, payout methods.

USE wambely_api;

CREATE TABLE notifications (
  id VARCHAR(32) PRIMARY KEY,
  user_id VARCHAR(32) NOT NULL,
  kind ENUM('transfer','security','kyc','promo','system') NOT NULL,
  title VARCHAR(150) NOT NULL,
  body VARCHAR(500) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_notifications_user_created ON notifications (user_id, created_at DESC);

ALTER TABLE users
  ADD COLUMN notify_transfer_updates TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN notify_security_alerts TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN notify_rate_alerts TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN notify_promotions TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE payout_methods (
  id VARCHAR(32) PRIMARY KEY,
  user_id VARCHAR(32) NOT NULL,
  type ENUM('bankAccount','debitCard') NOT NULL,
  label VARCHAR(100) NOT NULL,
  masked_detail VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_payout_methods_user_id ON payout_methods (user_id);
