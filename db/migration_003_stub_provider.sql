-- Supports running the full app against a deterministic in-house "stub"
-- money-transfer provider (see lib/provider/StubMoneyTransferProvider.php)
-- while real Nium credentials/sandbox access are still being sorted out.
--
-- `wallets.balance` is authoritative ONLY under the stub provider — the
-- real Nium provider must never read or write it, and always fetches the
-- live balance from Nium instead (see migration_002's comment on this table).

USE wambely_api;

ALTER TABLE wallets
  ADD COLUMN balance DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER currency_code;
