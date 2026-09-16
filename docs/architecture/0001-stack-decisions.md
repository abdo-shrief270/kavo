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
