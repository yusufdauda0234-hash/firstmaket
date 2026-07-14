# FirstMarket

Pay small small, collect with peace of mind.

FirstMarket is a commerce platform for customers who want either to save gradually toward products or pay the full product price at once. It is not a loan app, bank, BNPL product, or cash-withdrawal wallet. Customers fund a deposit-only wallet through Paystack, allocate money to Open Savings, Product Target Plans, or Pay At Once checkout, and receive products after the target price is fully paid.

## Product Surfaces

- Customer web application
- Vendor web portal
- Administrator dashboard
- Support dashboard
- Logistics dashboard
- Finance reconciliation dashboard
- Public website, delivered last after the transactional product is complete

## Recommended Stack

| Layer | Choice |
| --- | --- |
| Backend | Laravel 12/13 |
| Frontend | Inertia.js + React + TypeScript |
| Styling | Tailwind CSS |
| Database | PostgreSQL |
| Queue/cache | Redis |
| Payments | Paystack |
| Auth | Laravel Sanctum |
| Testing | Pest, Vitest, Playwright |
| Feature flags | Laravel Pennant |
| Secrets management | AWS Secrets Manager, Vault, or hosting-provider equivalent (production) |

## Delivery Phases

1. Phase 1: MVP transactional platform
2. Phase 2: Growth features
3. Phase 3: Scale features
4. Phase 4: Public website

The public website is intentionally last so marketing content can reflect the real completed product, not planned screens.

## Start-to-End Project Roadmap

| Order | Stage | Main Outcome |
| --- | --- | --- |
| 1 | Project foundation | Laravel/Inertia app, RBAC, modules, database baseline, admin shell, audit logging |
| 2 | Identity and onboarding | Customer/vendor registration, OTP, email verification, BVN/NIN hooks, vendor approval |
| 3 | Catalog and vendor listing | Categories, vendor products, pricing, approval queue, posting fees, AI review logs |
| 4 | Wallet and Paystack | Deposit-only wallet, webhook-confirmed credits, receipts, transaction history, finance reconciliation |
| 5 | Purchase and savings engine | Open Savings, Product Target Plans, Pay At Once checkout, contribution logic, target locking, progress tracking, redirection |
| 6 | Orders and logistics | Ready-for-delivery orders, address capture, admin confirmation, vendor preparation, delivery tracking |
| 7 | Support and notifications | Preferences, email/SMS/browser notifications, support tickets, hotline logs, IVR routing |
| 8 | AI/reporting/controls | Listing review assistant, reports, vendor suspension, user suspension, operational dashboards |
| 9 | MVP hardening and pilot launch | Security review, ledger tests, Paystack replay tests, E2E flows, production rehearsal |
| 10 | Growth | Wishlist, rewards, referrals, basic affiliate tracking, automatic debit, pause/resume, live chat, AI assistance, risk dashboards |
| 11 | Scale | Agent network, advanced affiliates, group/family/cooperative savings, full AI assistant, mobile apps |
| 12 | Public website | Marketing website using the completed product, real workflows, vendor CTA, SEO, public launch |

## Core Rules

- No withdrawal endpoint exists anywhere in the backend.
- Wallet balance is credited only after a verified Paystack webhook.
- Every ledger-affecting write uses a database transaction.
- Customer wallet has no cashout or withdrawal path.
- Affiliate commissions are separate partner payouts, not customer wallet withdrawals.
- Product target price is locked when a plan is created.
- Vendors never see customer identity or delivery details.
- Admin access is permission-based, not hard-coded by role name.
- Sensitive identity fields are encrypted at rest.
- All money, plan, listing, vendor, and order state changes are audited.
- Modules communicate through domain events or shared contracts, never by querying another module's models directly.
- Admin, Support, Logistics, and Finance dashboards are served from an isolated subdomain with their own cookie scope, separate from the customer app.
- 2FA is mandatory (not optional) for Admin, Finance Officer, and Super Administrator accounts.
- Production secrets are sourced from a secrets manager, not a plain `.env` file.
- Unfinished or phased modules ship behind feature flags (Laravel Pennant), not partial deploys.

## Documentation

| Document | Purpose |
| --- | --- |
| [Implementation Plan](docs/firstmarket_Implementation_Plan.md) | Build phases, architecture, module layout, and success metrics |
| [Database Schema](docs/firstmarket-Database_Schema.md) | Recommended PostgreSQL schema and table conventions |
| [Deployment and DevOps](docs/firstmarket_Deployment_DevOps.md) | Environments, deployment flow, CI, backups, monitoring |
| [Developer Guidelines](docs/firstmarket_Developer_Guidelines.md) | Stack decisions, coding rules, folder conventions, testing standards |
| [PRD Laravel](docs/firstmarket_PRD_Laravel.md) | Product requirements and module definitions |
| [Security and Compliance](docs/firstmarket_Security_Compliance.md) | Security, data protection, payment, ledger, and compliance controls |

## Recommended Folder Structure

```text
app/
  Modules/
    Auth/
    Identity/
    Customer/
    Vendor/
    Catalog/
    Wallet/
    Savings/
    Payments/
    Orders/
    Logistics/
    Notifications/
    Support/
    Admin/
    Reporting/
    AI/
    Risk/
    Rewards/
    Referrals/
  Shared/
    Casts/
    Contracts/
    Enums/
    Middleware/
    Scopes/
    Security/
    Services/
    Traits/
```

## Current Status

Sprint 1 foundation is scaffolded: Laravel 12 + Inertia + React + TypeScript + Tailwind, the `app/Modules`/`app/Shared` architecture, RBAC (Spatie Permission) with the seven core roles, audit logging, login-event tracking, feature flags (Pennant), the `/api/v1` reservation, and the admin-subdomain isolation with mandatory 2FA for Administrator/Super Administrator/Finance Officer. Everything past Sprint 1 (identity/OTP, catalog, wallet, savings, orders, etc.) is not built yet.

The dependency installers (`composer`, `npm`) need outbound network access this environment doesn't have, so `vendor/` and `node_modules/` were not generated here â€” run the steps below on a machine with internet access.

### Local Setup

1. Install PHP 8.2+, Composer, Node 20+, and PostgreSQL. Redis is required too (cache/session/queue default to it); install it locally or point `REDIS_*`/`SESSION_DRIVER`/`CACHE_STORE`/`QUEUE_CONNECTION` at `sync`/`file`/`array` for a quick start without it.
2. Add two local hostnames pointing at `127.0.0.1` (e.g. in `/etc/hosts` or Windows' `C:\Windows\System32\drivers\etc\hosts`), matching the admin-subdomain isolation rule:
   ```
   127.0.0.1 firstmarket.localhost
   127.0.0.1 admin.firstmarket.localhost
   ```
3. Install dependencies:
   ```
   composer install
   npm install
   ```
4. Copy the environment file and generate the app key:
   ```
   cp .env.example .env
   php artisan key:generate
   ```
5. Create the database, then set `SUPER_ADMIN_EMAIL` and `SUPER_ADMIN_PASSWORD` in `.env` (used once by the seeder to create the first Super Administrator):
   ```
   createdb firstmarket
   php artisan migrate --seed
   ```
6. Run the app:
   ```
   php artisan serve --host=firstmarket.localhost --port=8000
   npm run dev
   ```
   Customer/vendor app: `http://firstmarket.localhost:8000`. Admin/Support/Logistics/Finance: `http://admin.firstmarket.localhost:8000` (log in with the `SUPER_ADMIN_*` credentials, then complete the mandatory 2FA setup screen).
7. Run checks before committing:
   ```
   vendor/bin/pint
   vendor/bin/phpstan analyse
   vendor/bin/pest
   npm run typecheck
   ```

For automated testing, create a separate `firstmarket_testing` database (`createdb firstmarket_testing`) â€” `phpunit.xml` points Pest at it by default.
