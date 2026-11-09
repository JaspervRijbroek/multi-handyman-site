# Multi handyman site

One Symfony app that serves many small business websites. The hostname decides
which client's site is shown; clients edit their own content in a simple admin.

- **Symfony 7.4 LTS**, PHP 8.4, MariaDB, Doctrine
- **EasyAdmin 5** for the client admin
- Multilingual content per client (EN, ES, NL, DE, FR), with hreflang, sitemap and canonical URLs
- Contact form (saved in the admin + emailed to the client), WhatsApp button, click-to-call
- **Reviews**: customers write them on the client's site, the client publishes them in the admin
- **Find a pro**: the platform homepage searches all clients by trade and town, with a map,
  see [Search and reviews](#search-and-reviews)
- Cloudflare Turnstile on the contact, sign-up and login forms, every client domain added automatically, see [Spam protection](#spam-protection-cloudflare-turnstile)
- Spanish legal notice / privacy page generated from the client's details
- Cookieless analytics (Plausible) and Search Console verification per client
- **Onboarding**: clients sign up at `/register`, you approve in the admin or console, and the
  client, domain, login, welcome email, Google Search Console and map are set up automatically
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
| Sign-up form | https://multi-handyman-site.ddev.site/register | |
| Sent emails | `ddev mailpit` | |

To try another client site locally, add its hostname to `additional_hostnames`
in `.ddev/config.yaml`, run `ddev restart`, and add the domain in the admin.

## How multi-tenancy works

```
Request ─► TenantRequestListener
            ├─ PLATFORM_HOST?   → admin / login / search / platform pages
            ├─ known domain?    → TenantContext::set(tenant) → client site
            └─ unknown host     → 404
```

- **`Tenant`** is a client. **`Domain`** maps hostnames to a tenant; non-primary
  domains (e.g. `www.`) 301-redirect to the primary one.
- Everything a client owns (`Service`, `Project`, `Enquiry`, `Review`) implements
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

## Search and reviews

The platform brand (**Manitas** by default) comes from `BILLING_BRAND`.

**Platform pages** on `PLATFORM_HOST`: `/` is the "find a pro" homepage, `/search` the results,
`/for-professionals` the page for tradespeople with pricing (edit the price and FAQ in
`templates/platform/pros.html.twig`). These paths return 404 on client sites.

**Search** (`App\Search\ProSearch`) lists every active, non-suspended client with a domain:

- Clients pick their **trades** in Settings → Work area (fixed list in `App\Entity\Trade`).
- A **town** is matched against the clients' towns first ("javea" finds "Jávea"); anything
  else is looked up in OpenStreetMap (Nominatim), at most 30 lookups per visitor per hour.
  Results are within 75 km, nearest first or highest rated. If the lookup fails, all pros are shown with a notice.
- The **map** (Leaflet, served from `public/vendor/leaflet`) only loads OpenStreetMap after a click.

**Reviews**: customers write one at `/{locale}/review` on the client's site (honeypot +
Turnstile, like the contact form). It is saved hidden and the client gets an email; it shows on
the site once they switch on "Show on website" under Reviews in the admin. Clients cannot write
or edit reviews, only publish, hide or delete them. The review count and average are stored on
the tenant (`ReviewStatsListener`), so the search never reads other clients' reviews.

## Onboarding a new client

```
Client fills in /register ──► Sign-up (pending) ──► you approve ──► everything below, in one go
You add a client yourself ──────────────────────────────────────┘   (admin "Add client" or console)

  ✔ Client (Tenant) with name, phone, WhatsApp, address, work area, languages, legal defaults
  ✔ Domain: their own domain + www., or <slug>.CLIENT_SUBDOMAIN_BASE if they have none
  ✔ Client login: the password they chose on /register, or a 7-day "set your password" link
  ✔ Welcome email with the website and login links
  ✔ Payment setup email (Mollie), see Billing; skipped for free accounts
  ✔ Map: address → coordinates with OpenStreetMap (Nominatim, no key), map on the site
  ✔ Google Search Console: verification tag, verification, property and sitemap
```

Only super admins can create clients. The public form only stores a **sign-up**; nothing
is created until you approve it. Sign-ups show up under **Platform → Sign-ups** (with a
badge) and on the dashboard, and every super admin gets an email.

| Where | How |
| --- | --- |
| Admin | **Sign-ups** → *Approve* (or *Reject*). **Clients** → *Add client* for clients you sign up yourself: saving the form approves it straight away. |
| Admin | **Clients** → *Connect Google* re-runs the map lookup and Search Console steps for one client. |
| Console | `app:client:approve` lists pending sign-ups and lets you pick one; `app:client:approve 12` / `--reject` |
| Console | `app:client:onboard` asks for the details and onboards right away; pass them as options for scripts (`--help`) |
| Console | `app:google:connect <slug>` or `--all` retries everything that is not connected yet |

Each step reports *done / warning / skipped*, so it is clear what still needs doing. If
the sign-up cannot be approved as is (domain already used, email already has a login…),
nothing is saved and you get the reasons; fix the sign-up and approve again.

**Search Console needs the site to be online.** Google checks the verification tag on the
client's real domain, which only works once their DNS points to the server. Until then
the status is *Waiting for the site to be online*. Add this to cron and it connects by
itself as soon as the DNS is in place:

```cron
17 * * * * cd /var/www/multi-handyman-site && php bin/console app:google:connect --all -q
```

**Google Business Profile (the listing on Google Maps) cannot be created automatically.**
Google has no API for creating and verifying new listings: they verify by post, phone or
video with the owner. Create or claim it at <https://business.google.com/create> with the
client, then paste its share link under **Clients → Look & Google**. The website links to it
and tells Google the site and listing belong together (`sameAs` in the structured data).

**Map.** The address is turned into coordinates with [Nominatim](https://nominatim.org/),
OpenStreetMap's free geocoder (no API key; their [usage policy](https://operations.osmfoundation.org/policies/nominatim/)
allows this volume, and requests are limited to one per second). The site shows an
OpenStreetMap map with a marker, loaded only after the visitor clicks *Show map*, so no
visitor data goes to a third party before that and no cookie banner is needed. If the
street is not on OpenStreetMap, the marker goes on the town and the admin gets a warning.
Clients who work from home can switch off *Show the street on the website*: the address,
map and structured data then only show their town, and the map position is looked up at
town level, so it never points at their home.

### Google setup (once)

Everything works without this; the Search Console step is then skipped. (The map needs
no setup: it uses OpenStreetMap.)

1. In the [Google Cloud console](https://console.cloud.google.com/), create a project and
   enable the **Google Site Verification API** and the **Google Search Console API**.
2. **Search Console:** *IAM & Admin → Service accounts* → create a service account →
   *Keys* → *Add key* → JSON. Store the file on the server outside Git (e.g.
   `var/google-service-account.json`) and set
   `GOOGLE_SERVICE_ACCOUNT_KEY=var/google-service-account.json`. The service account
   becomes owner of every client property; set `GOOGLE_OWNER_EMAILS` to your own Google
   account(s) so the sites also appear in *your* Search Console.
3. Optional: `CLIENT_SUBDOMAIN_BASE=sites.yourplatform.com` plus a wildcard DNS record
   `*.sites.yourplatform.com → server`, so clients without a domain are online immediately.

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
  setup email* action. The setup email also goes out when a client is onboarded.
- Free accounts: tick *Free account* in the client's Billing tab. Per-client prices
  override `BILLING_MONTHLY_PRICE`.
- Locally: put a Mollie **test** key in `.env.local` (`MOLLIE_API_KEY=test_...`),
  then try a later date with `ddev console app:billing:run --date=2026-12-01`.

Mollie does not issue invoices (facturas). Send those from your accounting tool.

## Spam protection (Cloudflare Turnstile)

The contact form on every client site, the sign-up form (`/register`) and the admin
login use [Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/). The client
domains don't need to be on Cloudflare. A Turnstile widget only works on the hostnames
it lists, so the app manages its widgets through the Cloudflare API:

```
Domain saved / deleted in the admin ─► app:turnstile:sync (straight away, result shown as a flash)
Client onboarded or deleted, fixtures ─► same, at the end of the request/command
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
ddev console app:client:approve                # pending sign-ups (two demo ones in the fixtures)
ddev console app:client:onboard                # add a client without a sign-up
ddev console app:google:connect --all          # retry Google setup
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

For a new client: approve their sign-up (or add them, see *Onboarding a new client*) and
point their domain's A record at the server. HTTPS works on the first visit, the domain is
added to Cloudflare Turnstile automatically, and the Search Console setup completes by
itself through the cron job.

Set `GOOGLE_*` and `CLIENT_SUBDOMAIN_BASE` in `.env.local` too (see `.env.example`).

## Before going live

- Have the legal texts in `translations/messages.*.yaml` (`legal.*`) checked by a gestor or lawyer.
- Replace `templates/platform/index.html.twig` with your own landing page.
