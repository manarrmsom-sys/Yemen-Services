# خدمات اليمن — Yemen On-Demand Services Platform

Monorepo for the bilingual PWA + enterprise admin dashboard.

```
.
├── platform/                  Laravel 11 + Inertia/Vue 3 application  ← start here
├── deploy/                    Nginx, Supervisor, Dockerfile, n8n workflow
├── docs/                      SOW · Architecture · ERD · Deployment · Handover
├── docker-compose.yml         local / staging stack
├── yemen_services_website.html      original static concept (reference)
└── yemen_services_marketing.html    original marketing concept (reference)
```

## Start

```bash
cd platform
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
npm run dev            # + php artisan serve  → http://localhost:8000
```

Full instructions: [`platform/README.md`](platform/README.md) and [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).
Production `.env` values to fill in: [`docs/ENV_PRODUCTION.md`](docs/ENV_PRODUCTION.md).

## Highlights

- **Arabic-first PWA** — RTL default, instant EN/AR switch, offline app shell, Yemen national palette (`#CE1126` / `#FFFFFF` / `#0F172A`), Tajawal + Inter.
- **30-second booking** — 3-step bottom-sheet drawer with a live, transparent price estimate before any login.
- **Passwordless** — OTP over WhatsApp or SMS.
- **The Gap Play** — every booking generates a pre-filled `wa.me` dispatch link + a signed n8n webhook with cost breakdown and geo tags.
- **Dual-wallet ledger** — double-entry, COD commission settled from a prepaid float; manual + automated top-up reconciliation.
- **Enterprise admin** — real-time dispatch board, KYC review pipeline, wallet/commission manager, webhook suite.
- **Built for spikes** — stateless app, Redis queues, cached catalog + metrics, Nginx + app rate limiting, Cloudflare.
