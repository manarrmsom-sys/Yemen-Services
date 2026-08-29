# Handover Packet — Yemen On-Demand Services Platform

## 1. What this is

A bilingual PWA + enterprise admin dashboard for on-demand home & commercial services
in Yemen. Monorepo:

```
yemen service/
├── platform/            Laravel 11 + Inertia/Vue 3 application
├── deploy/              Nginx, Supervisor, Dockerfile, n8n workflow
├── docs/                this packet
├── docker-compose.yml   local/staging stack
└── *.html               original static concept (reference only)
```

Tech stack: **Laravel 11 · PHP 8.2 · MySQL 8 · Redis · Inertia/Vue 3 · Tailwind · Vite PWA**.

## 2. Run it locally

```bash
cd platform
composer install
npm install
cp .env.example .env
php artisan key:generate

# Option A — Docker (from repo root): docker compose up
# Option B — local services: point .env at your MySQL + Redis, then:
php artisan migrate --seed          # seeds governorates, catalog, an admin, and demo data
npm run dev                         # Vite dev server
php artisan serve                   # http://localhost:8000
```

`APP_ENV=local` also runs `DemoSeeder` (3 tradespeople, wallets, one completed order).
WhatsApp/SMS sends are logged to `storage/logs/laravel.log` instead of being sent.

## 3. Database schema

See `docs/ER_DIAGRAM.md` for the full ERD and column notes. Migrations live in
`platform/database/migrations/`. Regenerate a fresh DB with `php artisan migrate:fresh --seed`.

Key state machines:
- **Order**: `draft → pending_dispatch → dispatched → accepted → en_route → in_progress → completed → closed` (`cancelled`/`expired` branch out). Enforced by `App\Enums\OrderStatus::canTransitionTo()`.
- **KYC**: `unverified → pending → in_review → verified | rejected`.
- **Wallet posting**: double-entry, every `group_uuid` nets to zero (`App\Services\Wallet\WalletService`).

## 4. Credentials & where they live

| Secret | Location | Notes |
|---|---|---|
| `APP_KEY` | `.env` (server only) | `php artisan key:generate`. Never commit. |
| DB password | `.env` `DB_PASSWORD` | MySQL user `yemen_app@127.0.0.1`. |
| Redis password | `.env` `REDIS_PASSWORD` + `/etc/redis/redis.conf` | |
| Admin login | Created by `AdminUserSeeder`. Phone `SEED_ADMIN_PHONE` (default `+967700000001`). Password printed **once** to the console on seeding — store it in the client's password manager, then have the admin change it. | Admins can also log in passwordless via OTP. |
| WhatsApp Cloud API token | `.env` `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID` | From Meta Business. |
| SMS gateway key | `.env` `SMS_API_KEY`, `SMS_ENDPOINT` | Local aggregator. |
| n8n shared secret | `.env` `N8N_WEBHOOK_SECRET` + n8n credential | HMAC for dispatch callbacks. |
| Outbound webhook secrets | `webhook_endpoints.secret` (DB) | Shown once in the admin UI on creation; hashed-hidden after. |

**No secret is hard-coded anywhere in the source** — grep for `env(` shows every input; all
consumed through `config/*.php`.

## 5. External services & account ownership

| Service | Purpose | Owned by | Console |
|---|---|---|---|
| Domain registrar | `yemenservices.example` | Client | — |
| VPS (DigitalOcean/Hetzner/AWS) | App + DB + Redis | Client | provider dashboard |
| Cloudflare | DNS, TLS, DDoS, caching | Client | dash.cloudflare.com |
| Meta WhatsApp Cloud API | OTP + dispatch messaging | Client | business.facebook.com |
| SMS aggregator | OTP fallback | Client | provider portal |
| n8n | Dispatch automation, top-up reconciliation | Client (self-hosted or cloud) | n8n instance |
| Object storage (backups) | DB + KYC off-site backup | Client | provider |

The engineering vendor holds no production credentials after handover.

## 6. Security checklist (Phase 4)

- [x] No passwords / API keys in source — all via `.env` → `config/`.
- [x] All DB access via Eloquent ORM / query builder (prepared statements).
- [x] Admin/staff passwords hashed (bcrypt via `casts()['password'] => 'hashed'`); customers/tradespeople are passwordless (hashed OTP codes).
- [x] HTTPS enforced — `URL::forceScheme('https')` in prod + Nginx redirect + HSTS.
- [x] Authorization enforced server-side — `admin` / `verified.trade` middleware + `OrderPolicy` / `TradespersonProfilePolicy` / `WebhookEndpointPolicy`; never relies on the frontend.
- [x] CSRF on web routes; webhook receivers exempt but HMAC-signature-verified.
- [x] Security headers + CSP (`SecurityHeaders` middleware).
- [x] Rate limiting: Nginx zones + app limiters (per-IP and per-phone for OTP).
- [x] Input validation + sanitisation: Form Requests, `strip_tags` on free-text booking fields.
- [x] KYC documents on a private disk, streamed only through the gated `KycReviewController@file`.
- [x] Backup procedure documented (`docs/DEPLOYMENT.md` §5).

## 7. Acceptance checklist

Run `php artisan test` (SQLite in-memory). Then, against a seeded environment:

1. `POST /api/v1/estimate` returns a `data.total` with no auth.
2. `POST /api/v1/bookings` (guest) returns an order `reference` and a `wa.me` deep link; an `orders` row is `dispatched` and a `dispatch_jobs` row exists.
3. `POST /api/v1/auth/otp/request` then `/verify` returns a Sanctum token; re-using the phone resolves the same `users.id`.
4. Admin verifies a `pending` tradesperson in **Admin → KYC**; the `verified` badge appears on `/pros`.
5. Move a demo order to `completed` in **Admin → Orders**; a `commission_entries` row appears and `wallet_transactions` shows the balanced accrual + settlement legs.
6. **Admin → Integrations**: create an endpoint, "Send test", see a `success` (or `failed` w/ retry) in its deliveries.
7. `npm run build` produces `public/build` with code-split chunks + `sw.js`; loading the app offline shows the shell / `/offline`.

## 8. Maintenance plan

| Cadence | Task |
|---|---|
| Continuous | Supervisor keeps `queue:work` alive; scheduler expires stale orders + prunes OTPs. |
| Daily | Automated `mysqldump` + off-site sync (cron in §5 of the runbook). Check `storage/logs`. |
| Weekly | `composer outdated` / `npm outdated`; review `webhook_deliveries` for `dead` rows; review `inbound_events` for `error`. |
| Monthly | `composer update` + `npm update` on staging, run tests, deploy. Rotate the generated admin password. Test a DB restore into staging. |
| Quarterly | PHP/Laravel minor-version review; review Cloudflare + Nginx rate limits against real traffic; re-issue WhatsApp template if Meta requires. |
| As needed | Re-enable an auto-disabled webhook endpoint from the admin UI after fixing the receiver. |

## 9. Known gaps / next phase

See `docs/SCOPE_OF_WORK.md` §3. Notably: no in-app card payments, no map tracking,
inbound WhatsApp message parsing (auto-accept from a free-text "نعم") is stubbed in
`WhatsAppWebhookController` and should be completed with a queued handler + the n8n flow.
