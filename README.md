# FirstMarket

Pay small small, collect with peace of mind.

FirstMarket is a goal-based commerce platform for customers who want to save toward products and collect only after payment is complete. It is not a loan app, bank, BNPL product, or cash-withdrawal wallet. Customers fund a deposit-only wallet through Paystack, allocate money to Open Savings or Product Target Plans, and receive products after the target price is fully paid.

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
| 5 | Savings engine | Open Savings, Product Target Plans, contribution logic, target locking, progress tracking, redirection |
| 6 | Orders and logistics | Ready-for-delivery orders, address capture, admin confirmation, vendor preparation, delivery tracking |
| 7 | Support and notifications | Preferences, email/SMS/browser notifications, support tickets, hotline logs, IVR routing |
| 8 | AI/reporting/controls | Listing review assistant, reports, vendor suspension, user suspension, operational dashboards |
| 9 | MVP hardening and pilot launch | Security review, ledger tests, Paystack replay tests, E2E flows, production rehearsal |
| 10 | Growth | Wishlist, rewards, referrals, automatic debit, pause/resume, live chat, AI assistance, risk dashboards |
| 11 | Scale | Agent network, affiliates, group/family/cooperative savings, full AI assistant, mobile apps |
| 12 | Public website | Marketing website using the completed product, real workflows, vendor CTA, SEO, public launch |

## Core Rules

- No withdrawal endpoint exists anywhere in the backend.
- Wallet balance is credited only after a verified Paystack webhook.
- Every ledger-affecting write uses a database transaction.
- Product target price is locked when a plan is created.
- Vendors never see customer identity or delivery details.
- Admin access is permission-based, not hard-coded by role name.
- Sensitive identity fields are encrypted at rest.
- All money, plan, listing, vendor, and order state changes are audited.

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

This repository currently contains the planning documentation for the FirstMarket build. The Laravel application scaffold should be created after these documents are reviewed and approved.
