# Multi handyman site

One Symfony app that serves many small business websites. The hostname decides
which client's site is shown; clients edit their own content in a simple admin.

- **Symfony 7.4 LTS**, PHP 8.4, MariaDB, Doctrine
- **EasyAdmin 5** for the client admin
- Multilingual content per client (EN, ES, NL, DE, FR), with hreflang, sitemap and canonical URLs
- Contact form (saved in the admin + emailed to the client), WhatsApp button, click-to-call
- Cloudflare Turnstile on the contact and login forms, every client domain added automatically, see [Spam protection](#spam-protection-cloudflare-turnstile)
- Spanish legal notice / privacy page generated from the client's details
- Cookieless analytics (Plausible) and Search Console verification per client
- Monthly billing through Mollie with automatic reminders, see [Billing](#billing)

## Getting started

Requires [DDEV](https://ddev.com) and Docker (OrbStack, Docker Desktop or Colima).

```bash
ddev start
ddev bootstrap      # composer install, database, demo data
```

On the first run, `ddev bootstrap` creates `.env` (from `.env.example`) and
`.env.dev` (with a random dev `APP_SECRET`), and generates the first migration in
`migrations/`. Commit `.env`, the migration, `composer.lock` and `symfony.lock`;
`.env.dev` can be committed too since it only holds a dev secret.

| What | URL | Login |
| --- | --- | --- |
| Admin (super admin) | https://multi-handyman-site.ddev.site/admin | `admin@example.com` / `admin` |
| Admin (client) | same | `jan@example.com` / `jan` |
| Client site 1 | https://jans-klusbedrijf.ddev.site | |
| Client site 2 | https://handyman-javea.ddev.site | |
| Sent emails | `ddev mailpit` | |

To try another client site locally, add its hostname to `additional_hostnames`
in `.ddev/config.yaml`, run `ddev restart`, and add the domain in the admin.

## How multi-tenancy works

```
Request ─► TenantRequestListener
            ├─ PLATFORM_HOST?   → admin / login / landing page
            ├─ known domain?    → TenantContext::set(tenant) → client site
            └─ unknown host     → 404
```

- **`Tenant`** is a client. **`Domain`** maps hostnames to a tenant; non-primary
  domains (e.g. `www.`) 301-redirect to the primary one.
- Everything a client owns (`Service`, `Project`, `Enquiry`) implements
  `TenantOwnedInterface`. For those entities:
  - `TenantFilter` (Doctrine SQL filter) adds `tenant_id = …` to **every** query
    once a tenant is active, including `find()` and lazy loading.
  - `TenantAccessVoter` blocks clients from opening another tenant's record by ID in the admin.
  - `TenantGuardListener` assigns new records to the active tenant and throws
    on any cross-tenant insert, update or delete.
- In the admin, the tenant comes from the logged-in user. Super admins
  (`ROLE_SUPER_ADMIN`, no tenant) see everything plus the Clients, Domains and Logins screens.

Adding a new kind of client content? Implement `TenantOwnedInterface` (use
`TenantOwnedTrait`) and extend `AbstractTenantCrudController`. Isolation then works automatically.

Translatable texts are JSON maps (`{"en": "...", "es": "..."}`), edited with
`TranslatedField` in the admin and shown with `{{ text|localized }}` in Twig.

## Billing

Clients pay a monthly subscription through [Mollie](https://www.mollie.com),
charged automatically on the **first workday of each month** (Mon–Fri, skipping
1 Jan, 1 May and 1 Nov).

```
Client added ─► free until the first workday of next month (Tenant::billingStartsOn)
             └► "set up payment" email: card (€0.00 verification) or bank (€0.01 → SEPA mandate)

First workday ─► automatic payment with the mandate
                  ├─ paid    → done
                  └─ failed  → reminder 1 with payment link (right away)
                               + 2 weeks  → reminder 2
                               + 1 month  → final reminder: pay within a week
                               + 1 week   → site offline (503 page, admin stays available)
                               paid via link → site back online, new mandate if needed
```

- `app:billing:run` does all the scheduled work. Run it **daily** from cron; it is
  safe to run more than once a day.
  `0 9 * * * cd /var/www/multi-handyman-site && php bin/console app:billing:run --no-interaction`
- Mollie reports payment changes to `POST /billing/webhook` (production only, set
  `BILLING_WEBHOOKS=1`). The return page and the daily run also check open
  payments, so a missed webhook is never a problem.
- The payment link (`/billing/{token}`) works without logging in. Clients also
  get a **Billing** page in their admin; super admins get **Payments** (with *Mark as
  paid* for bank transfers), a **Billing** tab per client and a *Send payment
  setup email* action. The setup email also goes out when you create a client.
- Free accounts: tick *Free account* in the client's Billing tab. Per-client prices
  override `BILLING_MONTHLY_PRICE`.
- Locally: put a Mollie **test** key in `.env.local` (`MOLLIE_API_KEY=test_...`),
  then try a later date with `ddev console app:billing:run --date=2026-12-01`.

Mollie does not issue invoices (facturas). Send those from your accounting tool.

## Spam protection (Cloudflare Turnstile)

The contact form on every client site and the admin login use
[Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/). The client
domains don't need to be on Cloudflare. A Turnstile widget only works on the hostnames
it lists, so the app manages its widgets through the Cloudflare API:

```
Domain saved / deleted in the admin ─► app:turnstile:sync (straight away, result shown as a flash)
Client deleted, fixtures, …          ─► same, at the end of the request/command
Daily cron                           ─► same, catches up if Cloudflare was unreachable
```

- Every sync rebuilds the full list from the `domain` table plus `PLATFORM_HOST`, so it is
  safe to repeat. `www.example.com` is left out when `example.com` is listed, because
  Cloudflare also allows a widget on the subdomains of its hostnames.
- A widget holds up to `TURNSTILE_HOSTNAMES_PER_WIDGET` hostnames (10 on Cloudflare's free
  plan). When the widgets are full the app creates another one; empty ones are deleted.
  Hostnames stay on their widget, so open pages keep working. The keys are stored in the
  `turnstile_widget` table.
- If a domain is not on a widget yet, or Cloudflare can't be reached, its forms still work,
  protected by the honeypot field only. The Domains screen shows *Protected* or *Not on Cloudflare yet*.
- Setup: create an API token with **Account > Turnstile > Edit** and put
  `CLOUDFLARE_ACCOUNT_ID` and `CLOUDFLARE_API_TOKEN` in `.env.local`, then run
  `php bin/console app:turnstile:sync`. Add it to cron after the billing run:
  `15 9 * * * cd /var/www/multi-handyman-site && php bin/console app:turnstile:sync --no-interaction`
- Locally, without an API token: set `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` to
  Cloudflare's test keys (see `.env.example`), which always pass on any hostname.
  With none of these set, Turnstile is off.

## Useful commands

```bash
ddev console doctrine:migrations:diff     # after changing entities
ddev console doctrine:migrations:migrate
ddev console app:user:create you@yourdomain.com --super-admin
ddev console app:user:create client@example.com --tenant=jans-klusbedrijf
ddev console app:billing:run                  # daily billing run (charges, reminders, offline)
ddev console app:turnstile:sync               # put all client domains on the Cloudflare Turnstile widgets
```

## Production (Hetzner)

Rough outline for a small Ubuntu VPS:

1. Install PHP 8.4 FPM (with intl, pdo_mysql, gd), MariaDB and Caddy.
2. Deploy from Git, then `composer install --no-dev --optimize-autoloader`,
   `php bin/console doctrine:migrations:migrate`, `php bin/console cache:clear`.
3. Create `.env.local` with `APP_ENV=prod`, a random `APP_SECRET`, `DATABASE_URL`,
   `MAILER_DSN` (e.g. Brevo or Postmark), `MAILER_FROM` and `PLATFORM_HOST`, plus
   the live `MOLLIE_API_KEY` and `BILLING_WEBHOOKS=1`, and `CLOUDFLARE_ACCOUNT_ID` /
   `CLOUDFLARE_API_TOKEN`. Set the server timezone to `Europe/Madrid` and add the daily
   `app:billing:run` and `app:turnstile:sync` cron jobs (see [Billing](#billing) and
   [Spam protection](#spam-protection-cloudflare-turnstile)).
4. Use `deploy/Caddyfile`: Caddy gets TLS certificates automatically for every
   client domain, but only for domains listed in the admin (via `/_tls/ask`).
5. Back up the database and `public/uploads/` daily to somewhere off the server
   (e.g. a Hetzner Storage Box), and enable `unattended-upgrades`.

For a new client: point their domain's A record at the server, then add the
domain in the admin. HTTPS works on the first visit, and the domain is added to
Cloudflare Turnstile when you save it.

## Before going live

- Have the legal texts in `translations/messages.*.yaml` (`legal.*`) checked by a gestor or lawyer.
- Replace `templates/platform/index.html.twig` with your own landing page.
