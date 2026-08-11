# FirstMaket — Azure Operations Runbook

Everything needed to deploy and run FirstMaket on Azure App Service (Linux),
and what to change when shipping the current release.

---

## 1. What Azure runs

| Piece | Where it comes from |
| --- | --- |
| Web (nginx + php-fpm) | App Service built-in |
| Startup / bootstrap | `deploy/azure/startup.sh` |
| nginx site config | `deploy/azure/nginx-default.conf` (copied by the startup script) |
| Queue worker | Started by the startup script |
| **Scheduler** | Started by the startup script |
| Database | Azure Database for MySQL |
| Persistent files | `/home/data/storage` (Azure Storage mount) |

**Startup Command** (App Service → Configuration → General settings):

```
/home/site/wwwroot/deploy/azure/startup.sh
```

The startup script runs on **every container start** — deploy, restart, scale
event — and everything in it is safe to repeat. It fixes the web root, creates
the storage tree, links `public/storage`, runs migrations, rebuilds caches,
reloads nginx, and starts the background workers.

> If the web root is not corrected, App Service serves `wwwroot` directly and
> publishes `.env` and `vendor/` to the internet. That is what the first step
> of the script prevents.

---

## 2. The scheduler — nothing to configure per job

**You do not add jobs to Azure.** The startup script runs Laravel's own
scheduler as a long-lived process:

```bash
php artisan schedule:work
```

It reads `routes/console.php`, so **every scheduled job below already runs**,
and any job added in future runs automatically on the next deploy. There is no
Azure WebJob, Logic App, or Function to create or keep in step.

### What is scheduled right now

| Cron | Command | What it does | Why that timing |
| --- | --- | --- | --- |
| `*/15 * * * *` | `payments:reconcile` | Credits payments the webhook never delivered; prunes dead attempts | A customer who has paid and sees nothing credited will not wait until tomorrow |
| `0 * * * *` | `orders:auto-confirm` | Auto-confirms delivered orders after the grace period | Hourly is granular enough for a multi-day window |
| `0 * * * *` | `orders:flag-overdue-preparation` | Flags vendors sitting on orders | |
| `0 * * * *` | `firstmaket:revoke-unpaid-plans` | Releases plans that froze a price and never paid | Stops free indefinite price locks |
| `0 7 * * *` | `firstmaket:charge-automatic-debits` | Charges saved cards for due instalments | Early, so a declined card leaves the customer the day to fix it |
| `0 9 * * *` | `firstmaket:sweep-dormant-plans` | Warns and revokes abandoned plans | Business hours — it sends customer notifications |
| `0 2 * * *` | `firstmaket:recalculate-vendor-ratings` | Rebuilds vendor scores and tiers | Overnight; pure function of stored facts |
| `30 2 * * *` | `firstmaket:sweep-risk-flags` | Raises risk flags for review | Overnight, after ratings |

All are safe to run twice. `payments:reconcile` and
`firstmaket:charge-automatic-debits` additionally use `withoutOverlapping`,
because both make outbound calls to Paystack and two concurrent runs would
double that traffic against the rate limit.

### Verifying it is alive

```bash
# In App Service SSH:
pgrep -af "schedule:work"          # should print one process
php artisan schedule:list          # what will run and when
tail -f /home/data/storage/logs/scheduler.log
```

If nothing is running, restart the App Service — the startup script relaunches
it, and the `pgrep` guard means a restart never leaves two.

> **Single instance only.** The worker and scheduler are one-per-instance. If
> App Service is ever scaled out, every instance would run the scheduler and
> jobs would double up. Scale up, not out, or move the scheduler to a
> dedicated instance first.

---

## 3. App settings to add for this release

Set under **App Service → Configuration → Application settings**. Only these
are new; everything else is unchanged.

### Required

None. This release adds no mandatory setting — it runs on the existing
Paystack credentials.

### Recommended

| Setting | Value | Why |
| --- | --- | --- |
| `AFFILIATE_TRACKING_SIGNING_KEY` | any long random string | Affiliate links are signed with this. Without it the app falls back to `APP_KEY`, which means **rotating `APP_KEY` would invalidate every printed affiliate link at once**. Set it once and never rotate it. |

Generate one with:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

### Optional

| Setting | Default | Why you might set it |
| --- | --- | --- |
| `ASSISTANT_DRIVER` | `rules` | The savings assistant engine. Leave as `rules` — it costs nothing per question and keeps financial data on the platform. Only change when a hosted-model driver exists. |

### Already required (verify these are present)

`APP_KEY`, `APP_URL`, `ADMIN_DOMAIN`, `VENDOR_DOMAIN`, `SESSION_DOMAIN`,
`DB_*`, `MYSQL_ATTR_SSL_CA`, `PAYSTACK_SECRET_KEY`, `PAYSTACK_PUBLIC_KEY`,
`PAYSTACK_WEBHOOK_SECRET`, `PAYSTACK_BASE_URL`, `RESEND_KEY`,
`LARAVEL_STORAGE_PATH=/home/data/storage`.

---

## 4. External APIs used

The application calls out to these. **This release adds no new provider** —
payment reconciliation uses the Paystack credentials already configured.

| Provider | Used for | Credentials |
| --- | --- | --- |
| **Paystack** | Hosted checkout, saved-card charges, refunds, **and transaction verification (new use, same key)** | `PAYSTACK_SECRET_KEY` |
| Resend | Transactional email / OTP | `RESEND_KEY` |
| Google, Facebook | Social sign-in | `*_CLIENT_ID`, `*_CLIENT_SECRET` |

### Paystack endpoints called

| Endpoint | When |
| --- | --- |
| `POST /transaction/initialize` | Customer starts a payment |
| `POST /transaction/charge_authorization` | Automatic debit on a saved card |
| `POST /refund` | Return refunded |
| **`GET /transaction/verify/{reference}`** | **New** — reconciliation, on demand and every 15 minutes |

> The verify endpoint is the only new outbound traffic. Volume is roughly one
> call per unresolved payment attempt, capped by
> `payments.reconcile_batch_size` (default 200) per run.

### Webhook to configure in the Paystack dashboard

```
https://<your-domain>/webhooks/paystack
```

Unchanged, but note it is now **rate limited to 120 requests/minute**. That is
far above Paystack's real burst rate; a legitimate call is never refused.

---

## 5. Deploy checklist

```bash
# 1. Push the release. The startup script runs migrations automatically.

# 2. Re-seed roles and permissions. REQUIRED for this release.
php artisan db:seed --class=RolesAndPermissionsSeeder --force
```

> **Why the re-seed matters this time.** `savings.view` and `plans.view` were
> seeded from the start but never checked by anything — granting them granted
> nothing. They now gate the savings figures on the support lookup screen, and
> the seeder grants them to Support Agent so the job is unchanged. **Deploying
> the code without re-seeding means support agents temporarily stop seeing
> savings context** on that screen. Nothing breaks, and the next seed fixes it.

```bash
# 3. Verify.
php artisan schedule:list                       # payments:reconcile every 15 min
pgrep -af "schedule:work|queue:work"            # both alive
php artisan payments:reconcile                  # runs clean
```

Then check **Admin → System → Staff roles** shows the two new labels under
Finance, and **Admin → Growth → Affiliate payouts** loads.

---

## 6. Where to look when something is wrong

| Symptom | Look at |
| --- | --- |
| Customer says they paid, plan not credited | `php artisan payments:reconcile` — it will find and credit it. Then check why the webhook missed: Paystack dashboard → Webhooks. |
| Payments stuck pending forever | Is `schedule:work` running? Is `PAYSTACK_SECRET_KEY` correct? |
| Uploads 404 | `public/storage` symlink — the startup script fails hard if it is missing |
| Old settings after deploy | Config cache; the startup script clears it, so check the deploy actually ran |
| Scheduler silent | `/home/data/storage/logs/scheduler.log` |
| Queue silent | `/home/data/storage/logs/queue-worker.log` |

### Logs

Everything is under `/home/data/storage/logs/` — which survives deploys,
because it sits outside `wwwroot`.
