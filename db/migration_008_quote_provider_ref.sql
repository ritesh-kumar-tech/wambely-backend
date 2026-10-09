-- Keeps Nium's own quote id around for audit/debugging even though the
-- current transfer flow doesn't thread it through to the remittance call
-- (see NiumFxService's "ASSUMPTION TO VERIFY" doc comment) — useful to
-- have if that assumption turns out wrong and needs wiring up later.
USE wambely_api;
ALTER TABLE nium_quotes ADD COLUMN provider_quote_id VARCHAR(100) NULL AFTER quote_id;
