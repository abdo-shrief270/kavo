# ADR 0002 — Project Name, GitHub Org and Domain

**Status:** Proposed — needs a human decision
**Date:** 2026-09-16
**Checked:** 2026-09-16

---

## Recommendation

**Name:** Kavo
**GitHub org:** `kavohq` — https://github.com/kavohq
**Domain:** `kavohq.com`

Matched pair, four-letter stem, pronounceable in English and Arabic (كافو), and the `hq`
suffix is a well-established convention for exactly this situation (a good short stem
whose bare `.com` is gone). The repository is already named `kavo`, so nothing is thrown
away.

**Before buying anything, read the trademark note in §4.** It is not a blocker, but it
is a real consideration and it is cheaper to think about now.

---

## 1. Method and confidence

**GitHub** — checked by fetching `https://github.com/<name>` directly. HTTP 404 means the
account name is unclaimed; a rendered profile means taken. This is **definitive**.

**Domains** — RDAP, WHOIS and DNS-over-HTTPS endpoints are all blocked by this session's
network egress policy, so registration status could not be read from a registry. What was
done instead: direct NS/SOA lookups against **three independent public resolvers**
(Google 8.8.8.8, Cloudflare 1.1.1.1, Quad9 9.9.9.9), with agreement required across all
three.

That distinction matters:

- **NS or SOA records present → definitively registered.** No ambiguity.
- **NXDOMAIN on all three resolvers → very probably unregistered.** Not proof. A domain
  can be registered with no nameservers delegated — typically one just bought and not yet
  configured, or a registry-reserved or premium-priced name.

So: the "taken" column below is certain; the "available" column is a strong signal that
should be **confirmed at a registrar before purchase**. Expect a small number of the
listed names to come back as premium-priced rather than standard registration.

---

## 2. Results — GitHub

| Org name | Status |
|---|---|
| `kavo` | **Taken** — existing user account "KaVo" (9 followers, 2 public repos) |
| `kavohq` | **Available** |
| `kavo-hq` | **Available** |
| `kavocloud` | **Available** |
| `kavoplatform` | **Available** |
| `kavocore` | **Available** |
| `usekavo` | **Available** |

## 3. Results — `.com`

**Confirmed registered** (NS records present):

`kavo.com` · `getkavo.com` · `trykavo.com` · `kavoapp.com` · `kavolabs.com` ·
`kavostack.com` · `kavora.com` · `kavoos.com` · `kavoly.com` · `kavonis.com` ·
`kavoria.com` · `kavoro.com` · `kavito.com` · `qavo.com` · `cavo.com` · `navo.com` ·
`zavo.com` · `lavo.com` · `tavo.com` · `savo.com` · `ravo.com`

**No DNS on any of three resolvers — likely available:**

| Domain | Matching GitHub org | Comment |
|---|---|---|
| **`kavohq.com`** | `kavohq` ✅ | **Recommended.** Shortest, cleanest pair |
| `kavocloud.com` | `kavocloud` ✅ | Good second choice. Slightly generic |
| `kavoplatform.com` | `kavoplatform` ✅ | Accurate but long, and "platform" ages into a liability once there are four products |
| `kavocore.com` | `kavocore` ✅ | Reads more like an internal component than a company |
| `usekavo.com` | `usekavo` ✅ | The `use-` convention reads as a product page, not a company |
| `kavocommerce.com` | — | Too narrow — this platform is also Courses |
| `kavosaas.com` · `kavosys.com` · `kavobase.com` · `kavoflow.com` · `kavoengine.com` · `kavogrid.com` · `kavoworks.com` | — | All clear, none better than `kavohq` |

## 4. Trademark note — read this before buying

`kavo.com` belongs to **KaVo Dental**, a long-established dental-equipment manufacturer
(part of Envista, formerly Danaher). They publish formal trademark-usage guidelines,
which means the mark is actively registered and actively policed.

What this does and does not mean:

- Trademark protection is scoped by class. KaVo's mark sits in dental and medical devices;
  a vertical SaaS platform sits in classes 9 and 42 (software, and software-as-a-service).
  Different classes and non-overlapping markets — **coexistence is normally fine**, and
  plenty of unrelated brands share a name across classes.
- **But** three practical consequences are certain regardless: `kavo.com` will never be
  available; "kavo" search results will be dominated by a large incumbent for years; and
  a well-resourced rights-holder is more likely than average to send a letter about a
  similar mark, even where it would not ultimately prevail.
- This is a cheap question to settle. A short consultation with an Egyptian or EU
  trademark attorney, covering classes 9 and 42, is worth doing **before** anything is
  printed, incorporated or registered — not after.

**If that risk is unacceptable, the honest position is that there is no easy alternative.**
Of 166 hand-curated brandable candidates checked — textile and weaving terms fitting the
Fashion vertical (`loom`, `warp`, `weft`, `heddle`, `selvedge`), Arabic commerce words
(`matjar`, `souqly`, `rukn`, `tijara`, `dukkan`, `manasa`, `mizan`), construction and
foundation metaphors (`plinth`, `keystone`, `bedrock`, `basalt`, `granite`), and Egypt or
Nile references (`kemet`, `nilo`, `giza`, `minaret`, `caravan`) — **six were free**, and
none of those six were good. A further 2,300 generated coinages produced nothing
pronounceable enough to build a brand on.

Short, meaningful `.com` names are exhausted. A four-letter stem plus a conventional
suffix is a good outcome, not a compromise.

---

## 5. Also secure

Cheap, and worth taking at the same time to protect the name:

- `kavo.app`, `kavo.io`, `kavo.dev` — the bare stem is often still free on newer TLDs and
  beats `kavohq` on any of them
- `kavo.eg` / `kavo.com.eg` — the local market TLD
- The `kavohq` handle on X, Instagram, LinkedIn

If `kavo.app` turns out to be free, it is worth considering as the primary and keeping
`kavohq.com` as the redirect. A four-letter bare stem reads better than any suffixed form.

---

## 6. Next step

1. Confirm `kavohq.com` at a registrar (watch for premium pricing).
2. Register the GitHub org `kavohq` and transfer this repository into it.
3. Book the 30-minute trademark consultation before committing the name publicly.
