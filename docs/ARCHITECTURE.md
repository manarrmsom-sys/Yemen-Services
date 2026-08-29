# Architecture

## Stack

| Layer | Choice | Why |
|---|---|---|
| Framework | Laravel 11 (PHP 8.2) | Mature, batteries-included, good ops story on a VPS. |
| UI | Inertia.js + Vue 3 + Vite | SPA feel without a separate API client; one deploy. |
| Styling | Tailwind CSS | Fast, RTL-friendly with logical utilities. |
| i18n | `vue-i18n` (client) + Laravel `lang/` (server) | Instant switch client-side; server strings for emails/OTP/errors. |
| PWA | `vite-plugin-pwa` (Workbox) | Precache app shell, runtime-cache catalog, offline fallback. |
| DB | MySQL 8 (InnoDB, utf8mb4) | Relational integrity for the ledger + orders. |
| Cache / queue / session | Redis | Survives spikes; queue offloads webhooks, dispatch, SMS. |
| Auth | Laravel Sanctum | SPA cookie sessions + API tokens from one guard. |
| Edge | Cloudflare | DDoS, TLS, caching of static assets, real-IP restore. |
| Proxy | Nginx → PHP-FPM | Standard; rate-limit zones as defence-in-depth. |
| Workers | Supervisor (`queue:work`, `schedule:run`) | 4 queue procs; scheduler for order expiry + OTP pruning. |

## Request flow

```
Browser (PWA)
  │  Inertia visit / fetch
  ▼
Cloudflare ──► Nginx ──► PHP-FPM ──► Laravel
                              │        ├─ web routes  → Inertia pages (HandleInertiaRequests shares locale + catalog)
                              │        ├─ /api/v1/*    → JSON controllers (Sanctum, throttle:api|otp-request|booking|admin)
                              │        └─ /webhooks/*  → stateless receivers (HMAC-verified, CSRF-exempt)
                              ▼
                          MySQL 8  +  Redis (cache/queue/session)
                              ▲
                   Supervisor queue workers
                     ├─ DeliverWebhook (retry w/ backoff)
                     └─ dispatch / SMS / WhatsApp sends
```

## Domain services (`app/Services`)

| Service | Responsibility |
|---|---|
| `Pricing\PriceEstimator` | Deterministic quote: base model → pricing rules → governorate adjustment → urgency surcharge → VAT → round → minimum. Returns an immutable `PriceQuote`. |
| `Orders\OrderService` | Create order from booking (price snapshot + commission snapshot), state-machine transitions (`OrderStatus::canTransitionTo`), completion → wallet settlement + badge recalculation, event log, webhook emission. |
| `Dispatch\DispatchService` | Build `wa.me` deep link + structured n8n payload with geo tags & cost breakdown, persist `DispatchJob`, push to n8n (HMAC-signed), emit `order.dispatched`. |
| `Otp\OtpService` | Issue (hashed) codes, resend cooldown, per-phone + per-IP rate limits, verify with attempt cap. |
| `Messaging\ChannelManager` | Resolve `WhatsAppCloudChannel` / `HttpSmsChannel`; both fail-soft in non-prod. |
| `Wallet\WalletService` | Double-entry ledger. `post()` enforces net-zero across legs, row-locks wallets, updates denormalised buckets + lifetime counters, writes `wallet_transactions`. `settleCompletedOrder()` accrues + settles commission from the prepaid float. |
| `Kyc\KycService` | Store private docs, submit for review, per-document review, final verify/reject decision, badge recalculation, webhook emission. |
| `Webhooks\WebhookDispatcher` | Fan an event out to subscribed endpoints; each delivery queued via `DeliverWebhook` with exponential backoff and auto-disable. |

## The "Gap Play" — WhatsApp geo-dispatch

1. Booking created → `DispatchService::dispatchOrder($order, 'broadcast')`.
2. Builds:
   - **`wa_deeplink`** — `https://wa.me/<number>?text=<url-encoded message>` with a readable
     Arabic/English cost breakdown, service, governorate + district, urgency, payment method.
   - **`payload`** — JSON: `order` summary, `breakdown[]`, `geo` (governorate code/name, district,
     lat/lng, `geo_tag` = `ye:<gov>:<district-slug>`), `customer`, signed `callbacks.accept|decline`.
3. `DispatchJob` persisted; payload POSTed to `N8N_WEBHOOK_DISPATCH_URL` with
   `X-Signature: hmac_sha256(body, N8N_WEBHOOK_SECRET)`.
4. n8n fans the job to matching tradespeople over WhatsApp/SMS and, on a reply, calls back
   `POST /webhooks/n8n/dispatch-response` (or the tradesperson taps the signed accept link
   → `POST /webhooks/dispatch/{order}/{action}`).
5. First `accept` assigns the order and transitions it to `accepted`.

## Wallet / commission model (COD)

```
Top-up confirmed:
  tradesperson.available  +A      (credit)
  platform_escrow.available +A    (credit)          ── platform custodies the float

Order completed (subtotal S, commission C = S * pct):
  ── accrual ──
  tradesperson.commission_due +C  (credit)
  platform_revenue.pending    +C  (credit)          ── receivable recognised
  ── immediate settlement of min(C, available) = X ──
  tradesperson.available      -X  (debit)
  tradesperson.commission_due -X  (debit)
  platform_escrow.available   -X  (debit)           ── release custody
  platform_revenue.pending    -X  (debit)
  platform_revenue.available  +X  (credit)          ── income recognised
  (lifetime_earnings += S recorded separately; cash itself is off-ledger with COD)
```

Every block is one `group_uuid`, net-zero, row-locked, inside a DB transaction.
`commission_due` may remain positive if the float is short — the tradesperson tops up to clear it.

## Scaling & resilience

- **Stateless app** — session/cache/queue in Redis; horizontal scale by adding PHP-FPM nodes behind Nginx.
- **Spike tolerance** — webhook/SMS/WhatsApp sends are queued, never inline; dashboard metrics cached 20s; catalog cached 5 min and shared via Inertia to avoid an extra request per page.
- **Bundle** — Vite `manualChunks` + per-page code splitting; Workbox precache; fonts `CacheFirst` for a year.
- **Rate limits** — Nginx zones (`api` 20r/s, `otp` 1r/s) + app limiters (`api`, `otp-request` per-IP & per-phone, `booking`, `admin`).
- **Idempotency** — order `reference` unique; OTP `session_token` opaque; webhook `event_id` stable across retries.
