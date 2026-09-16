# Easesplit — Bachelor House Edition

A mobile-first expense-splitting app for a shared house: Laravel 12 / PHP 8.4
API backend + a single-file installable PWA frontend (`public/app.html`) that
works on Android and iOS with no app store, no build step.

## Try it right now

```bash
php artisan serve
```

Open **http://127.0.0.1:8000/** (redirects to `/app.html`). Sign up with a
username, create a house, add your housemates by username, create a split,
have them accept it, pay, confirm. On a phone, "Add to Home Screen" installs
it like a real app.

## What's implemented

- **Username-based auth** — sign up/log in with a username + password (no
  email required). Google Sign-In and phone OTP still work as alternate
  entry points and auto-generate a username if needed.
- **Houses** — create a house (name + optional type), add housemates by
  username directly, or share the invite code for others to self-join. A
  house is a `groups` row under the hood (type defaults to `house`) — kept
  as the existing, well-tested Group/Expense/Settlement domain rather than
  a parallel "house" table, since renaming touches a lot of surface for a
  vocabulary-only change. The UI always says "house."
- **Split creation** — a `+` button opens a sheet: amount, description,
  house, paid-by, and a live equal/percentage/custom split editor with a
  running per-person preview as you type.
- **Accept / reject workflow** — a split only becomes a real debt once each
  participant accepts it; only then does it hit the [Smart Debt
  Engine](app/Services/DebtEngine.php).
- **Pay via UPI** — tapping Pay builds a standard `upi://pay` deep link
  (works with any UPI app) and opens it. Per the spec, clicking Pay alone
  never settles a debt — the *recipient* confirms receipt from a "Confirm
  payments received" section on their Home screen, which is what actually
  calls the verification endpoint and nets the ledger.
- **Push notifications** — real Web Push (VAPID), not a stub. New split,
  split accepted/rejected, payment confirmed, and disputes all deliver as
  native OS notifications when enabled from Profile → Push notifications.
- **Installable PWA** — `manifest.json` + `sw.js` (offline app-shell
  caching, push handling, notification-click routing) plus the meta tags
  iOS needs for "Add to Home Screen" to behave like a real app.
- **Smart Debt Engine** — one normalized balance row per pair of users;
  "Ram owes Ajay ₹500, Ajay owes Ram ₹300" always collapses to "Ram owes
  Ajay ₹200." Verified end-to-end against the product spec's own worked
  example — see "Debt Engine correctness" below.
- **Dashboard, friend ledger, search, notifications API** — same as before.

## What's intentionally deferred

Gamification, OCR bill scanning, WhatsApp automation, AI insights, and the
`analytics_snapshots` reporting pipeline have their schema in place but no
UI/controllers — V2 per the product spec.

## Two deliberate deviations from the spec

**Sanctum instead of raw JWT.** The spec asks for "JWT Authentication."
This uses Laravel Sanctum (bearer tokens) instead — functionally
equivalent for a mobile/PWA client, framework-native, supports revocation
via `/auth/logout` for free. Swap in `php-open-source-saver/jwt-auth` if
you specifically need self-contained JWTs later.

**PWA instead of React Native.** The original spec named React Native for
mobile. Given the goal of housemates actually using this soon, a native RN
app needs Expo tooling, a dev build, and eventual app store/TestFlight
submission before anyone outside this machine can install it. The PWA is
one link, works today on any Android/iOS phone via the browser, and
supports push notifications (fully on Android; iOS 16.4+ for
home-screen-installed PWAs). If native distribution becomes a real
requirement later (deeper OS integration, app store presence), this same
API is what an RN app would talk to.

## Getting started

```bash
composer install
cp .env.example .env   # or keep the committed .env, which already points at SQLite
php artisan key:generate
php artisan migrate
php artisan serve
```

Ships with PHP 8.4 + Composer already set up locally, and a migrated SQLite
database so it runs with zero extra setup. For production, switch `.env` to
PostgreSQL and Redis — see the commented block in `.env.example`.
`predis/predis` is already installed (pure PHP, no `ext-redis` needed).

Run a queue worker (notifications, including push, are dispatched via the
queue):

```bash
php artisan queue:work
```

### Windows-specific: `OPENSSL_CONF`

Web Push (VAPID) needs `ext-openssl` to generate/sign EC keys, which on the
Windows PHP build fails with a cryptic `error:80000003:system
library::No such process` unless `OPENSSL_CONF` points at the bundled
`openssl.cnf`. This machine already has it set as a permanent user
environment variable. On a fresh machine:

```powershell
[System.Environment]::SetEnvironmentVariable("OPENSSL_CONF", "<path-to-php>\extras\ssl\openssl.cnf", "User")
```

(Restart your terminal after setting it.) Not needed on Linux/macOS.

### Generating your own VAPID keys

`.env` already has a working keypair for local testing. To generate fresh
ones for production:

```bash
php -r "require 'vendor/autoload.php'; print_r(\Minishlink\WebPush\VAPID::createVapidKeys());"
```

Put the result in `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY`.

## Debt Engine correctness

The core invariant — "only one balance should exist between any two
users" — is enforced by `ledgers`, a table with **exactly one row per
unordered pair of users**, updated inside a locked DB transaction
(`DebtEngine::applyDelta`). Verified end-to-end (via the API, and again
through the actual browser UI) against the exact worked example in the
product spec:

1. Ram pays ₹1000, split equally among Ram/Ajay/Kiran/Rahul/Vijay (₹200 each).
2. Ajay pays ₹1000 the next day, same group, same split.
3. Result: Ram's dashboard shows **no** balance with Ajay at all (fully
   netted to ₹0), while Kiran/Rahul/Vijay still each owe him ₹200.

## Bugs this testing process actually caught (and fixed)

Real bugs found by exercising the app end-to-end, not just reading the
code — kept here so the pattern is easy to recognize if it recurs:

- An **inverted balance sign** in `DebtEngine::applyDelta`.
- An **operator-precedence bug** in `->where()->orWhere()->where()` chains
  (SQL's `AND` binds tighter than `OR`) in `DebtEngine::balancesFor` and
  `FriendController`'s pending-settlements count — let settled balances
  leak back into "pending" counts.
- **`updateOrCreate` with a closure value** in `AuthController::google` —
  Eloquent never evaluates closures passed as attribute values, so it would
  have written a literal `Closure` object into the `password` column.
- An API-only app with **no web `login` route** — Laravel's `Authenticate`
  middleware called `route('login')` while building its exception for any
  guest request without a strict `Accept: application/json` header,
  throwing `RouteNotFoundException` (500) instead of a clean 401. Fixed via
  `$middleware->redirectGuestsTo(fn () => null)` in `bootstrap/app.php`.
- **`GET /groups` vs `GET /groups/{id}` response-shape mismatch** — the
  list endpoint didn't eager-load `users`, but the split-creation UI
  assumed every house object had `.users`, silently leaving the "Paid by"
  picker empty. Fixed by eager-loading consistently in
  `GroupController::index`.
- A chip-style username input that **only committed on Enter** — mobile
  virtual keyboards don't reliably fire a `keydown` for their "Go"/"Enter"
  key, so a typed username could be silently dropped on submit. Fixed by
  also committing on blur and on submit (`wireChipInput` in `app.html`).

## API surface

See `routes/api.php`, or run `php artisan route:list --path=api`. Add
`/api` prefix note: everything except `/auth/register`, `/auth/login`,
`/auth/google`, `/auth/otp/*`, and `/push/vapid-public-key` requires a
Sanctum bearer token.

## Database schema

All tables from the spec exist as migrations under `database/migrations/`:
`users` (extended with username/phone/OTP/UPI/Google fields), `friends`,
`groups`, `group_members`, `expenses`, `expense_participants`,
`expense_disputes`, `settlements`, `payment_verifications`, `ledgers` (the
debt engine's core table), `notifications` (Laravel's standard shape),
`activity_logs`, `reminders`, `analytics_snapshots`, `push_subscriptions`
(Web Push endpoints).

## Next steps

- Wire `reminders` to a scheduled command that creates reminder rows at
  1-day-before / due-date / 3/7/30-days-overdue and dispatches them via
  push/email/WhatsApp channels.
- Swap the payment verification stub in `SettlementController::verify` for
  a real UPI Collect / payment gateway webhook (with signature
  verification) once a provider is chosen — the manual "Confirm received"
  flow the PWA uses today is a reasonable stand-in for a household.
- If native app-store distribution ever becomes a real requirement, a React
  Native app can consume this same API without backend changes.
