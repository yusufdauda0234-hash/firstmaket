# FirstMaket Deployment and DevOps

Version: 1.0

## 1. Environment Strategy

Use three environments:

| Environment | Branch | Domain Example | Purpose |
| --- | --- | --- | --- |
| Local | feature branches | localhost | Development |
| Staging | `develop` | `staging.FirstMaket.ng` | QA and client review |
| Production | `main` | `FirstMaket.ng`, `app.FirstMaket.ng` | Live users |

Recommended domains:

- `FirstMaket.ng` for public website
- `app.FirstMaket.ng` for customer app
- `vendor.FirstMaket.ng` or `/vendor` for vendor portal
- `admin.FirstMaket.ng` for the Admin, Support, Logistics, and Finance dashboards

For the first release, path-based routing is acceptable for the public/customer/vendor split (`/app`, `/vendor`), but the admin surface must be on its own subdomain (`admin.FirstMaket.ng`) with its own session cookie scope from Sprint 1 onward. Sharing origin/cookies between the highest-traffic customer app and the highest-privilege admin surface is the one shortcut not worth taking, since retrofitting subdomain isolation after launch requires a coordinated cookie/session migration.

## 2. Server Stack

| Layer | Recommendation |
| --- | --- |
| OS | Ubuntu 24.04 LTS |
| Web server | Nginx |
| Runtime | PHP-FPM |
| App | Laravel |
| Frontend build | Vite |
| Database | Managed MySQL 8 preferred, with a read replica for reporting/reconciliation queries once Finance/Admin reporting load grows |
| Cache/queue | Laravel database driver (jobs/cache/sessions tables); Redis only if scale later demands it |
| Process manager | Supervisor |
| SSL | Cloudflare or Let's Encrypt |
| CI/CD | GitHub Actions |
| Monitoring | Laravel Pulse, Sentry, UptimeRobot |
| Secrets | AWS Secrets Manager, Vault, or hosting-provider equivalent for production; `.env` only for local/staging |

A managed MySQL read replica (or ProxySQL with routed read queries) keeps heavy Finance reconciliation and Admin reporting queries from competing with live wallet/order writes on the primary. Plan the connection-routing convention (e.g., a `reporting` DB connection in `config/database.php`) in Sprint 1 even if the replica itself is provisioned later, so reporting code is written against the right connection from the start.

## 3. Server Directory Layout

```text
/var/www/
  FirstMaket_production/
    app/
    bootstrap/
    config/
    database/
    deployment/
    public/
    resources/
    routes/
    storage/
    vendor/
    .env
  FirstMaket_staging/
  shared/
    backups/
    uploads/
```

## 4. Required Services

- PHP-FPM pool per environment
- Nginx site config per environment
- Supervisor queue worker per environment
- Laravel scheduler through cron
- Database-driver cache, sessions, queues, and rate limits (no Redis at MVP)
- MySQL backup jobs
- Object storage for uploaded product images and identity documents

### Azure App Service deployment

The Linux App Service deployment uses `deploy/azure/startup.sh` as its startup
command. In addition to migrations, storage, and Laravel caches, the startup
script launches one database queue worker and one Laravel scheduler per App
Service instance. Configure these application settings in Azure:

```text
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
```

The database must contain the `jobs`, `job_batches`, and `failed_jobs` tables
before the first worker starts; the startup script runs migrations before
launching the worker. Queue and scheduler output is written to the persistent
storage mount at `/home/data/storage/logs/queue-worker.log` and
`/home/data/storage/logs/scheduler.log`.

Keep the App Service at one instance unless a separate worker deployment is
configured. If the web app scales out, each instance will run its own worker
and scheduler; move those processes into a dedicated worker App Service or
Azure WebJob before enabling multiple web instances.

## 5. Deployment Flow

Production deployment should:

1. Run CI checks.
2. Put Laravel in maintenance mode.
3. Backup database.
4. Pull latest code.
5. Run `composer install --no-dev --optimize-autoloader`.
6. Run `npm ci` and `npm run build`.
7. Run `php artisan migrate --force`.
8. Clear and rebuild Laravel caches.
9. Restart queue workers.
10. Bring app out of maintenance mode.
11. Run health check.

## 6. CI Checks

Every PR should run:

- Composer validation
- Laravel Pint
- Larastan/PHPStan
- Pest unit and feature tests
- TypeScript check
- Vitest
- Vite production build
- Playwright smoke tests for critical paths
- `composer audit` for known PHP dependency vulnerabilities
- `npm audit` (or Dependabot alerts) for known JS dependency vulnerabilities
- Architecture test confirming no module queries another module's models directly (see Developer Guidelines)

The pay-on-delivery browser smoke test is a release gate, not just a backend
test. It must cover the delivered customer order's `Pay` action, the courier's
cash/customer-online/courier-online choices, the courier-online payment
redirect, and the handover lock until the payment webhook is verified. The
current repository has the feature UI and focused Pest coverage, but no
Playwright harness or authenticated browser fixture yet; do not mark this gate
complete until those fixtures can run against a test Paystack environment.

Critical smoke paths:

- Customer registration page loads
- Vendor registration page loads
- Admin login page loads
- Paystack webhook endpoint rejects invalid signature
- Product catalog excludes unapproved products
- Open Savings and Product Target Plan pages load
- Support, logistics, and finance role dashboards load
- Affiliate protected link rejects tampered token or invalid code
- Affiliate payout queue is visible only to authorized Finance users
- Public website loads in Phase 4 only

## 7. Secrets

Never commit real secrets.

Production secrets are stored in a secrets manager (AWS Secrets Manager, Vault, or hosting-provider equivalent) and injected at deploy/runtime, not kept in a server-side `.env` file long-term. Rotate `PAYSTACK_SECRET_KEY`, `PAYSTACK_WEBHOOK_SECRET`, `AFFILIATE_TRACKING_SIGNING_KEY`, and SMS/mail keys at least annually, immediately on suspected compromise, and on staff offboarding. `APP_KEY` rotation requires a re-encryption plan for existing encrypted columns before it is rotated. Staging and local environments never share production secrets.

Production secrets:

- `APP_KEY`
- `DB_*`
- `REDIS_*`
- `PAYSTACK_SECRET_KEY`
- `PAYSTACK_PUBLIC_KEY`
- `PAYSTACK_WEBHOOK_SECRET`
- `AFFILIATE_TRACKING_SIGNING_KEY`
- `SMS_PROVIDER_*`
- `MAIL_*`
- `OPENAI_API_KEY` or AI provider key
- `ADDRESS_LOOKUP_*`
- `PUSH_NOTIFICATION_*`
- `FILESYSTEM_DISK` credentials
- `SENTRY_LARAVEL_DSN`

## 8. External Integration Matrix

| Service | Provider Options | Deployment Notes |
| --- | --- | --- |
| Payments | Paystack | Webhook endpoint must be public over HTTPS and idempotent |
| SMS/OTP | Termii, Africa's Talking | Configure rate limits and sender ID |
| Email | SendGrid, Postmark | Use production domain authentication |
| Storage | Cloudinary or S3-compatible bucket | CAC and identity documents must be private |
| Address lookup | Google Maps Places API | Restrict keys by domain/IP where possible |
| AI | OpenAI or Anthropic | Use queue jobs, cost limits, and audit logs |
| Browser push | Web Push API or OneSignal | Used until native apps introduce mobile push |

## 9. Backup Strategy

| Asset | Frequency | Retention |
| --- | --- | --- |
| MySQL database | Daily | 30 days |
| Pre-deploy database backup | Every production deploy | Last 10 |
| Uploaded files | Daily | 30 days |
| Weekly archive | Weekly | 90 days |

Recovery goals:

- Bad deployment: under 15 minutes
- Database restore: under 45 minutes
- Full server rebuild: under 4 hours

## 10. Monitoring

Implement `/api/health`:

```json
{
  "status": "ok",
  "environment": "production",
  "checks": {
    "database": "ok",
    "queue": "ok",
    "storage": "ok"
  }
}
```

Monitor:

- Queue failures
- Paystack webhook failures
- Ledger mismatch alerts
- 5xx error rate
- Disk usage
- Database connection saturation
- Slow queries
- Failed identity verification provider calls
- SMS/OTP delivery failures
- AI listing review failures
- Automatic debit failures
- Vendor posting fee payment failures
- Affiliate link tampering or high suspicious-click rate
- Affiliate payout batch approval/payment failures
- SMS/OTP and AI provider spend approaching configured daily/monthly budget
- Wallet ledger sum vs. settled fund balance drift (fund safeguarding reconciliation)

## 11. Rollback

Rollback code by resetting to the previous deployed commit and rebuilding assets.

Only rollback the database in emergencies. A database rollback can lose deposits, order updates, and support records created after deployment. Prefer forward-fix migrations for schema issues.
