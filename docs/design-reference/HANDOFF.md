# MTCC Print — Configurator prototype · Handoff note

**Last updated:** 2026-06-23 (Toronto) · **Machine:** macOS desktop (drive "UNCRKD 2.0") · **Surface:** Claude Code CLI

## What this is
A **product-agnostic FRAMEWORK prototype** for the product configurator. "Poster" is only the **sample product** used to exercise the framework — other products supply their own images, options, sizes, finishes, and copy dynamically. Pricing, in-hand dates, and the artwork file are intentionally **placeholder**. **HST is not shown** in the configurator (it's added at checkout).

Files (in `docs/design-reference/rails/`):
- **`proto-unified-poster-v1.html`** — the live configurator prototype (Guided + "Everything" density views, the quick-edit **v2** side-panel, the buy bar, the Step-07 order summary, and the artwork proof).
- **`proto-bottom-bars.html`** — bottom-bar exploration → winner locked: **N12 · Segmented summary**.
- **`proto-price-cards.html`** — price-card exploration → winner locked: **Packing slip**.

## Preview locally
PHP isn't installed on this Mac, so it's served via Python:
```
cd "docs/design-reference/rails" && python3 -m http.server 8790
```
Open http://127.0.0.1:8790/proto-unified-poster-v1.html  (each file auto-cache-busts with `?v=Date.now()`).

## Locked design decisions
- **Bottom bar = N12** — segmented `Total · Size · Paper · In hand` + Save build + Add to cart. Wired into the prototype buy bar with live (mock) pricing.
- **Price card (Step 07) = Packing slip** — itemized table, "Order Summary" header (brand purple), dashed total line, Add to cart. Wired in; HST line removed.
- **Quick edit = v2 side-panel only.** v1 (top sheet), v3 (compact), v4 (dropdowns) were removed (markup + JS + v3/v4 CSS). The single "⚡ Quick edit" button opens v2, which **two-way syncs** with the main forms (option tiles + custom size/qty/unit inputs).

## Most recent work (this session)
- Wired both winners (N12 bar + Packing-slip card) into the prototype with live pricing.
- Removed quick-edit v1/v3/v4; collapsed to one "Quick edit" button → v2.
- **Stripped HST** entirely from the configurator (added at checkout instead).
- **v2 ⇄ main-form two-way binding** (incl. the custom size/qty/unit inputs).
- Added a framework/placeholder **badge** + a file-header comment documenting what's mock.
- **Artwork proof** — built it full-screen first, then (on UX-friction feedback) rebuilt it **in-context**: upload happens in the artwork step → art lands on the **hero** → a compact **DPI + checklist + Approve card** appears in the step, with a **Guides** toggle (exact bleed/trim/safe overlay on the live hero) and an opt-in **Inspect** that opens the full-screen detailed view (zoom + magnified corner detail). Add-to-cart is gated behind Approve. Print geometry is **real/exact** (0.125″ bleed + safe, computed from the live size); file rendering + true DPI/preflight are build-phase.

## In-flight / NEXT STEP when resuming
- **The user has "a few more items to edit on this prototype"** — not yet specified. **Ask what they are and continue.**
- **Eyeball the in-context proof**, especially the **Guided band height** with the new file-check card (the spot most likely to need tuning — may want a more compact Guided variant).
- The flagged-file **warning** states currently appear only in the full-screen **Inspect**; the in-step card defaults to the pass state.

## Deferred (decided)
- **Mobile** — pending **Rory**; the production build will be made responsive. Not in this prototype.
- **Accessibility** (roles/labels/keyboard/focus) — build phase.
- **Real file rendering + DPI/preflight** — build phase (the proof is a design mock: exact geometry, but a sample-art preview + representative checks).

## Notes
- No browser/screenshot tool was available in this CLI session — changes were verified **structurally** (HTTP 200, JS syntax via JavaScriptCore `new Function`, grep counts). **Visual confirmation is on the user.**
- These prototype files were **untracked** until this handoff; now committed to `mtcc-print` (branch `master`). The repo also carries ~133 CRLF line-ending "phantom" changes and ~180 untracked screenshot PNGs from past sessions — **intentionally not committed**.
