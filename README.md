# Multi handyman site

One Symfony app that serves many small business websites. The hostname decides
which client's site is shown; clients edit their own content in a simple admin.

- **Symfony 7.4 LTS**, PHP 8.4, MariaDB, Doctrine
- **EasyAdmin 5** for the client admin
- Multilingual content per client (EN, ES, NL, DE, FR), with hreflang, sitemap and canonical URLs
- Contact form (saved in the admin + emailed to the client), WhatsApp button, click-to-call
- **Reviews**: customers write them on the client's site (or through a 7-day review link the client
  sends after a job), the client publishes them in the admin
- **Find a pro**: the platform homepage searches all clients by trade and town, with a map,
  see [Search and reviews](#search-and-reviews)
- Cloudflare Turnstile on the contact, sign-up and login forms, every client domain added automatically, see [Spam protection](#spam-protection-cloudflare-turnstile)
- Spanish legal notice / privacy page generated from the client's details
- Cookieless analytics (Plausible) and Search Console verification per client
- **Onboarding**: clients sign up at `/register`, you approve in the admin or console, and the
  client, domain, login, welcome email, Google Search Console and map are set up automatically
- Monthly or yearly billing through Mollie with automatic reminders, see [Billing](#billing)

## Getting started

Requires [DDEV](https://ddev.com) and Docker (OrbStack, Docker Desktop or Colima).

```bash
ddev start
ddev bootstrap      # composer install, database, demo data, search index
```

Besides the web server and MariaDB, DDEV runs **Redis** (the [background queue](#background-queue),
`.ddev/docker-compose.redis.yaml`), **OpenSearch** (typos in the search,
`.ddev/docker-compose.opensearch.yaml`, about 1 GB of memory) and a queue worker.

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

The platform brand (**CasitaFix** by default) comes from `BILLING_BRAND`.

**Platform pages** on `PLATFORM_HOST` are in Spanish and English (`/es`, `/en`; `/` and
`/register` redirect to the visitor's language): the "find a pro" homepage, `/{locale}/search`,
`/{locale}/for-professionals` (price and testimonials in `templates/platform/pros.html.twig`) and the sign-up form.
Their texts are in `translations/platform.{es,en}.yaml`. These paths return 404 on client sites.

**Search** (`App\Search\ProSearch`) lists every active, non-suspended client with a domain.
Filtering, sorting and paging (20 per page) happen in the database, so a search runs a handful
of small queries however many clients there are.

- Clients pick their **trades** in Settings → Work area (fixed list in `App\Entity\Trade`).
- A **town** is matched against the clients' towns first (the database collation ignores case
  and accents, so "javea" finds "Jávea"); anything else is looked up in OpenStreetMap (Nominatim),
  at most 30 lookups per visitor per hour. Results are within 75 km, nearest first or highest
  rated. If the lookup fails, all pros are shown with a notice.
- **Typos in the town** ("Javae", "Benisa") are corrected when `OPENSEARCH_URL` points to an
  [OpenSearch](https://opensearch.org/) server: the clients' towns are matched fuzzily before
  OpenStreetMap is asked, and the page says which town it used. `app:search:index` rebuilds the
  index of towns; the [scheduler](#scheduled-tasks) runs it hourly (new towns are already found
  when typed correctly).
  Without OpenSearch, or when it is down, the search works as before. DDEV runs OpenSearch
  at `http://opensearch:9200`.
- The **map** (Leaflet, served from `public/vendor/leaflet`) only loads OpenStreetMap after a
  click and shows the pros on the current page.

**Reviews**: customers write one at `/{locale}/review` on the client's site (honeypot +
Turnstile, like the contact form). It is saved hidden and the client gets an email; it shows on
the site once they switch on "Show on website" under Reviews in the admin. Clients cannot write
or edit reviews, only publish, hide or delete them.

**Review links**: when a client adds a finished job (Projects in the admin), they can fill in the
customer's email address or phone number. The customer then gets a link to
`/{locale}/review/{token}` on the client's site that works for 7 days (`Project::REVIEW_LINK_DAYS`)
and only once. An email address gets the link by email; for a phone number there is no SMS
service, so the admin shows a "Send on WhatsApp" button with the message ready. On this form the
name is optional: an empty name is shown as "Anonymous". The review is linked to the job, and the
customer's contact details are removed once they have left their review. "Send review link" in
the project list sends a new link (e.g. after the old one expired). The review count and average are stored on
the tenant (`ReviewStatsListener`), so the search never reads other clients' reviews.

The client's homepage passes the rating and the published reviews to Google as structured data
(`aggregateRating` and `review`). Google decides whether to show stars: it often doesn't for
reviews a business collects on its own website.

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
the status is *Waiting for the site to be online*. The [scheduler](#scheduled-tasks) runs
`app:google:connect --all` every hour, so it connects by itself as soon as the DNS is in place.

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

Clients pay through [Mollie](https://www.mollie.com) on one of two plans:

- **Monthly**: charged automatically on the **1st of each month**.
- **Yearly**: 12 months for the price of 10 (10 × the monthly price), charged once a
  year up front. Two weeks before every yearly payment the client gets a "your payment
  is coming up" email (also when no payment method is set up yet).

Clients pick a plan on the sign-up form (the Monthly/Yearly toggle on the pricing page
preselects it); super admins can change it in the client's Billing tab. A change applies
from the next payment, once the period already paid for has ended.

A card payment is usually decided straight away; a SEPA direct debit takes a few
working days to come through.

```
Client added ─► free until the 1st of next month (Tenant::billingStartsOn)
             └► "set up payment" email: card (€0.00 verification) or bank (€0.01 → SEPA mandate)

1st of the month ─► automatic payment with the mandate (yearly plan: once the paid year ends,
                     announced by email 2 weeks before)
                     ├─ paid    → done
                     └─ failed  → reminder 1 with payment link (right away)
                                  + 2 weeks  → reminder 2
                                  + 1 month  → final reminder: pay within a week
                                  + 1 week   → site offline (503 page, admin stays available)
                                  paid via link → site back online, new mandate if needed
```

- `app:billing:run` does all the scheduled work. The [scheduler](#scheduled-tasks) runs it
  **daily** at 09:00; it is safe to run more than once a day.
- Mollie reports payment changes to `POST /billing/webhook` (production only, set
  `BILLING_WEBHOOKS=1`). The return page and the daily run also check open
  payments, so a missed webhook is never a problem.
- The payment link (`/billing/{token}`) works without logging in. Clients also
  get a **Billing** page in their admin; super admins get **Payments** (with *Mark as
  paid* for bank transfers), a **Billing** tab per client and a *Send payment
  setup email* action. The setup email also goes out when a client is onboarded.
- Free accounts: tick *Free account* in the client's Billing tab. Per-client prices
  override `BILLING_MONTHLY_PRICE`; the yearly plan is always 10 × the monthly price.
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
Daily (scheduler)                    ─► same, catches up if Cloudflare was unreachable
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
  `php bin/console app:turnstile:sync`. After that the [scheduler](#scheduled-tasks) runs it daily.
- Locally, without an API token: set `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` to
  Cloudflare's test keys (see `.env.example`), which always pass on any hostname.
  With none of these set, Turnstile is off.

## Scheduled tasks

One cron job runs everything that has to happen on a schedule. `app:schedule:run` runs every
minute and starts the tasks that are due (times in Spanish time):

| When | Command |
|---|---|
| every minute | `messenger:consume billing async` (the [queue](#background-queue) worker, for 55 seconds) |
| daily 09:00 | `app:billing:run` |
| daily 09:15 | `app:turnstile:sync` |
| hourly at :17 | `app:google:connect --all` |
| hourly at :23 | `app:search:index` |

```cron
* * * * * cd /var/www/multi-handyman-site/current && php bin/console app:schedule:run >> var/log/schedule.log 2>&1
```

Due tasks run side by side, each as its own process, so one failing doesn't stop the others; the output goes to
`var/log/schedule.log`. Every task is safe to repeat, so a run missed while the server was down
is caught up by the next one. See the schedule with `app:schedule:run --list`; change it in
`ScheduleRunCommand::TASKS`. Locally, run a task by hand instead (e.g. `ddev console app:billing:run`).

## Background queue

Work nobody should wait for goes into a queue in Redis (Symfony Messenger, `REDIS_URL`; config
in `config/packages/messenger.yaml`) and is done by a worker:

- **billing**: charging a client and checking a payment with Mollie. `app:billing:run` only
  queues them; the worker charges clients one at a time, one every 2 seconds
  (`billing_queue` in `config/packages/rate_limiter.yaml`). The Mollie webhook answers right
  away and queues the payment check. The return page after a checkout still checks straight away.
- **async**: every email (enquiries, reviews, onboarding, billing), so a form submits without
  waiting for the mail server.

Billing goes first. A failed message is retried after 1, 5 and 25 minutes, then moved to the
`failed` queue, which is kept in the database (table `messenger_messages`) so it survives
anything that happens to Redis: see it with `messenger:failed:show`, retry with `messenger:failed:retry`.
In production the [scheduler](#scheduled-tasks) starts the worker every minute, so a deploy
needs no restart. Locally DDEV runs the worker (`web_extra_daemons` in `.ddev/config.yaml`,
after `ddev restart`); its output is in `ddev logs`.

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
ddev console app:search:index                 # rebuild the OpenSearch index of towns (typo matching)
ddev console app:schedule:run --list          # what the scheduler runs, and when
ddev console messenger:stats                  # messages waiting in the queue
ddev console messenger:failed:show            # messages that failed all retries
```

**Translations.** Client sites are translated into all five languages (`translations/messages.*.yaml`),
the platform pages into Spanish and English (`platform.*.yaml`). After adding or changing a text,
check that no language misses it (no rows marked "missing" means complete):

```bash
for l in en es nl de fr; do ddev console debug:translation $l --domain=messages --only-missing; done
for l in es en; do ddev console debug:translation $l --domain=platform --only-missing; done
```

## Production (Hetzner)

Rough outline for a small Ubuntu VPS:

1. Install PHP 8.4 FPM (with intl, pdo_mysql, gd, redis), MariaDB, Caddy and Redis
   (`apt install redis-server`; turn on `appendonly yes` in `/etc/redis/redis.conf` so queued
   messages survive a restart, and keep it on 127.0.0.1).
2. Deploy the code with Deployer (see [Deployment](#deployment)).
3. Create `/var/www/multi-handyman-site/shared/.env.local` with `APP_ENV=prod`, a random `APP_SECRET`, `DATABASE_URL`,
   `MAILER_DSN` (e.g. Brevo or Postmark), `MAILER_FROM` and `PLATFORM_HOST`, plus
   the live `MOLLIE_API_KEY` and `BILLING_WEBHOOKS=1`, `CLOUDFLARE_ACCOUNT_ID` /
   `CLOUDFLARE_API_TOKEN`, `REDIS_URL=redis://127.0.0.1:6379` and `OPENSEARCH_URL` (empty
   without OpenSearch: the defaults point at the DDEV services). Set the server timezone to `Europe/Madrid` and add the one
   cron job for the deploy user (see [Scheduled tasks](#scheduled-tasks)). Optional: install
   OpenSearch (single node, listening on 127.0.0.1 only) for `OPENSEARCH_URL` (see
   [Search and reviews](#search-and-reviews)).
4. Use `deploy/Caddyfile`: Caddy gets TLS certificates automatically for every
   client domain, but only for domains listed in the admin (via `/_tls/ask`).
5. Back up the database and `shared/public/uploads/` daily to somewhere off the server
   (e.g. a Hetzner Storage Box), and enable `unattended-upgrades`.

### Deployment

Every push to `main` is deployed automatically by GitHub Actions
(`.github/workflows/deploy.yml`) with [Deployer](https://deployer.org) (`deploy.php`).
It uploads the code to a new folder in `releases/`, runs `composer install`, the database
migrations and the cache warm-up, then switches the `current` symlink. If a step fails,
the live site stays on the previous release. The last 5 releases are kept.

Layout on the server:

```
/var/www/multi-handyman-site/
  current -> releases/N      the live site (Caddy serves current/public)
  releases/                  previous releases
  shared/.env.local          your production settings
  shared/var/log/, shared/public/uploads/   kept between releases
```

One-time setup:

1. On the server: create a `deploy` user that owns `/var/www/multi-handyman-site`, and
   install `rsync`, `acl`, `unzip` and Composer. Deployer gives `www-data`
   (PHP-FPM) write access to `var/` and the upload folders with ACLs.
2. Make an SSH key pair for deployments (`ssh-keygen -t ed25519 -f deploy_key -N ""`) and
   add `deploy_key.pub` to `~deploy/.ssh/authorized_keys`.
3. In GitHub, *Settings → Secrets and variables → Actions*, add:
   - `DEPLOY_HOST`: the server's hostname or IP
   - `DEPLOY_USER`: `deploy`
   - `DEPLOY_SSH_KEY`: the contents of `deploy_key` (the private key)
   - `DEPLOY_KNOWN_HOSTS`: the output of `ssh-keyscan <server>`
4. Put your production `.env.local` in `/var/www/multi-handyman-site/shared/`, then run
   the workflow once (*Actions → Deploy → Run workflow*) or push to `main`.

To deploy by hand: `DEPLOY_HOST=<server> dep deploy` (uploads your local working copy).
Roll back to the previous release with `DEPLOY_HOST=<server> dep rollback`.

For a new client: approve their sign-up (or add them, see *Onboarding a new client*) and
point their domain's A record at the server. HTTPS works on the first visit, the domain is
added to Cloudflare Turnstile automatically, and the Search Console setup completes by
itself through the [scheduler](#scheduled-tasks).

Set `GOOGLE_*` and `CLIENT_SUBDOMAIN_BASE` in `.env.local` too (see `.env.example`).

## Before going live

- Have the legal texts in `translations/messages.*.yaml` (`legal.*`) checked by a gestor or lawyer.
- Replace `templates/platform/index.html.twig` with your own landing page.
