# backend

PHP/MySQL backend for the Wambely Flutter app — lives inside this Flutter
project (`wambely_flutter_app/backend/`) purely for convenience of having
one project folder; it still runs as its own PHP/MySQL server process under
WAMP (or any PHP host) and is never bundled into the Android/iOS app build.
`ApiConfig.baseUrl` (in `../lib/core/network/api_config.dart`) points at it.

## Scope

Everything the Flutter app needs now has a real endpoint: auth (register
with OTP, login, profile, sessions, password change, Google Sign-In), KYC,
wallet, recipients/beneficiaries, corridors, FX quotes, transfers,
notifications, notification preferences, payout methods, plus a basic admin
panel (transaction history viewer).

Money movement goes through a **provider abstraction**
(`lib/provider/MoneyTransferProvider.php`) so the whole app works today
without real Nium credentials:

- `MONEY_TRANSFER_PROVIDER=stub` (default) — deterministic local fake data
  (fixed USD→INR rate, instantly-settling transfers, wallets tracked in a
  local `balance` column). No external credentials needed at all.
- `MONEY_TRANSFER_PROVIDER=nium` — real Nium sandbox/production calls via
  `lib/nium/*Service.php`. Requires filling in the `NIUM_*` values in
  `.env`. Some Nium schemas (beneficiary, FX quote, transfer/remittance,
  webhook signature) are still placeholders pending exact API docs — see
  those files' doc comments.

## Setup

1. Create the database and run every migration in order:
   ```
   mysql -u root < db/schema.sql
   mysql -u root < db/migration_002_nium.sql
   mysql -u root < db/migration_003_stub_provider.sql
   mysql -u root < db/migration_004_beneficiary_display_fields.sql
   mysql -u root < db/migration_005_quote_delivery_estimate.sql
   mysql -u root < db/migration_006_notifications_payout_methods.sql
   mysql -u root < db/migration_007_google_auth.sql
   ```
2. Check the credentials in `env.php` match your local MySQL setup (defaults
   to `root` with no password, as is typical for WAMP).
3. Copy `.env.example` to `.env` and fill in what you actually have —
   everything is optional except `MONEY_TRANSFER_PROVIDER` (defaults to
   `stub`, so the app runs with zero external credentials until you're
   ready to flip it to `nium`).
4. Make this folder reachable under WAMP's `www` root — a directory
   junction works well since it keeps everything in one place on disk:
   ```
   mklink /J C:\wamp64\www\wambely_api "<this folder's full path>"
   ```
   Confirm at `http://localhost:8080/wambely_api/` (port may differ if
   something else — e.g. IIS — already holds port 80 on your machine;
   check WAMP's tray icon or `httpd.conf`'s `Listen` line).
5. Create your first admin login: `php create_admin.php admin "a-strong-password"`,
   then sign in at `http://localhost:8080/wambely_api/admin/login.php`.

## Trying it end-to-end

Register a user in the app, verify with the OTP code returned in the API
response's `debug_code` field (no real SMS/email sender is wired up yet —
**remove `debug_code` from `auth.php` before any real deployment**),
complete KYC (any document + selfie auto-approves under the stub
provider), then use the wallet ($5,000 USD starting balance under the
stub), add a recipient, get a quote, and send money. It settles instantly
under the stub provider.

## Going to production

Three separate things, independent of each other and of this folder's
location on disk:
1. **Real Nium credentials** — fill in `.env`'s `NIUM_*` values and flip
   `MONEY_TRANSFER_PROVIDER=nium` once the pending schemas are confirmed.
2. **Real hosting** — this backend needs to run on a real public server
   (with HTTPS) before a Play Store/App Store build can reach it; `localhost`
   only ever works from this dev machine.
3. **Google Sign-In** — needs a real Google Cloud OAuth Web Client ID in
   both `.env`'s `GOOGLE_CLIENT_ID` and `../web/index.html`'s
   `google-signin-client_id` meta tag.

## Notes

- Bearer tokens are plain random strings stored in `auth_tokens`, not JWTs —
  simplest thing that works for a single backend instance.
- The admin panel uses its own session-based login (`admin_users`), entirely
  separate from app user accounts.
- All queries use PDO prepared statements; admin HTML output is escaped with
  `htmlspecialchars`.
