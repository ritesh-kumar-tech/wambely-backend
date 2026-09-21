-- Wambely API — minimal schema (users + auth + transactions only).
-- Wallet balances, KYC, recipients, quotes/corridors, notifications and
-- payout methods are NOT modelled here — see README.md for scope.

CREATE DATABASE IF NOT EXISTS wambely_api CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE wambely_api;

CREATE TABLE users (
  id VARCHAR(32) PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(30) NULL,
  password_hash VARCHAR(255) NOT NULL,
  kyc_state ENUM('notStarted','pending','approved','rejected','actionRequired') NOT NULL DEFAULT 'notStarted',
  biometric_enabled TINYINT(1) NOT NULL DEFAULT 0,
  mfa_enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Bearer tokens issued on login/verify_otp. Also doubles as the
-- "active sessions" list shown in the app (one row per signed-in device).
CREATE TABLE auth_tokens (
  token CHAR(64) PRIMARY KEY,
  user_id VARCHAR(32) NOT NULL,
  device_name VARCHAR(100) NOT NULL DEFAULT 'Unknown device',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_active_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Backs register -> verify_otp. Holds the pending signup payload until the
-- code is confirmed, at which point the real `users` row is created.
CREATE TABLE otp_requests (
  request_id VARCHAR(32) PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  code VARCHAR(6) NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE transactions (
  id VARCHAR(32) PRIMARY KEY,
  user_id VARCHAR(32) NOT NULL,
  type ENUM('sent','received','deposit','withdrawal') NOT NULL,
  status ENUM('pending','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  amount DECIMAL(14,2) NOT NULL,
  currency_code CHAR(3) NOT NULL,
  fee DECIMAL(14,2) NOT NULL DEFAULT 0,
  exchange_rate DECIMAL(18,6) NULL,
  recipient_name VARCHAR(150) NULL,
  recipient_flag_emoji VARCHAR(8) NULL,
  delivery_estimate VARCHAR(100) NULL,
  reference VARCHAR(50) NULL,
  payment_status VARCHAR(50) NULL,
  compliance_status VARCHAR(50) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_transactions_user_created ON transactions (user_id, created_at DESC);
CREATE INDEX idx_transactions_status ON transactions (status);
CREATE INDEX idx_transactions_type ON transactions (type);

-- Admin panel logins — deliberately separate from `users`.
CREATE TABLE admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
