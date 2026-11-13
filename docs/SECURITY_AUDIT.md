# Security audit — 2026-11-14

Scope: the Laravel application in `platform/` (routes, controllers, policies,
models, webhooks, auth, uploads, configuration, JS dependencies).
Method: manual code review, then a regression suite that reproduces each issue
(`platform/tests/Feature/SecurityTest.php`, 38 tests). Not covered: the live
server, network, Cloudflare/Nginx configuration, and PHP dependency advisories
(Composer is not installed on the audit machine).

Run the suite: `php artisan test --filter=SecurityTest`

## Fixed

| # | Severity | Issue | Fix |
|---|---|---|---|
| 1 | Critical | Phone OTP login looked the user up by a null email and signed the caller in as the first account without an email. | Look up by the identity the code was issued for. |
| 2 | Critical | `/bypass-login` signed anyone in as admin, in every environment. | Registered only when `APP_ENV=local`. |
| 3 | Critical | `PATCH /api/v1/profile` let a user set any email, unverified. An attacker could pre-register a victim's address and receive the victim's account at first email login. | Email/phone are no longer editable there; they change only through OTP. |
| 4 | High | Unsigned n8n webhooks were accepted in every environment except `production` (staging included) — including `topup-matched`, which credits wallets. | Unsigned calls only on `local`/`testing`. |
| 5 | High | Same bypass on the WhatsApp inbound webhook: a forged "نعم" could assign orders. Signature was also checked against the access token instead of the Meta App Secret, so real signatures never verified. | Verify with `WHATSAPP_APP_SECRET`; unsigned only on `local`/`testing`. |
| 6 | High | Dispatch accept/decline links skipped the HMAC outside production, and the token did not cover `tp`, so one link could assign any tradesperson. | Token always required and bound to order + action + tradesperson. |
| 7 | High | Public order tracking (`/track/{ref}`) returned the whole order: customer name, phone, notes, IP, commission, internal event messages. | Strict whitelist (status + timeline), 30 lookups/min per IP. |
| 8 | High | `GET /api/v1/trade/jobs` gave every verified pro the customer's name, phone, notes and address for open jobs. | Summary only until a pro is assigned. |
| 9 | High | Any eager-loaded `User` serialised email, phone and registration IP (order actors, tradesperson owners). | `User::$hidden` covers contact fields; admin endpoints opt in with `makeVisible`. |
| 10 | Medium | A pro could accept cancelled/closed orders, orders outside their category, and two pros could win the same order (race). | Status + category checks, atomic conditional update. |
| 11 | Medium | OTP verify requests all shared one throttle bucket (keyed on an empty email), so three verifications per minute locked out every user. | Keyed on the session token. |
| 12 | Medium | Every API validation error and unauthenticated request returned 500. | Rendered as 422 / 401. |
| 13 | Medium | Guest order claim compared raw phone strings and accepted unverified phones. | Normalised comparison, verified phone required. |
| 14 | Low | Without WhatsApp credentials, login codes were written to the log on staging. | Only on `local`/`testing`. |
| 15 | Low | KYC document rows exposed `disk`/`path`; orders exposed `created_ip`. | Hidden. |
| 16 | Low | Webhook endpoint update accepted any URL scheme; `skills`/governorate arrays were unvalidated. | Validated. |
| 17 | — | 10 of 22 npm advisories fixed with `npm audit fix`. | See "Open". |

## Verified as already sound

SQL injection (Eloquent bindings throughout), stored XSS (Vue escapes output,
booking text is tag-stripped), price tampering (totals computed server-side),
IDOR on orders and addresses, admin route protection (every `/api/v1/admin/*`
route), dispatcher vs admin separation, suspended-staff lockout, KYC files on a
private disk behind a staff-only route, upload type sniffing (PHP/SVG rejected),
hashed OTP codes, no account enumeration on OTP request, CSRF on web routes,
CORS limited to `APP_URL`, security headers + CSP, no stack traces with
`APP_DEBUG=false`, no secrets in `.env.example`.

## Open — needs a decision or access

1. **12 npm advisories remain** (5 high). They need major upgrades
   (`vue-i18n` 10 → 11, `vite` 5 → 6) and should be done as their own change.
2. **PHP dependencies not audited.** Run `composer audit` where Composer exists.
3. **CSP allows `'unsafe-inline'` scripts** (needed by the inline Ziggy route
   script). Moving to nonces would remove it.
4. **Order references are 6 characters.** Tracking is now minimal and
   rate-limited; 8–10 characters would add margin.
5. **Tradespeople set `total_final` freely.** Commission uses the estimate
   subtotal, so this affects the customer's displayed total, not platform revenue.
6. **Admin has no second factor.** Login is a single OTP to email/phone.
7. **Server hardening is unverified**: firewall, Cloudflare rules, Redis/MySQL
   binding, backups, `.env` permissions — see `PRODUCTION_CHECKLIST.md`.
8. **Set before go-live:** `WHATSAPP_APP_SECRET`, `N8N_WEBHOOK_SECRET`,
   `WHATSAPP_WEBHOOK_VERIFY_TOKEN`. Without them the webhooks reject everything.
