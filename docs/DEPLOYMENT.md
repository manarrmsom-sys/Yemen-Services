# Deployment Runbook — VPS (Ubuntu 22.04)

Target: DigitalOcean / Hetzner / AWS Lightsail droplet, 2 vCPU / 4 GB minimum
(4 vCPU / 8 GB for the 50k-orders/day target). Cloudflare in front.

## 0. One-time server setup

```bash
sudo apt update && sudo apt install -y nginx redis-server supervisor unzip git \
  php8.2-fpm php8.2-cli php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl \
  php8.2-zip php8.2-bcmath php8.2-intl php8.2-redis mysql-server

# Node 22 for the asset build
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs

# Composer
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
```

### MySQL

```sql
CREATE DATABASE yemen_services CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'yemen_app'@'127.0.0.1' IDENTIFIED BY '<strong-password>';
GRANT ALL PRIVILEGES ON yemen_services.* TO 'yemen_app'@'127.0.0.1';
FLUSH PRIVILEGES;
```

### Redis

Set a password in `/etc/redis/redis.conf` (`requirepass ...`), `supervised systemd`, then
`sudo systemctl restart redis-server`.

## 1. Deploy the code

```bash
sudo mkdir -p /var/www/yemen-services && sudo chown $USER /var/www/yemen-services
cd /var/www/yemen-services
git clone <repo> current && cd current/platform    # or use a releases/ + symlink scheme

composer install --no-dev --optimize-autoloader
npm ci && npm run build          # emits public/build + service worker

cp .env.example .env
php artisan key:generate
# --- edit .env: APP_URL, DB_*, REDIS_*, WHATSAPP_*, SMS_*, N8N_*, SANCTUM_STATEFUL_DOMAINS, SESSION_DOMAIN ---

php artisan migrate --force
php artisan db:seed --class=GovernorateSeeder --force
php artisan db:seed --class=CatalogSeeder --force
php artisan db:seed --class=AdminUserSeeder --force   # prints a generated admin password once
php artisan storage:link

php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache

sudo chown -R www-data:www-data storage bootstrap/cache
```

Generate PWA icons (see `platform/public/icons/README.md`) and add `public/favicon.ico`.

## 2. Nginx + TLS

```bash
sudo cp /var/www/yemen-services/current/deploy/nginx.conf /etc/nginx/sites-available/yemen-services
sudo ln -s /etc/nginx/sites-available/yemen-services /etc/nginx/sites-enabled/
# Cloudflare real-IP list:
curl -s https://www.cloudflare.com/ips-v4 | sed 's/^/set_real_ip_from /; s/$/;/' | sudo tee /etc/nginx/cloudflare-ips.conf
curl -s https://www.cloudflare.com/ips-v6 | sed 's/^/set_real_ip_from /; s/$/;/' | sudo tee -a /etc/nginx/cloudflare-ips.conf
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d yemenservices.example -d www.yemenservices.example
sudo nginx -t && sudo systemctl reload nginx
```

Cloudflare dashboard: SSL/TLS mode **Full (strict)**, "Always Use HTTPS" on,
Bot Fight Mode on, Brotli compression on, and a rate-limiting rule on `/api/v1/auth/*`.
The bundled Nginx config enables gzip; Brotli should be provided by Cloudflare or by an
Nginx build with the Brotli module installed. Do not add Brotli directives to a stock
Nginx package without that module.

## 3. Workers

```bash
sudo mkdir -p /var/log/yemen-services
sudo cp /var/www/yemen-services/current/deploy/supervisor.conf /etc/supervisor/conf.d/yemen-services.conf
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start all
```

## 4. Webhooks / integrations

- **WhatsApp Cloud API**: set the callback URL to `https://yemenservices.example/webhooks/whatsapp`
  and the verify token to `WHATSAPP_WEBHOOK_VERIFY_TOKEN`. Add the OTP message template
  (`WHATSAPP_TEMPLATE_OTP`) and get it approved.
- **n8n**: import `deploy/n8n/dispatch-workflow.json`, set the two webhook URLs, and put the
  shared secret in both n8n and `N8N_WEBHOOK_SECRET`.
- **Admin → Integrations**: register outbound endpoints; copy the signing secret shown once.

## 5. Backups

```bash
# /etc/cron.daily/yemen-db-backup  (chmod +x)
#!/bin/bash
set -e
TS=$(date +%F-%H%M)
mysqldump --single-transaction --routines yemen_services | gzip > /var/backups/ys-$TS.sql.gz
find /var/backups -name 'ys-*.sql.gz' -mtime +14 -delete
# then sync /var/backups + storage/app/kyc to off-site object storage (rclone/restic)
```

Weekly: `restic backup /var/www/yemen-services/current/platform/storage/app/kyc`.
Test a restore into staging every month.

## 6. Zero-downtime releases

```bash
cd /var/www/yemen-services
git -C current pull
cd current/platform
php artisan down --render="errors::503"
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
sudo supervisorctl restart yemen-queue:*
php artisan up
```

(Prefer a `releases/<timestamp>` + `current` symlink flip for true zero-downtime.)

## 7. Post-deploy smoke test

```bash
curl -sf https://yemenservices.example/up
curl -sf -X POST https://yemenservices.example/api/v1/estimate \
  -H 'Content-Type: application/json' \
  -d '{"service_id":1,"governorate_id":1,"urgency":"today"}' | jq .data.total
```

Then run through `docs/HANDOVER.md` §"Acceptance checklist".
