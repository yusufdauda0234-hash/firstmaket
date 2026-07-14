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

## 3. Authentication

Requirements:

- Strong password policy.
- OTP verification for phone number.
- Email verification.
- Login alerts for new device or location.
- Rate limits on login, OTP, password reset, and identity verification.
- Session invalidation after password change or account suspension.

Recommended optional controls:

- Admin 2FA.
- Finance Officer 2FA.
- Super Administrator 2FA.

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

## 12. Backup And Disaster Recovery

Backups must be encrypted.

Required:

- Daily database backup.
- Pre-deploy database backup.
- Uploaded document backup.
- Restore test at least monthly.
- Access to backups limited to Super Administrator and infrastructure lead.

## 13. Production Checklist

- `APP_DEBUG=false`
- HTTPS enforced
- Secure cookies enabled
- Real Paystack webhook secret configured
- Admin 2FA enabled
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
