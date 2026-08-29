# Scope of Work — Yemen On-Demand Services Platform

**Client:** _(platform owner)_
**Vendor:** _(engineering team)_
**Date:** 2026-08-30
**Status:** Draft for sign-off

---

## 1. Objective

Deliver a production-ready, bilingual (Arabic-RTL default / English-LTR) Progressive
Web App plus an enterprise operations dashboard connecting Yemeni households and
businesses with verified tradespeople, optimised for low-bandwidth conditions and
sudden traffic spikes.

## 2. In scope

| # | Deliverable | Detail |
|---|-------------|--------|
| 2.1 | **PWA customer app** | Offline-first shell, installable, RTL/LTR with instant switch, Yemen national palette, Tajawal/Inter typography. |
| 2.2 | **3-step smart booking drawer** | Bottom-sheet flow: service → location & time → contact & payment. Sub-30-second target. |
| 2.3 | **Dynamic price estimator** | Transparent, rule-driven pricing shown before registration. Fixed / hourly / per-unit / quote models; governorate multipliers; urgent surcharge; VAT-ready. |
| 2.4 | **Passwordless auth** | OTP over WhatsApp (default) or SMS. No multi-page registration. Sanctum tokens (API) + session (web). |
| 2.5 | **WhatsApp geo-dispatch ("Gap Play")** | Pre-filled `wa.me` deep link + signed JSON webhook to n8n carrying category, cost breakdown, and localized geo tags. Accept/decline callbacks. |
| 2.6 | **KYC & badging** | Document upload pipeline, reviewer workflow, verified / background-checked / top-rated / experienced badges. |
| 2.7 | **Enterprise admin dashboard** | Real-time order tracker & dispatch board, KYC review pipeline, dual-wallet manager, webhook/integration suite. |
| 2.8 | **Dual-wallet ledger** | Double-entry ledger: tradesperson wallet (available / commission-due / pending / lifetime) + platform escrow + platform revenue. COD commission settlement from a prepaid float. Manual + n8n-automated top-up reconciliation. |
| 2.9 | **Security layer** | Rate limiting (Nginx + app), CSRF, security headers/CSP, HTTPS enforcement, input validation & sanitisation, private KYC storage, signed webhooks. |
| 2.10 | **Ops & deployment** | VPS provisioning guide, Nginx reverse proxy, Redis (cache/queue/session), Cloudflare, Supervisor workers, migrations + seeders, backup procedure. |
| 2.11 | **Documentation** | This SOW, architecture doc, ER diagram, deployment runbook, handover packet. |

## 3. Out of scope (future phases)

- Native iOS/Android apps (the PWA is installable and covers phase 1).
- In-app card payments / payment-gateway settlement (ledger is COD + manual/auto transfer confirmation).
- Real-time driver-style GPS tracking on a map (status timeline only).
- Multi-currency. Everything is Yemeni Rial (YER).
- Automated background-check integrations (reviewer marks the flag manually).
- SSR / SEO beyond the public marketing pages.

## 4. Assumptions

- Client provides: domain, VPS (DigitalOcean/Hetzner/AWS), Cloudflare account, WhatsApp Cloud API business number + access token, local SMS gateway credentials, n8n instance.
- One production environment + one staging environment.
- Content (service list, base prices, governorate multipliers) supplied by the client and loaded via seeders / admin.

## 5. Acceptance criteria

1. A guest can get a price estimate and place a booking that produces a working WhatsApp deep link — no login.
2. OTP login works over WhatsApp and SMS; a returning phone number resolves to the same account.
3. Admin can review KYC docs, verify a tradesperson, and see the badge appear on the public profile.
4. Completing an order accrues commission and settles it against the tradesperson's prepaid float, with a matching double-entry ledger.
5. A registered webhook endpoint receives signed `order.*` / `kyc.*` / `wallet.*` events and the delivery log records success/failure with retries.
6. `php artisan test` passes; the app builds (`npm run build`) with a code-split bundle and a generated service worker.
7. Lighthouse PWA install criteria met; the app shell loads offline.

## 6. Ownership

On final payment, all source code, the database schema, and deployment scripts are
owned by the client. Third-party account ownership (domain, VPS, Cloudflare, WhatsApp
Business, SMS gateway, n8n) sits with the client from day one — see `HANDOVER.md`.
