# CLAUDE.md

Guidance for Claude (and other contributors) working in this repository.

## Guiding principle: KISS

**Keep It Simple, Stupid.** This project deliberately favours the simplest solution that works.

- Pick the plain, boring option: built-in Symfony, Doctrine and EasyAdmin features before
  new libraries, bundles or services.
- Don't add abstractions, interfaces, config options or layers "for later". Build what is
  needed now; refactor when a second real use case shows up.
- Small, readable classes and methods over clever code. Obvious names, few indirections.
- Keep changes focused: no drive-by refactors, no speculative features.
- Fewer moving parts in operations too: one app, one database, one server (Caddy + PHP-FPM
  + MariaDB). No queues, caches or microservices unless there is a proven need.
- When an external service is unavailable or not configured, degrade gracefully (skip the
  step, warn) instead of adding complex retry machinery; the cron jobs catch up.
- Write docs (README, admin texts) in short, plain language.

If a simpler approach exists, use it — and if you're unsure, ask before adding complexity.

## What this project is

One Symfony app that serves many small business websites (handymen and trade businesses,
mainly in Spain). The request's hostname decides which client's (tenant's) site is shown;
clients edit their own content in a simple admin. See `README.md` for full details.

Features: multilingual client sites (EN, ES, NL, DE, FR) with hreflang/sitemap/canonical
URLs, contact form, customer reviews, WhatsApp/click-to-call, Spanish legal page, Plausible analytics,
a "find a pro" search across clients on the platform homepage (brand: CasitaFix),
client onboarding (public `/register` + approval), Google Search Console setup,
OpenStreetMap map/geocoding, monthly Mollie billing with FacturaDirecta invoices, and Cloudflare Turnstile spam protection.

## Stack

- PHP 8.4, **Symfony 7.4 LTS**, Doctrine ORM 3, MariaDB
- **EasyAdmin 5** for the admin (`/admin`)
- Twig templates, Symfony translations (`translations/messages.*.yaml`)
- Local dev with **DDEV**; production on a Hetzner VPS with Caddy (`deploy/Caddyfile`)
- Deployed with **Deployer** (`deploy.php`) by GitHub Actions on every push to `main`

## Layout

```
src/
  Entity/, Repository/     Doctrine entities (Tenant, Domain, User, Service, Project, Enquiry, Review, Signup, Charge, ...)
  Controller/              Public site, platform, billing, security
  Controller/Admin/        EasyAdmin dashboard and CRUD controllers
  EventListener/           Tenant resolution + isolation, Turnstile hooks
  Doctrine/TenantFilter    SQL filter that scopes queries to the active tenant
  Security/                TenantAccessVoter
  Tenant/TenantContext     Holds the active tenant
  Onboarding/              Approving sign-ups / creating clients
  Billing/                 Mollie billing, reminders, suspension
  Google/, Map/, Turnstile/  External integrations
  Search/                  The platform "find a pro" search
  Command/                 app:* console commands
templates/                 site/, platform/, admin/, billing/, email/, security/
translations/              messages.{en,es,nl}.yaml (client sites), platform.{es,en}.yaml (platform pages)
```

## Multi-tenancy rules (important)

- `TenantRequestListener` resolves the tenant from the hostname (`PLATFORM_HOST` = admin /
  landing page; known `Domain` = client site; unknown = 404).
- Client-owned entities implement `TenantOwnedInterface` (use `TenantOwnedTrait`).
  `TenantFilter`, `TenantAccessVoter` and `TenantGuardListener` then enforce isolation
  automatically. Admin CRUD for such entities extends `AbstractTenantCrudController`.
- Never bypass these mechanisms (e.g. disabling the filter or raw cross-tenant SQL) without
  a very good reason.
- Translatable texts are JSON maps (`{"en": "...", "es": "..."}`): edit with
  `TranslatedField` in the admin, render with `{{ text|localized }}` in Twig.

## Common commands

```bash
ddev start
ddev bootstrap                              # composer install, database, demo data
ddev console doctrine:migrations:diff       # after changing entities
ddev console doctrine:migrations:migrate
ddev console app:client:approve             # approve pending sign-ups
ddev console app:billing:run                # daily billing run
ddev console app:turnstile:sync             # sync domains to Cloudflare Turnstile
```

## Conventions

- After changing entities, generate a migration with `doctrine:migrations:diff`.
- New user-facing strings go into the translation files, not hard-coded in templates.
- Never commit secrets: real keys live in `.env.local`; document new variables in `.env.example`.
- Update `README.md` when behaviour or setup changes.
