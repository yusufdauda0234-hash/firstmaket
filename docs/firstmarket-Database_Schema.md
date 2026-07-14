# FirstMarket Database Schema

Version: 1.0  
Database recommendation: PostgreSQL

## 1. Database Recommendation

Use PostgreSQL for FirstMarket.

Why PostgreSQL fits this project better than MySQL:

- The product is ledger-heavy: wallet deposits, plan allocations, redirections, receipts, settlements, and delivery events need strong relational consistency.
- PostgreSQL has excellent constraints, transactional behavior, partial indexes, JSONB support, generated columns, and check constraints.
- It is a strong fit for reporting and reconciliation queries.
- It handles complex state-machine data cleanly, which matters for plans, product approval, order delivery, and Paystack settlement matching.

When MySQL is still acceptable:

- If the team is significantly more comfortable with MySQL/MariaDB.
- If hosting already provides managed MySQL but not PostgreSQL.
- If the first target is a very simple Laravel deployment and operational familiarity matters more than advanced database features.

Final decision: PostgreSQL for production, unless team operations strongly favor MySQL. Do not mix engines across environments.

## 2. Global Conventions

- Primary key: `id` big integer or UUID, but all public URLs expose `uuid`.
- Timestamps: `created_at`, `updated_at`; use `deleted_at` where recovery is useful.
- Money: store in kobo as integer where possible, or `DECIMAL(15,2)` if the team prefers Naira decimals. Pick one convention and enforce it everywhere.
- Identity fields: encrypt BVN, NIN, document references, and sensitive contact data.
- Audit: every ledger, identity, vendor, product, plan, order, and admin action needs audit trail.
- Status fields should be backed by PHP enums.
- All write flows that touch money must use transactions and row locks.

Recommended money convention: store money as integer kobo in the database, display as Naira in the UI. This avoids decimal rounding mistakes and keeps ledger math exact.

Reporting/reconciliation queries (Finance dashboards, Admin reports) should run against a dedicated `reporting` database connection, pointed at a read replica once one is provisioned, so heavy report queries never contend with live wallet/order writes on the primary. Define this connection in `config/database.php` from Sprint 1 even before a physical replica exists, so reporting code is written against the right connection from the start.

Define a retention window per sensitive/short-lived table (`otp_codes`, `login_events`, `identity_verifications` provider payloads, `uploaded_documents` for rejected/expired verifications) and a scheduled job that purges or anonymizes rows past that window, excluding anything required for financial audit trail (`wallet_transactions`, `audit_logs`, `receipts` are retained, not purged). This supports the NDPA data-minimization and erasure requirements described in the Security and Compliance document.

## 3. Full Table Inventory

This is the expected full schema surface from MVP through Phase 4. MVP tables should be implemented first; growth/scale tables can be added when those phases start.

| Area | Tables |
| --- | --- |
| Core auth/RBAC | `users`, `password_reset_tokens`, `sessions`, `personal_access_tokens`, `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` |
| Security/audit | `login_events`, `otp_codes`, `audit_logs`, `activity_log`, `security_events` |
| Profiles/identity | `customer_profiles`, `vendor_profiles`, `identity_verifications`, `uploaded_documents`, `addresses` |
| Catalog | `categories`, `products`, `product_images`, `product_price_history`, `product_status_events` |
| Vendor fees | `vendor_fee_settings`, `product_posting_fees` |
| AI/moderation | `ai_listing_reviews`, `ai_settings`, `ai_recommendations`, `ai_conversations`, `ai_messages`, `ai_cost_logs` |
| Wallet/payments | `wallets`, `wallet_transactions`, `paystack_transactions`, `payment_authorizations`, `receipts`, `paystack_webhook_events`, `settlement_imports`, `settlement_reconciliation_items` |
| Purchase/savings | `open_savings`, `product_target_plans`, `direct_checkouts`, `plan_contributions`, `plan_redirections`, `automatic_debits`, `plan_status_events` |
| Orders/logistics | `orders`, `order_status_events`, `delivery_assignments`, `vendor_preparation_events` |
| Notifications/support | `notifications`, `notification_preferences`, `notification_deliveries`, `support_tickets`, `support_ticket_messages`, `hotline_call_logs`, `faqs` |
| Growth | `wishlists`, `wishlist_price_alerts`, `reward_tiers`, `user_rewards`, `referrals`, `affiliates`, `affiliate_links`, `affiliate_clicks`, `affiliate_attributions`, `affiliate_conversions`, `affiliate_commissions`, `risk_flags`, `vendor_ratings`, `demand_forecasts` |
| Scale | `agents`, `agent_deposits`, `agent_commissions`, `affiliate_commission_tiers`, `affiliate_bank_accounts`, `affiliate_payout_batches`, `affiliate_payout_items`, `affiliate_fraud_flags`, `group_purchase_plans`, `group_purchase_members`, `group_purchase_contributions`, `family_groups`, `family_group_members`, `cooperative_groups`, `cooperative_members`, `cooperative_cycles`, `cooperative_contributions` |
| Public website | `contact_messages`, `public_pages` if content-managed |

## 4. Core Tables

### users

Holds all login-capable people: customers, vendors, admins, support, logistics, finance.

Key columns:

- `id`
- `uuid`
- `name`
- `email`
- `phone`
- `password`
- `user_type`
- `email_verified_at`
- `phone_verified_at`
- `status`
- `last_login_at`
- `created_at`
- `updated_at`

Required constraints:

- Unique `uuid`.
- Unique `email`.
- Unique `phone`.
- Check `status` in allowed user status enum.

### login_events

- `id`
- `user_id`
- `ip_address`
- `user_agent`
- `device_fingerprint`
- `location_summary`
- `is_new_device`
- `notified_at`
- `created_at`

### otp_codes

- `id`
- `user_id` nullable for pre-registration OTP
- `phone`
- `purpose` (`registration`, `login`, `password_reset`, `identity_verification`)
- `code_hash`
- `expires_at`
- `verified_at`
- `attempt_count`
- `request_ip`
- `created_at`

### audit_logs

Application-level audit trail for business actions.

- `id`
- `actor_id`
- `actor_type`
- `subject_id`
- `subject_type`
- `action`
- `old_values`
- `new_values`
- `ip_address`
- `user_agent`
- `created_at`

### security_events

- `id`
- `user_id`
- `event_type`
- `severity`
- `description`
- `metadata`
- `ip_address`
- `created_at`

### roles, permissions, role_user, permission_role

Use Spatie Laravel Permission or equivalent. If using Spatie, the actual pivot tables are normally `model_has_roles`, `model_has_permissions`, and `role_has_permissions`.

Core roles:

- Customer
- Vendor
- Super Administrator
- Administrator
- Support Agent
- Logistics Personnel
- Finance Officer

## 5. Identity And Verification

### identity_verifications

- `id`
- `user_id`
- `type` (`bvn`, `nin`, `cac`)
- `provider`
- `provider_reference`
- `status`
- `verified_at`
- `failure_reason`
- `metadata`

Security note: provider payloads should be minimized. If full payload storage is required, encrypt it.

### vendor_profiles

- `id`
- `user_id`
- `business_name`
- `contact_name`
- `bvn_encrypted`
- `nin_encrypted`
- `cac_document_path`
- `address`
- `status`
- `approved_by`
- `approved_at`
- `suspended_at`
- `banned_at`

Required constraints:

- Unique `user_id`.
- Vendor cannot post products unless `status = approved`.

### customer_profiles

- `id`
- `user_id`
- `bvn_encrypted`
- `nin_encrypted`
- `identity_status`
- `default_state`
- `default_lga`

### uploaded_documents

- `id`
- `uuid`
- `owner_id`
- `owner_type` (`customer`, `vendor`, `user`)
- `document_type` (`cac`, `identity`, `address_proof`, `other`)
- `disk`
- `path`
- `original_name`
- `mime_type`
- `size`
- `status`
- `uploaded_by`
- `created_at`

### addresses

- `id`
- `user_id`
- `label`
- `full_address`
- `state`
- `lga`
- `landmark`
- `latitude`
- `longitude`
- `is_default`
- `created_at`
- `updated_at`

## 6. Catalog

### categories

- `id`
- `uuid`
- `parent_id`
- `name`
- `slug`
- `status`
- `sort_order`

Seed categories:

- Electronics
- Home Appliances
- Solar Equipment
- Furniture
- Fashion
- Business Equipment

### products

- `id`
- `uuid`
- `vendor_id`
- `category_id`
- `name`
- `slug`
- `description`
- `price`
- `quantity_available`
- `status`
- `posting_tier`
- `posting_fee_amount`
- `approved_by`
- `approved_at`
- `rejected_reason`
- `published_at`

Required constraints:

- Customer-facing catalog queries must filter `status = approved`.
- Vendor price edit after approval must set status back to Pending Approval.

### product_images

- `id`
- `product_id`
- `path`
- `alt_text`
- `sort_order`

### product_status_events

- `id`
- `product_id`
- `old_status`
- `new_status`
- `changed_by`
- `reason`
- `created_at`

### product_price_history

- `id`
- `product_id`
- `old_price`
- `new_price`
- `changed_by`
- `change_reason`
- `created_at`

### vendor_fee_settings

- `id`
- `posting_mode` (`free`, `paid`)
- `basic_fee`
- `premium_fee`
- `featured_fee`
- `changed_by`
- `effective_from`

### product_posting_fees

- `id`
- `vendor_id`
- `product_id`
- `tier`
- `amount`
- `status`
- `paystack_reference`
- `created_at`

Initial suggested fee tiers:

- Basic: NGN 500
- Premium: NGN 2,000
- Featured: NGN 5,000

### ai_listing_reviews

- `id`
- `product_id`
- `provider`
- `status`
- `flag_type`
- `flag_reason`
- `confidence_score`
- `raw_response`
- `admin_decision`
- `decided_by`
- `decided_at`

### ai_settings

- `id`
- `key`
- `value`
- `description`
- `updated_by`
- `updated_at`

## 7. Wallet And Ledger

### wallets

- `id`
- `user_id`
- `currency`
- `balance`
- `status`

Required constraints:

- Unique `user_id`.
- Balance must never be negative.

### wallet_transactions

Immutable ledger of money movements.

- `id`
- `uuid`
- `wallet_id`
- `user_id`
- `type` (`deposit`, `plan_contribution`, `redirection`, `refund_to_plan_only`, `adjustment`)
- `direction` (`credit`, `debit`)
- `amount`
- `balance_before`
- `balance_after`
- `reference`
- `receipt_number`
- `metadata`
- `created_at`

Required constraints:

- Unique `reference`.
- Unique `receipt_number` where present.
- Check `amount > 0`.
- Ledger rows are immutable after creation.

### paystack_transactions

- `id`
- `user_id`
- `wallet_transaction_id`
- `paystack_reference`
- `access_code`
- `amount`
- `currency`
- `channel`
- `status`
- `webhook_verified_at`
- `provider_payload`

### paystack_webhook_events

Raw webhook event tracking for replay protection and debugging.

- `id`
- `event`
- `paystack_reference`
- `signature_valid`
- `payload_hash`
- `payload`
- `processed_at`
- `processing_status`
- `error_message`
- `created_at`

### receipts

- `id`
- `uuid`
- `wallet_transaction_id`
- `user_id`
- `receipt_number`
- `amount`
- `currency`
- `channel`
- `issued_at`
- `emailed_at`
- `pdf_path`

### settlement_imports

- `id`
- `provider`
- `file_path`
- `imported_by`
- `status`
- `started_at`
- `completed_at`
- `metadata`

### settlement_reconciliation_items

- `id`
- `settlement_import_id`
- `paystack_reference`
- `wallet_transaction_id`
- `provider_amount`
- `ledger_amount`
- `status` (`matched`, `missing_in_ledger`, `missing_in_provider`, `amount_mismatch`)
- `reviewed_by`
- `reviewed_at`

### payment_authorizations

For Phase 2 automatic debit.

- `id`
- `user_id`
- `authorization_code`
- `card_type`
- `bank`
- `last4`
- `exp_month`
- `exp_year`
- `reusable`
- `active`

## 8. Savings

### open_savings

- `id`
- `user_id`
- `wallet_id`
- `balance`
- `status`

Required constraints:

- Unique `user_id`.
- Balance cannot be withdrawn as cash.

### product_target_plans

- `id`
- `uuid`
- `user_id`
- `product_id`
- `target_price`
- `payment_mode` (`schedule`, `pay_at_once`)
- `cadence` (`daily`, `weekly`, `monthly`, nullable)
- `suggested_contribution`
- `amount_saved`
- `remaining_balance`
- `progress_percentage`
- `expected_completion_date`
- `status` (`active`, `paused`, `completed`, `ready_for_delivery`, `cancelled`)
- `paused_at`
- `pause_reason`
- `created_at`
- `completed_at`

Recommended additional columns:

- `started_at`
- `last_contribution_at`
- `ready_for_delivery_at`

Required constraints:

- `target_price` is copied from product price at creation and never automatically changes.
- `progress_percentage` must be between 0 and 100.
- Redirection is blocked once status is Ready for Delivery.

### direct_checkouts

Optional table if Pay At Once is modeled separately from Product Target Plans. If the team chooses to model Pay At Once as `product_target_plans.payment_mode = pay_at_once`, this table is not required.

- `id`
- `uuid`
- `user_id`
- `product_id`
- `vendor_id`
- `locked_price`
- `wallet_transaction_id`
- `paystack_transaction_id`
- `status` (`pending_payment`, `paid`, `ready_for_delivery`, `converted_to_order`, `cancelled`)
- `paid_at`
- `order_id`
- `created_at`
- `updated_at`

Required constraints:

- `locked_price` is copied from product price at checkout start.
- Checkout becomes paid only after verified Paystack webhook or verified wallet allocation.
- A paid checkout must create or link to one order.

### plan_contributions

- `id`
- `plan_id`
- `wallet_transaction_id`
- `amount`
- `contribution_date`
- `source` (`paystack_deposit`, `open_savings`, `redirection`)

### plan_schedules

Optional table if schedule instances are tracked explicitly.

- `id`
- `plan_id`
- `cadence`
- `due_date`
- `expected_amount`
- `paid_amount`
- `status`
- `created_at`

### plan_redirections

- `id`
- `user_id`
- `source_type`
- `source_id`
- `target_plan_id`
- `old_product_id`
- `new_product_id`
- `balance_transferred`
- `old_target_price`
- `new_target_price`
- `created_at`

### automatic_debits

Phase 2.

- `id`
- `plan_id`
- `payment_authorization_id`
- `cadence`
- `amount`
- `next_run_at`
- `status`
- `failure_count`
- `last_attempt_at`
- `paused_at`

### plan_status_events

- `id`
- `plan_id`
- `old_status`
- `new_status`
- `changed_by`
- `reason`
- `created_at`

## 9. Orders And Delivery

### orders

- `id`
- `uuid`
- `plan_id`
- `customer_id`
- `vendor_id`
- `product_id`
- `delivery_address`
- `state`
- `lga`
- `status`
- `confirmed_by`
- `confirmed_at`
- `delivered_at`

Required constraints:

- Order is created only from a Ready for Delivery plan.
- Vendor-facing order views must not expose customer identity or delivery address unless business policy changes.

### order_status_events

- `id`
- `order_id`
- `old_status`
- `new_status`
- `changed_by`
- `note`
- `created_at`

### delivery_assignments

- `id`
- `order_id`
- `logistics_user_id`
- `assigned_by`
- `assigned_at`
- `status`

### vendor_preparation_events

- `id`
- `order_id`
- `vendor_id`
- `status`
- `note`
- `created_at`

## 10. Support And Notifications

### support_tickets

- `id`
- `uuid`
- `customer_id`
- `assigned_to`
- `channel` (`faq`, `whatsapp`, `hotline`, `chat`, `complaint`)
- `subject`
- `status`
- `priority`
- `created_at`
- `resolved_at`

### support_ticket_messages

- `id`
- `support_ticket_id`
- `sender_id`
- `message`
- `channel`
- `created_at`

### hotline_call_logs

- `id`
- `customer_id`
- `support_ticket_id`
- `phone`
- `reason`
- `ivr_selection`
- `call_reference`
- `started_at`
- `ended_at`

### notifications

Use Laravel notifications table plus custom notification preferences.

### notification_preferences

- `id`
- `user_id`
- `category`
- `email_enabled`
- `sms_enabled`
- `browser_enabled`

### notification_deliveries

- `id`
- `user_id`
- `notification_id`
- `channel` (`email`, `sms`, `browser`)
- `provider`
- `status`
- `provider_reference`
- `error_message`
- `sent_at`
- `created_at`

### faqs

- `id`
- `category`
- `question`
- `answer`
- `status`
- `sort_order`
- `created_at`
- `updated_at`

## 11. Growth Tables

### wishlists

- `id`
- `user_id`
- `product_id`
- `created_at`

### wishlist_price_alerts

- `id`
- `wishlist_id`
- `old_price`
- `new_price`
- `threshold_percent`
- `notified_at`
- `created_at`

### reward_tiers

- `id`
- `name`
- `minimum_completed_savings`
- `benefits`
- `status`
- `sort_order`

### user_rewards

- `id`
- `user_id`
- `reward_tier_id`
- `lifetime_completed_savings`
- `awarded_at`

### referrals

- `id`
- `referrer_id`
- `referred_id`
- `referral_code`
- `status`
- `qualified_plan_id`
- `reward_amount`
- `reward_credited_at`
- `created_at`

### affiliates

Approved partner or customer-affiliate profile. Affiliate payout is separate from customer wallet withdrawal.

- `id`
- `uuid`
- `user_id`
- `display_name`
- `status` (`pending`, `approved`, `suspended`, `banned`)
- `approved_by`
- `approved_at`
- `suspended_at`
- `minimum_payout_amount`
- `created_at`
- `updated_at`

### affiliate_links

Protected tracking links. Do not expose database IDs, email addresses, phone numbers, BVN, NIN, or other sensitive values.

- `id`
- `affiliate_id`
- `code`
- `signed_token_hash`
- `landing_url`
- `campaign_name`
- `status`
- `expires_at`
- `created_at`

### affiliate_clicks

Click tracking is for attribution and fraud review only. It must not grant access or commission by itself.

- `id`
- `affiliate_link_id`
- `affiliate_id`
- `ip_hash`
- `user_agent_hash`
- `device_fingerprint_hash`
- `landing_url`
- `referrer_url`
- `is_suspicious`
- `created_at`

### affiliate_attributions

Stores the first valid affiliate relationship for a user within the attribution window.

- `id`
- `affiliate_id`
- `affiliate_link_id`
- `user_id`
- `attribution_token_hash`
- `attributed_at`
- `expires_at`
- `source_ip_hash`
- `source_device_hash`
- `created_at`

### affiliate_conversions

Tracks events that may become commissionable. Clicks and signups alone are not payable.

- `id`
- `affiliate_id`
- `affiliate_link_id`
- `referred_user_id`
- `conversion_type` (`signup`, `verified_customer`, `first_deposit`, `pay_at_once_delivered`, `plan_order_delivered`, `vendor_approved_first_product`)
- `qualified`
- `qualified_at`
- `source_order_id`
- `source_vendor_id`
- `status` (`tracked`, `pending_review`, `approved`, `rejected`)
- `reviewed_by`
- `reviewed_at`
- `rejection_reason`
- `created_at`

### affiliate_commissions

Partner commission records. These records must never be stored in, paid from, or mixed with customer wallet balances.

- `id`
- `affiliate_id`
- `affiliate_conversion_id`
- `amount`
- `commission_type` (`flat`, `percentage`, `tiered`, `vendor_recruitment`)
- `status` (`pending`, `approved`, `payable`, `rejected`, `paid`)
- `approved_by`
- `approved_at`
- `rejected_by`
- `rejected_at`
- `paid_at`
- `created_at`

### risk_flags

- `id`
- `user_id`
- `vendor_id`
- `flag_type`
- `severity`
- `description`
- `metadata`
- `status`
- `reviewed_by`
- `reviewed_at`

### vendor_ratings

- `id`
- `vendor_id`
- `tier`
- `score`
- `approved_products_count`
- `fulfilled_orders_count`
- `rejection_rate`
- `calculated_at`

### ai_recommendations

- `id`
- `user_id`
- `recommendation_type`
- `source_context`
- `recommendation`
- `explanation`
- `accepted_at`
- `dismissed_at`
- `created_at`

### demand_forecasts

- `id`
- `product_id`
- `category_id`
- `forecast_type`
- `expected_completions`
- `forecast_for_date`
- `metadata`
- `created_at`

## 12. Scale Tables

### agents

- `id`
- `uuid`
- `user_id`
- `agent_code`
- `status`
- `approved_by`
- `approved_at`
- `commission_rate`

### agent_deposits

- `id`
- `agent_id`
- `customer_id`
- `paystack_transaction_id`
- `amount`
- `status`
- `created_at`

### agent_commissions

- `id`
- `agent_id`
- `agent_deposit_id`
- `amount`
- `status`
- `paid_at`

### affiliate_commission_tiers

- `id`
- `name`
- `conversion_type`
- `commission_type`
- `commission_value`
- `minimum_qualified_conversions`
- `status`
- `created_at`

### affiliate_bank_accounts

- `id`
- `affiliate_id`
- `bank_name`
- `account_number_encrypted`
- `account_name`
- `verification_reference`
- `verified_at`
- `status`
- `created_at`

### affiliate_payout_batches

- `id`
- `batch_reference`
- `period_start`
- `period_end`
- `total_amount`
- `status` (`draft`, `pending_approval`, `approved`, `paid`, `cancelled`)
- `approved_by`
- `approved_at`
- `paid_at`
- `created_at`

### affiliate_payout_items

- `id`
- `affiliate_payout_batch_id`
- `affiliate_id`
- `affiliate_commission_id`
- `amount`
- `status`
- `paid_at`
- `created_at`

### affiliate_fraud_flags

- `id`
- `affiliate_id`
- `affiliate_link_id`
- `affiliate_conversion_id`
- `flag_type`
- `severity`
- `description`
- `status`
- `reviewed_by`
- `reviewed_at`
- `created_at`

### group_purchase_plans

- `id`
- `uuid`
- `product_id`
- `created_by`
- `target_price`
- `amount_saved`
- `status`
- `created_at`

### group_purchase_members

- `id`
- `group_purchase_plan_id`
- `user_id`
- `role`
- `contribution_share`
- `status`
- `joined_at`

### group_purchase_contributions

- `id`
- `group_purchase_plan_id`
- `member_id`
- `wallet_transaction_id`
- `amount`
- `created_at`

### family_groups

- `id`
- `uuid`
- `owner_id`
- `name`
- `status`
- `created_at`

### family_group_members

- `id`
- `family_group_id`
- `user_id`
- `relationship`
- `status`
- `joined_at`

### cooperative_groups

- `id`
- `uuid`
- `name`
- `created_by`
- `status`
- `rules`
- `created_at`

### cooperative_members

- `id`
- `cooperative_group_id`
- `user_id`
- `role`
- `status`
- `joined_at`

### cooperative_cycles

- `id`
- `cooperative_group_id`
- `cycle_number`
- `start_date`
- `end_date`
- `status`

### cooperative_contributions

- `id`
- `cooperative_cycle_id`
- `member_id`
- `wallet_transaction_id`
- `amount`
- `created_at`

## 13. Public Website Tables

These are Phase 4 only.

### contact_messages

- `id`
- `name`
- `email`
- `phone`
- `subject`
- `message`
- `status`
- `ip_address`
- `created_at`

### public_pages

Only needed if public pages are content-managed instead of static Inertia pages.

- `id`
- `slug`
- `title`
- `content`
- `meta_title`
- `meta_description`
- `status`
- `published_at`

## 14. Required Indexes And Constraints

- Unique `users.email`, `users.phone`.
- Unique `users.uuid`.
- Unique `wallets.user_id`.
- Unique `open_savings.user_id`.
- Unique `paystack_transactions.paystack_reference`.
- Unique `wallet_transactions.reference`.
- Unique `receipts.receipt_number`.
- Unique `agents.agent_code`.
- Unique `affiliate_links.code`.
- Unique `affiliate_attributions.user_id`.
- Index `affiliate_clicks(affiliate_id, created_at)`.
- Index `affiliate_conversions(affiliate_id, status, conversion_type)`.
- Index `affiliate_commissions(affiliate_id, status)`.
- Index `products(status, category_id, price)`.
- Index `product_target_plans(user_id, status)`.
- Index `orders(status, vendor_id)`.
- Index `wallet_transactions(user_id, created_at)`.
- Index `plan_contributions(plan_id, contribution_date)`.
- Index `support_tickets(customer_id, status)`.
- Index `risk_flags(status, severity)`.
- Check `amount >= 0` on money movement tables.
- Check `progress_percentage between 0 and 100`.
- Foreign keys on all core relationships.

## 15. Implementation Notes

- Start migrations in Phase 1 only for MVP tables.
- Keep Phase 2/3/4 migrations in later branches so the first build stays focused.
- Use `Schema::hasColumn()` guards only for late hotfix migrations, not normal create-table migrations.
- Add database-level constraints for ledger uniqueness, references, and status safety wherever PostgreSQL supports it.
- Never expose integer IDs in URLs; use `uuid` or route model binding by UUID.
