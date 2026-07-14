# FirstMarket Security and Compliance

Version: 1.0

## 1. Security Position

FirstMarket handles identity data, customer savings records, payment references, vendor documents, and delivery addresses. Even though it is not a bank, lender, or BNPL product, it must be engineered with financial-grade care because customers trust the platform with paid-in funds and identity documents.

## 2. Compliance Context

Primary Nigerian considerations:

- Nigeria Data Protection Act 2023
- NDPR principles
- Consumer protection expectations
- Standard KYC/identity verification practices for BVN and NIN
- Payment-provider compliance through Paystack

FirstMarket should avoid language and behavior that makes it look like a lender, deposit-taking bank, or investment product.

### 2.1 Regulatory Classification Sign-Off (pre-build gate)

A deposit-only wallet that holds customer pre-payment for future product delivery, marketed with "savings" language, sits close to the CBN regulatory perimeter (money transmission, deposit-taking optics) even without interest or lending. This risk is currently only asserted in product copy, not verified.

Before Sprint 1 starts:

- Obtain a written opinion from a Nigerian fintech/payments lawyer confirming the wallet + Product Target Plan + Pay At Once model does not require a CBN license (PSP, PSSP, MMO, or similar) as designed.
- Confirm the "no withdrawal, redirection only" model and the affiliate/customer-fund separation are sufficient to keep FirstMarket outside deposit-taking classification.
- Revisit this opinion before Phase 2 (automatic debit) and Phase 3 (agent network, cooperative savings) launch, since each expands the regulatory surface.

### 2.2 Fund Safeguarding

- Customer funds held between deposit and product delivery must sit in a settlement account that is operationally separate from FirstMarket's operating/expense account, so a cash-flow event at the company cannot strand customer balances.
- Document, before launch, whether Paystack settlement flows directly into a segregated account or into a shared account with internal sub-ledger separation, and reconcile the internal wallet ledger against that account's real balance as part of the finance reconciliation job (Section 7).
- The wallet ledger sum (`SUM(wallet_transactions.direction = credit) - SUM(debit)`) must reconcile to actual settled funds at all times; alert Finance if it drifts.

### 2.3 Data Subject Rights (NDPA)

- Define a data retention schedule per data category (identity documents, OTP codes, login events, audit logs, support transcripts) and a scheduled purge/anonymization job for data past its retention window, excluding records legally required for financial audit trails.
- Build an account-deletion/export request workflow reachable from Support: a customer can request their data export or erasure; erasure anonymizes PII on the user record while preserving the immutable ledger and audit trail under a pseudonymous actor reference.
- Log every data subject request (type, requester, actioned by, timestamp) for compliance evidence.

## 3. Authentication

Requirements:

- Strong password policy.
- OTP verification for phone number.
- Email verification.
- Login alerts for new device or location.
- Rate limits on login, OTP, password reset, and identity verification.
- Session invalidation after password change or account suspension.

Required controls:

- Admin 2FA is mandatory, not optional, before production launch — Administrator, Finance Officer, and Super Administrator accounts cannot be created or remain active without 2FA enrolled.
- 2FA enrollment is enforced at first login for these roles; the account is restricted to a setup-only screen until 2FA is configured.
- Support offline device-loss recovery through Super Administrator-mediated reset only, itself audited.

## 4. Authorization

Use permission-based authorization.

Rules:

- Customers access only their own plans, wallet, orders, tickets, and profile.
- Vendors access only their own vendor profile and products.
- Vendors never access customer identity.
- Support agents can view customer support context but not payment credentials.
- Logistics personnel can update delivery status only.
- Finance officers can view reconciliation and ledger data but cannot suspend users.
- Only Super Administrators manage roles and permissions.

## 5. Data Protection

Encrypt at application level:

- BVN
- NIN
- Phone number
- CAC document references
- Delivery address
- Identity provider payloads where sensitive

Sensitive values must never appear in plaintext application logs.

Protect files:

- Product images may be public after approval.
- CAC documents must be private.
- Identity documents must be private.
- Signed URLs should expire.

File upload security (all uploads: product images, CAC documents, identity documents, support attachments):

- Validate MIME type by file content inspection, not just the extension or client-supplied `Content-Type`.
- Enforce a maximum file size per upload type.
- Scan uploaded files for malware before they are readable by any other user (ClamAV or a hosted scanning service in the upload pipeline).
- Strip EXIF/metadata from images before storage.
- Store uploads under randomized, non-guessable paths regardless of bucket visibility; never derive the storage path from a sequential ID.

## 6. Payment Security

Paystack rules:

- Never trust client-side payment success alone.
- Verify webhook signature.
- Ensure webhook idempotency.
- Store Paystack reference uniquely.
- Reject duplicate credit attempts.
- Log webhook payload hash and verification time.

No-withdrawal rule:

- No withdrawal route.
- No cash refund workflow.
- Redirection to another product plan is the only allowed movement out of Open Savings or a plan.
- Affiliate payout is not customer wallet withdrawal. It is a separate partner payout controlled by Finance.
- Affiliate commissions must never be credited into, paid from, or mixed with customer wallet balances.

## 7. Ledger Integrity

Every wallet transaction must be immutable.

Required fields:

- Amount
- Direction
- Balance before
- Balance after
- Reference
- Receipt number
- Actor/system source
- Metadata
- Timestamp

Use transactions and row locks for:

- Paystack deposit credit
- Plan contribution
- Open Savings redirection
- Plan-to-plan redirection
- Admin adjustment, if ever allowed
- Affiliate commission approval
- Affiliate payout batch finalization

## 8. Audit Logging

Audit:

- Login events
- Identity verification attempts
- Vendor approval/rejection
- Product approval/rejection
- Product price changes
- Wallet credits
- Plan contributions
- Redirections
- Order status changes
- Delivery assignments
- User suspension/ban
- Permission changes
- Affiliate approval/suspension
- Affiliate conversion approval/rejection
- Affiliate payout approval/payment

Audit records should include:

- Actor
- Subject
- Action
- Old values
- New values
- IP address
- User agent
- Timestamp

## 9. Fraud And Risk Controls

Phase 1 baseline:

- OTP attempt limits
- OTP request limits per phone number and time window
- Paystack duplicate reference detection
- New device login alerts
- Admin review of suspicious vendor listings
- OTP/SMS cost-abuse controls: rate limit OTP requests per IP and per device fingerprint in addition to per phone number, since per-phone limits alone do not stop scripted requests across many numbers; set a daily SMS spend budget with an alert when the provider's usage approaches it; add a CAPTCHA or equivalent challenge on registration/OTP endpoints after repeated requests from the same IP.

Phase 2:

- Multiple failed OTP attempts in a short window
- Large deposit from new device flag
- Rapid plan switching flag
- Vendor rejection-rate spike
- Multiple failed payment attempts
- Unusual support/dispute volume

AI and risk outputs are advisory only. A human Administrator must make the final decision for product approvals, fraud review, account restrictions, or any action affecting money.

Risk flags should not automatically punish users. They should route to admin review.

## 10. Affiliate Security Controls

Affiliate payout rules:

- Use the terms Affiliate Commission or Partner Payout, not customer cashout.
- Customer wallet must not have a cashout or withdrawal path because of affiliate features.
- Store affiliate commissions in a separate commission ledger.
- Require Finance review before any affiliate commission becomes payable.
- Pay affiliates through controlled scheduled payout batches, preferably monthly.
- Enforce minimum payout threshold before payout batching.
- Require verified affiliate bank account details before payout.
- Use explicit statuses: pending, approved, payable, rejected, paid.
- Suspended affiliates cannot create links, receive new attribution, or receive payout.

Affiliate link protection:

- Use random non-sequential codes or signed/HMAC tracking tokens.
- Never place database IDs, email, phone, BVN, NIN, wallet references, or personal data in affiliate URLs.
- Validate every affiliate link server-side before attribution.
- Stop tracking immediately for suspended or expired links.
- Rate-limit clicks and signup attempts from the same IP, device, or user agent pattern.
- Detect suspicious bot traffic and repeated self-clicking.
- Prevent open redirects by allowing only approved internal landing paths.
- Store attribution cookies as HttpOnly, Secure, SameSite=Lax, with a clear expiry such as 30 days.
- A click must never grant permissions, wallet balance, product access, or commission by itself.
- Allow only one valid affiliate attribution per customer unless an administrator explicitly resolves a dispute.
- Block self-referral and flag repeated phone, email, BVN, NIN, or device reuse across related accounts.

## 11. Security Headers

Set:

- `Strict-Transport-Security`
- `X-Frame-Options`
- `X-Content-Type-Options`
- `Referrer-Policy`
- `Content-Security-Policy`
- Secure, HTTP-only, SameSite cookies

### 11.1 Admin Surface Isolation

- Serve the Admin, Support, Logistics, and Finance dashboards from an isolated subdomain (`admin.firstmarket.ng`), not a path (`/admin`) on the same origin as the customer app. A path-based split shares cookies and origin with the highest-traffic, most public surface, so any XSS in the customer app has a direct path to admin sessions.
- Scope admin session cookies to the admin subdomain only (`Domain=admin.firstmarket.ng`, not the parent domain).
- Apply a stricter `Content-Security-Policy` on the admin subdomain than on public/customer surfaces.
- Consider IP allowlisting or VPN-gating the admin subdomain for Super Administrator routes once operational usage patterns are known.

## 12. Secrets Management And Key Rotation

- Use a secrets manager (AWS Secrets Manager, HashiCorp Vault, or the hosting provider's equivalent) for production secrets rather than relying solely on a server-side `.env` file. `.env` remains acceptable for local/staging but production secrets should be injected at deploy/runtime from the manager.
- Rotate `PAYSTACK_SECRET_KEY`, `PAYSTACK_WEBHOOK_SECRET`, `AFFILIATE_TRACKING_SIGNING_KEY`, BVN/NIN provider keys, and SMS/mail provider keys on a defined schedule (at minimum annually, immediately on suspected compromise, and on staff offboarding for anyone with access).
- Rotate `APP_KEY` only with a documented plan for re-encrypting existing encrypted-at-rest fields (BVN, NIN, CAC references, addresses), since naive rotation breaks decryption of existing rows.
- Never let staging or local environments share production secrets, including provider API keys and the encryption key.

## 13. Independent Security Review

- Before the MVP pilot launch (end of Sprint 9), commission a third-party security review or penetration test covering authentication, authorization boundaries (vendor/customer isolation, admin permissions), the Paystack webhook flow, and file upload handling. Internal review alone is insufficient for a platform holding customer prepayments.
- Re-run a focused external review before Phase 2 (automatic debit expands the payment attack surface) and before Phase 3 (agent network and cooperative savings introduce new money-movement paths).
- Track findings to closure before each go-live; do not launch with open critical/high findings.

## 14. Backup And Disaster Recovery

Backups must be encrypted.

Required:

- Daily database backup.
- Pre-deploy database backup.
- Uploaded document backup.
- Restore test at least monthly.
- Access to backups limited to Super Administrator and infrastructure lead.

## 15. Production Checklist

- `APP_DEBUG=false`
- HTTPS enforced
- Secure cookies enabled
- Real Paystack webhook secret configured
- Admin 2FA enabled and enforced for Admin, Finance Officer, Super Administrator
- Admin/Support/Logistics/Finance dashboards served from isolated subdomain with scoped cookies
- Production secrets loaded from secrets manager, not a plain `.env`
- Dependency vulnerability scan (`composer audit`, `npm audit`) clean or exceptions documented
- Third-party security review completed with no open critical/high findings
- Data retention/purge job scheduled and account export/deletion workflow tested
- File upload MIME validation and malware scanning active on all upload endpoints
- OTP/SMS spend budget and alert configured
- Fund safeguarding: wallet ledger sum reconciles against actual settled balance
- Queue workers running
- Scheduler running
- Database backups running
- Error monitoring configured
- Object storage private buckets configured
- Support and finance roles tested
- No withdrawal route exists
- Paystack duplicate webhook test passes
- Vendor customer-data isolation test passes
- Sensitive identity values do not appear in logs
- OTP rate limit test passes
- Affiliate links do not expose IDs or sensitive data
- Affiliate self-referral test passes
- Affiliate payout cannot use customer wallet balance
