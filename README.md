# FirstMaket

Pay small small, collect with peace of mind.

FirstMarketis a commerce platform for customers who want either to save gradually toward products or pay the full product price at once. It is not a loan app, bank, BNPL product, or cash-withdrawal wallet. Customers fund a deposit-only wallet through Paystack, allocate money to Open Savings, Product Target Plans, or Pay At Once checkout, and receive products after the target price is fully paid.

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
| Database | MySQL 8 (MariaDB 10.4+ locally via XAMPP) |
| Queue/cache | Laravel database driver (no Redis needed at MVP) |
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
| 2 | Identity and onboarding | Customer/vendor registration, OTP, email verification, vendor approval |
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
| [Implementation Plan](docs/FirstMaket_Implementation_Plan.md) | Build phases, architecture, module layout, and success metrics |
| [Database Schema](docs/FirstMaket-Database_Schema.md) | Recommended MySQL schema and table conventions |
| [Deployment and DevOps](docs/FirstMaket_Deployment_DevOps.md) | Environments, deployment flow, CI, backups, monitoring |
| [Developer Guidelines](docs/FirstMaket_Developer_Guidelines.md) | Stack decisions, coding rules, folder conventions, testing standards |
| [PRD Laravel](docs/FirstMaket_PRD_Laravel.md) | Product requirements and module definitions |
| [Security and Compliance](docs/FirstMaket_Security_Compliance.md) | Security, data protection, payment, ledger, and compliance controls |

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

Sprint 1 foundation is complete: Laravel 12 + Inertia + React + TypeScript + Tailwind, the `app/Modules`/`app/Shared` architecture, RBAC (Spatie Permission) with the seven core roles, audit logging, login-event tracking, core settings table, feature flags (Pennant), the `/api/v1` reservation, and the admin-subdomain isolation with mandatory 2FA for Administrator/Super Administrator/Finance Officer.

Sprint 2 (identity and account onboarding) is complete: customer registration with phone OTP (hashed codes, expiry, retry limits, SMS provider abstraction), email verification with new-device login alerts, vendor registration with private CAC upload, the admin vendor approval/rejection queue, and immediate session revocation on suspension/ban. (BVN/NIN identity verification was built here originally and later removed â€” see the Sprint 5 note below.)

Sprint 0 (public home page, added after the marketplace survey) is complete: marketplace-style landing page at `/` owned by the Catalog module â€” top utility bar, search-first header, six-category navigation, hero carousel with promo tiles, category grid, how-it-works and trust strips, SEO footer, and the brand palette in Tailwind (`docs/FirstMaket_Brand_Assets.md`).

The Sprint 2 Addendum is complete: registration/login with **email or phone** (OTP through the matching channel â€” SMS or email), an AliExpress-style combined sign-in/register **modal** on the public pages plus matching split-layout `/login` and `/register` pages, passwordless OTP login, code-verified password reset, and **Continue with Google/Facebook** via Socialite (`social_accounts` table; set `GOOGLE_*`/`FACEBOOK_*` env keys). Staff accounts are invisible to the public auth flow.

Sprint 3 (catalog and vendor listing) is complete: categories (seeded via `CategorySeeder`), vendor product CRUD with kobo pricing and image upload, the product state machine (Draft â†’ Pending Approval â†’ Approved/Rejected â†’ Delisted) with status events, price history, posting-fee records and AI-review table structure, the admin approval queue on the admin subdomain (`products.approve` permission), the public catalog with search/category/price filters and sorting, public product detail pages, and home page product sections fed from the approved catalog (cached).

The Sprint 1â€“3 closeout (2026-07-18) filled every remaining checklist gap: vendor **suspend/reinstate** admin actions (`vendors.suspend`) where suspension delists all approved products via the `VendorSuspended` domain event; the admin **vendor fee settings** page (`vendor_fees.manage`, `/settings/fees` on the admin subdomain) managing Free/Paid posting mode and per-tier fees; **posting tier selection** on the vendor listing form (fee recorded as `pending` â€” wallet payment lands with Sprint 4); the **AI listing review + posting fee panels** on the admin product review page (advisory placeholders until Sprint 9); and the customer **account settings** page (`/settings/account`) for adding/verifying the secondary email-or-phone identifier by OTP, setting/changing passwords (including social-only accounts), and unlinking social logins without self-lockout. Sprint 4 (Wallet and Paystack) is next.

The **Vendor Center** (2026-07-18) moved vendor tooling onto its own isolated subdomain (`VENDOR_DOMAIN`, default `vendors.FirstMaket.localhost`), mirroring the admin portal: scoped `_vendor` session cookie, vendor-only sign-in at `/login` on that origin (LoginRequest portal guard + EnsureCorrectPortal both ways), customer routes 404 there, and `/dashboard` + `/products` management served only on the portal. The main-site `/dashboard` redirects vendor accounts across. Vendor registration stays on the main site (`/vendor/register`) because the phone/email verification flows live there.

Sprint 4 (Wallet and Paystack) is complete: a **deposit-only wallet** with an immutable, row-locked ledger (`wallets`, `wallet_transactions` with balance-before/after and a unique `reference`), Paystack deposit initialization for card/bank-transfer/USSD, a **signature-verified, idempotent webhook** as the *only* path that credits a wallet, receipts issued in the same transaction as the credit, transaction history with filters, and a Finance Officer **settlement reconciliation** dashboard (`wallet.reconcile`) that flags matched / amount-mismatch / missing lines. A verified phone is required before any funding. There is no withdrawal route, controller, or column anywhere. Customer wallet pages live at `/wallet`, `/wallet/add-money`, `/wallet/transactions`, and receipts; the webhook is `POST /webhooks/paystack` (CSRF-exempt). Reusable card authorizations are captured (not charged) for Phase 2 automatic debit.

Sprint 5 (Purchase and Savings Engine) is complete: **one Open Savings pot per customer** funded from the wallet by a ledger debit (`open_savings_allocation`), **Product Target Plans** that lock the product price at creation (vendor price edits never touch a running plan) with daily/weekly/monthly cadences, contributions from the wallet or Open Savings, progress + remaining-balance recalculation on every contribution, and expected-completion projection from the average of the last three contributions. **Pay At Once** is a `pay_at_once` plan paid in one full wallet contribution that reaches **Ready for Delivery** immediately (fires the `PlanReadyForDelivery` domain event Sprint 6 orders will consume). **Redirection** moves the full Open Savings balance into a plan (surplus stays in the pot) or switches an active plan to a different product carrying its full balance at a freshly locked price â€” always recorded in `plan_redirections`, audit-logged, blocked once Ready for Delivery, and never refundable as cash. Plans can be paused/resumed without unlocking money. There is no BVN/NIN identity verification requirement â€” schedule plans and Pay At Once are both available immediately after signup. Customer pages: `/savings` dashboard, plan tracker at `/savings/plans/{uuid}`, plan setup at `/product/{slug}/start-plan`, and checkout at `/checkout/{slug}`. **Sprint 6 (Orders, Logistics, and Vendor Settlement) is next.**

Sprint 6 (Orders, Logistics, and Vendor Settlement) is complete â€” the full Jumia-style fulfillment chain: a fully funded plan + delivery address (captured only after 100% funding) creates an **order** with the price and per-category **commission snapshotted** at creation (`OrderPaid` fires; the vendor gets an "item sold" email with product and order number, **never customer identity**). Admin confirmation starts the vendor **packing SLA** (48h default, `orders.prepaREDACTED_RESEND_KEY`); an hourly scheduler flags breaches. Vendors confirm stock / mark **Ready for Pickup** / reject with a reason from the Vendor Center; a rejected order is resolved by admin as **refund-to-Open-Savings** (never cash). Admin assigns a Logistics Personnel user who walks the order Packed â†’ Shipped â†’ Out for Delivery â†’ Delivered, with the customer emailed on **every** transition and a live tracking timeline at `/orders/{uuid}`. The customer confirms receipt (or `orders:auto-confirm` does after `orders.auto_confirm_days`, default 3), which fires `OrderDeliveryConfirmed` â€” the Vendor module credits the **append-only vendor earnings ledger exactly once per order** (unique order+type index). Vendors register a payout bank account (name resolved + transfer recipient via Paystack, number encrypted); Finance generates weekly **payout batches** of cleared balances, approves them (`vendor_payouts.approve`), and records transfers â€” the negative ledger row is written only on *paid*, so failed transfers never lose money, and payouts never touch customer wallets (tested). New admin pages: Orders queue + detail, Deliveries (logistics), Vendor payouts, Commission settings (`commissions.manage`, append-only rate history). **Sprint 7 (Support and Notifications) is next.**

Sprint 7 (Support and Notifications) is complete. **Notification preferences**: a per-user, per-category (orders, savings, security, support, promotions) email/SMS/in-app toggle matrix â€” `NotificationPreferenceService` resolves the live Laravel channel list per send, SMS only fires with a verified phone, and the **security category's email is locked on** server-side no matter what the customer requests. Every customer-facing notification now extends `PreferenceAwareNotification` (adds an in-app inbox payload + optional SMS text) and a `NotificationSent`/`NotificationFailed` listener logs every attempted send to `notification_deliveries` for failure monitoring. Customers get an **inbox** at `/notifications` (unread badge, mark read/mark all read) alongside the channel-preference matrix.

**Support**: customers reach help through a **Support Center** (`/support`) with FAQ accordion, a WhatsApp deep link, a hotline/callback request routed by **IVR reason** (payment/delivery/general â€” payment issues auto-escalate to high priority), and complaint **tickets** with a live reply thread; agent replies notify the customer through their own preference channels. A public **FAQ page** (`/faq`, no login) is linked from the storefront header and footer. Support Agents get a queue (ticket + hotline lists, status filters) on the admin subdomain plus a **read-only customer lookup** â€” order/plan/wallet context only, **never card data** (tested by asserting card-related values never appear in the response payload). New permission: `support.manage` (Support Agent + Administrator).

Sprint 8 (Cart and Multi-Vendor Checkout) is complete. A persistent **cart** (`/cart`) holds items from any vendor with a per-item quantity, re-validating Approved status and stock on every mutation. Checkout has two branches, split on **when payment happens**: **pay in full** (`/cart/checkout`) collects the delivery address upfront on the checkout screen, debits the wallet once for the whole cart total, and creates one `Order` per unit â€” possibly across several vendors â€” grouped by a `checkout_sessions` row for "placed together" receipts; a quantity of *N* fans out into *N* separate orders at the unit price, exactly like the existing one-order-per-unit model. **Pay Small** instead sends selected cart items into a Product Target Plan: a single item reuses the existing StartPlan flow unchanged, while two or more selected items â€” possibly from different vendors â€” bundle into one **multi-product plan** (`plan_items`, `product_target_plans.product_id` now nullable) with one combined target and one contribution schedule, gated by a new swappable `PlanEligibilityContract` (`RuleBasedPlanEligibilityChecker`: account â‰¥30 days old, â‰¥1 completed plan or â‰¥2 delivered Pay At Once orders, â‰¤1 currently cancelled plan â€” Sprint 9 swaps this for an AI-scored implementation behind the same contract). A bundled plan still asks for the delivery address only once fully funded, exactly like a single-product plan, but reaching 100% then creates **one order per bundled product in a single transaction** â€” never a subset early. Every resulting order, from either branch, runs through the unmodified Sprint 6 fulfillment chain with no vendor ever seeing another vendor's items in the same checkout or plan. **Sprint 9 (AI, Reporting, and Operational Controls) is next.**

### Paystack Webhook Setup

The wallet is credited only by a verified webhook, so this must be configured for deposits to reflect:

1. Set `PAYSTACK_PUBLIC_KEY` and `PAYSTACK_SECRET_KEY` in `.env` (from the Paystack dashboard â†’ Settings â†’ API Keys & Webhooks). The webhook signature is verified with the **secret key** (HMAC SHA512 over the raw body).
2. In the Paystack dashboard, set the **Webhook URL** to `https://<your-domain>/webhooks/paystack`.
3. Local testing: expose your app with a tunnel (`ngrok http 8000`, or Paystack's test webhook sender) and point the dashboard webhook URL at the tunnel. The endpoint is CSRF-exempt and requires no auth â€” it authenticates by signature.
4. Monitor `paystack_webhook_events` for `signatuREDACTED_RESEND_KEY = false` or `processing_status = failed` rows to catch misconfiguration or replay attempts.

### Social Login Setup (Google / Facebook)

The "Continue with Google/Facebook" buttons need OAuth credentials; until the env keys are set, the buttons show a friendly "not available yet" message instead of redirecting.

**Google** (free, ~5 minutes):

1. Go to [console.cloud.google.com](https://console.cloud.google.com), create a project (e.g. "FirstMaket").
2. **APIs & Services â†’ OAuth consent screen**: choose External, fill in the app name and your email, add yourself as a test user while in testing mode.
3. **APIs & Services â†’ Credentials â†’ Create Credentials â†’ OAuth client ID**: type **Web application**.
   - Authorized JavaScript origins: `http://FirstMaket.localhost:8000`
   - Authorized redirect URIs: `http://FirstMaket.localhost:8000/auth/google/callback`
4. Copy the Client ID and Client Secret into `.env` as `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`, then run `php artisan config:clear`.

**Facebook**: create an app at [developers.facebook.com](https://developers.facebook.com) (type Consumer, add the "Facebook Login" product), set the Valid OAuth Redirect URI to `http://FirstMaket.localhost:8000/auth/facebook/callback`, and copy the App ID/App Secret into `FACEBOOK_CLIENT_ID` / `FACEBOOK_CLIENT_SECRET`.

For production, add the real domain equivalents (e.g. `https://FirstMaket.ng/auth/google/callback`) to the same credentials and update the `*_REDIRECT_URI` env values.

### SMS Provider Sandbox Setup

The SMS gateway is behind a swappable contract (`App\Shared\Contracts\SmsSenderContract`) and a disabled-by-default env placeholder, so local development needs no account:

- `SMS_PROVIDER_DRIVER=log` (default) writes SMS to `storage/logs/laravel.log`; the OTP code appears there. For real sends, create a [SmartSMSSolutions](https://smartsmssolutions.com) account, set `SMS_PROVIDER_DRIVER=smartsmssolutions`, `SMS_PROVIDER_KEY` (your API-x token), and a registered `SMS_PROVIDER_SENDER_ID`.
- In feature tests, bind a fake for `SmsSenderContract` instead of hitting any provider.

There is no BVN/NIN identity verification feature â€” it was removed; phone verification is optional and never gates wallet funding or Product Target Plans.

### Local Setup

All commands below are run from the project root folder (the folder containing `artisan`, e.g. `C:\Users\<you>\Desktop\FirstMaket`). Open a terminal and move there first:

```
cd C:\Users\<you>\Desktop\FirstMaket
```

1. Install PHP 8.2+, Composer, Node 20+, and MySQL/MariaDB (XAMPP works â€” start its MySQL service). No Redis needed: sessions, cache, and queues use the database driver. Create the `FirstMaket` and `FirstMaket_testing` databases (utf8mb4).
2. Add two local hostnames pointing at `127.0.0.1` (e.g. in `/etc/hosts` or Windows' `C:\Windows\System32\drivers\etc\hosts`), matching the admin-subdomain isolation rule:
   ```
   127.0.0.1 FirstMaket.localhost
   127.0.0.1 admin.FirstMaket.localhost
   ```
3. Install dependencies (in the project root):
   ```
   composer install
   npm install
   ```
4. Copy the environment file and generate the app key:
   ```
   cp .env.example .env
   php artisan key:generate
   ```
   (On Windows PowerShell use `copy .env.example .env` instead of `cp`.)
5. Create the database, then set `SUPER_ADMIN_EMAIL` and `SUPER_ADMIN_PASSWORD` in `.env` (used once by the seeder to create the first Super Administrator):
   ```
   createdb FirstMaket
   php artisan migrate --seed
   ```
6. Run the app â€” this needs **two terminals**, both in the project root:

   Terminal 1 (Laravel backend):
   ```
   php artisan serve --host=FirstMaket.localhost --port=8000
   ```
   Terminal 2 (Vite frontend dev server):
   ```
   npm run dev
   ```
   Keep both running, then open the browser: customer/vendor app at `http://FirstMaket.localhost:8000`, Admin/Support/Logistics/Finance at `http://admin.FirstMaket.localhost:8000` (log in with the `SUPER_ADMIN_*` credentials, then complete the mandatory 2FA setup screen).
7. Run checks before committing (project root):
   ```
   vendor/bin/pint
   vendor/bin/phpstan analyse --memory-limit=512M
   vendor/bin/pest
   npm run typecheck
   ```

For automated testing, create a separate `FirstMaket_testing` database (`createdb FirstMaket_testing`) â€” `phpunit.xml` points Pest at it by default.

