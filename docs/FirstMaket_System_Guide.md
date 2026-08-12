# FirstMaket — System Guide

What the system is, how the pieces fit together, and how to run it.

This is the document to read first. The implementation plan records *what was
decided and why*; the developer guidelines record *how to write code here*.
This one records *what exists and how to use it*.

---

## 1. What FirstMaket is

A Nigerian marketplace where a customer can either **pay at once** or **save
towards a purchase over time** (Pay Small Small). Vendors list goods,
FirstMaket handles delivery, and money only ever moves through Paystack.

### The three claims the whole design protects

Everything unusual about this codebase comes from defending one of these.
If you are about to change something and it seems to make one of them less
true, stop and reconsider.

1. **There is no wallet and no balance.** Money enters against one specific
   plan or order and can only ever become those goods. There is no
   withdrawal route anywhere in the system, and adding one would change what
   FirstMaket legally is.
2. **Only a verified charge moves money.** A browser callback never credits
   anything. Either a signature-verified Paystack webhook, or an outbound
   authenticated verification call (see §6), is what turns a payment into a
   credited plan.
3. **Vendors never see customer identity.** A vendor sees the product and the
   order number. The delivery address belongs to logistics.

---

## 2. Who uses it

| Portal | Domain | Who |
| --- | --- | --- |
| Storefront | `APP_URL` | Customers and guests |
| Vendor Center | `VENDOR_DOMAIN` | Approved sellers |
| Admin workspace | `ADMIN_DOMAIN` | Staff |

Staff roles are **not hardcoded** — an admin creates them at
**Admin → System → Staff roles** by ticking permissions. Seven roles ship
seeded and cannot be renamed or deleted:

| Role | What it is for |
| --- | --- |
| Super Administrator | Everything. Granted by `Gate::before`, not by a permission list. |
| Administrator | Day-to-day running of the marketplace |
| Support Agent | Tickets, returns queue, customer lookup |
| Logistics Personnel | Delivery status only |
| Finance Officer | Reconciliation, refunds, payouts |
| Vendor / Customer | Account types, not staff jobs |

A permission may only be granted by someone who already holds it (Super
Administrator excepted), and a role cannot be deleted while staff still hold
it.

---

## 3. How the money flows

### Pay at once

1. Customer fills a cart (guests can too — it folds into their account on login).
2. Checkout **freezes** the basket, prices and address onto a `CheckoutSession`.
3. Customer is handed to Paystack.
4. The verified webhook turns the session into one `Order` per unit, and
   builds one shipment per vendor.

Anything that sold out between step 2 and step 4 is skipped, not failed —
the rest of the basket still completes.

### Pay Small Small

1. Customer picks a plan term. The price is **frozen** at that moment.
2. Each instalment is a separate Paystack charge credited by the webhook.
3. When the target is covered, the plan is fulfilled into orders.

A plan can be paused, rescheduled onto smaller instalments, or switched to a
different product. None of those move money.

### Pay on delivery

The delivery fee is charged upfront and the goods are paid at the door —
either in cash to the courier, or online from the order page.

---

## 4. The customer-facing features

| Area | Where | Notes |
| --- | --- | --- |
| Catalogue, search, compare | `/catalog` | Approved products only |
| Cart and checkout | `/cart` | Guest carts merge on login |
| Pay Small Small | `/savings` | Pause, reschedule, switch product |
| Saving together | `/savings/together` | See §5 |
| Savings assistant | `/account/assistant` | See §7 |
| Orders, returns | `/orders`, `/account/returns` | |
| Rewards, referrals | `/account/rewards` | |
| Affiliate program | `/account/affiliate` | See §8 |
| Wishlist, price alerts | `/account/wishlist` | |
| Support, complaints | `/support` | |

---

## 5. Saving together (Phase 3B)

Three models, all of which keep "no pooled balance" true a different way.

**Group purchase** — several people fund one plan owned by one organiser.
Every contribution is written into a ledger against the member who made it
and tied to the actual `PlanPayment` it came from, so a share is never a
typed number. The goods go to the organiser, and members are told that
before they can contribute.

> Contributions cannot be paid back out. A member who leaves keeps their name
> on the ledger but cannot take money out — there is no withdrawal anywhere,
> and a group is not an exception.

**Family group** — a read-only summary of members' own separate plans. It
moves no money and has no way to. Members opt in, can stop sharing at any
time, and the summary never reveals what anybody is buying.

**Cooperative (ajo/esusu)** — a rotating group. Each turn, every member pays
into the beneficiary's **own plan**.

> This differs from offline ajo in one important way: nobody receives cash.
> When your turn comes, everyone's contribution brings *your goods* closer.
> If someone needs to raise emergency cash, this is the wrong tool, and the
> UI says so before they join.

The rotation order is fixed when the group starts and cannot be changed
afterwards.

---

## 6. Payments and reconciliation

### The webhook is the source of truth

`POST /webhooks/paystack` — public, CSRF-exempt, signature-verified before
anything is processed, throttled, and idempotent three ways (the
transaction's `webhook_verified_at`, the unique reference on the credited
row, and the raw event log).

Payloads that fail signature verification are **recorded but not stored** —
the hash and event name are kept, the body is discarded. The endpoint is
public, and storing whatever a stranger posts would let anyone write
arbitrary JSON into the database.

### When the webhook never arrives

A webhook can be dropped, firewalled, or sent while the app is restarting.
The money moved; our records say it did not. Two things close that gap:

- **On demand.** Before a customer is sent to pay again, their unfinished
  attempts for that same thing are put to Paystack directly. If one
  succeeded, it is credited and they are shown the payment page instead of
  being charged twice.
- **On a schedule.** `payments:reconcile` runs every 15 minutes and does the
  same for everyone, so the fix does not depend on the customer trying again.

Three outcomes, deliberately asymmetric:

| Paystack says | What happens |
| --- | --- |
| Succeeded | Credited through the same code the webhook runs. Record kept forever. |
| Failed / abandoned / never seen | The row is deleted — it can never become money. |
| Still pending or ongoing | **Nothing.** The bank has not finished. |

> **The rule that must never bend:** an unreachable provider is not a
> failure. A timeout tells us nothing, and deleting a payment record on the
> strength of nothing is how a business destroys the evidence that somebody
> paid it. `TransactionSnapshot::isDead()` is false whenever Paystack could
> not be reached, and every deletion goes through it.

This is why the transactions table does not grow forever: only genuinely dead
attempts are pruned, and everything that ever became money is kept.

---

## 7. The savings assistant (Phase 3C)

At `/account/assistant`. Answers questions about the customer's **own** plans
and payments, in plain language, showing the figures each answer was worked
out from.

**It runs on a deterministic engine, not a language model.** For "explain my
own saving to me", arithmetic on the customer's own records cannot invent a
figure, every sentence traces to a row, it costs nothing per question, and no
financial data leaves the platform. `AssistantDriverContract` exists so a
hosted-model driver can be added when there is a reason to prefer one; set
`ASSISTANT_DRIVER` to switch.

Two properties are enforced by the schema, not by convention:

- **It may propose; it may not act.** A recommendation is inert until a
  separate `assistant_confirmations` row records that the customer accepted
  it. There is no path from "the assistant suggested it" to "it happened".
- **It sees one customer.** Every read filters by user id.

It never takes a payment. The most it can do, once confirmed, is pause a plan
— something the customer can already do themselves.

Usage is capped twice: per customer per day, and platform-wide by spend, so
switching to a paid driver later cannot become an unbounded bill.

---

## 8. The partner program (Phase 3A)

Affiliates get **named campaign links**, each carrying an HMAC signature so a
hand-edited URL fails rather than silently attributing to a guessed code.

- **First attribution wins, forever.** A later click never re-points a
  customer at a different partner.
- **Attribution expires** after a configurable window, stamped onto the
  attribution when created — shortening the setting later cannot void what
  partners already earned.
- **Tiers are earned**, from the track record *before* the sale being priced
  (otherwise one order could promote a partner and pay itself at the new rate).
- **Fraud heuristics flag, they do not block.** A flagged conversion goes to a
  review queue and earns nothing until a human clears it. Automatic
  suspension on a heuristic would let anyone knock a competitor offline.
- **Payouts** are monthly, need a minimum threshold, a verified bank account,
  and Finance approval. Reviewing partners and paying them are separate
  permissions.

---

## 9. Admin workspace map

| Section | Screen | Permission |
| --- | --- | --- |
| Catalogue | Products, Categories, Product fields | `products.approve`, `catalog.manage` |
| | Merchandising (campaigns), Hero slides | `catalog.manage` |
| Vendors | Applications, Commissions, Fees, Payouts | `vendors.*`, `commissions.manage` |
| Orders | Orders, Dispatch, Cash | `orders.manage`, `delivery.update` |
| Customers | Customers, Phone review | `customers.view`, `identity.review` |
| Support | Tickets, Lookup, Returns | `support.manage`, `returns.manage` |
| | Refunds | `refunds.issue` |
| | Notifications (broadcast) | `announcements.send` |
| Finance | Reconciliation, Vendor payouts | `finance.reconcile`, `vendor_payouts.approve` |
| | Financial summary, Transactions | `reports.view` |
| | Expenses | `expenses.manage`, `expenses.approve` |
| Growth | Affiliate partners | `affiliates.manage` |
| | Affiliate payouts | `affiliate_payouts.approve` |
| System | Staff, Staff roles | `staff.manage`, `roles.manage` |
| | Settings, Feature flags | `settings.manage` |
| | **Audit trail** | `audit.view` |
| | **Database backups** | `system.backup` |

### Notifications

At **Support → Notifications**, behind `announcements.send`. Compose a
message and send it to everyone, to one role, or to a single person, down any
of in-app, email and SMS.

The sender's channel choice is **narrowed** by each recipient's own
preferences for the chosen category, never widened — somebody who switched
off promotional email does not start receiving it because a broadcast ticked
the box. Suspended and banned accounts are always skipped, and a broadcast
matching nobody is refused rather than recorded as "sent to 0".

### Financial summary, transactions and expenses

There is no single transactions table, and deliberately so — a customer
charge, a vendor payout, a refund and an office expense are different records
with different lifecycles. **Finance → Transactions** reads them together at
query time; nothing is copied into a second place that could drift.

Only settled money appears: a charge is counted from its `webhook_verified_at`,
never from a browser callback, and a payout from its `paid_at`.

**Finance → Financial summary** shows money in, money out, the net position
and commission earned. Commission is shown *beside* money in, never added to
it: it is the platform's share of a charge that already appears there.

**Finance → Expenses** records what the business spends, by category, with an
optional receipt. Recording and approving are separate permissions and the
person who recorded an expense **cannot** approve it. Rejected spend stays on
the record but never counts toward a total, and only *approved* expenses reach
the ledger — so the net position cannot move on somebody's unreviewed data
entry.

### Audit trail

At **System → Audit trail**, behind `audit.view`, which **no role holds by
default**. Every money, plan, listing, vendor, order and admin state change
has always been written to `audit_logs`; this is the screen that reads it.
Filter by area, action, who, and period — the areas and actions offered are
derived from the data, so a feature shipped last week appears without anybody
updating a list.

Read-only. There is no route here that edits or deletes a row, and there
should never be one.

### Merchandising

The home page carries **no hardcoded content**. Hero slides are authored at
**Merchandising → Hero slides**; discount figures are never typed in — a slide
either computes a real figure from live data or hides itself. Campaigns
(flash deals) are set up at **Merchandising**, with a one-click starter that
builds a real campaign from your approved products.

### Database backups

At **System → Database backups**, behind `system.backup`, which **no role
holds by default** — only Super Administrator reaches it.

Download a `mysqldump` of the whole database or selected tables. Delete any
table's data after typing `DELETE` to confirm. Every download and every wipe
is written to the audit log.

> Deletion has no undo and no table is excluded. Take a backup first.

---

## 10. Settings, not constants

Operational numbers live in the `settings` table with an admin screen, not in
PHP constants — so staff can tune them without a deploy. Notable keys:

| Key | Default | What it does |
| --- | --- | --- |
| `payments.reconcile_after_minutes` | 30 | How long before an unpaid attempt is reconciled |
| `payments.reconcile_batch_size` | 200 | Attempts verified per scheduled run |
| `affiliates.attribution_window_days` | 90 | How long a referral keeps earning |
| `affiliates.payout_minimum_kobo` | 500000 | Below this, commissions roll over |
| `affiliates.fraud_min_minutes_to_convert` | 5 | Faster than this is held for review |
| `assistant.daily_message_limit` | 40 | Questions per customer per day |
| `assistant.daily_cost_cap_kobo` | 500000 | Platform-wide daily assistant spend |
| `returns.window_days` | 7 | How long a customer has to open a return |
| `home.*_limit` | varies | Tiles per home-page section |

---

## 11. Running it locally

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed          # roles, permissions, Super Administrator, reference data
npm run dev
php artisan serve
```

Set `SUPER_ADMIN_EMAIL` and `SUPER_ADMIN_PASSWORD` in `.env` before seeding,
or no admin account is created.

### Tests

```bash
php artisan test
```

Runs against `FirstMaket_testing`. **Never run two test processes at once** —
they share one database and will corrupt each other's fixtures. No test
touches the network; Paystack is faked everywhere.

---

## 12. Deployment

See **`docs/FirstMaket_Azure_Operations.md`** for the Azure-specific runbook:
app settings, the scheduler, the queue worker, and what to add when
deploying this release.
