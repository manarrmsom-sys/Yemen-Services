# Production Deployment Checklist — Yemen Services Platform

Target: single VPS (Ubuntu 22.04, ≥ 4 vCPU / 8 GB for the 50k-orders/day goal),
Nginx reverse proxy → PHP-FPM, Redis, Supervisor workers, Cloudflare in front.

Work top to bottom. `[ ]` = do it, `[x]` = already handled by the repo/CI.

---

## 1. DNS & Cloudflare

- [ ] `A` record `yemenservices.example` → VPS IPv4 (proxied / orange cloud).
- [ ] `A` record `www` → same, proxied. Page rule / redirect rule `www` → apex.
- [ ] SSL/TLS mode: **Full (strict)**.
- [ ] "Always Use HTTPS": **On**. "Automatic HTTPS Rewrites": **On**.
- [ ] Min TLS version: 1.2. Enable HTTP/3 + 0-RTT.
- [ ] Bot Fight Mode: **On**.
- [ ] Rate-limiting rule: `/api/v1/auth/*` → 10 req / 10 s / IP → managed challenge.
- [ ] Rate-limiting rule: `/webhooks/*` → 60 req / min / IP (bypass for known WhatsApp/n8n IPs if listable).
- [ ] Cache rule: `/build/*` → cache everything, edge TTL 1 year.
- [ ] Cache rule: `/`, `/api/*`, `/admin/*` → bypass cache.
- [ ] Firewall: allow only Cloudflare IP ranges to reach ports 80/443 on the origin (ufw + `deploy/nginx.conf` `real_ip`).

## 2. Server base

- [ ] `apt` packages installed (see `docs/DEPLOYMENT.md` §0): nginx, php8.2-fpm + extensions, redis-server, supervisor, mysql-server, certbot, unzip, git.
- [ ] `ufw`: allow OpenSSH; allow 80/443 **only from Cloudflare ranges**; deny 3306/6379 from the world.
- [ ] Unattended-upgrades enabled for security patches.
- [ ] Swap file configured (2–4 GB) as a safety net.
- [ ] Timezone `Asia/Aden`; NTP synced.
- [ ] Non-root deploy user with a locked-down `sudoers` entry for `systemctl reload php8.2-fpm` and `supervisorctl`.

## 3. PHP-FPM tuning (`/etc/php/8.2/fpm/pool.d/www.conf`)

- [ ] `pm = dynamic`, `pm.max_children` sized to RAM (≈ `(total_RAM * 0.7) / 60MB`).
- [ ] `pm.max_requests = 500` (recycle workers).
- [ ] `/etc/php/8.2/fpm/php.ini`: `opcache.enable=1`, `opcache.validate_timestamps=0` (prod),
      `opcache.memory_consumption=256`, `realpath_cache_size=4096K`, `upload_max_filesize=12M`,
      `post_max_size=13M`, `expose_php=Off`.
- [ ] `opcache.preload` pointed at `current/platform/vendor/laravel/framework` preload script (optional, big win).

## 4. MySQL

- [ ] `yemen_services` DB + `yemen_app@127.0.0.1` user created (see `docs/DEPLOYMENT.md`).
- [ ] `innodb_buffer_pool_size` ≈ 50–60% of RAM.
- [ ] `max_connections` ≥ `pm.max_children` + workers + headroom.
- [ ] `innodb_flush_log_at_trx_commit=1` (keep — the wallet ledger needs durability).
- [ ] Slow query log on (`long_query_time=1`).
- [ ] Daily `mysqldump --single-transaction` cron + off-site sync (script in `docs/DEPLOYMENT.md` §5).
- [ ] Verified a restore into staging.

## 5. Redis

- [ ] `requirepass` set; `REDIS_PASSWORD` in `.env`.
- [ ] `maxmemory` set (e.g. 512mb), `maxmemory-policy noeviction` for the DB `0` (queue) —
      run cache on DB `1` with `allkeys-lru` if you split instances; the repo already uses
      separate logical DBs (`REDIS_CACHE_DB=1`).
- [ ] `appendonly yes` (queue jobs survive a restart).
- [ ] Bound to `127.0.0.1` only.

## 6. Application config (`shared/.env` on the VPS)

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://yemenservices.example`.
- [ ] `APP_KEY` generated (`php artisan key:generate` once; then it lives in `shared/.env`).
- [ ] `APP_LOCALE=ar`, `APP_TIMEZONE=Asia/Aden`.
- [ ] `TRUSTED_PROXIES` = Nginx loopback (`127.0.0.1,::1`) — Cloudflare real-IP is restored by Nginx.
- [ ] DB / Redis credentials filled.
- [ ] `SESSION_DRIVER=redis`, `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=.yemenservices.example`.
- [ ] `SANCTUM_STATEFUL_DOMAINS=yemenservices.example`.
- [ ] `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`.
- [ ] `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_TEMPLATE_OTP`,
      `WHATSAPP_BUSINESS_NUMBER`, `WHATSAPP_WEBHOOK_VERIFY_TOKEN` set.
- [ ] `SMS_DRIVER=http`, `SMS_ENDPOINT`, `SMS_API_KEY`, `SMS_SENDER_ID` set (fallback channel).
- [ ] `N8N_WEBHOOK_DISPATCH_URL`, `N8N_WEBHOOK_SECRET` set (same secret in n8n).
- [ ] `PLATFORM_COMMISSION_PERCENT`, `PRICING_*`, `RATELIMIT_*` reviewed with the client.
- [ ] `SEED_ADMIN_PHONE` / `SEED_ADMIN_EMAIL` set; `SEED_ADMIN_PASSWORD` left blank on first seed
      (a strong one is generated + printed once) — then have the admin change it and clear the var.
- [ ] `.env` file perms `640`, owned `www-data:www-data`.

## 7. First deploy

- [ ] `$DEPLOY_PATH/{releases,shared}` created; `shared/.env` in place; `shared/storage/**` created.
- [ ] `php artisan migrate --force`.
- [ ] `php artisan db:seed --class=GovernorateSeeder --force`
- [ ] `php artisan db:seed --class=CatalogSeeder --force` (or load the client's real catalog).
- [ ] `php artisan db:seed --class=AdminUserSeeder --force` — **save the printed admin password** to the client's password manager.
- [ ] `php artisan storage:link`.
- [ ] `npm run generate:icons` ran (CI does this) → `public/icons/*.png`, `favicon.ico` present.
- [ ] `php artisan config:cache route:cache view:cache event:cache`.
- [ ] `chown -R www-data:www-data storage bootstrap/cache`.
- [ ] `current` symlink points at the release.

## 8. Nginx + TLS

- [ ] `deploy/nginx.conf` installed and adapted (`server_name`, cert paths, `root` → `current/platform/public`).
- [ ] `/etc/nginx/cloudflare-ips.conf` generated (`docs/DEPLOYMENT.md` §2).
- [ ] `certbot --nginx` issued a cert; auto-renew timer active (`systemctl list-timers | grep certbot`).
- [ ] `nginx -t` clean; `systemctl reload nginx`.
- [ ] `client_max_body_size 12M` (KYC / top-up proof uploads).
- [ ] Verify security headers + HSTS with `curl -sI https://yemenservices.example`.

## 9. Workers & scheduler (Supervisor)

- [ ] `deploy/supervisor.conf` installed to `/etc/supervisor/conf.d/`.
- [ ] `/var/log/yemen-services/` exists.
- [ ] `supervisorctl reread && update && start all`.
- [ ] `yemen-queue` running with `numprocs=4` (scale with `RATELIMIT`/volume).
- [ ] `yemen-scheduler` running (`schedule:run` every 60s) — confirms `expire-stale-orders` + OTP prune fire.
- [ ] `php artisan queue:failed` checked after first traffic; `queue:retry all` wired into runbook.

## 10. Integrations

- [ ] **WhatsApp Cloud API**: webhook URL `https://yemenservices.example/webhooks/whatsapp`,
      verify token = `WHATSAPP_WEBHOOK_VERIFY_TOKEN`. Fields subscribed: `messages`.
      OTP message template submitted **and approved** by Meta.
- [ ] GET verification handshake returns the `hub.challenge` (test from Meta dashboard).
- [ ] Send a real inbound "نعم" from a test tradesperson number → order auto-assigns
      (check `inbound_events` + `dispatch_jobs.status`).
- [ ] **n8n**: `deploy/n8n/dispatch-workflow.json` imported; `PLATFORM_URL`, `WHATSAPP_*`,
      and `YEMEN_DISPATCH_SECRET` credentials set; the two response flows created
      (`/webhooks/n8n/dispatch-response`, `/webhooks/n8n/topup-matched`).
- [ ] **Admin → Integrations**: register the client's outbound endpoints; the signing secret
      shown once is stored securely. "Send test" returns `success`.
- [ ] SMS gateway: send a live OTP over SMS and confirm delivery.

## 11. Security verification (Phase 4 sign-off)

- [ ] `grep -rn "env(" platform/app` → only inside `config/` consumers, none in app code.
- [ ] `APP_DEBUG=false` confirmed in the live `/` response (no stack traces).
- [ ] `curl https://.../\.env` → 404/403 (Nginx `deny` rules).
- [ ] `curl https://.../api/v1/admin/dashboard/metrics` unauthenticated → 401.
- [ ] Non-admin token hitting `/api/v1/admin/*` → 403.
- [ ] KYC file URL only reachable as an authenticated admin (streamed, not a public path).
- [ ] Webhook receiver rejects a payload with a bad `X-Signature` (n8n) / `X-Hub-Signature-256` (WhatsApp) in production.
- [ ] Rate limit: 6 rapid `POST /api/v1/auth/otp/request` from one IP → 429.
- [ ] HTTPS-only cookies (`Secure`, `HttpOnly`, `SameSite=Lax`) on the session cookie.
- [ ] `php artisan about` shows caches ON, driver = redis, env = production.

## 12. Observability & backups

- [ ] Log rotation for `storage/logs` + `/var/log/yemen-services`.
- [ ] Uptime monitor hitting `/up` (external, e.g. UptimeRobot / Cloudflare Health Check).
- [ ] Error alerting: `LOG_LEVEL=warning` + ship `storage/logs/laravel.log` to a collector
      (or add Sentry — `SENTRY_LARAVEL_DSN`, not bundled).
- [ ] DB backup cron verified running; last backup age alert.
- [ ] `restic`/`rclone` job for `shared/storage/app/kyc` to off-site object storage.
- [ ] Monthly restore drill scheduled.

## 13. CI/CD (GitHub)

- [ ] Repo secrets: `SSH_PRIVATE_KEY`, `SSH_HOST`, `SSH_USER`.
- [ ] Repo variables: `DEPLOY_PATH`, `PRODUCTION_DOMAIN`.
- [ ] `production` environment created with required reviewers (manual approval before deploy).
- [ ] The deploy user's public key is in the VPS `~/.ssh/authorized_keys`.
- [ ] First run: trigger **Deploy** via `workflow_dispatch`, watch it flip the `current` symlink,
      then confirm the smoke test step passes.
- [ ] Branch protection on `main`: require the `CI` workflow to pass before merge.

## 14. Go-live

- [ ] Real service catalog + governorate `dispatch_multiplier` values loaded (replace `CatalogSeeder` demo data).
- [ ] `DemoSeeder` **not** run in production (`DatabaseSeeder` already gates it to local/staging).
- [ ] Terms / privacy pages linked in the footer.
- [ ] A test end-to-end order completed by a real verified tradesperson; wallet ledger checked.
- [ ] Rollback rehearsed: `ln -sfn releases/<previous> current && php artisan queue:restart && systemctl reload php8.2-fpm`.
