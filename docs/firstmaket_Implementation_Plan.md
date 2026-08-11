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

### Where The Build Actually Is

Last reviewed against the codebase: **11 August 2026**.

Phases 1 and 2 (2A–2E) are built and covered by tests. This section records where the shipped system differs from the plan as originally written, because a plan that quietly disagrees with the code is worse than no plan.

**Complete**

| Phase | State | Notes |
| --- | --- | --- |
| 1 — MVP transaction platform | Done | Catalogue, payments, savings plans, orders, delivery, support, admin |
| 2A — Wishlist, rewards, referrals, affiliates | Done | Admin affiliate approval queue was built but unreachable; now in the nav |
| 2B — Automatic debit and plan pause | Done | Localisation shipped earlier than planned (en, fr, ha, ig, pcm, yo) |
| 2C — Support and assistance | Done, scope changed | See "Decisions that changed the plan" |
| 2D — Vendor tiers, risk flags, forecasting | Done | Tiers, risk queue, demand and completion forecasting |
| 2E — Returns, refunds, disputes | Done | Added during the build; was not in the original plan at all |

**Added along the way, not in the original plan**

- **Returns, refunds and disputes (2E).** The product page already published a returns policy — a seven-day window, who pays return delivery, refunds to card — that nothing implemented. Building it also introduced the first outward money path in the system, kept admin-only, capped, idempotent and audited.
- **Runtime configuration.** Roughly fifty operational values that began as constants or config are now settings staff can change without a deploy: the returns window, plan pause ceiling, automatic-debit retries, vendor rating weights and tier thresholds, risk thresholds, assistant tolerances, recommendation limits, delivery attempts, plan switch allowance, home-page section sizes and the live-chat provider. Two admin screens hold them — **Operations settings** and **Automation & rules**.
- **Vendor earnings clawback.** A completed return reverses the vendor earning, the affiliate conversion and its commission, inside the same transaction as the refund.

**The wallet was removed — and most of this document predates that**

The largest divergence between this plan and the code. Phase 1 was written around a deposit-only wallet with its own balance and ledger: "fund wallet through Paystack", "Customer wallet page", "credit wallet only after verified webhook". **None of that exists.** There is no `Wallet` model, no `wallets` table, and an architecture test that fails the build if any route URI contains `deposit`, `withdraw`, `cash-out`, `transfer-out` or `add-money`.

What replaced it: money never sits in a customer balance. It only ever enters against something specific —

- a **Pay Small Small instalment**, credited to that one plan and no other,
- a **card order payment**, or
- the **goods balance** on a delivered shipment.

and it only ever leaves as goods, as **plan credit** when a plan is cancelled, or — since Phase 2E — as a **refund reversing the exact charge that brought it in**.

This is a better design for what FirstMaket is, and it is why the storefront can say money is never at risk. But it invalidates any later phase written on the assumption that customers hold a balance somebody can top up. Read the Phase 1 wallet sections as historical; the money rules that actually hold are the ones above.

**Decisions that changed the plan**

- **AI is deferred, not delivered.** 2C called for an AI chatbot and AI savings assistant. Both are built as deterministic rules over the customer's own payment history instead. The rules are explainable line by line, cost nothing to run, cannot invent a figure, and keep financial data on the platform. The AI versions remain available in Phase 3D when there is a reason to prefer them.
- **Live chat is a third-party widget**, configured by naming a provider and an id. Deliberately not a paste-a-snippet box: that is arbitrary third-party JavaScript on pages where customers enter card details.
- **Dark mode dropped.** 2B listed it as "if needed". FirstMaket ships a single light design on purpose; adding a theme would contradict a decision already documented in the components.
- **Arabic deferred.** Five languages ship with real translations. Arabic needs right-to-left layout work, which is design, not a file drop.
- **No recommendation log table.** The recommendations are deterministic, so what was shown on any day can be recomputed. Only feedback — which cannot be recovered — is stored.

**Known gaps this document now plans for**

- Staff roles are seeded constants. An admin cannot create a "Logistics" role and hand it a set of pages. → **Phase 2F**.
- The home page's "Flash Sale", "Trending searches", "Top Picks for You" and "More to love" are the same two product lists relabelled. There is no flash mechanism, no trending data and no personalisation behind them. → **Phase 2G**.
- The Agent Network as written in Phase 3 assumed wallet deposits and cannot be built as specified. Rewritten and deferred → **Phase 6**.
- **Multi-product plan eligibility is intentionally open.** Any customer may bundle several products into one savings plan. The plan creates one item row per product and quotes one combined delivery fee; the setting `savings.multi_product_plans_enabled` remains an operational switch if eligibility needs to narrow later.
- **Browser push was never built.** Notifications are mail, SMS and in-app only.
- **Feature flags are settings-backed.** Pennant definitions read `feature.<name>` from the `settings` table, default off, and can be changed from the permission-gated admin Feature flags page. Module entry points still need to adopt individual checks as each optional module is enabled.

### Project Sequence At A Glance

| Order | Stage | What Gets Completed |
| --- | --- | --- |
| 0 | Public home page | Marketplace-style public home page shell (header, search, categories, hero, product sections, footer), brand assets |
| 1 | Foundation | Laravel/Inertia setup, modules, RBAC, audit logging, database baseline |
| 2 | Onboarding | Customer/vendor registration, OTP, email verification, vendor approval |
| 3 | Marketplace catalog | Categories, vendor products, product approval, vendor pricing, posting fees |
| 4 | Payments | Paystack initialization, webhook-only crediting against a plan or order, receipts, transaction history, finance reconciliation |
| 5 | Purchase and savings | Pay Small Small plans, Pay At Once checkout, payment logic, target price locking, tracker, switching and rescheduling |
| 6 | Orders and delivery | Ready-for-delivery order creation, delivery address, admin confirmation, vendor sold-notification and preparation, logistics tracking, vendor earnings and payouts |
| 7 | Support and communication | Notifications, support tickets, hotline logs, IVR routing, support-agent lookup |
| 8 | Cart and checkout | Multi-vendor cart, quantity, cart-based full-payment checkout, cart-based plan starts, checkout session grouping |
| 9 | AI and operations | Listing Review Assistant, reports, vendor/user suspension, operational controls |
| 10 | MVP launch | Security hardening, no-withdrawal tests, ledger tests, E2E tests, pilot vendor launch |
| 11 | Growth — done | Wishlist, rewards, referrals, affiliates, automatic debit, pause/resume, chat widget, rules-based assistant, vendor tiers, risk flags, forecasting, returns and refunds |
| 12 | Staff roles — **next** | Roles an administrator can create, with permissions chosen per role |
| 13 | Merchandising | Real flash deals, deal pricing, measured trending, personalised picks |
| 14 | Scale | Advanced affiliates, group/family/cooperative savings, full AI assistant |
| 15 | Public website | Public marketing website, SEO, real screenshots/workflows, vendor CTA, final brand launch |
| 16 | Mobile apps — future | Native iOS/Android on the reserved `/api/v1`, once the rules have settled |
| 17 | Agent network — future | Agent-assisted plan payments; the deposit-based version cannot be built |

### Phase 1: MVP Transaction Platform

Goal: web-based marketplace with Pay Small Small savings plans, full Pay At Once purchase, vendor listing, admin approval, Paystack payments, and delivery operations.

Status: **complete**. Read the wallet language in Sprints 4 and 5 as historical — see "The wallet was removed" above.

#### Sprint 0: Public Home Page and Brand Shell — COMPLETE

Scope: build the public-facing home page first, following the layout patterns shared by the major marketplaces surveyed (AliExpress, Amazon, eBay, Etsy, Walmart Marketplace, Temu, Rakuten, Mercado Libre, Shopee, Lazada, Jumia, Konga, Takealot, Kilimall, Bob Shop, Newegg, Bonanza, Wish, Cdiscount, OnBuy). The home page is the storefront and first impression; every surveyed marketplace opens with a search-first, category-driven landing page rather than a marketing brochure. Phase 4 later expands this into the full public website (About, How It Works, legal pages, SEO polish).

Note: Sprints 1 and 2 were built before this sprint was added. Sprint 0 is executed now, before Sprint 3, and its product sections go live progressively as the catalog (Sprint 3) fills with approved products. Until then, sections render from seeded demo/approved data or hide when empty.

Common home page anatomy found in the survey (adopt this structure):

1. **Top utility bar**: delivery location/language, "Sell on FirstMaket" vendor CTA, Help/Support link, app-download placeholder.
2. **Main header**: logo (left), prominent full-width search bar with category scope (center), account and cart icons (right). Search is the single most dominant element on every surveyed site.
3. **Category navigation**: sidebar category menu (Jumia/AliExpress style) or horizontal mega-menu, listing the six launch categories: Electronics, Home Appliances, Solar Equipment, Furniture, Fashion, Business Equipment.
4. **Hero area**: rotating promotional carousel beside the category menu, plus 1–2 static promo tiles (Pay Small Small, current deals).
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

#### Sprint 1: Project Foundation — COMPLETE

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

#### Sprint 2: Identity and Account Onboarding — COMPLETE

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

#### Sprint 2 Addendum: Registration and Login Options — COMPLETE

Scope: bring registration/login in line with the surveyed marketplaces (Jumia, Temu, Shopee, AliExpress all offer email-or-phone signup plus social login). Sprint 2 shipped email+password registration with phone OTP; this addendum widens the entry paths. Can be scheduled alongside or right after Sprint 3.

Backend:

- Allow registration with **email or phone number** as the primary identifier — exactly one is required at signup; the other can be added later from profile settings.
- Verification OTP goes through the channel that matches the identifier: email signup → 6-digit code by email; phone signup → 6-digit code by SMS. Reuse the existing OTP service with a `channel` field (`sms`, `email`).
- Add **passwordless OTP login** option: enter email/phone, receive one-time code, log in — alongside the existing password login, with the same rate limits as registration OTP.
- Add **social login** with Google and Facebook via Laravel Socialite: create-or-link account by verified provider email, store provider tokens in a `social_accounts` table, never create a duplicate account when the email already exists (link instead, after the user authenticates or confirms via email OTP).
- Social-only accounts have a nullable password; prompt them to set one (or keep social-only) in settings.
- Password reset works through both channels: email reset link, or phone OTP + new password form.
- Phone verification remains mandatory before any money movement, regardless of signup method, since SMS OTP secures transactions.

Frontend:

- Combined register form with an email/phone toggle (single "Email or phone number" input that detects the type is acceptable).
- "Continue with Google" and "Continue with Facebook" buttons on both login and register screens.
- OTP entry screen shared by SMS and email flows, showing the masked destination.
- Password reset flow with channel choice.
- Account settings: linked social accounts, add/verify secondary identifier, set password for social-only accounts.

QA and security:

- Test OTP rate limits per identifier, IP, and device for both channels.
- Test social login cannot take over an existing account without ownership proof.
- Test unverified-phone accounts are blocked from paying.
- Test a user cannot register twice with the same email/phone through different paths.

#### Sprint 3: Catalog and Vendor Listing — COMPLETE

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

#### Sprint 4: Payments and Paystack — COMPLETE, THEN SUPERSEDED

Scope as built: a deposit-only wallet, Paystack payment initiation, webhook-only balance credit, receipts, transaction history, and finance reconciliation.

**The wallet was later removed.** What survived, and is what the system runs on today:

- Paystack initialisation, the signature-verified webhook, and the rule that **only** a verified webhook moves money. Nothing about that changed.
- Receipts, the Paystack transaction log, and finance reconciliation.
- Reusable card authorizations, captured here and finally used by Phase 2B automatic debit.

What went: `wallets`, `wallet_transactions`, the balance, the customer wallet pages, and the whole idea of funding an account before choosing what to buy. A payment now names what it is for at the moment it is created — a plan instalment, an order, or a shipment's goods balance — and the webhook credits that one thing. The list below describes the original build; read it as history.

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

#### Sprint 5: Purchase and Savings Engine — COMPLETE, PARTLY SUPERSEDED

Scope as built: Open Savings, Product Target Plans, Pay At Once checkout, contribution logic, target locking, progress tracking, and redirection.

**Open Savings is retired along with the wallet.** The `savings` table still exists but now holds one thing only: **credit** left over when a customer cancels a plan or a vendor rejection is refunded. Credit can only ever be spent on another plan — there is no way to put money in, and no way to take it out. Its `balance_kobo` column is a dead wallet leftover pinned at zero.

What survived and is the heart of the product today: **Pay Small Small plans** with the price frozen at signup, per-plan payment history, progress and completion projection, switching a plan to a different item, and rescheduling. Phase 2B added pausing and automatic debit; Phase 2E added returns. Read the Open Savings items below as history.

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

#### Sprint 6: Orders, Logistics, and Vendor Settlement — COMPLETE

Scope: conversion from fully funded plan to order, vendor sold-notification, vendor preparation with a packing SLA, FirstMaket-controlled pickup and delivery, customer notifications, and vendor earnings/payout so the fulfillment chain is complete end to end.

The complete fulfillment chain (modeled on Jumia's dropship flow, where the marketplace controls delivery):

1. **Paid** — plan reaches 100% (or Pay At Once webhook confirms). Order is created; `OrderPaid` event fires.
2. **Vendor notified** — vendor instantly receives an "item sold" notification (dashboard + email/SMS) with product, quantity, and order number — never customer identity or address.
3. **Admin confirmation** — admin confirms the order (checks payment/ledger match) and moves it to Processing.
4. **Vendor prepares** — vendor confirms stock and packs within the preparation SLA (48 hours, configurable). Vendor marks the order **Ready for Pickup**. If the vendor cannot fulfil (out of stock), the vendor rejects with a reason and admin triggers the resolution path (redirect plan to another product or admin-managed refund-to-savings — never cash out).
5. **Handover to logistics** — FirstMaket logistics picks up from the vendor, or the vendor drops off at a FirstMaket hub. Logistics scans/accepts the package: status Packed → Shipped.
6. **Delivery** — logistics is assigned, status moves Out for Delivery → Delivered. Customer is notified at every step.
7. **Delivery confirmation window** — customer confirms receipt, or the order auto-confirms after N days (default 3) without a complaint/dispute.
8. **Vendor earnings credited** — on confirmed delivery, commission (per-category percentage set by admin) is deducted from the locked product price and the remainder is credited to the vendor's **earnings ledger**, which is entirely separate from anything a customer has paid into.
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
- Test vendor payout never touches a customer's plan or the savings credit ledger.

DevOps and docs:

- Add delivery status and vendor notification templates.
- Add operational runbook for stuck orders, SLA breaches, and failed payouts.
- Add monitoring for the auto-confirm and payout scheduler jobs.

Exit criteria:

- Fully funded plans become manageable orders with a complete chain: paid → vendor notified → packed → picked up → delivered → confirmed → vendor earnings credited → vendor paid.
- Delivery status is visible to customers and controlled by allowed roles.
- Vendors see their sales and get paid without ever seeing customer identity.

#### Sprint 7: Support and Notifications — COMPLETE

Scope: notification preferences, email/SMS/in-app notifications, support tickets, hotline logs, IVR reasons, FAQ, WhatsApp entry, and support-agent lookup.

> **Correction:** the channels built are **mail, SMS and in-app** (`NotificationPreferenceService` resolves exactly `mail | sms | database`). **Browser push was never implemented.** "In-app" means the notifications inbox at `/notifications`, which is real; nothing asks for browser permission or sends a push.

Backend:

- Build notification preference model and APIs.
- Implement notification dispatcher for email, SMS, and in-app. (Browser push was in the original scope and was not built.)
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

- Add SMS/email env placeholders.
- Add notification delivery failure monitoring.
- Document support escalation workflow.

Exit criteria:

- Customers can get help through FAQ, WhatsApp, hotline, and tickets.
- Support agents have safe, limited visibility.

#### Sprint 8: Cart and Multi-Vendor Checkout — COMPLETE, WALLET LANGUAGE SUPERSEDED

> **Two corrections, verified against the code.**
>
> 1. **The multi-product eligibility gate does not exist.** There is no `PlanEligibilityContract`, no `RuleBasedPlanEligibilityChecker`, no `plan_items` table and no `createMultiProduct()`. Multi-item plans *do* work — `savings_goal_items` holds the products — but **any customer can bundle**; the 30-day / prior-completion / serial-abandoner gate described below was never implemented. Decide whether it is still wanted before treating it as spec.
> 2. Everything else still describes the cart and checkout accurately **except** the payment step: where it says "debit the wallet", the customer now pays that checkout by card through Paystack, and the verified webhook raises the orders. The cart, the grouping into a `checkout_sessions` row, and the one-order-per-unit fan-out are unchanged.


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

- ~~Bind `PlanEligibilityContract` to an AI-scored checker.~~ **Not built** — the contract it refers to does not exist (see Sprint 8). The Listing Review Assistant *was* built and is real: `AiListingAnalyzerContract` with a deterministic `RuleBasedListingAnalyzer`, swappable for a real provider without touching the job or the admin UI.

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

#### Sprint 10: Hardening and Launch — COMPLETE

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
- Confirm plan payments and orders reconcile against the actual settled Paystack balance (fund safeguarding check).

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

#### Phase 2A: Wishlist, Rewards, Referrals, and Basic Affiliates — COMPLETE

Backend:

- Build wishlist model and APIs.
- Add side-by-side product comparison data endpoints.
- Add price-drop detection for wishlisted products.
- Build reward tier configuration: Bronze, Silver, Gold, Platinum Saver.
- Calculate reward tiers from cumulative completed savings, not from any current balance.
- Build single-level referral attribution.
- Credit referral reward only when referred customer's first Product Target Plan reaches Completed status.
- Build basic affiliate application and approval workflow.
- Generate protected affiliate codes and links using random non-sequential codes or signed tracking tokens.
- Track affiliate clicks with rate limiting and suspicious-repeat controls.
- Store attribution in secure cookies and attach it to registration when valid.
- Track customer and vendor conversions without paying commission for clicks or signup alone.
- Mark commission eligibility only after a delivered Pay At Once order, delivered completed-plan order, or referred vendor approval plus first approved product.
- Keep affiliate payout records entirely separate from customers' plans and the savings credit ledger.

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
- Test affiliate payout cannot touch a customer's plan or the savings credit ledger.

Exit criteria:

- Customers can save products, compare options, earn badges, refer other users, and approved affiliates can track qualified acquisition without opening fraud-prone reward or cashout paths.

#### Phase 2B: Convenience and Personalization — COMPLETE

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

#### Phase 2C: Live Support and Assistance — COMPLETE (AI deferred)

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

#### Phase 2D: Vendor Ratings, Risk, and Forecasting — COMPLETE

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

#### Phase 2E: Returns, Refunds, and Dispute Resolution — COMPLETE

Goal: make good on the returns promise the storefront already publishes, and give admins a controlled way to reverse a sale.

Why this is not optional. `BuyBoxPolicies.tsx` states a specific policy on every product page — seven days from delivery, who pays the return delivery, refunds to the original card within 5–10 working days — and the checkout repeats "full refund if the item is not as described". None of it is implemented: there is no return request, no return model, no route, and no way for an admin to send money back. The gap is a live consumer-protection exposure, not a missing convenience, and it is the reason this phase sits before Phase 3.

The one architectural decision to make first. Every money path in the system today is inward: the gateway contract deliberately exposes no payout or withdrawal operation, and savings can only ever leave as goods. A card refund is the first outward movement of money the platform will have. It must therefore be admin-only, never customer-triggered, capped at the original captured amount, idempotent against the original transaction, and fully audited — the same treatment the webhook gets, for the same reason.

Backend:

- Build a return request: one per order line, opened by the customer inside the return window.
- Snapshot the policy onto the request at creation (window length, who pays return delivery, exclusions), so a later policy edit cannot change a case already open.
- Build the reason taxonomy, because it decides who pays: damaged, faulty, not as described, wrong item, missing parts (platform pays) versus changed mind (customer pays, unopened only).
- Enforce the return window against `delivered_at`, not order creation.
- Enforce the exclusion list (perishables, underwear, pierced jewellery, made-to-order) except where the reason is faulty — ideally as a per-category flag on `categories` rather than a hardcoded list.
- Build the review workflow: requested → approved/rejected → in transit → received by vendor → inspected → refunded/closed.
- Build vendor inspection: the vendor confirms what came back and may contest the condition, which routes the case to admin rather than settling it.
- Add a refund operation to the gateway contract and the Paystack driver, keyed to the original transaction reference, capped at the captured amount, and idempotent.
- Reverse everything the sale set in motion: vendor earnings credited by `CreditVendorEarnings`, affiliate commission qualified by `QualifyAffiliateOrder`, promo redemption, and reward-tier contribution.
- Route refunds by how the order was paid: a card order refunds to the card; a Pay Small Small order returns value as plan credit, never cash, preserving the existing rule.
- Build a dispute path for when customer and vendor disagree, ending in an admin decision with a recorded rationale.
- Add notifications for every state change on both sides.

Frontend:

- "Report a problem" entry point on a delivered order, visible only inside the window and only on eligible lines.
- Return request form: line, quantity, reason, free text, and photo evidence.
- Return status timeline for the customer, including who pays the return delivery and why.
- Vendor return queue with an inspection outcome form.
- Admin returns dashboard: queue, case detail, refund action, and dispute resolution.
- Returns and refunds policy page, generated from the same configuration the enforcement reads, so the published policy and the enforced policy cannot drift.

Database:

- Add `return_requests`, `return_request_items`, `return_events`, `return_evidence` (photos), and `refunds`.
- Add `refunds.gateway_reference` with a unique constraint, so a retried refund cannot pay out twice.
- Add returnable/exclusion flags to `categories`, and `delivered_at` to orders if not already recorded.
- Index by customer, vendor, order, status, and opened-at date.

QA and security:

- Test a return cannot be opened after the window closes, or on an excluded category unless the reason is faulty.
- Test the refund amount can never exceed what was captured, across partial returns of a multi-line order.
- Test a replayed or retried refund pays out exactly once.
- Test only an admin with the refund permission can trigger a refund, and that a customer cannot reach it by any route.
- Test a Pay Small Small return produces credit and never a cash refund.
- Test vendor earnings, affiliate commission, promo redemption, and reward-tier totals are all reversed on a completed return.
- Test the auto-confirm window (3 days) interacting with the return window (7 days): earnings already credited must still be reversible.
- Test a customer cannot see another customer's return, and a vendor sees only returns for their own lines.

Exit criteria:

- The policy printed on the product page is the policy the system enforces, and money can be returned safely, once, and only by an authorised admin.

#### Phase 2F: Staff Roles You Can Create — NEXT

Goal: let an administrator invent a role, choose exactly what it can reach, and assign staff to it, without a developer.

Why now. Roles today are a fixed list in `RolesAndPermissionsSeeder` — Super Administrator, Administrator, Support Agent, Logistics Personnel, Finance Officer, Vendor, Customer. Permissions are already fine-grained and already enforced per route and per nav item, so the enforcement half is done; what is missing is the ability to compose a new set. Onboarding a logistics coordinator who should see dispatch and cash but not payouts currently means editing a seeder and deploying.

This is the next phase because it is an operational blocker rather than a feature: every new kind of staff member is a code change until it is fixed.

Backend:

- Make roles first-class records staff can create, rename, clone and retire, rather than seeder constants.
- Keep the seeded roles as the shipped defaults, and mark them as system roles so a mis-click cannot delete the role the platform depends on.
- Never allow a role to be deleted while staff still hold it; require reassignment first.
- Group permissions into readable sets — Catalogue, Orders, Logistics, Finance, Vendors, Support, Returns, Risk, Settings — so an admin picks capabilities rather than reading forty raw keys.
- Refuse privilege escalation: an administrator cannot grant a permission they do not themselves hold, and only a Super Administrator may grant `roles.manage`, `staff.manage`, `refunds.issue` or `settings.manage`.
- Audit every grant and revoke with actor, role, permission and time.
- Invalidate a staff member's cached permissions the moment their role changes, so a revoked capability stops working on the next request rather than the next login.

Frontend:

- Roles list with staff counts, so the cost of deleting a role is visible before the click.
- Role editor: name, description, and permissions grouped by area with select-all per group.
- "Clone this role" — most new roles are an existing one plus or minus a few capabilities.
- A preview of what the role can reach, generated from the same permission map the nav uses, so an admin can see the sidebar the role will get.
- Staff list filterable by role, with reassignment when a role is retired.

Database:

- `roles` and `permissions` already exist (Spatie). Add `is_system` and `description` to roles.
- Add a permission group/label table, or a static map, so the UI can present them in sections without hardcoding a list that drifts from the seeder.

QA and security:

- Test an administrator cannot grant themselves or anyone else a permission they lack.
- Test the reserved permissions stay Super Administrator only.
- Test a system role cannot be deleted or stripped of its identity.
- Test deleting a role with staff attached is refused.
- Test a revoked permission takes effect on the next request, not the next login.
- Test a custom role sees exactly the nav its permissions justify.

Exit criteria:

- An administrator can create a "Logistics Coordinator", tick the logistics pages, assign three staff to it, and remove it later — with no deploy and no developer.

#### Phase 2G: Real Merchandising

Goal: make the home page's promises true. Flash deals that are actually time-limited, deals that are actually discounted, trending that is actually measured, and picks that are actually personal.

Why. The home page currently renders `featuredProducts` and `newestProducts` under six different headings. "⚡ Flash Sale" has no clock and no discount, "Trending searches" is the newest products, "Top Picks for You" is the same list again, and "More to love" is filler. A shopper who notices — and they do notice when the flash deal never changes — learns that the labels mean nothing, which costs more trust than the sections earn.

Backend:

- Build campaigns: a named merchandising slot with a start and end, a set of products, and a display style (flash, super deal, spotlight).
- Enforce the clock server-side. A campaign that has ended stops rendering without anyone touching it.
- Build real deal pricing: a campaign price per product, with the original shown struck through, and a guard that refuses a "discount" above the current price.
- Cap and track stock committed to a flash deal, so "only 3 left" is a fact.
- Build trending from measured behaviour — search terms and product views over a rolling window — rather than recency. Store the counts; do not compute them per request.
- Feed "Top Picks for You" from the existing `RecommendationService`, which already explains why it chose each product.
- Keep "New Arrivals" as it is. It is the one section that already means what it says.
- Cache the home payload as now, and clear it when a campaign starts, ends or is edited.

Frontend:

- Admin campaign manager: create, schedule, add products, set deal prices, preview, and see what is live now.
- Countdown on flash sections driven by the campaign's real end time.
- Deal price with the original struck through, and the saving stated.
- Personalised picks carrying their explain-why, as on the wishlist.
- Trending list linked to the searches that produced it.
- Empty states that hide a section rather than filling it with unrelated products — an absent section is better than a dishonest one.

Database:

- `merchandising_campaigns`, `campaign_products` (with `deal_price_kobo` and `stock_committed`), `search_terms` and `product_view_counts`.
- Index by active window, and by term/product over the trending window.

QA and security:

- Test an expired campaign disappears without intervention.
- Test a deal price above the product price is refused.
- Test flash stock cannot oversell.
- Test trending reflects recorded activity, not recency.
- Test personalised picks never leak another customer's behaviour.
- Test a section with nothing to show is hidden rather than back-filled.

Exit criteria:

- Every heading on the home page is true, and staff can run a real promotion without a developer.

### Phase 3: Scale — BUILT

Goal: extend FirstMaket into new channels, new savings models, and mobile access after real usage data proves the MVP and growth features.

Status: **3A, 3B and 3C are built.** Three decisions were taken during the build that differ from the brief below, each recorded where it belongs:

1. **Tiers are earned from the record *before* the sale being priced** (3A). Otherwise one order could promote a partner and then pay itself at the new, higher rate. The rule a partner would assume — "your rate comes from your track record, the better rate applies from the next sale" — is the one implemented.
2. **A cooperative's payout is a plan, never cash** (3B). Offline ajo hands somebody the pot. That cannot exist here, so each turn's contributions land on the beneficiary's own Pay Small Small plan. The social discipline survives; the cash does not. The UI says so plainly, because somebody joining to raise emergency cash needs to know before their turn, not at it.
3. **The assistant ships on the deterministic driver** (3C). `AssistantDriverContract` exists so a hosted-model driver can be added later, but for "explain my own saving to me" arithmetic on the customer's own record beats a language model on every axis that matters: it cannot invent a figure, every sentence traces to a row, it costs nothing per question, and no financial data leaves the platform. Cost logging, spend caps and per-customer limits are built anyway, so switching drivers is a config change rather than a new project.

Two safety properties are enforced by schema rather than by convention, and both have tests: a group contribution is always tied to the `PlanPayment` it came from (a share can never be a typed number), and an assistant recommendation is inert until a separate `assistant_confirmations` row says the customer accepted it.

#### Phase 3A: Advanced Affiliate Program

Backend:

- Expand affiliate registration into a full partner program.
- Generate protected campaign links with random codes and optional HMAC/signed tokens.
- Enforce attribution windows, one valid attribution per user, and self-referral blocking.
- Track clicks, signups, verified users, first deposits, Pay At Once delivered orders, completed-plan delivered orders, and vendor recruitment conversions.
- Add commission rules for flat, percentage, tiered, and vendor-recruitment payouts.
- Add monthly payout batches with minimum threshold, Finance approval, rejection reasons, and paid status.
- Keep affiliate payouts separate from customers' plans and the no-withdrawal savings credit ledger.
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

#### Phase 3B: Group, Family, and Cooperative Savings

Backend:

- Build Group Purchase Plans where multiple customers contribute toward one product target.
- Build contribution ownership and share tracking.
- Build a Family Savings dashboard that summarises members' plans without pooling money. There are no wallets to pool: each contribution belongs to one plan, and that has to stay true inside a group.
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
- Test the family dashboard summarises without pooling: every contribution still belongs to one plan and one owner.
- Test cooperative schedules do not create withdrawal paths.

Exit criteria:

- Multi-person savings models work without breaking ownership or the no-cash-withdrawal rule.

#### Phase 3C: Full AI Financial Assistant

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

### Phase 4: Public Website — MOSTLY COMPLETE

Goal: the full public brand presence, once the product can demonstrate real workflows rather than mock-ups.

Status: **mostly built, and built differently than planned.** The marketing content lives *inside* the storefront rather than as a separate brochure site — which is the better outcome, and matches every marketplace the Sprint 0 survey looked at. A visitor lands on a working shop, not a landing page that asks them to click through to one.

**Already shipped**

| Planned page | Where it actually lives |
| --- | --- |
| Home | `/` — hero, categories, deals, and the trust strip |
| How It Works | A section on the home page |
| About / trust pillars | "Why trust FirstMaket" on the home page |
| Become a Vendor | "Sell on FirstMaket" CTA → the live vendor onboarding |
| Marketplace Preview | The real catalogue at `/catalog`, approved products only |
| FAQ | `/faq`, no login |
| Terms, Privacy, Data deletion | `/legal`, `/legal/{slug}`, `/terms`, `/privacy-policy`, `/data-deletion` — **content-managed**, so staff edit the wording in admin without a deploy |
| Public route group with no auth | Done, and enforced by tests |

That answers the "static or content-managed" question the original plan left open: the legal pages are content-managed and the rest are Inertia pages, because policy wording changes and a hero section does not.

**Genuinely outstanding**

- `robots.txt` and a generated `sitemap.xml`. Neither file exists.
- Per-page SEO metadata. The document head is shared; only the title varies.
- A contact form. There is a support hotline, WhatsApp, the Support Centre and now the Complaint Centre — a contact form may simply be unnecessary, and is worth a decision rather than a default.
- A standalone About page, if the home-page section is judged not enough.

**One correction to the content brief below:** it says to explain "Pay At Once, Open Savings, and Product Target Plans". Open Savings no longer exists and Product Target Plans are called **Pay Small Small** in the product. Marketing copy should use the two names customers actually see.

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

### Phase 5: Native Mobile Applications — FUTURE

Deliberately last of the product phases, and after the public website.

The web app is the product; a mobile client is a second front end onto the same rules. Every phase before this one adds or changes those rules — returns, roles, merchandising, agents, cooperative savings — and each change would otherwise have to be made twice and shipped through an app-store review. Building the apps once the rules have settled means one implementation to port, not a moving target to chase.

Two things are already in place for it: `/api/v1` is reserved (see Architecture), and the modules keep business logic in services rather than controllers, so an API surface is a new entry point rather than a rewrite.

Backend:

- Harden Sanctum token mode for mobile.
- Expose API endpoints needed by mobile without duplicating business logic.
- Add mobile push notification device tokens.
- Add mobile versioning and forced-update settings.

Frontend/mobile:

- Build Android and iOS apps consuming the Laravel API.
- Implement authentication, dashboard, catalogue, plans, tracker, orders, returns, notifications, and support.
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

### Phase 6: Agent Network — FUTURE

Moved out of Phase 3 and deferred, because the version written there cannot be built.

It assumed agents would take **deposits** and earn commission per deposit, with a test that "an agent cannot directly credit wallet without verified Paystack flow". There is no wallet to credit and no deposit to take. Rewriting it as agent-assisted *plan payments* is possible, but it is a different feature with different risks, and it should not be attempted until the phases before it have settled.

What it would have to become:

- An agent helps a customer **start a plan or pay an instalment**, never "deposit". Money still enters against one specific plan and nothing else.
- The charge still goes through Paystack and is still credited by the verified webhook. An agent is a person standing next to the customer, not a new way for money to enter the system.
- Commission is earned on a **qualifying plan payment**, and paid from the affiliate/partner ledger — never from a customer's plan, and never as a balance the agent can hold.
- Agent codes are attribution, exactly like affiliate links, and grant no permission over the customer's account.

Why it is deferred rather than dropped. Community-assisted acquisition genuinely suits how many Nigerians already save, and the ajo/esusu collector is the model Pay Small Small is named after. But it introduces a person handling somebody else's money in a system whose entire safety argument is that money only moves through verified Paystack charges. That is worth doing carefully and late, after roles (2F) exist to scope what an agent can see, and after the returns and refund paths have run in production long enough to trust.

Open questions to settle before building it:

- Does the agent ever handle cash? If yes, this needs a float and reconciliation model closer to the existing cash-on-delivery flow than to affiliates, and that is a much larger piece of work.
- What can an agent see of a customer they signed up? The default should be nothing beyond their own attribution.
- How is an agent's commission clawed back when the plan they started is cancelled or refunded?

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
    Savings/
    Cart/
    Orders/
    Returns/
    Logistics/
    Payments/
    Notifications/
    Support/
    Admin/
    Reporting/
    AI/
    Risk/
    Affiliates/
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

- There is no customer balance. A payment names what it is for when it is created — a plan instalment, an order, or a shipment's goods balance — and the signature-verified Paystack webhook credits that one thing.
- A verified webhook is the only event that credits anything. A browser callback never moves money.
- No withdrawal or deposit endpoint may exist. An architecture test fails the build if a route URI contains `withdraw`, `deposit`, `cash-out`, `transfer-out` or `add-money`.
- Money leaves in exactly three ways: as goods, as plan credit when a plan is cancelled, or as a refund reversing the original charge. A refund is admin-only, capped at what was captured, and idempotent on a unique reference.
- All money values use integer kobo or `DECIMAL(15,2)` consistently; do not use floats.
- Product target price is locked at plan creation.
- Vendor price edits after approval return the product to pending approval.
- Vendors never see customer identity or delivery address.
- Vendor earnings live in a separate vendor ledger; commission is deducted at delivery confirmation, and payouts go only to verified vendor bank accounts through Finance-approved batches.
- Vendor earnings are credited exactly once per delivered order, inside a database transaction, and never before the delivery confirmation window passes.
- Registration accepts email or phone; the verification OTP travels through the matching channel, and phone verification is mandatory before any payment.
- Social login (Google/Facebook) links to an existing account only after ownership proof; it never silently merges or duplicates accounts.
- Admin roles must be permission-based, not hard-coded role checks.
- All ledger-affecting writes must run inside database transactions.
- Affiliate commission is a separate partner payout record, never taken from a customer's plan or savings ledger. A returned order reverses the commission with it.
- Affiliate links must use random non-sequential codes or signed tokens, never database IDs or personal data.
- All sensitive identity fields must be encrypted at application level.
- OTP requests must be rate-limited by phone number, IP, and device fingerprint.
- Recommendations and risk flags that touch money, product approval, fraud or account access are advisory only; a human decides. Nothing is suspended, cancelled or refunded because a rule fired.
- Operational thresholds live in the settings table with an admin screen, not in constants. A value staff cannot change is a deploy waiting to happen — and a setting nothing reads is worse, because it looks like it works.
- Anything the storefront promises the system must enforce, from the same value. The returns window is printed and enforced from one setting for exactly this reason.
- Third-party scripts are embedded by naming a known provider and an id, never by storing a snippet. Customers enter card details on these pages.
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
| Address lookup | Google Maps Places API | Not integrated. Addresses are typed and validated against the state/LGA list |
| AI | OpenAI or Anthropic | Listing review only. The savings assistant and recommendations are deterministic rules, not a model — see Phase 2C |
| Live chat | Tawk.to or Crisp | Storefront chat widget, selected in admin by provider name and id |
| Browser push | Web Push API or OneSignal | **Not integrated.** Notifications are mail, SMS and in-app only |

## 6. MVP Success Metrics

| Area | Target |
| --- | --- |
| Payment confirmation | 100% webhook-verified; no browser callback ever moves money |
| Ledger integrity | No plan payment without a matching verified transaction; no refund paid twice |
| Returns | Every case resolved inside the published window, with the reason recorded |
| Dashboard response | Under 500 ms for normal account load |
| Plan creation | Under 90 seconds for a verified customer |
| Vendor listing review | Admin can approve or reject in under 2 minutes |
| Delivery status | Customer notified on every status change |
| Public website | Shipped as part of the storefront; SEO polish outstanding |
