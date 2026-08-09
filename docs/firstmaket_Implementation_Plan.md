# FirstMaket Implementation Plan

Version: 1.0  
Date: July 2026  
Source: `documentation.docx`, reviewed against IHMS Laravel documentation and folder conventions.

## 1. Recommended Architecture

Build FirstMaket as a Laravel modular monolith with Inertia and a typed frontend. This follows the strongest IHMS pattern: one backend, one database, domain modules under `app/Modules`, shared cross-cutting code under `app/Shared`, and role-specific web surfaces served by the same Laravel application.

Recommended stack:

| Layer | Recommendation |
| --- | --- |
| Backend | Laravel 12/13, PHP 8.4+ |
| Frontend | Inertia.js + React + TypeScript |
| Styling | Tailwind CSS + shadcn-style reusable components |
| Database | MySQL 8 for production (MariaDB 10.4+ locally, e.g. XAMPP); utf8mb4 throughout |
| Queue/cache | Laravel `database` driver for cache, sessions, queues, and rate limits — Redis is deliberately not required at MVP scale and can be introduced later as a drop-in driver swap if load demands it |
| Auth | Laravel Sanctum, session auth for web, token auth ready for mobile |
| Payments | Paystack webhooks as source of truth |
| Storage | S3-compatible bucket or Cloudinary for products and documents |
| Testing | Pest, Vitest, Playwright |
| Monitoring | Laravel Pulse, Sentry, uptime checks |
| Feature flags | Laravel Pennant |
| Secrets management | AWS Secrets Manager, Vault, or hosting-provider equivalent for production |

### 1.1 Cross-Module Communication

The modular monolith only stays modular if modules do not reach directly into each other's tables. Modules communicate through:

- **Domain events** for anything another module needs to react to: `PlanCompleted`, `OrderDelivered`, `VendorApproved`, `PaystackDepositConfirmed`, `AffiliateConversionQualified`, etc. Each module dispatches events for its own state changes; interested modules attach listeners. This is how Referrals, Rewards, and Affiliates react to Orders/Savings state without those modules knowing Referrals/Rewards/Affiliates exist.
- **Contracts** (interfaces in `app/Shared/Contracts`) when one module needs to call another synchronously (for example, Orders needing the locked target price from Savings) — depend on the interface, not the other module's model or service class directly.
- Never query another module's Eloquent models directly from outside that module; expose what's needed through the module's own service/action layer.

Decide and document this pattern in Sprint 1, before more than one module exists, since retrofitting event-based decoupling into modules that already call each other directly is expensive.

### 1.2 Feature Flags

Use Laravel Pennant from Sprint 1 so Phase 2/3 modules (rewards, affiliates, automatic debit, agent network, cooperative savings) can be built and deployed dark, then enabled per environment or per cohort without a deploy. This also gives an operational kill-switch for any module if a problem is found post-launch.

### 1.3 API Versioning

Reserve `/api/v1` for any JSON API surface from the start, even though Phase 1 is Inertia-only. Phase 3E (native mobile apps) will consume this API; adding versioning after a mobile client already depends on unversioned routes is more disruptive than reserving the prefix now.

## 2. Delivery Phases

### Project Sequence At A Glance

| Order | Stage | What Gets Completed |
| --- | --- | --- |
| 0 | Public home page | Marketplace-style public home page shell (header, search, categories, hero, product sections, footer), brand assets |
| 1 | Foundation | Laravel/Inertia setup, modules, RBAC, audit logging, database baseline |
| 2 | Onboarding | Customer/vendor registration, OTP, email verification, vendor approval |
| 3 | Marketplace catalog | Categories, vendor products, product approval, vendor pricing, posting fees |
| 4 | Wallet and payments | Paystack initialization, webhook-only wallet crediting, receipts, transaction history, finance reconciliation |
| 5 | Purchase and savings | Open Savings, Product Target Plans, Pay At Once checkout, contribution logic, target price locking, tracker, redirection |
| 6 | Orders and delivery | Ready-for-delivery order creation, delivery address, admin confirmation, vendor sold-notification and preparation, logistics tracking, vendor earnings and payouts |
| 7 | Support and communication | Notifications, support tickets, hotline logs, IVR routing, support-agent lookup |
| 8 | Cart and checkout | Multi-vendor cart, quantity, cart-based full-payment checkout, cart-based plan starts, checkout session grouping |
| 9 | AI and operations | Listing Review Assistant, reports, vendor/user suspension, operational controls |
| 10 | MVP launch | Security hardening, no-withdrawal tests, ledger tests, E2E tests, pilot vendor launch |
| 11 | Growth | Wishlist, rewards, referral, basic affiliate tracking, automatic debit, pause/resume, live chat, AI assistant, risk dashboards |
| 12 | Scale | Agents, advanced affiliates, group/family/cooperative savings, full AI assistant, mobile apps |
| 13 | Public website | Public marketing website, SEO, real screenshots/workflows, vendor CTA, final brand launch |

### Phase 1: MVP Transaction Platform

Goal: Web-based marketplace with customer savings, full Pay At Once purchase, vendor listing, admin approval, Paystack wallet funding, and delivery operations.

#### Sprint 0: Public Home Page and Brand Shell

Scope: build the public-facing home page first, following the layout patterns shared by the major marketplaces surveyed (AliExpress, Amazon, eBay, Etsy, Walmart Marketplace, Temu, Rakuten, Mercado Libre, Shopee, Lazada, Jumia, Konga, Takealot, Kilimall, Bob Shop, Newegg, Bonanza, Wish, Cdiscount, OnBuy). The home page is the storefront and first impression; every surveyed marketplace opens with a search-first, category-driven landing page rather than a marketing brochure. Phase 4 later expands this into the full public website (About, How It Works, legal pages, SEO polish).

Note: Sprints 1 and 2 were built before this sprint was added. Sprint 0 is executed now, before Sprint 3, and its product sections go live progressively as the catalog (Sprint 3) fills with approved products. Until then, sections render from seeded demo/approved data or hide when empty.

Common home page anatomy found in the survey (adopt this structure):

1. **Top utility bar**: delivery location/language, "Sell on FirstMaket" vendor CTA, Help/Support link, app-download placeholder.
2. **Main header**: logo (left), prominent full-width search bar with category scope (center), account and cart icons (right). Search is the single most dominant element on every surveyed site.
3. **Category navigation**: sidebar category menu (Jumia/AliExpress style) or horizontal mega-menu, listing the six launch categories: Electronics, Home Appliances, Solar Equipment, Furniture, Fashion, Business Equipment.
4. **Hero area**: rotating promotional carousel beside the category menu, plus 1–2 static promo tiles (wallet funding, savings plans).
5. **Flash/featured strip**: horizontally scrolling product row ("Featured", later "Flash Sales" with countdown) — Featured posting-fee tier products surface here.
6. **Category grid blocks**: image tiles per category linking into the catalog.
7. **Product feed**: paginated/infinite "For You"/"Top Selling" grid of approved products.
8. **How-it-works strip** (FirstMaket-specific): three cards — Pay At Once, Save Small Small (Product Target Plans), FirstMaket Delivers — since goal-based savings is the differentiator none of the surveyed sites have.
9. **Trust strip**: secure Paystack payments, verified vendors, FirstMaket-controlled delivery, support hotline.
10. **SEO footer**: category links, help/FAQ, About, Become a Vendor, Terms, Privacy, contact channels, social links, payment method logos.

Backend:

- Public route group with no authentication (`/` and public catalog preview endpoints).
- Home page content endpoint(s) serving approved products only: featured, newest, per-category previews.
- Category listing endpoint for the navigation menu.
- Settings-driven hero/promo slides (admin-manageable later; static config acceptable for Sprint 0).
- Cache the home page data (database cache driver) since it is the highest-traffic anonymous page.

Frontend:

- `Pages/Public/Home.tsx` with the ten sections above, responsive from 360 px up.
- Public layout (header, category nav, footer) reusable by later public pages (product detail preview, Phase 4 pages).
- Search bar routing to the catalog search page (works fully once Sprint 3 lands).
- Skeleton/empty states for sections whose data source is not live yet.
- Brand assets wired in: logo variants (see `docs/FirstMaket_Brand_Assets.md`), favicon, brand colors in Tailwind config.

QA and security:

- Public home page requires no login; authenticated app pages still require login.
- Home page never exposes unapproved products or vendor/customer personal data.
- Lighthouse pass for performance and SEO basics (meta tags, semantic landmarks).

Exit criteria:

- Visitors land on a marketplace-style home page with search, categories, and product sections.
- Layout matches the surveyed marketplace anatomy and reuses the shared public layout.

#### Sprint 1: Project Foundation

Scope: establish the Laravel/Inertia application base, domain architecture, security foundation, and first admin shell.

Backend:

- Create Laravel project with Inertia, React, TypeScript, Tailwind, Pest, and Sanctum.
- Configure domain module registration under `app/Modules`.
- Create shared folders for enums, traits, casts, services, middleware, scopes, and security helpers.
- Install and configure RBAC package, preferably Spatie Permission.
- Define initial roles: Customer, Vendor, Super Administrator, Administrator, Support Agent, Logistics Personnel, Finance Officer.
- Add audit logging foundation for sensitive and operational actions.
- Create base API/Inertia response conventions and shared exception handling.
- Install Laravel Pennant and define the feature-flag convention for gating unfinished/dark modules.
- Establish the domain event bus convention (event classes, listener registration per module) before a second module is built.
- Reserve `/api/v1` route prefix for any JSON API surface.
- Route Admin, Support, Logistics, and Finance dashboards under an isolated subdomain (`admin.FirstMaket.ng`) with a separate session cookie scope from the customer app.
- Enforce mandatory 2FA enrollment at first login for Admin, Finance Officer, and Super Administrator accounts.

Frontend:

- Set up application shell, route-level layouts, and shared UI structure.
- Create role-aware dashboard layout for Admin, Support, Logistics, Finance, Vendor, and Customer.
- Add base components: buttons, inputs, selects, modals, empty states, tables, alerts, page headers, breadcrumbs.
- Add permission-aware navigation placeholders.
- Add login/auth pages from the selected Laravel starter pattern.

Database:

- Create baseline migrations for users, roles, permissions, audit logs, login events, and core settings.
- Add UUID/public identifier convention.
- Add enum/status conventions for users and roles.

QA and security:

- Add initial Pest test suite.
- Add architecture tests for module boundaries and permission registration.
- Add tests that prove all protected dashboards require authentication.
- Configure Pint/Larastan/TypeScript checks.

DevOps and docs:

- Add `.env.example` placeholders.
- Add local setup instructions.
- Add CI skeleton for backend tests, frontend type checks, and build.
- Decide local MySQL/MariaDB setup path (queues/cache/sessions use the database driver; no Redis).

Exit criteria:

- A developer can install, run, log in, and see role-specific dashboard shells.
- RBAC and audit foundations are present.
- CI runs basic checks.

#### Sprint 2: Identity and Account Onboarding

Scope: customer and vendor registration, document upload, and admin approval. There is no BVN/NIN identity verification feature — a version of it was built here originally and later removed entirely (schema, contracts, admin review queue, and UI); vendor identity assurance is CAC document review only.

Backend:

- Build customer registration and profile creation.
- Build vendor registration and vendor profile creation.
- Implement OTP generation, expiry, verification, retry limits, and SMS provider abstraction.
- Implement email verification and login-alert events.
- Add vendor CAC document upload and private storage handling.
- Build admin approval/rejection workflow for vendors.
- Revoke active sessions on user suspension or ban.

Frontend:

- Customer registration flow.
- Vendor onboarding flow with CAC upload.
- OTP verification screen.
- Email verification prompt.
- Admin vendor approval queue.
- Admin vendor detail page with approve/reject actions.
- User status indicators and rejection reason display.

Database:

- Add `customer_profiles`, `vendor_profiles`, upload/document metadata, and login-event tables.
- Add encrypted casts for phone/address where required.
- Add vendor status enum: Pending, Approved, Rejected, Suspended, Banned.

QA and security:

- Test OTP expiry and rate limits.
- Test only Admin/Super Admin can approve vendors.
- Test CAC documents are private and not publicly accessible.

DevOps and docs:

- Add SMS, mail, and file-storage env placeholders.
- Document provider sandbox/test setup.

Exit criteria:

- Customers and vendors can register.
- Vendors cannot list products until approved.
- Identity verification events are logged and reviewable.

#### Sprint 2 Addendum: Registration and Login Options (post-survey)

Scope: bring registration/login in line with the surveyed marketplaces (Jumia, Temu, Shopee, AliExpress all offer email-or-phone signup plus social login). Sprint 2 shipped email+password registration with phone OTP; this addendum widens the entry paths. Can be scheduled alongside or right after Sprint 3.

Backend:

- Allow registration with **email or phone number** as the primary identifier — exactly one is required at signup; the other can be added later from profile settings.
- Verification OTP goes through the channel that matches the identifier: email signup → 6-digit code by email; phone signup → 6-digit code by SMS. Reuse the existing OTP service with a `channel` field (`sms`, `email`).
- Add **passwordless OTP login** option: enter email/phone, receive one-time code, log in — alongside the existing password login, with the same rate limits as registration OTP.
- Add **social login** with Google and Facebook via Laravel Socialite: create-or-link account by verified provider email, store provider tokens in a `social_accounts` table, never create a duplicate account when the email already exists (link instead, after the user authenticates or confirms via email OTP).
- Social-only accounts have a nullable password; prompt them to set one (or keep social-only) in settings.
- Password reset works through both channels: email reset link, or phone OTP + new password form.
- Phone verification remains mandatory before any money movement (wallet funding), regardless of signup method, since SMS OTP secures transactions.

Frontend:

- Combined register form with an email/phone toggle (single "Email or phone number" input that detects the type is acceptable).
- "Continue with Google" and "Continue with Facebook" buttons on both login and register screens.
- OTP entry screen shared by SMS and email flows, showing the masked destination.
- Password reset flow with channel choice.
- Account settings: linked social accounts, add/verify secondary identifier, set password for social-only accounts.

QA and security:

- Test OTP rate limits per identifier, IP, and device for both channels.
- Test social login cannot take over an existing account without ownership proof.
- Test unverified-phone accounts are blocked from wallet funding.
- Test a user cannot register twice with the same email/phone through different paths.

#### Sprint 3: Catalog and Vendor Listing

Scope: approved product catalog, vendor-controlled pricing, admin product moderation, posting-fee configuration, and AI review logging foundation.

Backend:

- Build category management and seed categories: Electronics, Home Appliances, Solar Equipment, Furniture, Fashion, Business Equipment.
- Build product listing CRUD for vendors.
- Enforce vendor-controlled pricing.
- Build product state machine: Draft, Pending Approval, Approved, Rejected, Delisted.
- Ensure approved vendor price edits return product to Pending Approval.
- Build admin product approval/rejection actions.
- Add vendor posting-fee settings: Free/Paid, Basic, Premium, Featured.
- Add product posting fee records and payment status fields.
- Add AI listing review log structure, without requiring full AI integration yet.

Frontend:

- Vendor product create/edit form with image upload.
- Vendor listing dashboard: Draft, Pending, Approved, Rejected, Delisted.
- Customer catalog grid/list with search, category filter, price filter, sorting.
- Product detail page.
- Admin product approval queue.
- Admin approval detail page showing product data, vendor, images, price, AI flags, and decision controls.
- Admin vendor fee settings page.

Database:

- Add categories, products, product images, product price history, vendor fee settings, product posting fees, AI listing reviews, and product status events.
- Add indexes for product status, category, price, vendor, and search fields.

QA and security:

- Test unapproved products never appear in customer catalog.
- Test vendors cannot see customer data through product/order queries.
- Test price change after approval returns product to Pending Approval.
- Test vendor suspension delists approved products.
- Test admin cannot edit vendor-set selling price directly.

DevOps and docs:

- Add storage rules for product images.
- Add seeders for initial categories and posting fee settings.

Exit criteria:

- Approved vendors can create products.
- Admin can approve/reject products.
- Customers see only approved catalog items.

#### Sprint 4: Wallet and Paystack

Scope: deposit-only wallet, Paystack payment initiation, webhook-only balance credit, receipts, transaction history, and finance reconciliation.

Backend:

- Build deposit-only wallet model and immutable wallet ledger.
- Create Paystack payment initialization for card, bank transfer, and USSD channels.
- Verify Paystack webhook signatures.
- Make webhook handling idempotent by Paystack reference.
- Credit wallet only after verified webhook.
- Generate receipt numbers and receipt records inside the same transaction.
- Build transaction history queries.
- Build finance reconciliation service for Paystack settlements against internal ledger.
- Store reusable Paystack authorization metadata for Phase 2 scheduled automatic debit.
- Ensure no withdrawal route, controller, action, or service exists.

Frontend:

- Customer wallet page.
- Add Money flow with Paystack payment initialization.
- Payment pending/success/failure screens.
- Transaction history page with filters.
- Receipt view/download page.
- Finance Officer reconciliation dashboard.
- Reconciliation detail view showing provider reference, ledger record, status, and mismatch flags.

Database:

- Add wallets, wallet transactions, Paystack transactions, receipts, payment authorizations, settlement imports, and reconciliation records.
- Add unique constraints for Paystack references and ledger references.
- Add balance-before and balance-after fields.

QA and security:

- Test client callback cannot credit wallet.
- Test valid webhook credits wallet once.
- Test duplicate webhook does not duplicate credit.
- Test invalid webhook signature is rejected.
- Test withdrawal endpoints do not exist.
- Test ledger balances remain consistent after concurrent deposit events.

DevOps and docs:

- Add Paystack env variables.
- Document webhook URL, local webhook testing, and production webhook setup.
- Add monitoring for webhook failures and ledger mismatches.

Exit criteria:

- Customers can fund wallet through Paystack.
- Ledger is immutable and webhook-verified.
- Finance can reconcile deposits.

#### Sprint 5: Purchase and Savings Engine

Scope: Open Savings, Product Target Plans, Pay At Once checkout, contribution logic, target locking, progress tracking, and redirection.

Backend:

- Build one Open Savings balance per customer.
- Build Product Target Plan creation.
- Build Pay At Once checkout for customers who want to pay the full approved product price immediately.
- Lock product target price at plan creation.
- Support schedule mode: daily, weekly, monthly.
- Support Pay At Once mode.
- On full Pay At Once payment, move the product purchase directly to Ready for Delivery/order creation after verified Paystack webhook.
- Apply contributions from wallet/Open Savings to plans.
- Recalculate amount saved, remaining balance, progress percentage, and expected completion date.
- Calculate expected completion date using the customer's actual average contribution rate over the last three cycles.
- Move plan to Ready for Delivery at 100% funded.
- Build plan redirection from Open Savings or another active plan.
- Audit every redirection.
- Block redirection after Ready for Delivery.

Frontend:

- Customer savings dashboard.
- Open Savings page.
- Start a Plan flow from product detail page.
- Pay At Once checkout flow from product detail page.
- Plan setup page for schedule mode and Pay At Once mode.
- Product Tracker with progress bar, amount saved, balance remaining, and expected completion date.
- Contribution allocation UI.
- Plan redirection flow.
- Ready-for-delivery prompt.

Database:

- Add open savings, product target plans, plan contributions, plan redirections, and plan status events.
- Add direct checkout/order linkage fields if Pay At Once is modeled separately from Product Target Plans.
- Add fields for cadence, suggested contribution, progress percentage, expected completion date, paused state, and completion dates.

QA and security:

- Test one customer has exactly one Open Savings balance.
- Test target price remains locked after vendor price changes.
- Test contribution math for daily, weekly, monthly, and Pay At Once modes.
- Test Pay At Once immediately reaches Ready for Delivery after verified full payment.
- Test progress reaches 100% and moves plan to Ready for Delivery.
- Test redirection carries full balance and updates target math.
- Test no cash refund/withdrawal path exists through redirection.

DevOps and docs:

- Add calculation examples to internal docs.
- Add monitoring/logging for failed contribution application jobs.

Exit criteria:

- Customers can pay fully at once, save openly, or save gradually toward products.
- Plan progress and readiness are accurate and auditable.

#### Sprint 6: Orders, Logistics, and Vendor Settlement

Scope: conversion from fully funded plan to order, vendor sold-notification, vendor preparation with a packing SLA, FirstMaket-controlled pickup and delivery, customer notifications, and vendor earnings/payout so the fulfillment chain is complete end to end.

The complete fulfillment chain (modeled on Jumia's dropship flow, where the marketplace controls delivery):

1. **Paid** — plan reaches 100% (or Pay At Once webhook confirms). Order is created; `OrderPaid` event fires.
2. **Vendor notified** — vendor instantly receives an "item sold" notification (dashboard + email/SMS) with product, quantity, and order number — never customer identity or address.
3. **Admin confirmation** — admin confirms the order (checks payment/ledger match) and moves it to Processing.
4. **Vendor prepares** — vendor confirms stock and packs within the preparation SLA (48 hours, configurable). Vendor marks the order **Ready for Pickup**. If the vendor cannot fulfil (out of stock), the vendor rejects with a reason and admin triggers the resolution path (redirect plan to another product or admin-managed refund-to-savings — never cash out).
5. **Handover to logistics** — FirstMaket logistics picks up from the vendor, or the vendor drops off at a FirstMaket hub. Logistics scans/accepts the package: status Packed → Shipped.
6. **Delivery** — logistics is assigned, status moves Out for Delivery → Delivered. Customer is notified at every step.
7. **Delivery confirmation window** — customer confirms receipt, or the order auto-confirms after N days (default 3) without a complaint/dispute.
8. **Vendor earnings credited** — on confirmed delivery, commission (per-category percentage set by admin) is deducted from the locked product price and the remainder is credited to the vendor's **earnings ledger** (separate from customer wallets).
9. **Vendor payout** — Finance runs a periodic (weekly) payout batch of cleared earnings to the vendor's verified bank account; payout records are auditable and reversible entries are ledger-based, never edits.

Backend:

- Create order from Ready-for-Delivery plan.
- Capture delivery address, state, and LGA only after plan is fully funded.
- Dispatch `OrderPaid` domain event; Vendor module listener sends the vendor "item sold" notification without customer identity.
- Require admin confirmation before Processing.
- Build order status state machine: Pending, Processing, Ready for Pickup, Packed, Shipped, Out for Delivery, Delivered, plus vendor rejection path.
- Enforce and track the vendor preparation SLA (48h default, configurable setting); flag overdue preparations to admin.
- Build vendor preparation workflow (confirm stock, mark ready, reject with reason) without exposing customer identity.
- Build logistics pickup/drop-off acceptance and delivery status update services.
- Build delivery confirmation: customer confirm action plus auto-confirm scheduler job after the configurable window.
- Add per-category commission rate settings (admin-managed, e.g. 5–15%).
- On confirmed delivery: compute commission, credit vendor earnings ledger inside a database transaction, fire `OrderDelivered`/`VendorEarningsCredited` events.
- Build vendor bank account capture with verification (Paystack transfer recipient / account name resolution).
- Build weekly vendor payout batch generation, Finance approval, and paid/failed status tracking.
- Notify customer on every delivery status change; notify vendor on pickup, delivery, earnings credit, and payout.

Frontend:

- Customer ready-for-delivery address form.
- Customer orders and delivery tracking pages with confirm-receipt action.
- Admin order confirmation queue and order detail page.
- Admin commission rate settings page (per category).
- Vendor "Orders to Prepare" dashboard: new sold items, preparation SLA countdown, mark Ready for Pickup, reject with reason — no customer identity.
- Vendor earnings page: pending (in delivery), cleared, paid balances, per-order commission breakdown.
- Vendor payout history and bank account settings.
- Logistics dashboard for pickups and assigned deliveries; delivery status update screen.
- Finance vendor payout batch review/approval page.

Database:

- Add orders (with price, commission rate/amount, vendor earning amount snapshot columns), order status events, delivery assignments, vendor preparation records.
- Add vendor earnings ledger, vendor bank accounts, vendor payout batches, and payout items.
- Add commission rate columns/settings per category.
- Add indexes for order status, vendor, customer, logistics assignee, and payout status.

QA and security:

- Test address capture only after Ready for Delivery.
- Test vendor is notified on paid order and sees no customer name, phone, address, or plan history.
- Test admin confirmation is required before Processing.
- Test preparation SLA breach flags the order to admin.
- Test logistics role cannot access catalog/pricing management.
- Test customer receives notification for each status transition.
- Test commission math per category and that earnings credit happens exactly once per order (idempotent, transaction-wrapped).
- Test vendor earnings are not payable before delivery confirmation window passes.
- Test payout batch totals reconcile with the earnings ledger and cannot include another vendor's earnings.
- Test vendor payout never touches customer wallets or savings ledgers.

DevOps and docs:

- Add delivery status and vendor notification templates.
- Add operational runbook for stuck orders, SLA breaches, and failed payouts.
- Add monitoring for the auto-confirm and payout scheduler jobs.

Exit criteria:

- Fully funded plans become manageable orders with a complete chain: paid → vendor notified → packed → picked up → delivered → confirmed → vendor earnings credited → vendor paid.
- Delivery status is visible to customers and controlled by allowed roles.
- Vendors see their sales and get paid without ever seeing customer identity.

#### Sprint 7: Support and Notifications

Scope: notification preferences, email/SMS/browser notifications, support tickets, hotline logs, IVR reasons, FAQ, WhatsApp entry, and support-agent lookup.

Backend:

- Build notification preference model and APIs.
- Implement notification dispatcher for email, SMS, and browser push.
- Build support tickets and support ticket messages.
- Build hotline call log model.
- Store IVR reason categories: payment issue, delivery issue, general inquiry.
- Build FAQ content model or static FAQ module.
- Build support-agent read-only customer lookup.
- Ensure support agents cannot see payment card details or sensitive identity fields.

Frontend:

- Customer notification settings page.
- Notification inbox/feed.
- Support Center with FAQ, WhatsApp link, complaint/ticket entry, and hotline request.
- Support Agent dashboard: ticket queue, hotline queue, customer order/plan lookup.
- Ticket detail screen with status changes and messages.

Database:

- Add notifications, notification preferences, support tickets, support messages, hotline call logs, and FAQ entries if dynamic.
- Add indexes for ticket status, assigned agent, customer, and channel.

QA and security:

- Test notification preferences are respected per category.
- Test support agent sees order/plan context but no card credentials or raw identity data.
- Test hotline logs attach to customer account.
- Test IVR reason routes support records correctly.

DevOps and docs:

- Add SMS/email/browser push env placeholders.
- Add notification delivery failure monitoring.
- Document support escalation workflow.

Exit criteria:

- Customers can get help through FAQ, WhatsApp, hotline, and tickets.
- Support agents have safe, limited visibility.

#### Sprint 8: Cart and Multi-Vendor Checkout — COMPLETE

Scope: replace the single-product-at-a-time Pay At Once/Save Small Small entry points with a standard product → cart → checkout flow. Cart items can come from any vendor. Checkout offers two branches: pay the full cart total now, or send selected items into a Product Target Plan — which can now, for eligible customers, bundle multiple products (from different vendors) into one plan with one combined target. This sprint does not change how an individual order is fulfilled once a plan/checkout is paid (Sprint 6's chain is untouched) — it changes how a customer assembles and pays for one or more products, and what a "plan" is allowed to contain.

**Build note (2026-07-24):** cart CRUD shipped first; checkout stalled on a delivery-address-timing conflict — `PlanService::payAtOnce()` only reaches Ready-for-Delivery, and the actual `Order` needs a separately-submitted address, which didn't fit a multi-item cart's "one address at checkout" expectation. Resolved as: **address timing follows payment timing.** Pay-in-full checkout collects the address once, upfront, on the checkout screen, before the single wallet debit — it never creates a plan at all, going straight from `checkout_sessions` to `Order` rows via `OrderService::createFromCheckoutSession()`. A plan (single- or multi-product) keeps the existing "ask once fully funded" pattern — a bundled plan's address form creates every bundled order in one transaction via `OrderService::createFromBundledPlan()`, never a subset early. `orders.plan_id` is now nullable and no longer unique (a bundle's orders share one `plan_id`); `orders.checkout_session_id`, `orders.plan_item_id`, and `orders.plan_delivery_group_id` were added for grouping/display. See `docs/firstmaket-Database_Schema.md` section 8a for the final table shapes.

Design decisions (confirm before building):

- A cart holds items from multiple vendors at once. At full-payment checkout, items are grouped by vendor and one `Order` is still created per unit purchased (matching today's one-order-per-product model exactly) — a lightweight `checkout_sessions` record ties those orders together only for "placed together" display and receipts. Each resulting order still runs through the existing independent per-vendor fulfillment chain (vendor notified, admin confirms, preparation SLA, logistics, earnings) with no changes.
- Cart items carry a `quantity`. At full-payment checkout, a quantity greater than 1 fans out into that many individual `Order` rows (each still quantity 1) rather than adding a `quantity`/line-item model to `orders` — this avoids touching commission math, vendor preparation, or delivery tracking, which are all built per single unit today.
- **Plans can now be single-product (unchanged, default, no eligibility check — exactly today's behavior) or multi-product (new).** A multi-product plan bundles several cart items — possibly different vendors — into one combined target price and one contribution schedule. `product_target_plans.product_id` becomes nullable; a new `plan_items` table (`plan_id`, `product_id`, `vendor_id`, `locked_price_kobo`, `quantity`) holds the products for multi-product plans only, so single-product plans need no data migration and no existing Sprint 5/6 behavior changes.
- **Multi-product plans require passing a rule-based eligibility check** (new `PlanEligibilityContract`, bound to a `RuleBasedPlanEligibilityChecker` — same swappable-contract pattern as `SmsSenderContract`/`PaymentGatewayContract`). Eligible when **all** of: account is at least 30 days old; at least one prior Completed plan or two delivered Pay At Once orders (proven follow-through); no more than one plan currently `Cancelled` (protects against serial abandoners). Ineligible customers can still start any number of single-product plans — the gate only applies to bundling multiple products into one plan. **Sprint 9 swaps the implementation for an AI-scored checker behind the same contract** (using the customer's full contribution/purchase history as model input) without changing anything else in Sprint 8 — see the note added to Sprint 9 below.
- **A multi-product plan delivers all-or-nothing.** `PlanService::recalculate()` already computes progress against `target_price_kobo` regardless of what makes up that target, so the math is unchanged — but reaching 100% now creates one `Order` per `plan_item` simultaneously (grouped the same way cart checkouts are grouped), never one early order for whichever product's "share" happened to be funded first.
- One wallet debit funds an entire full-payment checkout (the cart total), exactly like today's single-product Pay At Once — the shortfall-driven "Add money" prompt already used on the Pay At Once page carries over unchanged.

Backend:

- Build a persistent cart (one per customer, not session-only, so it survives across devices and logins).
- Add/update/remove cart items; enforce product is Approved and in stock at add-time and again at checkout.
- Build cart checkout (full payment): validate stock/approval for every item, debit the wallet once for the total, create one `checkout_sessions` row, and create one `Order` per unit exactly as `PlanService::payAtOnce()` does today, tagged with the new session id.
- Build "start a plan from cart": one selected item reuses `PlanService::create()`/`StartPlan` unchanged; multiple selected items call a new `PlanService::createMultiProduct()` that first calls `PlanEligibilityContract::check($user)` and blocks with a clear reason if ineligible.
- On a multi-product plan reaching Ready for Delivery, create one `Order` per `plan_item` in the same transaction, tagged with a shared grouping id, then proceed through the existing Sprint 6 chain per order.
- Remove successfully checked-out items from the cart; leave failed items (e.g. now out of stock) in place with a clear error.

Frontend:

- Add-to-cart action on the product page/catalog (alongside or replacing the direct "Pay At Once" / "Save Small Small" buttons).
- Cart page: items grouped by vendor, quantity controls, remove item, running subtotal/total, wallet balance shown, multi-select for "Pay Small" across items.
- Checkout screen: "Pay in full" (existing shortfall/add-money pattern) vs "Pay Small" — single item routes into the existing StartPlan flow; multiple selected items route into a new bundle-plan setup screen, with a clear message if the eligibility check fails.
- Product Tracker page shows all bundled products, their individual locked prices, and the combined progress bar for multi-product plans.
- Order confirmation / receipt view groups orders from the same checkout session (or the same multi-product plan's delivery) together for the customer, even though each is tracked independently afterward.

Database:

- Add `carts` (`id`, `uuid`, `user_id`, `created_at`, `updated_at`) — unique `user_id`.
- Add `cart_items` (`id`, `cart_id`, `product_id`, `quantity`, `created_at`, `updated_at`).
- Add `checkout_sessions` (`id`, `uuid`, `user_id`, `wallet_transaction_id`, `total_amount`, `created_at`) — groups orders placed together.
- Add nullable `orders.checkout_session_id` (FK to `checkout_sessions`) and nullable `orders.plan_delivery_group_id` — purely grouping/display fields, no change to existing order semantics.
- Make `product_target_plans.product_id` nullable; add `plan_items` (`id`, `plan_id`, `product_id`, `vendor_id`, `locked_price_kobo`, `quantity`, `created_at`) for multi-product plans.

QA and security:

- Test a cart can hold items from multiple vendors and checkout still creates one independent order per unit per vendor.
- Test a quantity of N fans out into N separate orders with correct per-unit pricing.
- Test stock/approval is re-validated at checkout, not just at add-to-cart.
- Test single-product "Pay Small" is blocked for a cart item with quantity greater than 1 until reduced to 1.
- Test a multi-product plan is blocked for an ineligible customer with a clear reason, and that single-product plans remain unaffected by eligibility.
- Test a multi-product plan reaches Ready for Delivery only when the combined target is met, never per individual product.
- Test a multi-product plan at 100% creates one order per bundled product, in one transaction, across different vendors.
- Test a partially-failed checkout (one item now out of stock) leaves that item in the cart and does not charge the wallet for it.
- Test removing/adjusting cart items never affects an already-placed order or an already-started plan.

DevOps and docs:

- Update the PRD's Pay At Once/Product Target Plan descriptions to reflect the cart-based, multi-product flow (done alongside this sprint).
- Add monitoring for abandoned carts (informational only — no automated reminder messaging is in scope here).

Exit criteria:

- A customer can add products from more than one vendor to a single cart and either pay for all of them in one checkout, or — if eligible — bundle several into one savings plan with one combined target.
- Every resulting order still follows the exact Sprint 6 fulfillment chain with no vendor ever seeing another vendor's items in the same checkout or plan.
- A multi-product plan never delivers a subset of its bundled products early.

#### Sprint 9: AI, Reporting, and Operational Controls — COMPLETE

Scope: AI-assisted listing review, configurable thresholds, operational reports, vendor suspension, user suspension, admin controls, and swapping the Sprint 8 rule-based multi-product plan eligibility checker for an AI-scored one.

**Build note (2026-07-25):** an audit before building found vendor suspend→auto-delist and the session-revocation/login-block plumbing for `UserStatus::Suspended`/`Banned` already fully working from earlier ad-hoc sessions (Sprint 2/3) — neither was rebuilt. Everything else here was net-new. The "AI-assisted" pieces (Listing Review Assistant, the multi-product plan eligibility scorer) are built behind swappable contracts — `AiListingAnalyzerContract` and `PlanEligibilityContract` — with **no real external AI provider wired in** (`config('services.ai')` has the driver/key scaffold, but nothing consumes a key yet): `RuleBasedListingAnalyzer` runs deterministic, zero-cost checks only (price-outlier vs. category average, description length, image count, a prohibited-keyword scan) and is the default/fallback driver; `AiScoredPlanEligibilityChecker` currently just delegates to `RuleBasedPlanEligibilityChecker` verbatim. Both are real, tested, and swappable — a future session can add a real provider case to the `match()` in `AppServiceProvider` without touching the job, the admin UI, or the eligibility call sites. Reports are always live source-table reads (no snapshot table) so "reports match source tables" holds by construction. User moderation reuses the vendor pattern (a reason field + the existing generic `audit_logs` table) rather than a dedicated status-events table.

- Bind `PlanEligibilityContract` to a new AI-scored checker (in place of `RuleBasedPlanEligibilityChecker`), using the customer's contribution reliability and purchase history as model input. Keep the rule-based checker available as a fallback/override — the AI output is advisory input to the same eligibility decision, not a black box the customer can't get an explanation from; a blocked customer still sees a clear, human-readable reason.

Backend:

- Integrate Listing Review Assistant with queued jobs.
- Analyze listing image quality, blur, image/product match, description completeness, prohibited/spam content, category mismatch, and price outliers.
- Store human-readable AI flag reasons.
- Make AI outputs advisory only.
- Add Super Administrator setting for price-outlier threshold.
- Build reports for signups, deposits, plan completions, order volume, vendor activity, and product approval outcomes.
- Build user suspension and ban actions that revoke sessions.
- Build vendor suspension action that automatically delists approved products.

Frontend:

- Admin AI listing flag panel inside approval queue.
- AI settings page for Super Administrator.
- Reporting dashboard with date ranges and export buttons.
- User management page with suspend/ban controls.
- Vendor management page with suspend/ban controls and delisting preview.
- Operational alerts for risky or stuck records.

Database:

- Add AI settings, AI review logs, report snapshots if needed, user status events, vendor status events, and risk flags.
- Add audit metadata for admin decisions and AI outcomes.

QA and security:

- Test AI flag reason is displayed to admin.
- Test AI cannot auto-approve/reject products or restrict accounts.
- Test price-outlier threshold is configurable.
- Test user suspension revokes sessions.
- Test vendor suspension delists products.
- Test reports match source tables.

DevOps and docs:

- Add AI provider env variables and cost-limit settings.
- Add queue monitoring for AI jobs.
- Document AI failure fallback: listings still go to manual review.

Exit criteria:

- Admin can review AI-assisted product flags.
- Operational reports and suspension workflows are usable and audited.

#### Sprint 10: Hardening and Launch

Scope: security review, test depth, performance, deployment readiness, pilot vendor launch, and production rehearsal.

Backend:

- Review policies for every route/action.
- Lock down route middleware by role and permission.
- Add idempotency protections to money and webhook flows.
- Add database constraints and indexes discovered during testing.
- Add production-safe logging that excludes sensitive identity values.
- Commission and remediate findings from a third-party security review/penetration test before pilot launch.
- Run `composer audit` and `npm audit` (or Dependabot) to a clean state, or document accepted exceptions.
- Confirm production secrets are sourced from the secrets manager, not a committed or shared `.env`.
- Test the data export/deletion workflow and confirm the retention/purge job runs on schedule.
  - Done: the published `/data-deletion` page, which is the instructions URL Meta
    requires before it will review a Facebook login app. It routes a request through
    the Support Center or the hotline.
  - Outstanding: the workflow behind it is still manual. There is no self-service
    export, no deletion request record, and no scheduled purge — the 30-day promise
    on that page is currently kept by hand.
- Confirm wallet ledger sum reconciles against the actual settled fund balance (fund safeguarding check).

Frontend:

- Complete responsive review for customer, vendor, admin, support, logistics, and finance screens.
- Polish empty, loading, error, and permission-denied states.
- Verify all forms show useful validation and recovery paths.
- Complete accessibility pass for forms, tables, dialogs, and status indicators.

Database:

- Run migration fresh tests.
- Verify foreign keys, unique constraints, check constraints, and indexes.
- Prepare seeders for roles, permissions, categories, Super Administrator, and baseline settings.

QA and security:

- Run no-withdrawal architecture tests.
- Run Paystack webhook replay tests.
- Run ledger integrity and concurrency tests.
- Run vendor/customer data isolation tests.
- Run E2E paths: registration, identity, vendor approval, listing approval, deposit, plan creation, contribution, ready delivery, order delivery, support ticket.
- Run pilot vendor cohort acceptance testing.

DevOps and docs:

- Complete production runbook rehearsal.
- Test backup and restore.
- Test deploy and rollback.
- Configure monitoring, alerts, queue workers, scheduler, and health checks.
- Confirm secrets are not committed.
- Prepare launch checklist.

Exit criteria:

- MVP can safely launch with pilot vendors and real customers.
- Payment, Pay At Once purchase, savings, vendor, order, support, and admin workflows are tested end to end.

### Phase 2: Growth

Goal: improve retention, convenience, personalization, and operational intelligence after the core transaction loop is stable.

#### Phase 2A: Wishlist, Rewards, Referrals, and Basic Affiliates

Backend:

- Build wishlist model and APIs.
- Add side-by-side product comparison data endpoints.
- Add price-drop detection for wishlisted products.
- Build reward tier configuration: Bronze, Silver, Gold, Platinum Saver.
- Calculate reward tiers from cumulative completed savings, not current wallet balance.
- Build single-level referral attribution.
- Credit referral reward only when referred customer's first Product Target Plan reaches Completed status.
- Build basic affiliate application and approval workflow.
- Generate protected affiliate codes and links using random non-sequential codes or signed tracking tokens.
- Track affiliate clicks with rate limiting and suspicious-repeat controls.
- Store attribution in secure cookies and attach it to registration when valid.
- Track customer and vendor conversions without paying commission for clicks or signup alone.
- Mark commission eligibility only after a delivered Pay At Once order, delivered completed-plan order, or referred vendor approval plus first approved product.
- Keep affiliate payout records separate from customer wallet and savings ledgers.

Frontend:

- Customer wishlist page.
- Product comparison view.
- Price-drop notification indicators.
- Rewards and badge page.
- Referral code/link sharing page.
- Referral status page showing pending and earned rewards.
- Affiliate application page.
- Affiliate dashboard showing protected link, clicks, signups, verified users, delivered conversions, and commission status.
- Admin affiliate approval queue.

Database:

- Add `wishlists`, `wishlist_price_alerts`, `reward_tiers`, `user_rewards`, `referrals`, `affiliates`, `affiliate_links`, `affiliate_clicks`, `affiliate_attributions`, `affiliate_conversions`, and `affiliate_commissions`.
- Add indexes for customer, product, referral code, affiliate code, attribution token, conversion status, and reward status.

QA and security:

- Test price-drop notifications trigger only when configured threshold is met.
- Test referral reward is not credited at signup.
- Test referral program is single-level only.
- Test reward tier recalculates only after plan completion.
- Test affiliate links use protected random codes and expose no database IDs or personal data.
- Test affiliate click tracking grants no application permission.
- Test suspended affiliate links stop tracking.
- Test self-referral is blocked.
- Test duplicate phone, email, or suspicious device patterns are blocked or flagged.
- Test affiliate commission is not payable until a qualified delivered order or qualified vendor conversion occurs.
- Test affiliate payout cannot touch the customer wallet ledger.

Exit criteria:

- Customers can save products, compare options, earn badges, refer other users, and approved affiliates can track qualified acquisition without opening fraud-prone reward or cashout paths.

#### Phase 2B: Convenience and Personalization

Backend:

- Build scheduled automatic debit using stored reusable Paystack authorization.
- Add daily scheduler job for due automatic debit records.
- Implement retry once after 24 hours, then pause automatic debit until reauthorization.
- Build plan pause/resume.
- Ensure pause stops reminders and automatic debit only.
- Add translation keys and localization infrastructure.
- Add notification templates for English, Hausa, French, and Arabic.
- Add dark-mode preference storage if needed.

Frontend:

- Automatic debit setup screen.
- Automatic debit status and reauthorization screen.
- Pause/resume controls on Product Target Plan page.
- Localized UI text switcher.
- Dark-mode toggle.
- Improved payment reminder preference controls.

Database:

- Extend `automatic_debits` with retry/failure tracking.
- Add plan paused fields and status events.
- Add user locale/theme preferences.
- Add localized notification templates if templates are database-driven.

QA and security:

- Test failed automatic debit retry behavior.
- Test automatic debit pause does not pause the Product Target Plan itself.
- Test plan pause does not change target price or amount saved.
- Test translations do not break forms or validation.
- Test scheduled jobs are idempotent.

Exit criteria:

- Customers can automate contributions, pause reminders/debits safely, and use the app in supported languages.

#### Phase 2C: Live Support and AI Assistance

Backend:

- Build live chat or integrate selected chat provider.
- Build Complaint Center workflow.
- Build Customer Support Chatbot for FAQ and simple query deflection.
- Build AI Savings Assistant that suggests realistic contributions using actual payment history.
- Build Product Recommendation Engine using wishlist and savings behavior.
- Store AI recommendations and user-visible explanations.
- Keep all AI suggestions advisory.

Frontend:

- Live chat UI.
- Complaint Center page.
- Chatbot entry point inside Support Center.
- Savings Assistant panel on savings dashboard.
- Product recommendations on catalog, wishlist, and dashboard.
- Explain-why UI for recommendations.

Database:

- Add complaint records, live chat transcripts if self-hosted, AI recommendation logs, and recommendation feedback.
- Add indexes for customer, recommendation type, support status, and created date.

QA and security:

- Test AI does not move money or change plans automatically.
- Test support chatbot escalates unresolved issues.
- Test support transcripts do not expose payment credentials.
- Test AI prompts avoid sending unnecessary sensitive identity data.

Exit criteria:

- Customers get faster help and better savings guidance without handing financial decisions to AI.

#### Phase 2D: Vendor Ratings, Risk, and Forecasting

Backend:

- Build vendor rating tiers: Bronze, Silver, Gold, Platinum.
- Build fraud/risk flag engine for administrator review.
- Add flags for repeated OTP failures, large deposit from new device, rapid plan switching, vendor rejection-rate spike, and failed payments.
- Build demand forecasting for products trending toward completion.
- Add reports for wishlist demand, expected completions, and vendor performance.

Frontend:

- Vendor rating page.
- Admin risk flag dashboard.
- Risk flag detail page with review outcome.
- Forecasting dashboard for expected product demand and upcoming completions.
- Vendor performance reporting.

Database:

- Add vendor ratings, rating history, risk flags, risk review outcomes, demand forecasts, and forecast snapshots.

QA and security:

- Test risk flags do not automatically suspend users.
- Test only authorized admins can resolve risk flags.
- Test rating calculations are reproducible.
- Test forecasting data does not expose customer identity to vendors.

Exit criteria:

- Admins can monitor risk and demand, while vendors gain transparent performance tiers.

### Phase 3: Scale

Goal: extend FirstMaket into new channels, new savings models, and mobile access after real usage data proves the MVP and growth features.

#### Phase 3A: Agent Network

Backend:

- Build agent registration and approval.
- Generate unique agent codes.
- Record agent-assisted customer signups.
- Record agent-assisted deposits and reconcile through Paystack.
- Calculate agent commissions per successful deposit.
- Build agent status, suspension, and audit workflow.

Frontend:

- Agent onboarding/admin approval screens.
- Agent dashboard with signups, deposits, commission, and status.
- Admin agent management page.
- Finance agent commission reconciliation page.

Database:

- Add agents, agent codes, agent-assisted signups, agent deposits, and agent commissions.

QA and security:

- Test agent cannot directly credit wallet without verified Paystack flow.
- Test agent commission is calculated only after successful deposit.
- Test agent cannot view full customer financial history.

Exit criteria:

- FirstMaket can support offline/community-assisted acquisition and deposits without weakening ledger controls.

#### Phase 3B: Advanced Affiliate Program

Backend:

- Expand affiliate registration into a full partner program.
- Generate protected campaign links with random codes and optional HMAC/signed tokens.
- Enforce attribution windows, one valid attribution per user, and self-referral blocking.
- Track clicks, signups, verified users, first deposits, Pay At Once delivered orders, completed-plan delivered orders, and vendor recruitment conversions.
- Add commission rules for flat, percentage, tiered, and vendor-recruitment payouts.
- Add monthly payout batches with minimum threshold, Finance approval, rejection reasons, and paid status.
- Keep affiliate payouts separate from customer wallets and no-withdrawal savings ledgers.
- Add affiliate bank account verification before payout.

Frontend:

- Affiliate dashboard.
- Affiliate link and campaign management page.
- Admin affiliate management page.
- Finance affiliate payout/reconciliation view.
- Conversion review and fraud-flag screens.
- Affiliate payout history with pending, approved, rejected, and paid states.

Database:

- Add affiliate commission tiers, affiliate payout batches, affiliate payout items, affiliate bank accounts, and affiliate fraud flags.

QA and security:

- Test attribution cannot be overwritten after valid first attribution.
- Test commissions are calculated only on qualified conversion events.
- Test affiliate links cannot expose customer personal data.
- Test open redirects are blocked.
- Test signed tracking tokens cannot be tampered with.
- Test payout requires minimum threshold and Finance approval.
- Test rejected conversions cannot be paid.
- Test suspended affiliates cannot create new attribution or receive payout.

Exit criteria:

- External partners can drive signups and delivered conversions through protected links, auditable conversion rules, fraud controls, and finance-approved partner payouts.

#### Phase 3C: Group, Family, and Cooperative Savings

Backend:

- Build Group Purchase Plans where multiple customers contribute toward one product target.
- Build contribution ownership and share tracking.
- Build Family Savings dashboard without pooling underlying wallets.
- Build Cooperative Savings model for structured rotating contribution groups.
- Define group permissions, invitations, approvals, and exit rules.
- Keep no-withdrawal principle intact for group/cooperative flows.

Frontend:

- Group purchase creation flow.
- Group invite and member contribution screen.
- Family dashboard combining member plan summaries.
- Cooperative group dashboard.
- Group progress tracker.
- Admin review pages for disputes or suspicious group activity.

Database:

- Add group purchase plans, group members, group contributions, family groups, family group members, cooperative groups, cooperative cycles, and cooperative contributions.

QA and security:

- Test contribution ownership remains traceable.
- Test one member cannot redirect another member's funds.
- Test family dashboard does not pool wallets.
- Test cooperative schedules do not create withdrawal paths.

Exit criteria:

- Multi-person savings models work without breaking wallet, ownership, or no-cash-withdrawal rules.

#### Phase 3D: Full AI Financial Assistant

Backend:

- Build conversation memory scoped to the customer.
- Let assistant explain customer's own savings, plans, and transaction history in plain language.
- Let assistant recommend cheaper product redirection for stalled plans.
- Keep all recommendations advisory and customer-confirmed.
- Add AI cost controls and abuse limits.

Frontend:

- Conversational assistant panel.
- Suggested action cards for plan adjustment or product switch.
- Conversation history page.
- Confirmation flow for any user-chosen action.

Database:

- Add AI conversations, messages, recommendation actions, confirmation records, and cost logs.

QA and security:

- Test assistant cannot execute money movement without explicit user confirmation.
- Test assistant cannot reveal another user's data.
- Test prompt payloads minimize sensitive data.
- Test cost limits and rate limits.

Exit criteria:

- Customers can receive contextual savings help while retaining full control over decisions.

#### Phase 3E: Native Mobile Applications

Backend:

- Harden Sanctum token mode for mobile.
- Expose API endpoints needed by mobile without duplicating business logic.
- Add mobile push notification device tokens.
- Add mobile versioning and forced-update settings.

Frontend/mobile:

- Build Android and iOS apps consuming the Laravel API.
- Implement authentication, dashboard, catalog, wallet, plans, tracker, orders, notifications, and support.
- Add native push notifications.
- Keep web and mobile UI flows consistent.

Database:

- Add personal access token strategy, device tokens, mobile sessions, and app-version settings if needed.

QA and security:

- Test API authorization for every mobile endpoint.
- Test device token registration and revocation.
- Test mobile app cannot bypass web business rules.
- Run mobile smoke tests for critical customer flows.

Exit criteria:

- Mobile apps reuse the stable backend and add native convenience without a parallel system.

### Phase 4: Public Website

Goal: Expand the Sprint 0 home page into the full public brand presence once the core product can demonstrate real workflows, screenshots, vendor onboarding, support, and trust messaging accurately. The marketplace home page itself ships in Sprint 0; Phase 4 adds the surrounding marketing/informational pages and final SEO polish.

Backend/CMS:

- Decide whether pages are static Inertia pages or content-managed.
- Build public route group with no authentication requirement.
- Add public approved-catalog preview endpoint if Marketplace Preview uses live catalog data.
- Add contact form endpoint with spam protection.
- Add vendor interest CTA routing to live vendor onboarding.
- Add SEO metadata, sitemap, and robots configuration.

Frontend:

- Home
- About
- How It Works
- Marketplace Preview
- Become a Vendor
- Contact
- FAQ
- Terms of Service
- Privacy Policy
- SEO metadata and sitemap
- Vendor application CTA wired to the live onboarding flow

Content and brand:

- Use real product screenshots and completed workflows.
- Explain Pay At Once, Open Savings, and Product Target Plans clearly.
- State that FirstMaket is not a loan app, bank, BNPL product, or withdrawal wallet.
- Highlight trust pillars: Paystack payments, verified vendors, FirstMaket-controlled delivery, support hotline, and no vendor access to customer identity.

QA and security:

- Verify public pages require no login.
- Verify protected app pages still require login.
- Test contact form spam/rate limits.
- Test SEO metadata and sitemap.
- Test Marketplace Preview only shows approved products.

DevOps and launch:

- Configure domain, SSL, redirects, sitemap submission, and analytics.
- Add uptime monitoring for public website.
- Prepare public launch checklist.

Exit criteria:

- Website is live on production domain.
- Core copy explains that FirstMaket supports Pay At Once and savings-based purchases, but is not loans or BNPL.
- Marketing content reflects the completed application, not planned features.
- Contact form, hotline, and vendor interest form are working.

## 3. Recommended Folder Structure

Use the IHMS modular layout, adapted to FirstMaket:

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
    Orders/
    Logistics/
    Payments/
    Notifications/
    Support/
    Admin/
    Reporting/
    AI/
    Risk/
    Referrals/
    Rewards/
  Shared/
    Casts/
    Commands/
    Contracts/
    Enums/
    Exceptions/
    Middleware/
    Policies/
    Scopes/
    Security/
    Services/
    Traits/
  Providers/
    ModuleServiceProvider.php
```

Recommended module internals:

```text
app/Modules/Savings/
  Actions/
  Controllers/
  DTOs/
  Events/
  Jobs/
  Listeners/
  Models/
  Policies/
  Requests/
  Services/
  routes.php
```

Frontend structure:

```text
resources/js/
  Components/
    common/
    feedback/
    forms/
    layout/
    ui/
    domain/
      catalog/
      savings/
      wallet/
      orders/
      vendor/
      admin/
  Hooks/
  Layouts/
  Pages/
    Public/
    Customer/
    Vendor/
    Admin/
    Support/
    Logistics/
    Finance/
  Types/
  Utils/
```

Other project folders:

```text
database/
  migrations/
  seeders/
  factories/
deployment/
  configs/
  scripts/
docs/
tests/
  Feature/
  Unit/
  Browser/
```

## 4. Key Engineering Rules

- Paystack webhook confirmation is the only event that credits wallet balance.
- No backend withdrawal endpoint may exist.
- All money values use integer kobo or `DECIMAL(15,2)` consistently; do not use floats.
- Product target price is locked at plan creation.
- Vendor price edits after approval return the product to pending approval.
- Vendors never see customer identity or delivery address.
- Vendor earnings live in a separate vendor ledger; commission is deducted at delivery confirmation, and payouts go only to verified vendor bank accounts through Finance-approved batches.
- Vendor earnings are credited exactly once per delivered order, inside a database transaction, and never before the delivery confirmation window passes.
- Registration accepts email or phone; the verification OTP travels through the matching channel, and phone verification is mandatory before wallet funding.
- Social login (Google/Facebook) links to an existing account only after ownership proof; it never silently merges or duplicates accounts.
- Admin roles must be permission-based, not hard-coded role checks.
- All ledger-affecting writes must run inside database transactions.
- Affiliate commission is a separate partner payout record, never a customer wallet withdrawal or savings ledger movement.
- Affiliate links must use random non-sequential codes or signed tokens, never database IDs or personal data.
- All sensitive identity fields must be encrypted at application level.
- OTP requests must be rate-limited by phone number, IP, and device fingerprint.
- AI recommendations that affect money, product approval, fraud, or account access are advisory only; human admins make final decisions.
- Modules communicate through domain events or shared contracts, never by querying another module's models directly.
- Admin, Support, Logistics, and Finance surfaces are served from an isolated subdomain with a separate cookie scope from the customer app.
- Admin, Finance Officer, and Super Administrator accounts require 2FA; it is not optional.
- Production secrets are sourced from a secrets manager, not a plain `.env` file.

## 5. External Integrations

| Service | Recommended Provider Options | Used For |
| --- | --- | --- |
| Payment processing | Paystack | Card, bank transfer, USSD, reusable authorizations |
| SMS/OTP | Termii, Africa's Talking | OTP and reminders |
| Social login | Google OAuth, Facebook Login (via Laravel Socialite) | Register/login with Google or Facebook |
| Vendor payouts | Paystack Transfers | Weekly vendor payout batches to verified bank accounts |
| Email | SendGrid, Postmark | Verification, receipts, notifications |
| Storage | Cloudinary or S3-compatible bucket | Product images and CAC documents |
| Address lookup | Google Maps Places API | Delivery address entry and validation |
| AI | OpenAI or Anthropic | Listing review, support chatbot, savings assistant |
| Browser push | Web Push API or OneSignal | Web notifications before native mobile apps |

## 6. MVP Success Metrics

| Area | Target |
| --- | --- |
| Deposit confirmation | 100% webhook-verified |
| Ledger integrity | No orphaned wallet transaction |
| Dashboard response | Under 500 ms for normal account load |
| Plan creation | Under 90 seconds for a verified customer |
| Vendor listing review | Admin can approve or reject in under 2 minutes |
| Delivery status | Customer notified on every status change |
| Public website | Launch after the transactional product is complete and validated |
