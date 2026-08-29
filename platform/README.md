# خدمات اليمن · Yemen Services Platform

On-demand home & commercial services platform for the Yemeni & regional market —
an installable, offline-first PWA (Arabic-RTL default / English-LTR) plus an
enterprise operations dashboard.

Built with **Laravel 11 · Inertia + Vue 3 · MySQL 8 · Redis · Tailwind · Vite PWA**.

## Quick start

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed        # governorates + catalog + admin + demo data
npm run dev                       # terminal 1
php artisan serve                 # terminal 2  → http://localhost:8000
```

Admin sign-in: phone `+967700000001` (OTP — the code is written to `storage/logs/laravel.log`
in local dev) or the password printed once by `AdminUserSeeder`.

## What's inside

| Area | Where |
|---|---|
| 3-step booking drawer | `resources/js/Components/Booking/BookingDrawer.vue` |
| Dynamic price estimator | `app/Services/Pricing/PriceEstimator.php` |
| OTP / WhatsApp auth | `app/Services/Otp/`, `app/Services/Messaging/` |
| WhatsApp geo-dispatch ("Gap Play") | `app/Services/Dispatch/DispatchService.php` |
| Dual-wallet double-entry ledger | `app/Services/Wallet/WalletService.php` |
| KYC pipeline | `app/Services/Kyc/`, `app/Http/Controllers/Api/Admin/KycReviewController.php` |
| Webhook/integration suite | `app/Services/Webhooks/`, `app/Jobs/DeliverWebhook.php` |
| Admin dashboard | `resources/js/Pages/Admin/*` |
| API surface | `routes/api.php` (`/api/v1/*`, `/webhooks/*`) |

## Tests

```bash
php artisan test
```

## Docs

See [`../docs/`](../docs): `SCOPE_OF_WORK.md`, `ARCHITECTURE.md`, `ER_DIAGRAM.md`,
`DEPLOYMENT.md`, `HANDOVER.md`.

## Security

No secrets in source — everything via `.env` → `config/*.php`. ORM everywhere,
hashed credentials, HTTPS-forced in prod, CSRF + security headers + CSP, per-IP/per-phone
rate limits, private KYC storage, HMAC-signed webhooks. Details in `../docs/HANDOVER.md` §6.
