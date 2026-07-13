# FirstMarket Developer Guidelines

Version: 1.0

## 1. Recommended Stack Decision

### PostgreSQL vs MySQL

Recommendation: PostgreSQL.

FirstMarket is a wallet, savings, order, and vendor marketplace platform. The most important technical risk is money integrity, not page rendering. PostgreSQL gives stronger tools for constraints, financial ledgers, reporting, and complex transactional workflows.

Use MySQL only if team familiarity and hosting constraints are more important than PostgreSQL's richer constraint and reporting features.

### React vs Vue

Recommendation: React with TypeScript.

Reasons:

.
- The ecosystem is very strong for dashboards, tables, charts, admin UIs, and future mobile reuse through React Native.
- shadcn-style component architecture fits operational dashboards well.

Vue is also a good Laravel/Inertia choice and is slightly easier to learn for many teams. Choose Vue only if the development team is clearly more comfortable with Vue than React.

Note: the original SRS proposed Inertia with Vue 3. After reviewing the IHMS project, the stronger local recommendation is React because the existing IHMS conventions, component structure, and team reference project already use React + Inertia.

Final recommendation: Laravel + Inertia + React + TypeScript + PostgreSQL + Redis.

## 2. Golden Rules

- No withdrawal endpoint exists in the backend.
- Never credit wallet balance from the browser callback. Credit only after verified Paystack webhook.
- Every ledger-affecting action runs inside a database transaction.
- Never use floating-point money calculations.
- Product target price is locked when a plan is created.
- Vendor identity and customer identity are separated by policy and query design.
- Vendors never see customer names, phone numbers, addresses, or plan history.
- Admin abilities are permission-based, not role-name checks.
- Sensitive identity values are encrypted at rest.
- All state changes on plans, listings, orders, and vendor accounts are audited.

## 3. Backend Structure

Use domain modules:

```text
app/Modules/{Domain}/
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

Shared code:

```text
app/Shared/
  Casts/
  Contracts/
  Enums/
  Middleware/
  Scopes/
  Security/
  Services/
  Traits/
```

Recommended modules:

- Auth
- Identity
- Customer
- Vendor
- Catalog
- Wallet
- Savings
- Payments
- Orders
- Logistics
- Notifications
- Support
- Admin
- Reporting
- AI
- Risk
- Rewards
- Referrals

## 4. Model Standards

Each model should define:

- Fillable fields
- Casts, including enum casts
- Relationships
- Policies
- Scopes for common filters
- UUID public identifier where exposed in URLs

Money models must include:

- Balance before
- Amount
- Balance after
- Reference
- Actor
- Metadata

## 5. Controller Standards

Controllers should stay thin:

- Validate through Form Requests.
- Authorize through policies.
- Delegate business logic to Actions or Services.
- Return Inertia pages or JSON resources.
- Avoid placing ledger logic directly in controllers.

## 6. Action Classes

Use action classes for business workflows:

- `CreateProductTargetPlan`
- `ApplyPaystackDeposit`
- `RedirectPlanBalance`
- `ApproveVendor`
- `ApproveProductListing`
- `ConfirmReadyOrder`
- `UpdateDeliveryStatus`

Action classes should be easy to test and should own transaction boundaries when they mutate multiple tables.

## 7. Frontend Structure

```text
resources/js/
  Components/
    ui/
    common/
    forms/
    feedback/
    domain/
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

Guidelines:

- Use TypeScript for all pages and components.
- Keep reusable controls in `Components`.
- Keep route-level screens in `Pages`.
- Keep money formatting in one utility.
- Keep role navigation separate by layout.
- Use server-provided permissions to render actions.

## 8. Permissions

Core permission groups:

- `customers.view`
- `customers.suspend`
- `vendors.view`
- `vendors.approve`
- `vendors.suspend`
- `products.approve`
- `wallet.view`
- `wallet.reconcile`
- `plans.view`
- `orders.manage`
- `delivery.update`
- `support.manage`
- `finance.reconcile`
- `vendor_fees.manage`
- `ai_settings.manage`
- `settings.manage`
- `roles.manage`

Never infer authorization from the frontend alone.

## 9. Testing Standards

Backend:

- Unit tests for savings calculations.
- Feature tests for Paystack webhook verification.
- Feature tests proving no withdrawal route exists.
- Policy tests for vendor/customer/admin data access.
- Ledger tests for balance integrity.

Frontend:

- Component tests for forms, plan tracker, product cards, admin approval queue.
- Playwright tests for registration, deposit simulation, plan creation, product approval, order flow.

Critical architecture tests:

- Vendors cannot query customer data.
- Unapproved products never appear in customer catalog.
- Payment cannot credit wallet without valid webhook.
- Plan target price does not change after vendor price edit.
- Vendor price edit after approval returns listing to Pending Approval.
- Vendor suspension unlists all approved products.
- User suspension revokes active sessions.
- Product Target Plan cannot activate without required BVN/NIN verification.
- Pausing a plan stops reminders and automatic debit without changing target price or saved amount.
- Referral reward is credited only after the referred customer's first plan is completed.

## 10. Role Page Map

Customer:

- Registration and Login
- Dashboard
- Product Catalog and Search
- Product Details
- Start a Plan
- Wallet
- Transactions and Receipts
- Product Tracker
- Orders and Delivery Tracking
- Notifications
- Support Center
- Profile and Settings

Vendor:

- Registration and Onboarding
- Vendor Dashboard
- Add or Edit Product Listing
- Listings Pending Approval
- Approved Listings
- Earnings
- Support

Administrator:

- Overview and Analytics
- User Management
- Vendor Management and Approvals
- Product Approval Queue with AI flags
- Payment Confirmation
- Order Management
- Delivery Status Management
- Vendor Fee Settings
- Reports

Specialized roles:

- Support Agent: Hotline and Chat Ticket Queue, Customer Order and Plan Lookup.
- Logistics Personnel: Delivery Status Update screen only.
- Finance Officer: Paystack Settlement Reconciliation.
- Super Administrator: Role and Permission Management, Platform-Wide Settings.

## 11. Git Workflow

```text
main <- develop <- feature/{module}-{feature}
               <- fix/{module}-{bug}
```

Commit format:

- `feat: add product target plan creation`
- `fix: prevent duplicate Paystack webhook credit`
- `test: cover wallet ledger balance updates`
- `docs: update deployment runbook`

PR requirements:

- Tests pass.
- Migration is reversible or explicitly documented.
- Money/state changes include tests.
- Security-sensitive changes receive review.
