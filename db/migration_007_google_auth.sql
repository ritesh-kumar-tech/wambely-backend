-- Links a user to their Google account for "Sign in with Google". Nullable
-- and separate from password_hash — a Google-only account still gets a
-- (unusable, random) password_hash since that column is NOT NULL, but
-- google_sub is what auth.php actually authenticates against for these users.

USE wambely_api;

ALTER TABLE users
  ADD COLUMN google_sub VARCHAR(64) NULL AFTER phone;

CREATE UNIQUE INDEX uq_users_google_sub ON users (google_sub);
