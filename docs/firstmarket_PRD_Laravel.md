# FirstMarket PRD Laravel

Version: 1.0  
Product type: Goal-based commerce, direct purchase, and savings marketplace

## 1. Product Summary

FirstMarket lets customers either save toward products over time or pay the full product price at once. It is not a loan, bank, BNPL product, or cash-withdrawal wallet. Customers fund a deposit-only wallet through Paystack, allocate funds to Open Savings, Product Target Plans, or Pay At Once checkout, and receive products after the full target price is paid.

## 2. Core Promise

Pay small small, collect with peace of mind.

The platform should make product ownership feel planned, transparent, and safe:

- Customers know exactly what they are buying, whether they pay at once or save gradually.
- Vendors list products and set prices.
- FirstMarket controls approval, customer relationship, payment tracking, and delivery.
- Vendors never receive customer identity or delivery details.

## 3. User Roles

| Role | Purpose |
| --- | --- |
| Customer | Saves toward products and tracks orders |
| Vendor | Lists products and sees own catalog/earnings |
| Super Administrator | Full platform control |
| Administrator | Approves vendors/products and manages operations |
| Support Agent | Handles support and hotline issues |
| Logistics Personnel | Updates delivery status |
| Finance Officer | Reconciles Paystack settlements and wallet ledger |

## 4. Product Surfaces

- Public website
- Customer web app
- Vendor web portal
- Admin dashboard
- Support dashboard
- Logistics dashboard
- Finance reconciliation dashboard

## 5. Phase 1 MVP Modules

### Authentication and Identity

- Customer registration
- Vendor registration
- OTP phone verification
- Email verification
- BVN check through Paystack Identity Verification
- NIN check through a dedicated Nigerian identity provider such as Youverify, Smile Identity, or Prembly
- Login alerts
- Password reset

Product Target Plans require successful BVN and NIN verification. Open Savings can begin earlier to reduce signup friction.

Functional requirements:

- OTP codes expire after a configurable window, default 10 minutes.
- Every login records device and location metadata.
- New-device logins notify the customer by email.

### Customer Dashboard

- Open Savings balance
- Active plans
- Total saved
- Orders
- Product Tracker summary
- Notifications

Requirements:

- Dashboard figures come from live Wallet and Savings data, not a daily snapshot.
- Active plans paginate after five plans.

### Product Catalog

- Categories: Electronics, Home Appliances, Solar Equipment, Furniture, Fashion, and Business Equipment
- Search
- Price range filters
- Vendor rating filter when ratings launch
- Approved products only

Requirements:

- Customer catalog queries exclude all products not in Approved status.
- Sorting supports price, popularity, and newest listing.

### Savings Engine

Open Savings:

- One balance per customer.
- No target product.
- Can be redirected into a Product Target Plan.
- Cannot be withdrawn as cash.

Product Target Plan:

- One product target.
- Locked target price.
- Schedule mode: daily, weekly, monthly.
- Pay-at-once mode.
- Status: active, completed, ready for delivery.

Pay At Once / direct full purchase:

- Customer selects an approved product.
- Customer pays the full locked product price in one transaction.
- System may represent this internally as a Pay At Once Product Target Plan or a direct order checkout, but the user experience should feel like a normal full purchase.
- Once Paystack confirms full payment, the item moves to Ready for Delivery / order creation.

Requirements:

- A customer has exactly one Open Savings balance and can have multiple Product Target Plans.
- A Product Target Plan or Pay At Once purchase moves to Ready for Delivery when paid amount reaches 100% of target price.
- Expected completion date recalculates after each contribution using the customer's actual average contribution rate over the last three cycles.

### Wallet and Paystack

- Add money by card, bank transfer, or USSD through Paystack.
- Confirm through webhook.
- Immutable transaction ledger.
- Receipt number per successful transaction.
- Transaction history, receipt download, receipt email, and PDF export.
- Allocate a deposit to Open Savings or a Product Target Plan.
- Pay full product price at once through Pay At Once checkout.
- Store reusable Paystack authorization for Phase 2 scheduled automatic debit.
- No withdrawal feature.

### Product Tracker and Redirection

- Amount saved
- Remaining balance
- Progress percentage
- Expected completion date
- Redirect Open Savings into plan
- Switch active plan to a different product
- Audit every redirection

Requirements:

- Redirection is allowed only while the plan is Active.
- Redirection carries the full existing balance to the new target.
- If the carried balance covers the new target, the plan becomes Ready for Delivery immediately.

### Orders and Delivery

- Created from ready-for-delivery plan.
- Customer submits delivery address only after fully paid.
- Administrator confirms order.
- Vendor prepares item.
- FirstMarket logistics handles delivery.
- Customer receives status notifications.

Delivery statuses:

- Pending
- Processing
- Packed
- Shipped
- Out for Delivery
- Delivered

### Notifications

- Payment reminders
- Plan completion alerts
- Delivery updates
- New product alerts
- Email, SMS, and browser notifications

### Support

- FAQ
- WhatsApp link
- Hotline logging
- IVR call routing by reason: payment issue, delivery issue, or general inquiry
- Support tickets
- Escalation path: chatbot, live chat, hotline call center, complaint ticket
- Support agent customer lookup without payment credentials

### Vendor Portal

- Registration and onboarding
- CAC document upload
- Product creation
- Product status tracking
- Own listings
- Earnings summary
- Support

Vendor cannot view customer identity.

### Admin Dashboard

- User management
- Vendor approval
- Product approval queue
- AI listing flags
- Payment and settlement overview
- Order management
- Delivery management
- Vendor fee settings
- Reports
- Role and permission management for Super Administrator

Vendor fee settings:

- Posting mode can be Free or Paid.
- Paid tiers include Basic, Premium, and Featured.
- Suggested initial fees from the SRS: Basic NGN 500, Premium NGN 2,000, Featured NGN 5,000.
- Fee changes apply only to new posts, not already pending posts.

### Artificial Intelligence Services

Phase 1 baseline:

- Listing Review Assistant checks image quality, blur, product-image match, description completeness, prohibited or spammy content, category mismatch, and price outliers.
- Listings without AI flags appear in a fast-approval queue.
- Flagged listings show the specific human-readable reason to the Administrator.

Phase 2 and later:

- Customer Support Chatbot
- Savings Assistant
- Product Recommendation Engine
- Fraud and Risk Scoring
- Demand Forecasting
- Full AI Financial Assistant

AI rules:

- AI outputs are advisory only.
- Human Administrators make final decisions for product approval, fraud, account access, and money-affecting actions.
- Price-outlier thresholds are configurable by Super Administrator without code changes.
- AI recommendation, decision, and final human outcome are logged for audit.

## 6. Phase 2 Growth

- Wishlist with side-by-side product comparison and price-drop notifications
- Rewards and badge tiers: Bronze, Silver, Gold, Platinum Saver
- Single-level referral program, never multi-level
- Basic affiliate tracking: protected links, click tracking, signup attribution, delivered-order conversion tracking
- Multi-language interface: English, Hausa, French, Arabic
- Dark mode
- Scheduled automatic debit
- Pause and resume Product Target Plans without unlocking money or changing target price
- Live chat
- Customer support chatbot
- AI savings assistant based on actual payment history
- Product recommendation engine based on wishlist and savings behavior
- Vendor rating tiers
- Fraud/risk flags
- Demand forecasting for products trending toward completion

Referral rule:

- Referral reward is credited only when the referred customer's first Product Target Plan reaches Completed status, never at signup.

Affiliate baseline rule:

- Affiliate tracking may begin in Phase 2, but affiliate commission should not be paid for clicks or signup alone.
- Customer affiliate commission qualifies only after a referred customer's Pay At Once order is delivered or a referred customer's completed Product Target Plan order is delivered.
- Vendor affiliate commission qualifies only after a referred vendor is approved and has at least one approved product.
- Affiliate commission is a controlled partner payout, not a customer wallet withdrawal.
- Finance must review and approve affiliate payouts.
- Affiliate links must use protected random codes or signed tracking tokens, never database IDs, email addresses, or sensitive data.

Pause/resume rule:

- Pausing stops reminders and automatic debit only; it does not change locked target price, saved amount, or no-withdrawal policy.

## 7. Phase 3 Scale

- Agent network
- Advanced affiliate program
- Group purchase
- Family savings
- Cooperative savings
- Full AI financial assistant
- Native mobile apps

Phase 3 requirements:

- Agent network supports in-person signup and deposit collection with agent codes and commission tracking.
- Affiliate program supports protected tracked links, conversions, tiered commission, monthly payout review, fraud checks, and finance-approved partner payouts configured by Super Administrator.
- Group purchase allows multiple customers to contribute toward one shared product target.
- Family savings gives a household dashboard without pooling underlying wallets.
- Cooperative savings supports structured rotating-contribution models.
- Native mobile apps reuse the Laravel API rather than requiring a second backend.

## 8. Phase 4 Public Website

The public website should be completed after the transactional platform, so the marketing content can reflect the real product rather than planned screens.

Pages:

- Home
- About Us
- How It Works
- Marketplace Preview
- Become a Vendor
- Contact Us
- FAQ
- Terms of Service
- Privacy Policy

Requirements:

- Public pages require no login.
- Pages are SEO-indexable.
- Marketplace Preview is read-only or connected to approved live catalog data.
- Become a Vendor links to the live vendor onboarding flow.
- Copy clearly states there are no loans and no cash withdrawal.

## 9. Non-Functional Requirements

Security:

- Encrypt BVN, NIN, phone number, address, identity provider payloads, and other sensitive personal data where required.
- Never write sensitive identity values to plaintext logs.
- Enforce RBAC on backend.
- Verify Paystack webhooks.
- Audit all money and status changes.
- Protect affiliate links from enumeration, tampering, open redirects, and self-referral abuse.
- Keep affiliate payouts separate from the customer wallet no-withdrawal policy.

Performance:

- Customer dashboard under 500 ms normal load.
- Catalog search under 700 ms normal load.
- Webhook response under 2 seconds after signature verification and queue dispatch.

Availability:

- Payment, ledger, and delivery flows receive priority monitoring.
- Queue jobs retry safely and idempotently.

Localization:

- Naira formatting.
- Nigerian states/LGAs.
- Translation-ready UI strings for future multi-language support.

## 10. Pages By Role

Customer web application:

- Registration and Login
- Dashboard
- Product Catalog and Search
- Product Details
- Start a Plan or Pay At Once
- Wallet
- Transactions
- Receipts
- Product Tracker
- Orders
- Delivery Tracking
- Notifications
- Support Center
- Profile and Settings
- Phase 2: Wishlist, Rewards and Badges, Referral, Live Chat

Vendor web portal:

- Registration and Onboarding
- Vendor Dashboard
- Add or Edit Product Listing
- Listings Pending Approval
- Approved Listings
- Earnings
- Support
- Phase 2: Ratings

Administrator dashboard:

- Login with role-based landing page
- Overview and Analytics
- User Management
- Vendor Management and Approvals
- Product Approval Queue with AI flags
- Payment Confirmation
- Order Management
- Delivery Status Management
- Vendor Fee Settings
- Reports
- Super Administrator: Role and Permission Management, Platform-Wide Settings

Specialized staff views:

- Support Agent: Hotline and Chat Ticket Queue, read-only Customer Order and Plan Lookup
- Logistics Personnel: Delivery Status Update screen only
- Finance Officer: Paystack Settlement Reconciliation
- Finance Officer: Affiliate Commission Payout Review

Affiliate dashboard:

- Affiliate code and protected links
- Clicks, signups, verified customers, first deposits, Pay At Once purchases, completed plans, delivered orders
- Pending, approved, rejected, and paid commission
- Payout history

Affiliate admin dashboard:

- Affiliate applications
- Affiliate approval/suspension
- Protected link/campaign management
- Conversion review
- Commission settings
- Monthly payout queue
- Fraud flags
- Top affiliates and revenue generated

## 11. Competitive Positioning

FirstMarket should borrow proven marketplace patterns while preserving its unique savings model:

- Jumia: marketplace plus first-party payment and logistics control.
- AliExpress: payment protection before product release; FirstMarket applies this more strongly through pay-before-release savings.
- CDK Global: avoid disconnected tools by keeping catalog, payment, delivery, support, fraud, and audit workflows unified.

FirstMarket stands out through goal-based product savings, vendor pricing freedom, AI-assisted moderation, and free customer support including hotline access.

## 12. Recommended Laravel Architecture

Use:

- Laravel modular monolith
- Inertia.js
- React + TypeScript
- PostgreSQL
- Redis
- Spatie Permission
- Laravel Sanctum
- Laravel notifications
- Queued jobs for provider calls

The architecture should support a future mobile app by keeping business logic in Laravel services/actions and exposing API endpoints where needed.
