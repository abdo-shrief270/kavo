# Kavo

Vertical SaaS platform. One shared foundation, many products: Fashion first,
then Courses, Beauty and Auto Parts.

This repository is **Phase 0** — the platform every vertical is built on.
Nothing customer-facing ships here. The goal is that Fashion (Phase 1) is the
first *consumer* of this platform, not a rewrite of it.

## Layout

```
apps/
├── api/            Laravel 13 · PHP 8.4 · PostgreSQL · Valkey/Redis · Reverb
├── storefront/     Nuxt 4 SSR — public, per-tenant, custom domains
├── dashboard/      Vue 3 SPA — merchant console        (:5173)
└── admin/          Vue 3 SPA — platform console, staff (:5174)

packages/
└── api-client/     Shared Sanctum client for both SPAs

deploy/             Atomic-release deploy script, Caddyfile, role provisioning
docs/architecture/  ADRs — read 0001 before changing anything structural
```

Three frontends rather than one, because the three surfaces have genuinely
different requirements: the storefront needs SSR for SEO and per-tenant
theming, the merchant console is an authenticated SPA, and the platform
console is staff-only and deliberately on a separate origin.

## Tenancy, in one paragraph

Single database, shared schema, `tenant_id` on every tenant-scoped table.
Isolation has **two independent layers**. The Eloquent global scope filters
reads and stamps `tenant_id` on write. Postgres row-level security sits
underneath it and still holds when that scope is bypassed — by a raw query, a
model missing the trait, or an explicit `withoutGlobalScopes()`. The first
layer is convention; the second is a contract the planner enforces.

Three things make the second layer real, and all three are asserted by tests:

1. The application role owns no tables and holds no `BYPASSRLS`.
2. `FORCE ROW LEVEL SECURITY` is set, so ownership alone is not an exemption.
3. The tenant GUC is cleared on request termination and after every job, so a
   pooled connection never carries one tenant's identity into the next
   request. A cleared GUC reads **zero** rows, not every row.

`tenants`, `domains` and `tenant_user` are deliberately outside RLS: they are
the resolution and authorisation layer and are necessarily read before any
tenant is bound. They carry no tenant payload. See the migration for the full
reasoning.

## Local setup

Requires PHP 8.4+, PostgreSQL 16+, Redis or Valkey, Node 22, pnpm 10.

```bash
# 1. Database roles. The split is load-bearing — see deploy/sql/01-provision-roles.sql
sudo -u postgres psql -v owner_password="'secret'" -v app_password="'secret'" \
  -f deploy/sql/01-provision-roles.sql

# 2. API
cd apps/api
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --database=pgsql_owner   # migrations run as the OWNER
php artisan db:seed  --database=pgsql_owner
php artisan serve                            # :8000

# 3. Frontends
pnpm install
pnpm dev:storefront   # :3000
pnpm dev:dashboard    # :5173
pnpm dev:admin        # :5174
```

Seeded accounts: `merchant@kavo.test` / `admin@kavo.test`, password
`password`.

Note the `--database=pgsql_owner` on both commands. The application role has
no DDL rights and cannot bypass RLS — that is the point, and it is why
migrations use a different identity.

## Tests

```bash
cd apps/api && php vendor/bin/phpunit
```

89 tests against **real Postgres and Redis**. Not SQLite: half of tenant
isolation is enforced by row-level security, which SQLite does not have, so a
SQLite run would pass while proving nothing. The suite connects as the
unprivileged application role for the same reason.

The isolation suite is **generated**, by reflecting over the
`BelongsToTenant` trait. A new tenant-scoped model is enrolled automatically,
and one without a factory fails the build rather than shipping unproven.

## Operational notes

- **Migrations run before the symlink swap, and the swap rolls back while the
  schema does not.** Old and new code also coexist briefly while queue workers
  drain. Destructive migrations are therefore a two-deploy change, and CI
  rejects `dropColumn` / `renameColumn` / `->change()` without an explicit
  `expand-contract-reviewed` note.
- **`/up` vs `/internal/health`.** `/up` is liveness and is what the deploy
  gates rollback on. `/internal/health` is the deep dependency check and is
  what monitoring alerts on. Gating rollback on the deep check would let a
  Redis blip roll back a good deploy.
- **Custom domain TLS** is Caddy on-demand issuance gated by
  `/internal/tls-ask`, which only approves hostnames that reached `verifying`
  or `active`. No Certbot, no cron, no DNS API credentials.
- **Gateways are interfaces.** `WhatsAppGateway` (BeOn today, Meta Cloud API
  later) and `CertificateProvider` are bound, not called directly.

## Documentation

- `docs/architecture/0001-stack-decisions.md` — the stack and the reasoning
- `docs/architecture/0002-naming-and-domains.md` — name, org and domain
