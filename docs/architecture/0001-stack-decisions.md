# ADR 0001 — Stack & Platform Decisions

**Status:** Proposed
**Date:** 2026-09-16
**Scope:** Phase 0 (Shared Foundation) and the stack Phase 1 (Fashion SaaS) inherits.
**Inputs:** `phase-0-shared-foundation-plan.md`, `vertical-saas-shared-architecture-plan.md`

This ADR records what we run, and — more importantly — the seven places where research
changed the original plan. Everything not listed as a change stands as written.

---

## 0. Verdict up front

The original plan is sound. The modular monolith, single-DB tenancy, interface-first
gateways, atomic-release deploys and expand/contract migrations are all the right calls
and none of them need revisiting.

Seven changes are recommended, ordered by value:

| # | Change | Why |
|---|---|---|
| 1 | **Octane + FrankenPHP from commit 1**, not PHP-FPM | ~4.5× throughput, ~3× p99. Retrofitting it later onto a multi-tenant app is dangerous |
| 2 | **Postgres RLS underneath the global scope** | Their own #1 catastrophic risk currently has exactly one layer of defence |
| 3 | **Valkey instead of Redis** | Redis is AGPL/SSPL since 2024–25. Drop-in swap, zero code change |
| 4 | **WAL archiving off-box (pgBackRest → object storage)** | RPO goes 24h → ~5min for a few $/month. Highest reliability-per-dollar item in the plan |
| 5 | **Filament for the merchant dashboard too**, Nuxt only for storefronts | Cuts the largest chunk of Phase 1 frontend work |
| 6 | **Model voucher/reference payments in the `PaymentGateway` interface now** | Fawry is async-pay-later, not card auth/capture. Miss this and it's a rewrite |
| 7 | **Enforce module boundaries in CI (Deptrac), not code review** | Review discipline decays; a failing job doesn't |

And one answer to the "multiple stacks" question: **no second language in Phase 0.**
Detail in §7.

---

## 1. Backend runtime — Laravel 13 / PHP 8.5 / Octane / FrankenPHP

Laravel 13 shipped 2026-03-17 and supports PHP 8.3–8.5, so the plan's assumption holds.
Bug fixes through Q3 2027, security through Q1 2028.

### Change 1 — run Octane + FrankenPHP from the first commit

Published 2026 benchmarks put FrankenPHP + Octane (8 workers) at ~3,184 req/s with
p95 32ms / p99 71ms, against PHP-FPM at ~712 req/s and p95 98ms. FrankenPHP also has
the flattest p99 curve of the Octane runtimes across concurrency levels — for a
platform whose value proposition is "fast", tail latency is the number that matters.

The reason to do it **now** rather than "later" is not performance, it's safety. Octane
keeps the application in memory between requests. Any static property, any container
singleton holding tenant state, any global that survives a request becomes a
**cross-tenant data leak** — the exact catastrophic risk already named in the Phase 0
risk table. The plan already anticipates this ("store the resolved tenant in a scoped
singleton, *not* a static — this matters if you ever move to Octane"). That instinct is
correct; the conclusion should be stronger. Build under Octane from day one and every
leak surfaces on the day it's written, in a codebase small enough to fix it. Retrofit it
after twelve modules exist and you are auditing accumulated state under time pressure,
in the one area of the system where a mistake is unrecoverable.

Guardrails that make this safe:
- `TenantContext` is a **scoped** binding (`$this->app->scoped()`), never `singleton()`, never static.
- A PHPStan rule banning static mutable properties anywhere under `app/Modules/`.
- Octane `RequestTerminated` listener hard-resets `TenantContext` and the RLS GUC (§2).
- The tenant isolation suite runs **under Octane** in CI, not just under the test runner.

### FrankenPHP replaces Nginx

FrankenPHP is Caddy-based, so adopting it removes Nginx rather than adding to it:

- **Structured access logs** are native JSON in Caddy — better than the `log_format json_combined` workaround, and it satisfies §6 of the original plan directly.
- **Automatic HTTPS** is built in, which substantially simplifies the Domains slice (§4 below).
- One less process to configure, reload and reason about at deploy time.

`systemctl reload php8.5-fpm` in the deploy script becomes a FrankenPHP graceful reload.
The rest of the deploy sequence — atomic symlink swap, `queue:restart`, health check,
rollback — is unchanged and stays correct.

---

## 2. Multi-tenancy — keep the design, add a second layer

**Unchanged:** single database, shared schema, `tenant_id` discrimination. The reasoning
in the original plan (schema-per-tenant means thousands of schemas and hours-long
migrations; database-per-tenant multiplies connection overhead) is correct and is the
same conclusion the 2026 literature reaches: shared schema is the right default for new
B2B SaaS.

### Change 2 — add Postgres Row-Level Security under the global scope

The plan's defence is a global Eloquent scope plus a per-model isolation test. That is
good and necessary — and it is one layer, made of convention. It is bypassed by:

- `DB::select()` / raw query builder calls that never touch Eloquent
- an explicit `withoutGlobalScopes()` that was correct when written and isn't anymore
- a new model where someone forgot the `BelongsToTenant` trait
- a join that pulls in a table nobody scoped
- a queue job that doesn't re-establish tenant context — a failure mode the plan itself
  flags as "a data-leak bug waiting to happen"

RLS does not replace the global scope. It makes a forgotten scope **non-fatal**. As the
research puts it: a WHERE clause is a convention, a policy is a contract. Given that
tenant data leak is already ranked catastrophic, one layer is not enough.

Implementation:

```sql
ALTER TABLE orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE orders FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON orders
  USING (tenant_id = current_setting('app.tenant_id', true)::bigint);
```

Three details that decide whether this actually works:

1. **The app's DB role must not own the tables.** Table owners bypass RLS unless
   `FORCE ROW LEVEL SECURITY` is set — set it anyway, but also split the roles:
   `kavo_owner` runs migrations, `kavo_app` runs the application. `kavo_app` gets no
   `SUPERUSER` and no `BYPASSRLS`. Without this split, RLS is decoration.
2. **Setting the GUC under Octane.** Persistent connections mean a GUC set for tenant A
   is still set when tenant B's request reuses that connection. Set `app.tenant_id` at
   the start of each request and **clear it in `RequestTerminated`**, in the same
   listener that resets `TenantContext`. Treat a request that reaches the DB with no GUC
   set as a hard error, not a "return nothing".
3. **Platform-level queries** (cross-tenant analytics, the Filament super-admin panel)
   need a documented, audited escape hatch — a separate connection using a role that is
   permitted to bypass, never an ad-hoc `SET`.

CI gains one test: as `kavo_app`, with the GUC set to tenant A, call
`Order::withoutGlobalScopes()->get()` and assert tenant B's rows are **still** invisible.
That test is the whole point of the layer.

---

## 3. Data stores

| Concern | Decision | Notes |
|---|---|---|
| Primary DB | **PostgreSQL 17** | Unchanged |
| Cache / queue / locks | **Valkey 9** (change 3) | BSD-3, Linux Foundation, protocol-identical |
| Queue supervision | **Horizon** | Also supplies queue depth for the health endpoint |
| Analytics events | **Partitioned Postgres** (change) | See below |
| Search | **Scout + `database` driver now → Typesense in Phase 1** | See below |
| Object storage | **S3-compatible via Flysystem** → R2 or Bunny | See §6 |

### Change 3 — Valkey, not Redis

Redis relicensed to SSPL/RSALv2 in 2024 and added AGPLv3 in Redis 8 (May 2025). For a
commercial SaaS that is a legal conversation with no upside. Valkey is BSD-3-Clause,
Linux Foundation-governed, and already runs in production behind AWS ElastiCache
Serverless and Google Memorystore. It is protocol-compatible: same `phpredis`, same
Laravel config, zero application code changes. Everything the plan says about Redis —
entitlement caching, usage counters, Reverb pub/sub, queues — applies unchanged.

### Analytics events do not belong in the primary table space

The plan puts `analytics_events` in the main database with "partition candidate later".
That table is the one most likely to hurt the VPS, and partitioning is nearly free to
declare at creation and painful to retrofit once it's large.

Staged decision:
1. **Now** — create it as a **declaratively partitioned** table (monthly, by `occurred_at`)
   with a scheduled job pre-creating the next partition and detaching old ones. Buffer
   writes in Valkey and batch-insert, the same pattern the plan already uses for
   `usage_counters` and for the same reason.
2. **When rollups get slow** — add the TimescaleDB extension for continuous aggregates.
   Still Postgres, still real SQL and joins, no second system to operate.
3. **ClickHouse** — only at billions of rows. It is genuinely 10–100× faster on large
   aggregations with 5:1–10:1 compression, and it is genuinely a second operational
   discipline. That is a Phase 3 problem and adopting it in Phase 0 is precisely the
   "building Phase 0 forever" failure mode the plan warns about.

### Search — defer the engine, not the abstraction

Postgres full-text search is strict about exact word matching (the canonical example:
searching "lambo" against "Lamborghini" returns nothing). For a fashion catalog, and for
a mixed Arabic/English catalog especially, typo tolerance is not a nice-to-have.

**Typesense** is the Phase 1 target over Meilisearch, for one multi-tenancy-specific
reason: Typesense scoped API keys **embed the tenant filter in the key itself**, so the
frontend cannot issue an unscoped query. That is the same "you cannot forget to filter"
property that motivates RLS, applied to search. It also documents multi-node HA as a
first-class arrangement. Meilisearch's advantage — tolerating an index larger than RAM —
matters on a constrained VPS, so revisit if memory becomes the binding constraint.

Phase 0 does nothing here except use **Laravel Scout with the `database` driver**. Scout
is already the abstraction; swapping the driver in Phase 1 is a config change.

---

## 4. Custom domains & SSL — simpler than planned

The plan specifies Certbot + Cloudflare DNS-01 automation. Adopting FrankenPHP replaces
most of that slice with Caddy's **on-demand TLS**: Caddy issues a certificate on first
request for an unknown hostname, provided an `ask` endpoint approves it.

That endpoint is the entire integration: `GET /internal/tls-ask?domain=` → look the
hostname up in the `domains` table → `200` if status is `verifying` or `active`, `404`
otherwise. Certificates are then issued and renewed with no cron, no DNS API credentials
and no per-tenant Nginx server block. The `domains` table, verification tokens and status
machine from the plan stay exactly as designed — they now drive the `ask` endpoint
instead of driving Certbot.

**Cloudflare for SaaS** remains the documented alternative and is genuinely attractive:
100 custom hostnames are included on Free/Pro/Business, then $0.10/hostname/month
pay-as-you-go to 50,000, and it brings edge caching and WAF per tenant domain — which
matters for storefront speed in a way Caddy alone does not. Two caveats: hostname-state
webhooks are Enterprise-only, and there is a default ceiling of 15 certificate issuances
per minute (exceeding it triggers a 30-second lockout), which is a real constraint during
a bulk onboarding.

**Decision:** ship Caddy on-demand TLS (zero cost, zero vendor, already in the server we
run). Put it behind a `CertificateProvider` interface — the same discipline the plan
correctly applies to `WhatsAppGateway` — so moving to Cloudflare for SaaS for edge
caching is a binding swap, not a rewrite.

---

## 5. Frontend — three surfaces, two stacks

The plan lists "Vue 3.5 / Nuxt 4" and "Filament 5" without saying which surface gets
which. That decision is worth making explicitly, because it is the largest lever on
Phase 1 delivery time.

| Surface | Stack | Rationale |
|---|---|---|
| Platform super-admin (internal) | **Filament** | Not in question |
| Merchant dashboard (tenant admin) | **Filament, second panel** (change 5) | See below |
| Customer storefront (public, per-tenant, custom domain) | **Nuxt 4 SSR** | Where SSR actually pays |

### Change 5 — the merchant dashboard is a Filament panel, not a Nuxt SPA

The merchant dashboard is CRUD over tenant-scoped resources: products, variants, orders,
inventory, drops. That is exactly what Filament is for, and Filament ships first-class
multi-tenancy that maps directly onto the `tenant_user` pivot already in the schema.
Building it in Nuxt costs several times the effort to produce something users experience
as "a competent admin panel" either way. Filament v4's Blade rendering overhaul made
large tables — the usual bottleneck — render 2–3× faster with no code changes, and v5 is
that same codebase with Livewire 4 support and a smaller JS bundle. (Worth knowing: v5
introduces **no API changes** over v4, so if any ecosystem plugin lags, shipping on v4
and upgrading later is free.)

The honest cost: this couples the merchant dashboard to server-rendered Livewire, so it
is not reusable for a future mobile app. The mitigation is already mandated by the
architecture — business logic lives in module services, Filament resources stay thin —
and the REST API gets built regardless for the storefront and for mobile later.

### Storefront — Nuxt 4, and the one rule that must not be broken

SSR earns its place here: product-page SEO, Core Web Vitals on conversion pages, and
per-tenant theming from the `tenant_theme_settings` design tokens.

- **Route rules per rendering strategy** are the single biggest lever: prerender the
  home page, SWR/ISR for category and product pages, `ssr: false` for cart and account.
- **Every cached handler's key must include the tenant ID.** A cached event handler
  keyed without it serves tenant A's storefront to tenant B — the frontend twin of the
  RLS problem, and just as unrecoverable. This is a hard rule with a test, not a
  convention.
- `@nuxt/fonts` self-hosts font files, removing an external round trip — worth real
  milliseconds on MENA latency.
- Nuxt 4 dedupes payload across components fetching the same data; lean on it rather
  than hand-rolling a store.

### Realtime — Reverb stands

Reverb handles tens of thousands of concurrent connections per instance and scales
horizontally over Valkey pub/sub. Soketi's 2026 maintenance situation makes it a worse
bet, and inside a Laravel app Reverb has taken its place. One operational note the plan
should carry: Reverb competes for CPU with the app on a shared box — when that bites,
move it to its own VPS. It is designed to scale that way.

---

## 6. Media, CDN and payments

### Media

R2 is the cost winner on egress — $0 egress vs S3, and the gap is not subtle: 5TB stored
plus 50TB egress is roughly $75/month on R2 against ~$4,625 on S3. R2 has **no built-in
image transformation** (you build it with Workers). Bunny Edge Storage is $0.01/GB/region
and includes an Optimizer that resizes and serves WebP/AVIF automatically, from $1/month.

For a fashion storefront — which is almost entirely images — **Bunny Storage + Optimizer**
is the pragmatic default, with **R2 + Cloudflare Images** as the alternative if we end up
on Cloudflare for SaaS anyway and prefer one vendor. Both speak S3, so the `Media`
module's storage abstraction (already in the plan) makes this a config change.

### Payments — change 6

Primary: **Paymob** — best-documented API in the Egyptian market, PCI DSS Level 1,
Apple Pay and Google Pay, and it has an explicit **marketplace/split-payment product**,
which matters for the marketplace and supplier-commission scope in Phase 1+.

Second rail: **Fawry** — 300,000+ payment points reaching ~97% of Egyptian households.
For an Egyptian fashion storefront this is not an optional extra; cash-adjacent payment
is a large share of real conversions.

**The catch worth designing for now:** Fawry's reference-number flow is
*pay-later-at-a-kiosk* — the customer receives a code and settles hours or days later.
That is asynchronous and has no card-style auth/capture. If the `PaymentGateway`
interface is modelled only on card authorize/capture/refund, Fawry is a rewrite rather
than an implementation. The interface must express, from the start:

- an intent that may be `awaiting_offline_payment` for an extended window
- an expiry on that intent
- settlement arriving via **webhook**, not via a response to our own call
- an order state that is placed-but-unpaid, with inventory reserved rather than committed

That costs almost nothing to model in Phase 0 and is expensive to introduce later. It is
the same interface-first reasoning the plan already applies to `WhatsAppGateway`, which
remains correct: ship BeOn, keep Meta Cloud API swappable.

---

## 7. The "multiple tech stacks" question — answered

**Phase 0 is single-language. PHP only.** This is a deliberate decision, not an omission.

The 2026 consensus is that most scaling problems come from unclear boundaries, not from
a shortage of services, and that the right shape is a modular monolith plus a *small*
number of services extracted for genuine, measured hot paths. The plan's own top-ranked
risk is "Building Phase 0 forever". A second language multiplies CI, deploy,
observability, dependency management and on-call surface — it feeds that risk directly,
before there is a single tenant to justify it.

Concretely, Octane + FrankenPHP at ~3,000 req/s is already far beyond anything Phase 0 or
Phase 1 will see. There is no performance argument for a Go service at this stage.

Where a second language *would* eventually earn its place, and what to do about it now:

| Candidate hot path | Verdict | Phase 0 action |
|---|---|---|
| Image processing pipeline | Don't write it — buy it | Bunny Optimizer / Cloudflare Images |
| Analytics ingestion endpoint | Plausible later at real volume | Keep it behind the `AnalyticsIngestor` contract |
| Webhook fan-out at scale | Plausible later | Already an interface + queued job |
| The API itself | No | — |

The rule: extract only with a profiler trace justifying it. The architecture already
mandates that every module's public surface is its contracts and events, which means any
of these can be lifted out later without touching callers. That is the whole payoff of
the modular monolith, and it is why nothing needs deciding now.

One clarification: adopting FrankenPHP **does** put a Go-based server in production. That
is the good version of polyglot — Go's networking, none of Go's maintenance.

---

## 8. Reliability — the weakest part of the plan

The risk table names "Single VPS is a single point of failure" and mitigates it with
off-box backups and a restore drill. Backups are necessary and the drill is genuinely
good practice, but the industry framing is blunt: *single-instance Postgres with snapshot
backups is a development database, not a production one.*

The answer is not an HA cluster in Phase 0 — that is the scope-creep failure mode. It is
a staged plan where each step is cheap and each step is taken before it is needed.

### Change 4 — WAL archiving now (highest value item in this ADR)

Nightly `pg_dump` means the worst case is losing a full day of every tenant's orders.
**pgBackRest streaming WAL to object storage** (R2 or B2) takes the recovery point
objective from ~24 hours to ~5 minutes, costs a few dollars a month, and is maybe an hour
of setup. Nothing else in this document buys as much reliability per unit of effort.

Then keep the restore drill the plan already calls for — and automate it: a monthly
scheduled job that restores the latest backup into a scratch database and asserts row
counts. A drill that is run once is a drill that stops being true.

### Split the data tier off the app box, earlier than feels necessary

App + Postgres + Valkey + Reverb + queue workers + search on one VPS means a memory spike
in any one of them can OOM-kill the others. Postgres is the one process that must never
be OOM-killed. Separating app from data is the cheapest reliability step after WAL
archiving, and it makes the next step possible.

### Staged path

| Stage | Trigger | Action |
|---|---|---|
| Now | Phase 0 | WAL archiving off-box; automated restore drill; app/data split |
| Phase 1 | First paying tenants | Streaming replica on a second cheap VPS — warm standby, manual promotion. Minutes of downtime, not a day of data |
| Phase 2 | Revenue justifies it | Managed Postgres, or Patroni with automatic failover |

### Two gaps in an otherwise-correct deploy pipeline

**Migrations do not roll back with the symlink.** The deploy runs `migrate --force`
before the atomic swap and rolls the symlink back on health-check failure — but the
schema change stays. The expand/contract rule in the plan is exactly what makes this
safe, which means it cannot be left as documentation. Enforce it: a CI job that fails any
migration containing `dropColumn`, `renameColumn`, or a non-nullable column addition
unless it carries an explicit reviewed-annotation. Same philosophy as RLS — turn the
convention into a gate.

**Split liveness from readiness.** The plan extends `/up` to check DB, Valkey, queue
depth and Reverb. If `/up` fails because Valkey is briefly down, a perfectly good deploy
auto-rolls-back. Use `/up` for liveness (is this release serving requests?) and gate the
rollback on that; use `/health` for the deep dependency check and alert on it.

---

## 9. CI/CD additions

The pipeline in the plan is good — lint, Larastan at level 5 ratcheting up, Pest against
real Postgres and Valkey service containers, build, `composer audit`. Additions:

- **Change 7 — Deptrac enforces module boundaries.** The plan's boundary rules
  (`Platform/*` never references `Commerce/*` or a vertical namespace) are enforced "in
  code review, later by a static analysis rule". Make that now: a `deptrac.yaml` with a
  layer per module is an afternoon's work and it never gets tired. Review discipline
  decays, especially under delivery pressure — which is exactly when a boundary violation
  is most likely and most costly.
- **Isolation tests must be un-forgettable.** The plan generates them from a shared
  trait. Go further: reflect over every model using `BelongsToTenant` and **fail CI if
  one has no isolation test**. A new model shipping without a test should break the
  build, not silently have no coverage.
- **The RLS test** from §2 — bypass the global scope, assert isolation holds anyway.
- **Run the isolation suite under Octane**, since that is where state leaks appear.
- **The `env()` rule.** The plan flags `env()` outside `config/` as a production-breaking
  risk under `config:cache`. That is a ~20-line custom PHPStan rule. Write it.

---

## 10. Two things missing from the Phase 0 gate

Both are cheap now and miserable to retrofit across twelve modules:

1. **Tenant export and hard-delete.** Needed the first time a trial lapses, and needed
   for any data-protection obligation. Implementing "delete this tenant everywhere"
   after twelve modules own their own tables is significantly worse than defining the
   contract while there are two.
2. **Idempotency keys on write APIs**, not just webhooks. The plan gets webhook
   idempotency exactly right, including the reasoning — but the same property is needed
   for order creation over a flaky mobile connection, which is the normal case in this
   market. An `Idempotency-Key` middleware plus one table, now.

---

## 11. Final stack

```
Runtime        PHP 8.5 · Laravel 13 · Octane · FrankenPHP (Caddy)
Database       PostgreSQL 17 + Row-Level Security · partitioned analytics_events
Cache/Queue    Valkey 9 · Horizon
Realtime       Laravel Reverb (Valkey pub/sub)
Admin panels   Filament — platform panel + merchant panel
Storefront     Nuxt 4 SSR · route-rule rendering · @nuxt/fonts
Search         Scout (database driver) → Typesense in Phase 1
Media          S3-compatible via Flysystem → Bunny Storage + Optimizer
TLS/domains    Caddy on-demand TLS → Cloudflare for SaaS when edge caching is needed
Payments       Paymob (cards/wallets) + Fawry (reference/voucher) behind one interface
WhatsApp       BeOn behind WhatsAppGateway (Meta Cloud API swappable)
Observability  Sentry (backend + frontend, one project) · Caddy JSON logs ·
               pg_stat_statements · auto_explain · log-viewer in Filament
Backups        pgBackRest → object storage, WAL archived, restore drill automated
CI/CD          GitHub Actions · Pint · Larastan · Deptrac · Pest · atomic releases
```

Everything else in the Phase 0 plan — module layout, slice order, exit checklist,
expand/contract discipline, webhook idempotency, the interface-first gateway rule —
stands as written.

---

## Amendment A — decisions changed during Phase 0 implementation

**Date:** 2026-09-16 · **Status:** Accepted, supersedes §5 and parts of §11

### A1. No Filament anywhere (supersedes Change 5)

§5 recommended Filament for both the platform console and the merchant
dashboard, on the grounds that it is CRUD over tenant-scoped resources and
would cut the largest chunk of Phase 1 frontend work. **The owner decided
against Filament entirely.** Implemented as three separate frontends:

| Surface | Stack | Origin |
|---|---|---|
| Storefront | Nuxt 4 SSR | tenant hostname |
| Merchant console | Vue 3 SPA | `:5173` |
| Platform console | Vue 3 SPA | `:5174` |

The trade-off §5 named is real and is now paid: the merchant dashboard is
hand-built rather than generated, which is more work per screen. Two things
that were mitigations are now benefits — business logic already lives in
module services with thin controllers, and the API had to exist for the
storefront regardless, so a future mobile client inherits a complete API
rather than a Livewire surface it cannot use.

The super-admin console is a **separate application on a separate origin**,
not a route inside the merchant dashboard. The two should never share a
session surface or be one mis-scoped route away from each other, and the
platform console is visually distinct so it is never ambiguous which is open.
Its API client never sends a tenant header: it reads across tenants by design
and must not carry an identity that could appear to authorise a request.

### A2. RLS coverage — three tables excluded, and why

Implementation surfaced a bootstrapping problem the ADR did not anticipate.
Resolution has to read the database *before* a tenant is known, so a policy on
the tables it reads deadlocks every request. Excluded, with reasoning recorded
in the migration:

- `tenants` — the thing being scoped to.
- `domains` — maps hostname to tenant, read before the GUC is set. Reached
  only through `TenantLocator`, the single audited pre-tenant read path.
- `tenant_user` — membership. This is what `ResolveTenant` and every channel
  authorisation callback consult to decide whether a user may act as a tenant
  at all, so it cannot itself require a bound tenant. Queries against it are
  scoped by authenticated `user_id` instead.

None carry tenant payload. Everything that does is covered.

### A3. The migration role needs BYPASSRLS

§2 specified splitting the roles so the app role owns nothing. Correct, but
incomplete: `FORCE ROW LEVEL SECURITY` applies policies to the table owner
too, which blocks the cross-tenant backfills that expand/contract migrations
legitimately perform. `kavo_owner` therefore holds `BYPASSRLS` and is never
used to serve a request; `kavo_app` remains non-owner and `NOBYPASSRLS`. CI
asserts the latter before running the isolation suite, because a suite running
with privileges production never has would prove nothing.

### A4. PHPUnit instead of Pest

§9 specified Pest. Pest could not be installed in this environment — composer
cannot authenticate to GitHub for that dist — so the suite is written for
PHPUnit, which Pest runs on top of anyway. The substance is unchanged: 89
tests, real Postgres and Redis, generated isolation coverage. Moving to Pest
later is additive.

PHPStan/Larastan and Deptrac hit the same download failure, so their configs
(`phpstan.neon`, `deptrac.yaml`) are written and wired into CI but **have not
been run locally** — they are unverified until the first CI run.

### A5. Versions actually used

The ADR assumed PHP 8.5 and PostgreSQL 17. This environment provides PHP 8.4
and PostgreSQL 16, both within Laravel 13's supported range, and nothing built
depends on a 8.5- or 17-only feature. CI targets PHP 8.4 and Postgres 17.
Octane and FrankenPHP are installed and configured per Change 1 but the
application has not yet been run under them — that is the first task of the
next slice, and the Octane-specific state teardown it requires is already
implemented and tested.

### A6. Exit-gate walk, verified

The Phase 0 loop was run end to end against the running API, not merely
asserted in tests. Verified in order: signup provisions a tenant with a plan
and a trial; the quota engine reports correct per-metric entitlements; a
custom domain issues a DNS challenge; the TLS ask endpoint refuses an
unverified hostname (404) and a request with no token (403); the storefront
config resolves by `Host` header and returns theme plus design tokens; a
cross-tenant read via `X-Tenant` is refused (404); a merchant is refused the
platform console (403); a platform admin reads cross-tenant metrics; and that
read appears in the audit log with a null tenant.

Four defects were found this way and none were reachable from below the HTTP
layer: the `sanctum` guard was never defined, the stateful-session middleware
was registered twice, `is_platform_admin` serialised as `null` rather than
`false` on a freshly created user, and platform-scope audit writes were
rejected by their own RLS policy. The audit policy is now asymmetric by
design — a bound tenant sees only its own rows, an unbound (platform-scope)
connection sees only platform rows — which also lets the super-admin audit
view run on the ordinary application connection instead of a privileged one.

`tests/Feature/AuthAndProvisioningTest.php` now covers this path so the same
class of defect fails CI rather than a manual walk.

**Not yet exercised**, and the first tasks of the next slice: the app has not
been run under Octane/FrankenPHP (installed and configured, and the
Octane-specific state teardown is implemented and tested); Reverb has not been
started, so `/internal/health` reports `degraded` on that check by design;
PHPStan and Deptrac are wired into CI but their packages would not install
here, so they are unverified until the first CI run; and no live BeOn or
Paymob credentials have been exercised — both run through their logging and
sandbox paths.

### A7. Observability, implemented

§9 is now built rather than planned.

**Correlation.** A `TracksRequestContext` middleware runs first in the global
stack, so anything logged or reported after it — including failures in the
middleware that follows — carries a request id. It is echoed back as
`X-Request-Id` so a user reporting a problem can quote an id that finds the
exact request. An inbound id is honoured only from a trusted proxy: accepting
a client-supplied one would let anyone collide with or poison another
request's trace. It survives the hop from the storefront's SSR server into the
API, so one page render produces one trace rather than two unrelated sets of
logs.

**Structured logs.** Every channel taps a formatter that emits JSON and pushes
a processor stamping `request_id`, `tenant_id`, `product`, `release`,
`environment` and `module` onto each line. In a shared-schema multi-tenant
system a line without a tenant is close to useless — "checkout failed" is a
support ticket, "checkout failed for tenant 41 on POST /api/orders in release
abc123" is a bug report. The processor never throws: a logger that failed
would hide the very error it was asked to record.

**Sentry**, one project for all four surfaces (`dashboard`, `admin`,
`storefront`, `storefront-ssr`, `webhooks`, `internal`), tagged rather than
split — an error that starts in the API and surfaces in the browser is one
incident. `send_default_pii` is off and only a user id and staff flag are
attached: request bodies, cookies and emails are merchant and shopper data we
have no need to ship to a third party to read a stack trace. The frontends
drop 401/402/403/419 before sending, because a plan limit or an expired
session is an answer, not a fault. `release` is the git SHA, written into the
shared `.env` by the deploy script, so backend and frontend issues trace to
the same deploy.

**Postgres.** `deploy/sql/02-observability.sql` installs `pg_stat_statements`
and documents the `postgresql.conf` entries for `log_min_duration_statement`
and `auto_explain`. This is done at the database rather than only in the
application because an app-level listener sees only queries the app made, and
only while the app is healthy. `kavo:slow-queries` surfaces the results,
ordered by *total* time by default: a 5 ms query run two million times costs
more than a 2-second report run once a day.

**One defect worth recording**, because the class of it will recur. The log
tap type-hinted `Monolog\Logger`, but Laravel hands a tap its own
`Illuminate\Log\Logger` wrapper. The tap therefore never ran — silently. No
exception, no warning; logs simply kept their default format while a unit test
of the processor passed. It was only caught by reading an actual log line at
runtime. The fix added an integration test that resolves a real channel and
asserts the emitted bytes are JSON, rather than testing the processor in
isolation.

**Still outstanding here:** `opcodesio/log-viewer` is not installed (its
package would not download in the build environment), and log shipping to
Loki stays deferred per §9 until there is production traffic worth watching.

### A8. Payments, with the offline rail modelled first

Change 6 warned that modelling only card authorise/capture makes Fawry a
rewrite. The contract is therefore shaped around the asynchronous case, and
the synchronous one treated as the special case that happens to finish
immediately.

`PaymentGateway` exposes `charge()`, `parseSettlement()` and `refund()`. Three
consequences run through all of it:

- **`charge()` returning without money is a normal outcome.** A reference
  payment *expects* to leave with nothing collected. `PaymentResult` is a
  four-way answer — succeeded, requires-action, awaiting-offline-payment,
  failed — rather than a boolean, because code that reads "not succeeded" as
  "failed" would cancel every Fawry order at the moment it was placed.
- **Settlement arrives through a webhook we receive, never as the return value
  of a call we made.** This is the only way an offline payment becomes paid,
  and the safest way on a card rail too: a customer can close the tab
  mid-3-D-Secure, and the callback still arrives.
- **Every open intent has an expiry.** An unpaid reference otherwise holds its
  reservation forever and the catalogue sells out to customers who never paid.
  `kavo:expire-payments` sweeps hourly and emits `PaymentSettled`, which is the
  seam Commerce will consume in Phase 1 to release stock.

`PaymentStatus::AwaitingOfflinePayment` is a first-class state, and
`reservesRatherThanCommits()` is what tells an order to hold stock rather than
consume it.

**Replay protection has two layers**, both tested. An intent that is already
terminal ignores a repeated callback — providers retry by design, so that is
the common path and must not error. A replay that reaches a non-terminal
transition collides on a unique `(payment_intent_id, external_event_id)`
constraint: the append-only history enforces idempotency rather than a check
every caller has to remember. A settlement whose amount disagrees with the
intent is never applied automatically — that is either a provider bug or a
tampered payload, and both need a human.

Routing is by rail (`PaymentGatewayManager`), so no caller names Paymob or
Fawry. `fake` supports every rail, which makes the offline lifecycle — the one
hardest to drive against a sandbox — exercisable with no credentials.

**This also closes the second §10 gap.** `Idempotency-Key` middleware plus an
`idempotency_keys` table means a checkout retried over a flaky mobile
connection cannot become two charges, which in this market is the normal case
rather than the edge case. Same key and body replays the first response; same
key with a *different* body is a 422, because that is a client bug and
replaying the first response would hide it behind a success; a key still in
flight is a 409. Only non-5xx responses are stored — a server error must stay
retryable.

**Worth recording:** the generated isolation suite caught `IdempotencyKey`
shipping without a factory and failed the build, which is exactly what that
guard exists for. It is the second time it has caught a new tenant-scoped
model before review did.

### A9. Deploy and backups, rehearsed rather than written

The last two §8 items. Both were **run**, and both were wrong the first time.

**Deploy.** `deploy.sh` was refactored so every path and every host command is
overridable. That is not a testing affordance bolted on: a deploy script with
hardcoded paths and `sudo` calls cannot be rehearsed at all, and an
unrehearsable deploy script is one you debug for the first time during an
outage. `rehearse-deploy.sh` drives it against a scratch directory and checks
the parts that only matter when something goes wrong — 13 assertions, now in
CI:

- the symlink swap moves `current` and keeps the previous release on disk
- a failing health check **after** the swap rolls the symlink back and keeps
  the broken release for inspection
- a failure **before** the swap discards the partial release and never touches
  the live one
- pruning never deletes the release currently being served

The rollback path is the one that matters and the one nobody tests. It runs
here on every change to `deploy/`.

**Backups.** Covered in `deploy/backup/README.md`; the short version is that
three separate defects stood between the scripts and a working recovery, none
of them visible by reading the code: the cluster configuration was not in the
backup at all (Debian keeps it outside `PGDATA`), extraction reset the data
directory's mode below what Postgres will start on, and the drill *hung*
rather than failing because `psql` prompted for a password with no terminal
attached — which cron would never have reported.

The final run proves point-in-time recovery: a base backup holding 1 tenant,
25 more written afterwards, and a restore that returned 26 with all 18
`tenant_isolation` policies intact. CI re-proves it on every change to
`deploy/`.

**What this does not prove.** Neither has run against a real VPS. The stubbed
commands — `systemctl reload php8.4-fpm`, `supervisorctl restart`, composer
and pnpm — are real on the box and stubbed in rehearsal, so a first production
deploy still needs watching. What is proven is the logic around them, which is
where the failure modes actually live.

### A10. Cross-tenant settlement hijack, and the test that should have caught it

An independent review of the branch found it; it is the most serious defect
this phase produced, and it was reachable by anyone who could sign up.

`payment_intents` is unique on `(tenant_id, reference)` — deliberately, because
two merchants may both have an `order-1001`. Settlement resolved the owning
tenant by that same `reference`, on the schema-owner connection, with no tenant
bound and no `ORDER BY`. That lookup is the one place in the system where
neither isolation layer is watching: the Eloquent scope needs a tenant in
context and RLS is bypassed by the owner role, both by design, because a
provider callback arrives with no session at all.

So an attacker who signed up and pre-created `order-1001` collected the next
tenant's `order-1001` settlement: their intent was marked paid, the victim's
stayed unpaid, and the provider's full payload — customer name, phone, card
metadata — was written to a `payment_events` row the attacker reads back over
the API.

The uncomfortable part is that a test named
`two_tenants_can_hold_the_same_reference` already existed, asserting the exact
precondition, and nothing asserted what settlement then did with it. Proving a
property is safe to have is not the same as proving the code that consumes it
is safe.

**The fix.** `public_reference` — platform-generated `kv_<ulid>`, globally
unique, added by migration with a backfill before the index. It is now the only
identifier sent to a gateway and the only one matched on the way back, so a
callback resolves to exactly one tenant or to none. The merchant's `reference`
stays exactly as it was: theirs, unique per tenant, and never used to answer a
cross-tenant question. `tenantFor()` also refuses to guess when a lookup
somehow returns two rows — unreachable behind the unique index, kept because
failing closed on that query is worth more than the query costs.

`SettlementRoutingTest` covers it, and cannot be transactional: the lookup runs
on a second connection, so a fixture inside the test's own transaction would be
invisible to the code under test and the tests would pass for the wrong reason.
They commit and truncate instead. Reverting the lookup to `reference` fails
them.

### A11. Three endpoints that only a third party ever calls

The same review surfaced a read SSRF, and walking the callback surface
afterwards found three more breaks. All four share a shape: nothing in normal
use touches them, and every one fails closed, so the symptom is silence —
indistinguishable from "no customer has paid yet".

**Outbound webhook delivery was a read SSRF.** `url:https` validates the
string, not the destination; Guzzle follows redirects by default. A tenant
endpoint answering `302 → http://169.254.169.254/` put the cloud metadata
service's response into `webhook_deliveries.response_body`, readable over
`GET /api/webhooks/deliveries`. Now: the host is resolved and every answer
checked against private, loopback, link-local, CGNAT and the other
non-routable ranges — at registration *and* before every attempt, because DNS
is the tenant's to change afterwards — redirects are refused, and the
connection is pinned with `CURLOPT_RESOLVE` to the address that was checked, so
the name cannot mean something else a millisecond later. DNS sits behind
`ResolvesHosts` so the rules are testable against answers no registrar would
sell.

**`/webhooks/fawry` returned 404.** `FawryWebhookVerifier` existed and was
never listed in `kavo.webhooks.verifiers`, and an unlisted provider is refused
rather than accepted unverified — correct, and in this case it meant the
reference rail could never complete a sale. The customer pays at a kiosk with
no connection to us; the callback is not an optimisation, it is the mechanism.

**Both payment verifiers checked the wrong signature.** Each extended a
generic raw-body HMAC-SHA256, and neither provider signs that way: Fawry sends
a plain SHA-256 digest over a documented field concatenation, in the body as
`messageSignature`; Paymob appends `?hmac=` and signs an ordered subset of the
transaction with HMAC-SHA512. Every genuine callback would have been rejected
as forged. Paymob's real algorithm was in the codebase the whole time, as a
public method on `PaymobGateway` that nothing called — module boundaries put
it out of the verifier's reach, so it was written twice and used never. It now
lives in the verifier, and the gateway's copy is gone. `services.paymob` also
had two settings for Paymob's single secret, which is how the gateway and the
verifier ended up reading different values; there is one now.

**On-demand TLS would have refused every certificate.** `TlsAskController`
expected a shared secret in `X-Caddy-Token`, and Caddy's `on_demand_tls ask`
is a bare GET with no way to set a header — so the check could only ever 403,
and no tenant custom domain would have got a certificate. The token moves to
the query string, which is what Caddy can send: it is in the URL rather than
absent, the endpoint is loopback-only, and the gate that actually matters is
still `hostnameIsIssuable`.

`ProviderCallbackTest` covers all three, including tampered amounts and the
old raw-body shape being rejected, and `OutboundWebhookDeliveryTest` covers
the SSRF cases down to the pinned resolve entry.

### A12. CI had never run, and everything it was meant to catch

The checklist line "CI green on every PR — unverified" was generous. CI had
never run once, on any commit, and the first time it did it failed eleven
times in a row on things that had been wrong for as long as they had existed.

**Why it never ran.** Every workflow was gated on `push: [main, develop]` plus
`pull_request`. This repository has one branch and no pull request. Meanwhile
the only workflow producing runs was deploy-production, failing instantly with
zero jobs on every push — `if: ${{ secrets.X != '' }}` uses a context GitHub
does not offer a step's `if`, which is a parse error, not a false condition.
That is also why a workflow restricted to `main` was firing on a feature
branch: an unparseable workflow is reported against whatever was pushed.

**What turning it on found**, in the order CI found it:

- The storefront had no root `tsconfig.json`, so `nuxt typecheck` could not
  start and `pnpm -r typecheck` had never typechecked anything. With it
  running: `robots: false` route rules on /account and /checkout belong to
  `@nuxtjs/robots`, which is not a dependency, so the pages meant to be kept
  out of search results were indexable.
- `pnpm/action-setup` refuses to run when a version is named both in the
  action and in `packageManager`. The whole frontend job died at setup.
- **PHPStan: 58 errors.** Mostly one defect wearing twenty hats — no model
  carried `@property` annotations and no relation carried generics, so every
  enum cast looked like a string and every relation like a bare Model. Eight
  were real: a `new static()` on an abstract class whose subclasses extend the
  constructor, a dead `catch` for an exception never thrown, a coalesce on a
  NOT NULL column, `pushProcessor()` on something only documented as a PSR
  logger, `?->` on the left of `??` twice, a Faker method that lives in the
  en_US locale rather than the base generator, and a docblock that promised
  strings while returning booleans.
- **Deptrac** was failing on `--fail-on-uncovered` counting 467 *framework*
  classes, while reporting zero violations — nine runs chasing a problem that
  did not exist. Underneath it, once readable: the Observability module had
  never been registered as a layer, Media and Notifications were missing the
  Audit and Realtime entries they had always needed, and Billing's settlement
  listener took Webhooks' Eloquent model.
- Fixing that last one properly — a shared `InboundWebhookReceived` event —
  meant writing the end-to-end test, which found that **no Paymob callback
  could ever have settled a payment**. The job named its event
  `webhook.{provider}.{type}` and the listener was registered against a
  hardcoded matrix of guessed type strings containing `transaction`. Paymob
  sends `TRANSACTION`. Event names are case-sensitive. Accepted, marked
  processed, acted on by nobody.
- `phpunit.xml` declared a `tests/Unit` suite over a directory that has been
  empty since the Laravel installer made it. Git does not track empty
  directories, so the suite ran everywhere except a clean checkout.

**What this says about the rest.** Every one of these had been true for many
commits and none was visible from inside the repository. The pattern across
this phase is consistent: the things that break are the ones nothing exercises,
and they break silently. Two boundary checks now live in the ordinary test
suite — every module has a layer, and no layer imports what its ruleset
forbids — specifically so the rules survive an environment where deptrac
cannot be installed, which is how they went unchecked in the first place.

One thing is deferred rather than fixed: `qossmic/deptrac` is abandoned in
favour of `deptrac/deptrac`. Switching needs a composer update against
github.com, which this environment cannot reach. `composer audit` reports it
instead of failing on it; advisories still fail.

---

## Phase 0 status

Against the original slice list: **all thirteen are now built**, and the exit
checklist stands as follows.

| Exit criterion | State |
|---|---|
| CI green on every PR | ✅ Running and green: lint, PHPStan, Deptrac, 190 tests, isolation, three frontend builds |
| Zero-downtime deploy, both environments | Logic rehearsed and in CI; not run on a VPS |
| Rollback tested | ✅ Exercised, including the post-swap path |
| Tenant isolation suite, one test per model | ✅ Generated; has caught two models pre-review |
| Sign up → plan → quota → blocked/warned | ✅ End to end, 402 with an upgrade prompt |
| Custom domain verified, SSL automatic | Verification ✅; **no certificate has been issued** |
| Email, in-app and WhatsApp delivered and logged | ✅ Path built and tested; no live BeOn credentials |
| Inbound webhook processed idempotently | ✅ Replay tested; Fawry and Paymob signatures now verified as the providers actually sign |
| Outbound webhook retried and dead-lettered | ✅ Exercised: backoff, dead-letter, subscription disabled, destination refused |
| Reverb delivers to an authorised channel, rejects others | Rejection ✅ tested; delivery ✅ dispatched, not observed over a live socket |
| Sentry receiving tagged errors, both halves | Wired; **no DSN has been exercised** |
| Slow query logging with tenant and route | ✅ Verified at runtime |
| Off-box backup, restore performed once | ✅ Restore performed; **off-box upload unexercised** (no bucket) |

The honest summary: the platform's logic is built and tested, and what remains
unproven is everything that needs credentials or a server — a first deploy, a
real certificate, a live Sentry DSN, a real BeOn send, and an S3 bucket. Those
are an afternoon with infrastructure, not more code.

---

## Amendment B — Phase 1 (Fashion / Commerce), decisions as built

Phase 1 is the first vertical on top of the platform. Everything under
`app/Modules/Commerce/` may use the platform; no Platform module lists
`Commerce` in its Deptrac ruleset, and that asymmetry is the whole point —
the platform has to serve four verticals without knowing anything about any of
them.

### B1. Products and variants, split the way fashion forces

Merchandising lives on the product; everything that differs per option
combination lives on the variant. One shirt is six rows, and the S/Black one
can be out of stock while the M/White one is not.

Two decisions that are not obvious:

- **`option_signature`.** A canonical, sorted, case-folded rendering of the
  option values (`colour:black|size:m`), uniquely indexed per product. jsonb
  cannot be uniquely indexed in a way that survives key ordering, and two
  variants that both mean M/Black is not a cosmetic problem: stock splits
  across them, and the storefront shows one while orders decrement the other.
- **Stock is two numbers, not one.** `stock_on_hand` is what is physically
  there; `stock_reserved` is what unpaid orders are holding. This exists from
  the catalogue slice rather than the cart slice because the offline payment
  rail makes it unavoidable — a Fawry reference holds stock for up to 72 hours
  before any money moves. A `CHECK (stock_reserved <= stock_on_hand)`
  constraint backs it in the database, because reservation arithmetic under
  concurrency is exactly where an off-by-one becomes stock sold twice.

### B2. Carts remember intent; orders remember the agreement

A cart stores **no prices at all**. Pricing is read live from the variant on
every request, so a cart abandoned three weeks ago cannot make a merchant
honour a figure they have since changed. An order stores **every** display
field as its own column — name, SKU, options, unit price — so renaming,
re-pricing or deleting a product cannot rewrite what someone was charged. The
variant link on an order line is `nullOnDelete`: deleting a product changes the
catalogue, not the history of what was sold.

Carts are addressed by an opaque `X-Cart-Token` rather than a session cookie.
The storefront is server-rendered on a different origin from the API, so a
cookie would have to be a cross-origin credential on every request. Cart lines
are resolved *through* the cart and never by id alone — the endpoint is
anonymous, so route-model binding would let anyone holding any cart token edit
anybody else's basket, and row-level security cannot help because both baskets
belong to the same shop.

### B3. Reservations are claimed in the WHERE clause

Every stock movement is a single conditional `UPDATE`, never a read, a decision
in PHP and a write:

```sql
UPDATE product_variants
   SET stock_reserved = stock_reserved + CASE WHEN track_inventory THEN ? ELSE 0 END
 WHERE id = ? AND (NOT track_inventory OR stock_on_hand - stock_reserved >= ?)
```

Two shoppers reaching for the last shirt both read "1 available", and a check
made in PHP passes for both of them. A check made in the WHERE clause passes
for exactly one. Untracked variants are handled inside the same statement
rather than by an early return on a PHP-side flag, because a flag read from a
model loaded seconds ago is a stale read — and a stale read that says
"untracked" skips the availability check entirely.

### B4. Checkout's ordering is the design

1. **Reserve.** The only step that can fail for a reason the shopper can act
   on, and the only one cheap to undo.
2. **Meter.** `orders` is a *flow* metric — it counts orders placed in a period
   and is never given back, because cancelling an order does not un-place it.
   That makes it the one irreversible step, so it goes after everything that
   might still refuse the checkout. (Contrast `products` and `storage_mb`,
   which are *stock* metrics and are released on delete.)
3. **Write the order**, with every price and name copied onto it.
4. **Only then call the gateway** — outside the transaction, because an
   external HTTP call inside one holds row locks for as long as the provider
   takes to answer.

Steps 1–3 share a transaction, so a failure anywhere in them rolls the
reservations back rather than relying on compensating writes that can
themselves fail. The one seam that cannot be transactional is the meter, which
lives in Redis: if the order write fails after it was charged, the tenant's
order count is one high for the period. That is the least harmful place to put
the inaccuracy, and it is stated rather than discovered.

Order numbers are `max(number) + 1` under the tenant's own row-level security,
starting near 1000. Per shop, so it never leaks how many orders the platform as
a whole has taken — a shop's first order should not be numbered 84,312 — and a
unique index plus a savepoint-guarded retry handles two checkouts computing the
same number.

### B5. `PaymentSettled` is the seam, and it finally has a consumer

Phase 0 built `PaymentSettled`, `reservesRatherThanCommits()` and
`releasesReservation()` for a vertical that did not exist yet. They are now
consumed by `Commerce\Orders\Listeners\SettleOrder`, registered in a separate
`CommerceServiceProvider` — a *platform* provider that registers an orders
listener has made the platform depend on commerce in the one place nothing else
can see.

One listener serves both rails without knowing which it is on: a card settles
inside the checkout request, a Fawry reference settles from a provider callback
three days later in a queued job. Both arrive with the tenant already bound.

**Idempotency is a claim, not a check.** Settlements are not delivered once —
providers retry, an hourly expiry sweep can race a payment made two minutes
ago, and a merchant can cancel an order in the same second it settles. Reading
"is it paid?" to decide whether to decrement stock answers the wrong question;
the right one is "has this order's stock already been accounted for?", and only
a recorded state can answer it. So `orders.inventory_state` moves
`reserved → committed | released` via a conditional UPDATE. Exactly one caller
wins; everyone else does nothing.

A refund deliberately does **not** restock. By the time one happens the goods
have usually shipped, and a platform that silently put them back on the shelf
would oversell the merchant's next customer.

A payment that succeeds against an already-cancelled order logs `critical`
rather than returning quietly. It is reachable — a customer can pay a Fawry
reference minutes after the merchant cancelled the order it was issued for —
and it means somebody has been charged for goods this shop is no longer
holding.

### B6. The outbound webhook path gets its first production caller

`WebhookDispatcher` was built, tested and then never invoked by anything, which
is the same as not having it. Checkout now publishes `order.placed`,
`order.paid`, `order.cancelled` and `order.refunded` through a new
`Shared\Contracts\OutboundEvents` — a contract rather than a direct call,
because a vertical raising `order.paid` should not know that the platform
delivers it over HTTP with HMAC signatures and exponential backoff, any more
than it knows how storage is metered.

### B7. What the tests had to be made to catch

The first run of the checkout suite passed 21 of 21, which in this repository
is a reason for suspicion rather than confidence. Mutating
`Inventory::apply()` to ignore the affected-row count was caught immediately.
Mutating `OrderSettlement::claim()` to drop its `inventory_state = 'reserved'`
condition was **not**: every route into that service checks the order's status
first, so no request can reach a second commit — which is exactly why the guard
underneath needed its own test rather than being assumed from the outside. The
race it protects against (a provider callback crossing a merchant's
cancellation) is not reproducible from a single request, so it is now driven
directly, and asserts both that the stock moves once and that the merchant's
systems are told once.

### B8. The storefront that spends the money, and two defects it exposed

An API nothing calls is the same defect class as a dispatcher nothing invokes,
so the cart and checkout are wired through the Nuxt storefront in the same
slice: add to basket from the product page, a basket with live prices, a
checkout that offers the rails the shop can actually be paid through, and an
order page a customer comes back to days later with their kiosk code.

The cart token is an **httpOnly cookie on the storefront's own origin**, not on
the API's. The browser only ever talks to the storefront, so the cookie needs
no cross-site relaxation and script on the page never reads it; the Nitro
server translates it into the `X-Cart-Token` header the API expects. The cart,
checkout and order pages are `ssr: false` with `X-Robots-Tag: noindex` — a
cached cart is one shopper's basket served to another, and a crawler that found
an order page would publish somebody's order.

Building it compiled. Then it was run — a real API on :8000, a real Nitro
build on :3000, a real purchase — and two things that compiled fine were
wrong:

- **The cart cookie was `Secure` because the *build* was production, not
  because the *connection* was.** A production build served over plain http
  sets `Secure`, the browser never sends the cookie back, and every request
  quietly starts a new empty basket. It now follows `X-Forwarded-Proto`, which
  is the axis that was always meant: secure exactly when the connection is.
- **The API's refusal never reached the shopper.** An error thrown out of a
  Nitro event handler is re-reported as a generic "Server Error" with no body,
  so "Only 4 left", "That item is out of stock" and "Your basket mixes
  currencies" all arrived as nothing. The upstream status and JSON body are now
  passed through deliberately — and only for 4xx, because a 5xx from the API is
  ours and its text belongs in logs, not on a product page.

Running it also found a defect in Phase 0 code that no Phase 0 surface had
exposed: `PaymentIntent::customerAction()` published the kiosk reference
whenever the column was set, regardless of the payment's state. An intent keeps
its `payment_reference` forever — it is the history of what was issued — so a
**paid** order's page told the customer to go and pay the same code again, and
an **expired** one told them to pay for stock that had already gone back on the
shelf. It now returns nothing once the intent is no longer open or its window
has closed, which is what its own docblock always claimed it did.

The end-to-end run, for the record: two orders placed through the storefront by
two different shoppers. `ORD-1001`, two shirts on the reference rail, held
`reserved=2 available=0` for three days and committed only when the kiosk
payment arrived. `ORD-1002`, one shirt on the card rail, paid inside the
request. Stock moved from `4/2/0` to `3/0/0` across the three sizes, numbered
per tenant from 1001, with nothing left reserved.

### B9. Three more, found by reading the diff for what a user could type

A pass over the slice looking specifically for inputs a shopper or a merchant
can produce without trying:

- **`GET /orders/{number}` was a 500 on any non-numeric segment.** The segment
  is compared against a bigint column, and Postgres answers
  `number = 'abc'` with an error rather than with no rows. The route is now
  constrained to digits, so a typo is a 404.
- **The merchant's order search was a 500 on any word.** Same cause, reached
  by typing a customer's name into the search box — the most ordinary thing
  that box is for. The number clause is now added only when the term is all
  digits.
- **A checkout with no cart token wrote a cart row.** The refusal was correct
  ("Your basket is empty", 422) but it went through `resolve()`, which starts
  a cart when none is presented — so every doomed checkout left behind a row
  nobody holds a token for. Checkout now reads through `existing()`, which
  never creates one.

Each had a test written before the fix, and each test failed first: two with
`SQLSTATE[22P02] invalid text representation`, one with a cart count of 1
where it should have been 0.

### B10. The platform is the merchant of record

**Decided:** one set of gateway credentials for the whole platform. Every
tenant's customers pay into the platform's Paymob and Fawry accounts, not into
each merchant's own.

The alternative was each tenant bringing their own acquirer account, which
needs encrypted per-tenant credential storage, a settings screen, and a
gateway manager that resolves credentials per request rather than once at boot.
That is more code and more support burden, and it pushes onboarding friction
onto the merchant — who now has to hold a Paymob contract before they can sell
anything. Shopify Payments exists for exactly this reason.

**No code changes.** The implementation already assumes this:
`PaymentGatewayManager` resolves one gateway per rail from platform config,
credentials are read once in `PlatformServiceProvider` so `config:cache` keeps
working, and a settlement finds its tenant through the intent's globally unique
`public_reference` rather than through whose account it landed in. What was
built for isolation reasons turns out to be exactly what this model needs.

**What it obliges, and what does not exist yet.** There are now two money
flows, in opposite directions, and only one of them is modelled:

| Flow | Direction | Modelled by |
|---|---|---|
| Subscription for the SaaS plan | tenant → platform | `subscriptions`, `invoices`, `invoice_lines` |
| Order takings collected on a tenant's behalf | platform → tenant | **nothing** |

A grep for `commission`, `payout`, `platform_fee` or `balance` across `app/`
and the migrations returns nothing. The moment a real tenant takes a real
order, the platform holds their money and has no record that says how much it
owes, what commission it kept, or whether that has been paid. The amount is
*derivable* — paid orders carry `total_cents` per tenant — but derivable is not
reconciled, and it is certainly not a payout.

So a ledger is now a prerequisite, not a nice-to-have, and it must exist before
the first real tenant rather than after:

- a per-order split: gross, platform commission, net owed to the merchant;
- a running per-tenant balance, credited on settlement and debited on refund —
  which is also why refunds come out of the platform's float, and why a refund
  against a tenant whose balance is already paid out is a real case rather than
  a theoretical one;
- payout records, so "what have we actually sent them" is a fact rather than a
  spreadsheet.

Two consequences worth naming, because they are the platform's now and were the
merchant's under the other model: chargebacks land on the platform's acquirer
relationship, and the platform is the party the acquirer knows. Neither changes
the code; both change who answers the phone.
