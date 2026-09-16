# ADR 0002 — Project Name, GitHub Org and Domain

**Status:** Accepted (provisional — the owner chose this "for now")
**Date:** 2026-09-16 (revised)
**Decision:** **Kaabosh** · `kaabosh.tech` (owned) · GitHub org **`kaaboshhq`**

---

## 1. Decision

The project is **Kaabosh**. The primary domain is **`kaabosh.tech`**, already registered
and resolving. Alternatives surveyed in §4 were not taken up.

**GitHub:** `github.com/kaabosh` is **taken** — an existing user account (id 98673142)
with a repository named `kaabosh-website`. Given the name match, that may well be an
account already controlled by the owner; **check before doing anything else**, because if
it is, a personal account can be converted into an organization and the name kept.

If it is not reachable, **`kaaboshhq`** is free and is the recommended org name.

| Handle | Status |
|---|---|
| `github.com/kaabosh` | Taken — see above, verify ownership first |
| **`github.com/kaaboshhq`** | **Free — recommended** |

## 2. Domains worth securing now

`kaabosh.tech` is registered and in use. These returned NXDOMAIN on all three resolvers
(Google, Cloudflare, Quad9) and are **likely available**:

| Domain | Note |
|---|---|
| **`kaabosh.com`** | **Worth buying.** See §3 |
| `kaabosh.app` | Good for the merchant dashboard host |
| `kaabosh.io` | Defensive |
| `kaabosh.dev` | Defensive |
| `kaabosh.net` | Defensive |

`kabosh.com` (single `a`) is **registered** — not available as a defensive holding.

Also worth taking at the same time: `kaabosh.eg` / `kaabosh.com.eg` for the local market,
and the `kaabosh` handle on X, Instagram and LinkedIn.

## 3. Buy the `.com`

`kaabosh.com` appearing unregistered is the useful finding here, and it should not be left
sitting. For a commercial SaaS selling to merchants:

- `.com` remains the default assumption. People type it, mistype into it, and trust it.
  A share of traffic intended for `kaabosh.tech` will land on `kaabosh.com` regardless of
  what is printed on the site.
- Leaving it open means someone else can take the matching `.com` for the brand later,
  and buying it back costs many multiples of the registration fee — if it is for sale.
- It is a few dollars a year to redirect to `.tech`. There is no version of this that is
  not worth it.

Keep `.tech` as the primary if preferred; the `.com` can simply 301 to it.

## 4. Alternatives surveyed and not taken

Recorded so this is not re-researched later. Roughly **3,700 candidates** were checked
across five semantic families — turn/vertical (the *verto* root), weave/textile,
build/foundation, trade/market, and root/core.

**Every bare four-to-six letter `.com` in all five families is registered**, as is every
bare GitHub equivalent (`merx`, `texo`, `pylo`, `trama`, `plexo`, `verto` are all taken
accounts). Nine short names survived with both free; five were worth naming:

| Name | Meaning | Why not |
|---|---|---|
| Stamen | Latin: warp thread of a loom | Was the recommendation — clean, but not chosen |
| Merx | Latin: merchandise | Means *goods*; does not cover the Courses vertical |
| Texo | Latin: *I weave* | DTEN filed **TEXO in Feb 2026 expressly covering SaaS** — same class, freshly filed |
| Pylo | Egyptian temple gateway | Pylo d.o.o. (Slovenia), active software company — same class |
| Weav | Weave | WEAV trademark over *commerce-platform software*; Weav acquired by Brex; Weave (NYSE: WEAV) |

**Verto specifically** — requested by name, and unavailable: `verto.com`, `vertohq.com`
and `github.com/verto` are all taken, as is every clean variant tested (`verta`, `vertos`,
`vertix`, `vertek`, `vertio`, `vertis`, `vertum`, `vertana`, `vertiva`, `verso`, `vertly`,
`vertify`). Only `getverto.com` and `usverto.com` survived.

**Kavo** — the earlier working name, from the original repository name. `kavohq.com` and
`github.com/kavohq` are both free if it is ever revisited, but `kavo.com` is KaVo Dental
(Envista), a large and actively-policing rights-holder in a different class.

## 5. One thing to be aware of

*Kibosh* is an established English idiom — "to put the kibosh on something" means to stop,
veto or put an end to it. `kaabosh` is a different spelling, and to an Arabic-speaking
audience the association is unlikely to register at all. But for English-speaking
merchants, international investors or press, the echo is there and it points at *stopping*
rather than *building*.

This is information, not an objection — plenty of strong brands carry an odd echo, and the
name may have a meaning or personal significance that outweighs it. Flagging it once, in
case it had not come up, since the choice was described as provisional.

## 6. Method and confidence

**GitHub** — fetched `https://github.com/<name>` directly. 404 = unclaimed, rendered
profile = taken. **Definitive.**

**Domains** — RDAP, WHOIS and DNS-over-HTTPS are all blocked by this session's network
egress policy, so registration could not be read from a registry. Instead: NS/SOA lookups
against **three independent resolvers** (Google `8.8.8.8`, Cloudflare `1.1.1.1`, Quad9
`9.9.9.9`), requiring agreement across all three.

- **NS/SOA present → definitively registered.**
- **NXDOMAIN on all three → very probably unregistered.** Not proof: a domain can be
  registered with nameservers not yet delegated, or be registry-reserved or premium-priced.

`kaabosh.tech` resolves on all three, consistent with it being registered and in use.
Everything listed as available in §2 should still be **confirmed at a registrar before
purchase**.

**None of this is legal advice.** A short consultation with an Egyptian or EU trademark
attorney covering classes 9 and 42 is worth doing before the name is printed, incorporated
or registered.

## 7. Next steps

1. Check whether `github.com/kaabosh` is an account you already control. If so, convert it
   to an organization. If not, register **`kaaboshhq`**.
2. Buy `kaabosh.com` and redirect it to `kaabosh.tech`. Grab `.app` and `.io` while they
   are open.
3. Transfer this repository into the organization.
4. Environments then follow ADR 0001 §10: `kaabosh.tech` production,
   `stg.kaabosh.tech` staging, `ws.kaabosh.tech` for Reverb.
