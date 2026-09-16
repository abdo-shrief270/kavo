# ADR 0002 — Project Name, GitHub Org and Domain

**Status:** Proposed — needs a human decision
**Date:** 2026-09-16 (revised)
**Checked:** 2026-09-16

---

## Recommendation

**Name:** Stamen · **GitHub org:** `stamenhq` · **Domain:** `stamenhq.com`

*Stāmen* is Latin for **the warp thread on an upright loom** — the fixed threads strung
first, that every other thread is woven across. That is a precise description of what
Phase 0 is: the shared foundation strung once, with Fashion, Courses, Beauty and Auto
Parts woven onto it. It is also the only candidate in this search with no same-class
trademark conflict.

**Runner-up:** Merx (`merxhq.com` / `github.com/merxhq`) — Latin for *merchandise*, root of
commerce, merchant and market.

Both are free on `.com` and GitHub. Read §4 before buying — the conflict picture is the
thing that separates these candidates, not availability.

---

## 1. "Verto" is not available

Checked first, since it was the specific request:

- `verto.com` — **registered**
- `vertohq.com` — **registered**
- `github.com/verto` — **taken**
- Every clean variant tested (`verta`, `vertos`, `vertix`, `vertek`, `vertio`, `vertis`,
  `vertum`, `vertana`, `vertiva`, `verso`, `versohq`, `vertigo`, `vertly`, `vertify`) —
  **all registered**

Only `getverto.com` and `usverto.com` survived, and a `get-` prefix reads as a product
landing page, not a company. The instinct behind *verto* was right, though — a Latin root
tied to the work — so the search widened along exactly that axis, and everything below
came from it.

---

## 2. Method and confidence

**GitHub** — fetched `https://github.com/<name>` directly. 404 = unclaimed, rendered
profile = taken. **Definitive.**

**Domains** — RDAP, WHOIS and DNS-over-HTTPS are all blocked by this session's network
egress policy, so registration could not be read from a registry. Instead: NS/SOA lookups
against **three independent resolvers** (Google `8.8.8.8`, Cloudflare `1.1.1.1`, Quad9
`9.9.9.9`), requiring agreement across all three.

- **NS/SOA present → definitively registered.**
- **NXDOMAIN on all three → very probably unregistered.** Not proof: a domain can be
  registered with nameservers not yet delegated, or be registry-reserved or
  premium-priced.

So "taken" below is certain; "available" is a strong signal to **confirm at a registrar
before purchase**. Expect one or two to come back premium-priced.

---

## 3. Search scope

Roughly **3,700 candidates** were checked across five semantic families, all chosen to
mean something about the work:

| Family | Reasoning | Examples tried |
|---|---|---|
| Turn / vertical | The *verto* root | verto, verso, torno, pivo, vertek |
| **Weave / textile** | Fashion is Phase 1, *and* the architecture literally weaves verticals onto one fabric | texo, tela, telar, trama, stamen, licia, plexo, warp, loom, weav |
| Build / foundation | What Phase 0 is | struo, fundo, basis, pylo, strata, forma, fabri |
| Trade / market | What three of four verticals do | merx, merca, vendo, trado, agora, souk |
| Root / core / growth | Shared core, many products | radix, kerna, nucla, axio, orto, surga, cresa |

**Every bare four-to-six letter `.com` in all five families is registered.** So is every
bare form on GitHub (`merx`, `texo`, `pylo`, `trama`, `plexo` are all taken accounts).
That is the normal state of `.com` in 2026 and not specific to these words. Stem + `hq` is
where the clean matched pairs are.

---

## 4. The five clean matched pairs, and why four lose

All five have **both** the `.com` and the GitHub org free. The conflict column is what
decides it.

| Name | Domain | GitHub | Meaning | Conflict |
|---|---|---|---|---|
| **Stamen** | `stamenhq.com` ✅ | `stamenhq` ✅ | Latin: warp thread of a loom | **Stamen Design** — a cartography/data-viz studio. Design services, not a SaaS platform. Adjacent, not overlapping |
| **Merx** | `merxhq.com` ✅ | `merxhq` ✅ | Latin: merchandise, goods | **MERX** — Canadian government e-tendering platform (mdf commerce). Same class — but the brand is being **retired in favour of SOVRA** |
| Texo | `texohq.com` ✅ | `texohq` ✅ | Latin: *I weave* | ❌ **DTEN Inc. filed TEXO in Feb 2026**, expressly covering software platforms, mobile apps and **SaaS**. Direct same-class hit, and freshly filed |
| Pylo | `pylohq.com` ✅ | `pylohq` ✅ | The monumental gateway of an Egyptian temple | ❌ **Pylo d.o.o.** (Slovenia) — an active software and hardware company, domain-verified on GitHub. Same class |
| Weav | `weavhq.com` ✅ | `weavhq` ✅ | Weave | ❌ Worst of the set: a **WEAV trademark covering commerce-platform software**, a Weav acquired by Brex for $50M, and Weave (NYSE: **WEAV**) in healthcare SaaS |

**Texo deserves a note**, because on pure brand merit it was the best of the five — *texō*
gives us text, textile, texture and context ("woven together"), and it is four letters
that an Arabic speaker reads without friction. It loses on timing alone. A trademark
application filed in February 2026 that explicitly names SaaS is the single worst kind of
conflict to build on: same class, same services, and recent enough that the applicant is
actively investing in the mark. Not worth it.

**Why Stamen wins over Merx.** Merx means *goods* — excellent for Fashion, Beauty and Auto
Parts, and wrong for Courses, which is Phase 2. A commerce name puts a ceiling on a
platform that is deliberately not commerce-only. Stamen names the *platform*, not the
products, so it generalises across all four verticals and any fifth. Its conflict is also
genuinely weaker: a design studio in a different line of business, versus a same-class
procurement platform whose trademark may well be maintained even after the SOVRA rebrand.

Merx remains a good choice if the commerce framing is wanted deliberately.

---

## 5. Compared with Kavo

Kavo was the previous recommendation and remains viable: `kavohq.com` and
`github.com/kavohq` are both free, and the repository is already named `kavo`.

Its problem is the same shape as Texo's, one step milder. `kavo.com` is **KaVo Dental**
(Envista, formerly Danaher) — a large manufacturer that publishes formal trademark-usage
guidelines, meaning the mark is actively policed. Dental devices sit in a different class
from SaaS (9 and 42), so coexistence is normally fine. But three things are certain: the
bare `.com` will never be obtainable, "kavo" search results will be dominated by a large
incumbent for years, and a well-resourced rights-holder is above-average likely to send a
letter even where it would not ultimately prevail.

Kavo also means nothing. Stamen and Merx both say something true about the product.

| | Stamen | Merx | Kavo |
|---|---|---|---|
| `.com` + GitHub free | ✅ | ✅ | ✅ |
| Means something relevant | ✅ warp thread | ✅ merchandise | ❌ |
| Covers all four verticals | ✅ | ⚠️ not Courses | ✅ (says nothing) |
| Same-class conflict | ✅ none | ⚠️ retiring incumbent | ✅ none (different class) |
| Large active rights-holder | ✅ no | ⚠️ mdf commerce | ⚠️ Envista |

---

## 6. Honest caveat on all of it

Of **166 hand-curated brandable candidates** and roughly **3,500 generated coinages**,
nine short names survived with both `.com` and GitHub free, and five were worth naming.
Short, meaningful `.com` is exhausted — a stem plus `hq` is a good outcome in 2026, not a
compromise. Linear, Resend and others made the same trade.

If none of the five appeal, the remaining clean-but-weaker options are `texoris.com`,
`texolis.com`, `orturis.com`, `kernoris.com` and `fabrora.com` — lower collision risk,
weaker as brands.

**None of this is legal advice.** A short consultation with an Egyptian or EU trademark
attorney covering classes 9 and 42 is worth doing **before** the name is printed,
incorporated or registered — not after. It is cheap now and expensive later.

---

## 7. Also secure

- `stamen.app`, `stamen.io`, `stamen.dev` — a bare stem on a newer TLD beats `stamenhq`
  on any of them, and is often still free
- `stamen.eg` / `stamen.com.eg` — the local market TLD
- The `stamenhq` handle on X, Instagram and LinkedIn

---

## 8. Next step

1. Pick between **Stamen** and **Merx** (or keep **Kavo**).
2. Confirm the `.com` at a registrar — watch for premium pricing.
3. Register the GitHub org and transfer this repository into it.
4. Book the trademark consultation before committing the name publicly.
