# Prototype Gap Report — Rory Check + Premortem

**Date:** 2026-07-03
**Subject:** `rails/proto-unified-poster-v1.html` (unified configurator prototype)
**Method:** Two independent parallel audits — (1) a Rory Sutherland behavioral-science lens audit of the full coded customer journey, (2) a premortem ("it's 3 months post-launch and it failed — why?") comparing the proto against the live platform code it must be wired into.
**Status:** AWAITING USER REVIEW + FEEDBACK. Do not act on items below until the user has marked their picks.

---

## PART 1 — SYNTHESIS (as delivered in session, verbatim)

## Verdict
The prototype is **not done** — but the remaining work is *finishing*, not rethinking. Nothing structural needs to change. The Rory check found the journey's **ending is its weakest beat**, and the premortem found the proto's biggest risks live at the **seam with the real platform**, not in the design.

## 🎯 Where both audits converge (fix these first — they're design AND production bugs)

1. **The approve-gate is the single most dangerous element.** Rory: clicking Add-to-cart un-approved silently hijacks into a file picker — reads as broken or bait-and-switch; and after approving, Add-to-cart does *nothing* (no terminal beat). Premortem: the live platform deliberately supports **"art to follow"** carts with a deadline (`CartController.php:92-104`) — a hard gate blocks a flow that earns money today, and the checks it gates on are theatre (fake DPI/CMYK). **Fix: soft gate** — "Approve now or send art later (due Fri 3pm)" — and never show a green "CMYK verified ✓" the code didn't verify.

2. **Fake elements that read as fake.** The countdown never ticks (frozen "05:12:04" — urgency that curdles into distrust once noticed); the DC1 card claims "⚠ 2 issues" while showing only 1 amber cell (safe-zone cell omitted); the file-check results are hardcoded. Each is trust acid at exactly the anxious moment.

3. **Trust content silently fell out during component swaps.** Dead bindings prove it: `$('osumDate')` writes the in-hand date to an element that no longer exists (the *promise* never reaches the receipt), `priceEach`/`priceSub` per-unit reframe lost from the buy bar, orphaned "Free proof" badge CSS. Rule for future rounds: **when a winning component replaces an old one, port its trust content, not just its box.**

## 🧠 Rory gaps (design-phase — fixable in the proto now)

| # | Gap | One-line fix |
|---|---|---|
| 1 | **Guided view has no ending** — Everything gets the Step-07 packing-slip finale; Guided (built for the nervous buyer!) trails off at the artwork band | Add band 6 "Your order" reusing the already-built `.oslip` + a 7th rail node |
| 2 | **Warn path = pure liability transfer** — "Approve anyway" with no comfort; the one-click "✦ Upscale to 300 dpi" fix exists but is buried in the guides legend; post-approve the amber "⚠ 2 issues" chip never clears | Primary = Fix it, secondary = "Print as-is" + honest consequence + human-check reassurance; chip → "Approved as-is ✓" |
| 3 | **Mounting is sold in 4 places but unbuyable** — `foam`/`gator` rates exist in `FIN` but have no tiles | Add the tiles → instant good-better-best ladder (None / +$2 / +$6 / +$9, compromise effect) — or strip the copy |
| 4 | **Zero reassurance at the money surfaces** — buy bar + slip have no trust line; the killer review ("Same price they quoted, no surprises") is buried below the fold | One quiet line under the total; restore the in-hand date row |
| 5 | **Guided/Everything psychology diverges** — Guided paper tiles sell on "$5/sq ft" (a unit no consumer feels), no "★ Most popular" badge; Everything leads with feel + badge | Feel-word + live delta ("+$18") in Guided; badge parity |
| 6 | **No next-break nudge on custom qty** — type 12, nothing says 15 unlocks −20% | "3 more → $48.00/ea (save $2.40 each)" |

**Protected (both auditors flagged "don't touch"):** the `.pass` deadline card ("delivered by … — promise"), the upload labor-illusion (instant art + 1.3s staged check), per-unit under qty tiles, rail checkmark ladder, single "★ Most popular" anchor, buy-bar/quick-edit separation.

## 💣 Premortem — top production risks (build-phase, but shape decisions NOW)

1. **Pricing split-brain (HIGH×HIGH).** The proto's client math (`paper+finish × sqft × qty × dateMult`) has **no server counterpart**. Worse, two traps in the live code: `CatalogPricer.php:43` **silently falls back to `size_variants[0]`** on an unrecognized size — a custom 59×120 would price as the first variant with no error — and checkout **trusts the session-cached price** (`CartCheckoutService.php:64`), so a wrong number gets charged. → Proto math = display scaffolding only; server quotes every number; make the pricer **fail closed**.

2. **"Product-agnostic" is currently poster-shaped (HIGH×HIGH).** Real catalog products break the assumptions: grommets/hemming (`role:"passthrough"`), retractable banners with `custom_size_allowed:false` + only **3 tiers** (the date dock hardcodes 6), booklets pricing on pages/binding. → Prove the option-schema on paper against retractable + grommeted vinyl + booklet **before** wiring.

3. **Mobile is a launch-blocker, not polish.** The proto *deliberately removed* the viewport meta (round 29k's multi-monitor fix) and is a fixed-100dvh canvas; storefront traffic is majority-mobile. Reinstating the meta re-opens the exact bug you spent 3 rounds fighting. → 390px chassis spike before wiring begins.

4. **Two-views + quick-edit = every option built in ~6 synced surfaces**, and quick-edit clones live DOM via `innerHTML` — the build doc's own rounds were largely spent re-syncing these. → One renderer per option-kind emitting into N mounts from one spec.

5. **Cheap, huge wins:** per-slug rollout flag in the catalog entry (`"configurator":"v2"` → rollback = flip a JSON field in HQ, no deploy); state → sessionStorage + URL (today a refresh eats a 10-minute build); one cached price-grid endpoint (not 22 quotes per qty row); feed the date dock from real `tiers` + holidays; strip the `?v=Date.now()` self-redirect at port time.

Also flagged: artwork tokens **silently degrade to "art to follow" when the session expires** (`CartController.php:94-104`) — a customer sees "✓ approved," the order ships without the file. That one's a live-platform bug worth fixing regardless of the configurator.

## 📋 Recommended order of attack

**Phase A — finish the proto (design work, this repo, now):** Rory gaps 1–6 + the converged fixes (soft-gate UX, tick the countdown, DC1 cell parity, restore the dead bindings). Roughly one good session.

**Phase B — pre-wiring contracts (thinking, not code):** pricing contract (which engine per family, fail-closed), option-schema proven against the 3 hard products, soft-gate decision documented, mobile chassis spike.

**Phase C — wiring, gated:** per-slug flag, poster-only first, server-priced everywhere, instrumented via the existing `cfgTrack` pipe so a conversion drop is visible in days.

---
---

## PART 2 — APPENDIX A: Full Rory-lens audit (raw agent report, verbatim)

I read all three files in full: the 1706-line prototype (`/u/mtcc-print/docs/design-reference/rails/proto-unified-poster-v1.html`), the decision log (`configurator-build-doc.md`, rounds 24–29t), and `HANDOFF.md`. Audit below respects the settled decisions (two density views, N12 bar, packing-slip card, GX9/DC1 file-check cards, v2 folder-tab quick-edit, no HST, poster-as-sample, mobile/a11y/preflight deferred).

# BEHAVIORAL-SCIENCE AUDIT — proto-unified-poster-v1.html

## 1. TOP GAPS (ranked by conversion impact)

### GAP 1 — The end of the journey is a dead end; the CTA silently hijacks (peak-end rule, effort justification)
**Stage:** approve → add to cart (the END moment).
**What the code does:** A capture-phase listener (line 1696) intercepts every `.add` / `.oslip-add` click: `if(!proofApproved){ e.preventDefault(); ... triggerUpload(); }`. A nervous buyer who clicks the big purple "Add to cart" before approving gets — with zero explanation — either a native file-picker dialog or the full-screen Inspect overlay. The CTA appears broken or, worse, feels like a bait-and-switch. And when the proof IS approved, clicking Add to cart does **nothing at all** — no handler, no state, no confirmation. `approveProof()` (line 1675) just swaps rail text to "✓ approved" and re-renders the card.
**What's missing:** The single most-remembered moment (the end) is the weakest beat in the build. There is no completion peak, no "it's in — here's what happens next," no relief.
**Suggested change:** (a) When un-approved, don't hijack — respond in place: pulse/scroll to the artwork step with a friendly line ("One thing left — approve your artwork proof"), so the gate reads as care, not malfunction. (b) On approve, fire a visible forward beat (Guided: auto-advance to a review band; Everything: scroll-nudge to Step 07) and make the buy bar visibly "unlock" (e.g., the Add button gains the green check). (c) Give Add-to-cart a terminal state even in the mock — a success beat is the peak-end anchor for the whole product.

### GAP 2 — Guided view has no ending at all (peak-end, closure)
**Stage:** Guided step 6 (artwork) — last band.
**What the code does:** Everything view ends on a designed finale: `Step 07 · Your order — "Ready to print."` with the `.oslip` packing slip (lines 993–1010). Guided's last band (`data-band="5"`, lines 1150–1154) is just the dropzone — it has **no `.navrow`, no Next button, no review step**, and the rail (`#rail`, lines 840–845) has only 6 nodes with no "Order/Review" node. After approving, a Guided user is left staring at the artwork band; the journey trails off.
**What's missing:** The Everything buyer gets a receipt-shaped closure ritual; the Guided buyer — the nervous first-timer the Guided view exists FOR — gets none.
**Suggested change:** Add a band 6 "Your order" that shows the same `.oslip` packing slip (it's already built and live-bound), reachable via "Next: Review →" on the artwork band and auto-advanced-to on approve. Add a 7th rail node ("Order") that earns the final green check — completing the checkmark ladder is itself a reward.

### GAP 3 — WARN path: liability is transferred to the customer, and the fix that exists in the code is never offered (loss aversion, blame reversal)
**Stage:** file-check warn → "Approve anyway".
**What the code does:** In warn state the card heading is "Needs a look", chip "⚠ 2 issues", and the sole primary button becomes `Approve anyway →` (`fckApprove()`, line 1629; `renderProofChecks()`, line 1619). Three problems: (a) **The one-click fix exists elsewhere but not here** — `#pfFix "✦ Upscale to 300 dpi"` lives only in the guides legend `.pfbar` (line 1030), which most users will never toggle on; the warn card offers no fix, only Replace / Inspect / Approve-anyway. (b) **"Approve anyway" is pure liability framing** — it makes the customer sign a waiver with no consequence explanation and no comfort. The reassurance that would defuse it ("a human proofs it before it prints", "we'll flag anything that shifts") exists ONLY in the SEO block (lines 1228, 1238), below the fold, invisible at the moment of anxiety. (c) **After approving, the chip still reads "⚠ 2 issues"** — `fckGuided()`/`fckEverything()` render the warn chip regardless of `proofApproved`, so the approved-with-issues end state stays visually anxious.
**Suggested change:** Warn card CTA hierarchy: primary = "✦ Fix it — upscale to 300 dpi" (reuse the pfFix concept), secondary = "Print as-is" with one line of honest consequence ("may look soft up close — most viewers won't notice at booth distance") plus the human-check reassurance line pulled up from the SEO copy. Post-approve, the chip should change state ("Approved as-is ✓") — never leave an amber warning on a completed step.

### GAP 4 — Mounting is sold in the copy but unbuyable in the UI (choice architecture, revenue)
**Stage:** finish step.
**What the code does:** The `FIN` pricing object (line 1279) contains `foam:{n:'Mounted · foamcore',r:6}` and `gator:{n:'Mounted · gatorboard',r:9}` — the highest-margin finishes. But both finish rows (`#finRow` lines 1089–1093, `#coFin` lines 902–908) offer only None / Gloss laminate / Matte laminate. Meanwhile the copy actively sells mounting: Guided finish navhint "Mounted posters stand on an easel or hang flush" (line 1094), date-band navhint "Mounting adds a day — we've built that in" (line 1147), the Everything Step 03 subcopy "mounting makes it rigid and ready to hang" (line 901), and a whole SEO section (line 1218). The customer is told about an option they cannot click.
**What's missing:** Beyond the inconsistency, the finish step has no good-better-best ladder and no decoy — "None" vs two identical +$2 laminates gives no reason to trade up.
**Suggested change:** Add the two mounting tiles. The resulting ladder (None / +$2 lam / +$6 foam / +$9 gator) makes foamcore the compromise-effect winner and lifts finish attach rate. If mounting is genuinely out of scope for the sample product, strip the mounting copy from all four locations — dangling references erode trust.

### GAP 5 — Zero reassurance at the two commitment surfaces (trust signals, anxiety at the point of money)
**Stage:** buy bar + Step-07 packing slip.
**What the code does:** The N12 bar (lines 734–746) is Total | Size | Paper | In hand + Save build + Add to cart — no trust content. The chosen `.oslip` packing slip (lines 998–1009) is header, 2 line items, total, Add button. Notably, the CSS still contains the orphaned `.osum-badge` ("Free proof") and `.osum-rea` (reassurance line) styles from the pre-packing-slip receipt (lines 316, 322) — **the reassurance elements existed in round 29l and were lost in the swap to the packing slip**; the markup no longer uses them. "Free proof on every order" survives only as .6rem util-bar text (line 709) and in the artwork-step microcopy. Reviews (concrete, excellent ones — "Same price they quoted, no surprises") sit exclusively below the fold in `.seo` (lines 1244–1248). Also: the slip has no in-hand date row at all — `applyDate` still writes to a ghost `$('osumDate')` (line 1447) that has **no corresponding element** in the `.oslip` markup. The one thing the customer was promised ("delivered by Tue, June 16 — promise") vanishes from the final receipt.
**Suggested change:** One quiet line under the slip total / bar: "Free proof · human-checked before press · HST is the only thing added at checkout." Restore the in-hand date as a slip row (the binding already exists — add `id="osumDate"`). Consider floating one review snippet near the buy bar ("Same price they quoted, no surprises — Priya S.") — the anti-hidden-fees quote is precision-targeted at checkout anxiety.

### GAP 6 — No per-unit reframe or quantity at the moment of commitment (pricing psychology)
**Stage:** buy bar at qty > 1.
**What the code does:** `paint()` still writes to `priceEach` and `priceSub` (lines 1342–1343) but those ids **don't exist in the N12 markup** — dead bindings. So at qty 50 the bar reads a naked "$3,600.00" with no "/ea", and neither the bar segments (Total|Size|Paper|In hand) nor the drawer footer (Total|Size|In hand) shows **Quantity** anywhere. Per-unit price lives only on the qty tiles (`.qper`) and the rail.
**What's missing:** Big totals need the per-unit anaesthetic exactly where the money decision happens. And a bar that doesn't say "50 posters" invites "wait, what am I paying $3,600 for?"
**Suggested change:** Add a Qty segment (`bb-cell`) and a small "$72.00 /ea" under or beside the total (the computation and flash plumbing already exist).

### GAP 7 — Guided paper step sells on price; Everything sells on feel (signalling divergence, framing)
**Stage:** paper/stock.
**What the code does:** Guided `#paperRow` tiles show only a swatch + name + "$5/sq ft" (lines 1076–1081) — price-per-square-foot, a unit no consumer can feel, as the ONLY descriptor. Everything's `#coStocks` cards lead with feel ("Glare-free · deep colour", "Thick · premium") and carry a "★ MOST POPULAR" ptag on satin (line 856). Guided satin has **no popular badge** and no descriptors. The killer stat in the SEO copy — "what 8 in 10 customers pick" (line 1208) — never appears in either configurator view.
**Suggested change:** In Guided, replace the `/sq ft` sublabel with the feel word + a live delta for the current size ("included" / "+$18" / "+$84" — computed from `PAPER[].r × sqft()`), add the popular badge to satin, and surface "8 in 10 pick this" at the point of choice. Absolute per-sq-ft rates anchor on arithmetic; deltas anchor on the decision.

### GAP 8 — Quantity breaks: percent-only, break-tiles-only, and the custom input is nudge-blind
**Stage:** quantity.
**What the code does:** `.qsave`/`.be` badges show "−20%" only on exact break tiles (lines 1105–1119); `updateQtyTiles()` puts $/ea under every tile (good). The custom qty input (`#bandQtyInput`/`#coQtyInput`) accepts any number silently — type 12 and nothing tells you 15 unlocks −20%; type 23 and nothing points at 25.
**What's missing:** Percent framing is weaker than dollars for concrete goods, and the next-break nudge — the single highest-leverage upsell in print — doesn't exist.
**Suggested change:** Beside the custom input (and on flash), show "3 more → $48.00/ea (save $2.40 each)". Consider dollar-izing the badges ("save $36") at least at the 10+ tiers.

### GAP 9 — The countdown never ticks (urgency that reads as fake)
**Stage:** in-hand pass card, both views.
**What the code does:** `.pt` "05:12:04" / "and this price is yours" is hard-coded in both pass cards (lines 979, 1141) with **no interval updating it** — the build doc calls it a "countdown stub". A frozen countdown, once noticed, converts urgency into distrust ("this whole thing is theatre") and contaminates the genuinely honest elements around it (the price-lock promise, the tier calendar).
**Suggested change:** Tick it (trivial JS), define what happens at zero (roll to the next cut-off with copy — "missed today's cut-off, tomorrow's price shown"), and consider showing it only after a date has been actively picked — a countdown you didn't opt into is pressure; one on a date you chose is service.

### GAP 10 — DC1 card's issue count doesn't match its visible cells (trust arithmetic)
**Stage:** Everything warn card.
**What the code does:** `fckData()` counts fails across 4 checks including safe-zone (line 1626–1628), so warn state yields chip "⚠ 2 issues". But `fckEverything()`'s stat cells are Size / Res / Color / Bleed (lines 1645–1648) — **safe-zone is omitted**, so the card claims 2 issues while displaying only 1 amber cell. The Guided GX9 card shows all four checks correctly. An anxious customer counting cells against the chip finds a discrepancy at the exact moment they're deciding whether to trust the machine.
**Suggested change:** Swap the DC1 "Color" or "Size" cell for Safe-zone (Guided proves 4 fits), or compute the chip from visible cells only.

## 2. WORKING WELL — protect these

- **The `.pass` deadline card** (lines 970–983, 1132–1146): "Printed, trimmed and delivered by … — promise", "Order it now — price is locked in" with green check, "Wait a little longer, saves a little more" in green, tier-coloured badge synced to the dock. This is the psychological high point of the flow — protect it, and note GAP 5's finding that its promise currently never reaches the receipt.
- **Upload → instant art on hero + labor-illusion check** (`uploadArt()` line 1669: art lands immediately, then a 1.3s spinner with staged messages "Reading dimensions → Measuring resolution → …" in `startCheck()`). The pacing manufactures perceived diligence. Don't speed it up.
- **Per-unit price under every qty tile** (`updateQtyTiles()`, `.qper`) — quiet, honest, effective.
- **Green rail checkmarks on completed steps** (`.rn.done::after`, line 145) and purple circle-checks on selected tiles — visible progress/endowment.
- **One confident "★ Most popular" anchor on 36×48** in both views (round 29c decision) — correct; don't dilute.
- **Custom size honesty**: live shape preview (`#csPrevBox`), "we round up to the nearest inch" hint, auto-orientation — reduces custom-size fear.
- **Buy bar as pure commitment surface** with Quick-edit exiled to the folder tab (round 29l Rory call) — the separation is right; GAPs 5–6 are additive, not structural.
- **The SEO reviews copy** — outcome-specific, objection-targeted ("Same price they quoted, no surprises"). The words are right; only their placement is wasted.
- **Approve-gated add-to-cart** — the right instinct (weight where commitment is); only its feedback is broken (GAP 1).

## 3. INTERNAL INCONSISTENCIES (Guided vs Everything psychology divergence)

1. **Step order / first anchor**: Guided opens on Size (band 0, no prices visible); Everything opens on "Step 01 · The print" with six $/sq-ft prices as the first thing seen. Two different anchoring strategies in one product — the first number an Everything user meets is $14/sq-ft backlit; a Guided user meets $60 total.
2. **Social proof asymmetry**: satin's "★ MOST POPULAR" ptag and the sizes' social-proof line exist only in Everything; Guided has only the size qbadge. (Also a copy bug: line 875 reads "the four most **booths order** at events like this" — broken grammar in a trust-carrying sentence.)
3. **Paper framing**: Guided = price-only sublabels; Everything = feel-first descriptors (GAP 7).
4. **Terminal beat**: Everything gets Step 07 + packing slip; Guided ends nowhere (GAP 2).
5. **Warn-card check coverage**: GX9 shows 4/4 checks; DC1 shows 3 of the 4 it counts (GAP 10).
6. **SEO block contradicts the configurator**: sizes "18×24, 24×36 (most popular), 27×40, 36×48" (lines 1195, 1215) vs the builder's 36×48/A0/46×45/96×48 with 36×48 most popular; "150 dpi minimum" (line 1222) vs the warn state flagging **168** dpi as "Low resolution"; mounting described at length but unbuyable (GAP 4). Placeholder content, but if the pattern ships, page-vs-builder contradictions are trust acid.
7. **Dead bindings as evidence of lost psychology**: `$('osumDate')` (line 1447), `$('priceEach')`/`$('priceSub')` (lines 1342–1343), and orphaned `.osum-badge`/`.osum-rea` CSS — each one is a reassurance/reframing element that existed in an earlier round and silently fell out during component swaps. Worth a sweep rule: when a winning component replaces an old one, port its trust content, not just its box.
8. **Risk note (settled in 29m, flagging for the A/B list only, not re-litigating)**: the reverse-psychology flash (GREEN = price up, `pdelta.up{color:#059669}`, line 91) applies at the buy bar — the same surface where "green = savings" badges (`.qsave`, green) appear. Two green vocabularies with opposite meanings coexist within 100px at the commitment surface; if the flash gamble underperforms, this collision is the first suspect.

---
---

## PART 3 — APPENDIX B: Full premortem (raw agent report, verbatim)

# PREMORTEM: Unified configurator wired into the live platform — why it failed at T+3 months

## Context established from code

The live platform already has a working universal configurator at `/products/{slug}` (route: `U:\mtcc-print-platform\config\routes.php:673`) — a 3-column server-rendered page (`src/Http/Views/storefront/configurator/page.php`) driven by `window.CONFIGURATOR_STATE` + a 3,641-line `assets/js/products/configurator.js` that already does: debounced server pricing (`/api/catalog/pricing`, js:2427), server-authoritative cart add (js:2964 → `CartController::add`), artwork upload w/ tokens (js:3409 → `ArtworkController`), holiday/cutoff math, templates, AI design, quote-routing, analytics. The prototype is not "a configurator to wire in" — it is a **replacement view layer** for all of that, exercised only by a fake poster.

## Ranked failure modes (likelihood × impact)

### 1. Pricing split-brain: the proto's client math has no server counterpart (HIGH × HIGH)
**Story:** Three weeks in, customers screenshot a configurator total that doesn't match their cart, and custom-size posters get silently priced as the wrong SKU; refunds and support tickets spike, trust in displayed pricing collapses.
**Evidence — proto:** `proto-unified-poster-v1.html:1324-1326` — price = `(PAPER.r+FIN.r) * sqft * qty * volMult(qty) * (1+st.dAdd)`, with invented rates (`PAPER` at :1278, `volMult` breaks at :1325) and a client-side date multiplier `dAdd` from `datedock.js` (fixed 6-tier ratio curve, datedock.js:12). Custom size continuous to 59″×120″ (`ACLICK_MAX`, :1275).
**Evidence — platform:** the real path is `CatalogPricer::price()` (`src/Pricing/CatalogPricer.php:34-88`): discrete `size_variants` → vendor SKU → live wholesale × `MarkupCurve` (bands + tier-addons + category overrides + competitor data, `MarkupCurve.php:6-27`, `data/shared/pricing/competitor-prices.json`). **Critical trap at CatalogPricer.php:43 and CartController.php:75:** an unrecognized size string *silently falls back to `size_variants[0]`* — the proto's free-form custom W×H would price as the first variant with no error. Meanwhile the *other* engine (`PricingEngine`/`AreaPricingTable`, area-banded CSV `data/shared/pricing/poster.csv` with 6 tier columns) is what actually supports poster area pricing — but it serves the legacy `/order/posters` flow (`routes.php:650`), not `/products/{slug}`. There is no server implementation of the proto's paper+finish+sqft+volume model at all. Also: checkout **trusts the session-cached `retail_cents`** (`CartCheckoutService.php:64,181`) — whatever mispriced number gets into the cart is what Stripe charges.
**Cheapest derisk:** rule that the proto's `total()` is display-scaffolding only; every visible number comes from `/api/catalog/pricing` (the pattern already in configurator.js:2395-2440). Make `CatalogPricer` **fail closed** on unmatched size (return 0/422, not variant[0]). Decide per product family which engine (CatalogPricer vs PricingEngine) backs the unified UI *before* wiring, and add a golden-file test: N specs × both engines × expected cents.

### 2. "Product-agnostic framework" is poster-shaped: the real option schema doesn't fit (HIGH × HIGH)
**Story:** Poster ships in month 1; then banners, booklets, and rigid signs each turn into a bespoke rebuild, the "framework" claim dies, and the rollout stalls at one product.
**Evidence — proto:** hardcoded vocab: `SIZES` (4 presets + custom, :1274), `PAPER` (6), `FIN` (5), orientation, one qty row. `paint()` (:1331-1374) writes ~30 hardcoded element IDs (`rvPaper`, `bbPaper`, `osumFin`, `drawerSize`…). No schema-driven renderer exists; the build doc itself defers "Back-end option-schema admin (Phase 2)" (`configurator-build-doc.md:69-72`).
**Evidence — platform:** real products are arbitrary `option_groups` with roles: `vinyl_banners_banners_13oz_matte_vinyl.json` has **grommets** (6 options, `role:"passthrough"`, line ~641), **hemming**, and ~70 discrete sizes; `retractable_banner_standard.json` has `custom_size_allowed:false`, enumerated quantities, and only **3 tiers** (standard/nextday/sameday) vs the proto dock's fixed 6-tier ladder (`datedock.js:29 tierForDate` assumes early…sameday); booklets price on pages/cover/binding (configurator.js:2391 comment). Poster's platform config caps at 96×48 with min 12×12 (`config/products/poster.json:23-24`) vs proto's 59×120 A-Click roll math. The proto has no concept of role:"passthrough" options, per-entry tier sets, enumerated-qty-only products, or size-variant→SKU mapping.
**Cheapest derisk:** before any wiring, write the option-schema contract (JSON: group → render-kind → surface placements) and hand-render **retractable banner + vinyl banner w/ grommets + booklet** as paper mockups against it. If the schema can't express them, the chassis isn't ready.

### 3. Artwork: approve-gate contradicts the live trade model, and the checks it gates on are fake (HIGH × HIGH)
**Story:** Conversion drops because "art to follow" buyers — a flow the platform deliberately supports — are now hard-blocked at Add-to-cart; worse, customers who *do* approve trusted a "300 DPI · CMYK · Print-ready ✓" card that never inspected their file, and vendor preflight rejections/reprints land on Print Stuff.
**Evidence — proto:** capture-phase interceptor blocks all `.add` clicks until `proofApproved` (:1696); the checks are hardcoded theatre — `proofChecksData()` returns `dpi: proofWarn?168:300` with a manual "Preview a flagged file" toggle (:1608-1611); upload is a 1.3-second `setTimeout` (:1669-1671).
**Evidence — platform:** the trade model explicitly allows cart lines with `'status'=>'to_follow'` plus an artwork deadline (`CartController.php:92-104`, configurator.js:2958-2963 sets `artwork_due` from tier cutoff). `ArtworkController.php` validates extension/magic/size only; `preview_url` exists **only for raster images** (:67, :86) — the proto's canonical `artwork.pdf` gets no hero preview and no DPI measurement; no Imagick/Ghostscript rasterization path exists in `src/` for uploads, and it's a cPanel shared host (CLAUDE.md:45,113). HANDOFF.md:41 defers real preflight to "build phase" — but the entire approve-gate UX is built *on* those results, so this deferral CAN block launch as designed.
**Cheapest derisk:** ship the gate as **soft** ("Approve now or send art later — due {cutoff}") preserving `to_follow`; scope preflight v1 to what's actually checkable server-side (PDF page-box parse for dims/bleed — pure-PHP parser is feasible; DPI only for rasters via `getimagesize`); never render a green "CMYK verified" the code didn't verify — say "we'll check it" instead.

### 4. Two-views (+ quick-edit) means every option renders in ~6 synced surfaces (MED-HIGH × HIGH)
**Story:** The build stalls: each new option type must be built for the Guided band, the Everything scene, the Quick-Edit v2 drawer, the rail, the buy bar, and the Step-07 slip, all kept in sync by hand — velocity per product craters and bugs live in the least-tested surface.
**Evidence — proto:** duplicated ID pairs everywhere (`passDate2/pcD2/pcB2/pcW2/passTier2`, `cW/cH` vs `coCW/coCH`, `csUnit/csUnitB`, `pdelta/pdelta2`); `applyDate` fans out to both cards (:1436-1448); Quick-Edit v2 **clones live DOM via innerHTML/outerHTML and strips IDs** (`openSheet2`, :1544-1560), re-binding by class/data-role (`syncV2Custom`, :1571-1578) — fragile against any dynamic markup. The build doc's rounds 29a-29t are largely spent re-synchronizing these surfaces (e.g. Round 29b: "stale `18x24` keys would have crashed `dims()`" — the sheet drifted from the main form *within the prototype itself*).
**Cheapest derisk:** one renderer function per option-kind that emits into N mount points from one spec (the platform's partial pattern), and delete Quick-Edit's clone-the-DOM approach in favor of re-rendering from state. If that refactor isn't budgeted, cut one density view for launch and A/B the second later.

### 5. State lives in-page: refresh/back/multi-line all lose the build (HIGH × MED)
**Story:** A researcher spends 10 minutes configuring, tabs away to find their PDF, comes back to a refreshed page (or hits back after the cart drawer) — build gone, sale gone.
**Evidence — proto:** all state is the in-memory `st{}` object (:1280); no URL params, no localStorage; "Save build" is a stub that flashes "✓ Saved" (:1588). The `?v=Date.now()` self-redirect (:6) additionally guarantees a *new URL identity* per visit.
**Evidence — platform:** server hydrates initial state via `CONFIGURATOR_STATE` (page.php:184) but ongoing spec state is also client-only; cart is `$_SESSION` (CartController.php:36) and artwork tokens are session keys that **silently degrade to "art to follow" when expired** (CartController.php:94-104) — user saw "✓ approved", order ships without the file. `data/shared/saved-builds.json` exists, so a persistence backend is partially there.
**Cheapest derisk:** serialize `st{}` to `sessionStorage` + URL (`?size=&paper=&qty=`) on change; on cart-add, have the server response echo the resolved artwork status and surface "art attached ✓ / due Fri 3pm" in the drawer instead of trusting the client's approved flag.

### 6. Mobile/viewport time bomb — deferral that blocks launch (MED-HIGH × HIGH)
**Story:** Launch week, majority-mobile storefront traffic hits a fixed 100dvh desktop chassis with **no viewport meta tag**, gets a zoomed-out unusable page, and conversion drops platform-wide.
**Evidence:** the proto *deliberately removed* `<meta name="viewport">` to fix a desktop multi-monitor bug (proto:7-8; build-doc Round 29k: "THE full-screen bug, finally found"), after three failed JS height hacks (rounds 29g/29j documenting `--appvh` written then fully reverted). Making production responsive (pending "Rory", HANDOFF.md:39) requires reinstating the viewport meta — which re-opens the exact desktop bug the proto spent three rounds fighting. The full-screen `height:100vh/lvh/dvh` `.screen` chassis (proto:40) is fundamentally fixed-canvas; the live configurator is a normal scrolling document.
**Cheapest derisk:** treat "responsive" as a chassis decision, not a polish pass — prototype the Guided view at 390px width *before* wiring begins; keep the live configurator as the mobile fallback behind a UA/width check if needed.

### 7. Live-pricing traffic amplification on a shared host (MED × MED-HIGH)
**Story:** Every option click needs a server quote, and the qty row alone shows per-unit prices on 22 tiles — the cPanel host and the SinaLite vendor API start timing out under one busy afternoon, and prices render as "…".
**Evidence — proto:** `updateQtyTiles()` computes per-unit price for every `[data-qty]` tile on every paint (:1272); the ±$ flash fires synchronously on every state change (:1373). **Evidence — platform:** real pricing is one debounced network quote per spec (configurator.js:2427, 350ms debounce, latest-wins), sometimes hitting a live vendor API per call (`CatalogPricer` → adapter → SinaLite); there is no batch/tier-grid endpoint in the storefront shape (`StorefrontQuoter.php:88-90` explicitly *strips* `tier_prices`).
**Cheapest derisk:** add a "price grid" endpoint returning the whole qty×tier matrix for the current non-qty spec in one call (the engine already computes `tier_prices`), cache it per spec-hash in `data/shared/cache/`, and make the flash animate only on server-confirmed totals.

### 8. Asset/page architecture collides with the platform pipeline (MED × MED)
**Story:** The 1,706-line self-contained page gets pasted into a view, its generic class names (`.add`, `.seg`, `.screen`, `.band`, `.poster`) collide with `configurator.css`'s 3,250 lines of `cfg-*` rules, the `?v=Date.now()` self-redirect ships and doubles every page load while defeating HTTP caching entirely, and a stale-CSS incident follows the first FTP deploy because the manual cache-buster wasn't bumped.
**Evidence:** proto:6 (self-redirect); proto has zero namespacing vs platform convention (all live classes `cfg-`-prefixed); asset versioning is a hand-bumped `$assetVer = '62'` (page.php:20) + service-worker `$cacheVersion` (CLAUDE.md rule 6); deploys are confirm-every-upload FTP with no terminal (CLAUDE.md rules 3-4); proto also adds a second Google-Fonts family (Inter) and ships its own header (`uhdr`) duplicating the themed `_nav.php`.
**Cheapest derisk:** strip the dev-only self-redirect at port time (grep-gate it in the CI lint); namespace all proto classes `cfg2-`/`ucfg-`; reuse `_nav.php`; split CSS/JS into `assets/{css,js}/products/` files run through `scripts/build-assets.php`.

### 9. Big-bang route swap on a money-making page, thin rollback (MED × HIGH, cheap to mitigate)
**Story:** The new page replaces `storefront.configurator.page` for the single `/products/{slug}` route that serves *every* catalog entry (~100+ styles incl. the business-card volume line), a booklet-specific bug surfaces day 2, and rollback is an after-hours FTP re-upload with a cache-buster bump.
**Evidence:** one route serves all entries (routes.php:673); the current page carries live revenue (business cards with static `mock_pricing` + generic products via live pricing); `config/feature-flags.php` + `docs/feature-flags.md` exist but nothing gates configurator version per product; staging exists (`staging.print-stuff.ca`, CLAUDE.md:30).
**Cheapest derisk:** per-slug/per-category flag in the catalog entry (`"configurator": "v2"`) checked in `ConfiguratorController::show` to pick the view — launch poster-only, expand per product, rollback = flip one JSON field via the HQ Catalog Manager (no deploy at all).

### 10. In-hand date promises diverge from real cutoff logic (MED × HIGH operationally)
**Story:** The proto dock promises "Wed by 1pm · price locked" using its own tier ladder and 9AM/1PM/4PM chips; real per-product tiers, cutoff hours, holidays and pickup options disagree, and ops eats missed same-day promises.
**Evidence:** proto/datedock: fixed ratio curve + `tierForDate` biz-day walk, times "By 9 AM / 1 PM / 4 PM" (build-doc Round 29e); platform: per-product `tiers` with `cutoffHour` + `delivery_times` of 9am/12pm/3pm/6pm/anytime (`config/products/poster.json:31-46`), holidays inlined from `data/shared/holidays.json` (page.php:32-41), per-entry tier subsets (retractable banner: 3 tiers), `BusinessCalendar` in `src/Pricing/`. "Price locked" text is also legally wrong until quote-hold semantics exist.
**Cheapest derisk:** keep the dock's UI but feed it entirely from `CONFIGURATOR_STATE.tiers` + `window.HOLIDAYS` + the existing `getCutoffForTier` logic in configurator.js — delete datedock.js's internal curve.

### 11. Organizational: single dev, messy branches, live-edited data dir (MED × MED)
**Evidence:** platform repo is checked out on `feat/pricing-cockpit` with a dirty working tree (7 modified + untracked files) while `main` lags; orphan/backup branches (`backup-orphan-local-2026-05-13`, `claude/*`); legacy repo has 339 dirty files + CRLF phantom churn (HANDOFF.md:45); ~14 knowingly-failing tests muddy the CI signal (CLAUDE.md:101); `data/shared/catalog|pricing` is edited live on the server and git deploys can wipe it (CLAUDE.md rule 2). A multi-month view-layer rewrite on top of this = high chance of a bad merge or a data/ clobber during the launch window.
**Cheapest derisk:** before wiring: merge/park `feat/pricing-cockpit`, cut a `feat/configurator-v2` off a green `main`, snapshot server `data/` → git backfill, and get the baseline tests to a clean known-good list.

### Deferred items as launch-blockers only
- **Mobile (pending Rory):** BLOCKS launch (see #6) — the chassis itself is desktop-fixed, not just unpolished.
- **Real preflight:** BLOCKS the approve-gate as designed (see #3); soft-gate workaround unblocks.
- **Accessibility:** does not block launch technically; native `<details>` FAQ etc. already set a partial precedent (page.php:105-123). Schedule, don't gate.

## Prerequisites-before-wiring checklist

1. **Pricing contract signed off:** which engine backs each product family; `CatalogPricer` fails closed on unknown size; custom-size path defined (extend PricingEngine area tables or forbid custom in v2); golden-price test fixture (spec → cents) in `tests/`.
2. **Option-schema spec** proven on paper against retractable banner, grommeted vinyl banner, and booklet — including per-entry tier subsets and `role:passthrough` groups.
3. **Soft artwork gate decision** documented (approve OR art-to-follow-with-deadline), and truthful v1 preflight scope (dims/bleed from PDF boxes; DPI raster-only).
4. **Per-slug rollout flag** in catalog entries + rollback drill executed once on staging.
5. **State persistence plan:** sessionStorage + URL params; server echo of artwork status at cart-add.
6. **Price-grid endpoint + cache** for qty-tile per-unit pricing (one call, not 22).
7. **Port hygiene gates in CI:** no `Date.now()` cache-bust, no unprefixed classes, viewport meta present, asset files (not inline) with bumped `$assetVer` + SW `$cacheVersion`.
8. **Mobile chassis spike** at 390px before any desktop wiring.
9. **Repo hygiene:** pricing-cockpit branch resolved, clean baseline test list, `data/` live→git backfill snapshot.
10. **A/B or at minimum before/after conversion instrumentation** via the existing `cfgTrack`/`/api/analytics/events` pipe (configurator.js:2683) so "conversion dropped" is detectable per-product within days, not months.

Key files: proto `U:\mtcc-print\docs\design-reference\rails\proto-unified-poster-v1.html` (pricing :1274-1326, paint :1331, quick-edit clone :1544, approve gate :1696, self-redirect :6); platform `U:\mtcc-print-platform\src\Pricing\CatalogPricer.php` (:43 silent variant fallback), `src\Http\Controllers\Storefront\CartController.php` (:75 fallback, :92-104 artwork degrade), `src\Orders\CartCheckoutService.php` (:64 trusts session price), `assets\js\products\configurator.js` (:2395 live pricing, :2958 art-to-follow), `src\Http\Views\storefront\configurator\page.php` (:20 manual assetVer), `config\routes.php` (:673 single route), `data\shared\catalog\*.json` (option schema reality).
