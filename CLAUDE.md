# MTCC Print Services — Project Context for Claude Code

> **This file is the single source of truth for working on this project.**
> Read it fully before making any changes. Every convention, path rule, and architectural decision documented here was learned through real debugging sessions and must be followed.

---

## What This Project Is

A custom PHP-based order management platform for **poster printing services** at the **Metro Toronto Convention Centre (MTCC)**, operated by **Print Stuff** (print-stuff.ca). The system handles the complete order lifecycle:

**Customer submits order → Stripe payment → Admin assigns to vendor → Vendor prints → Courier dispatches → Customer picks up / receives delivery**

**Live URL:** `mtcc.print-stuff.ca`
**Admin dashboard:** `mtcc.print-stuff.ca/admin-orders.php`
**Vendor portal:** `mtcc.print-stuff.ca/fulfillment/`
**Business phone:** (437) 882-8822
**Business email:** orders@printstuff.ca

**Primary users:** Convention attendees ordering posters for events (COMIC, FANEXPO, MST, TECH, AAIC, etc.). Typical volume is 100–250 orders per day during active events.

---

## Tech Stack

- **Backend:** PHP 8.x (modular includes architecture, no framework)
- **Frontend:** Vanilla JavaScript, CSS (no React, no build tools)
- **Data storage:** JSON files (in `data/` directory), CSV pricing tiers
- **Payments:** Stripe (Checkout redirect + Payment Links + Webhooks)
- **Email:** PHPMailer via SMTP (mail.printstuff.ca:465 SSL), fallback to PHP `mail()`
- **Phone input:** intl-tel-input v18.5.0 (Canada default, international format storage)
- **Maps/Routing:** Google Routes API (dispatch routing and distance calculation)
- **Audio:** Web Audio API (fulfillment notification tones — no external audio files)
- **Composer deps:** `stripe/stripe-php`, `phpmailer/phpmailer`
- **Server:** Linux hosting (cPanel), deployed via FTP (Dreamweaver or FileZilla)
- **Timezone:** `America/Toronto` (Eastern Time)
- **Currency:** CAD (Canadian dollars), HST 13%
- **No build step.** No npm, no webpack, no compilation. PHP/JS/CSS deployed directly.

---

## ⚠️ CRITICAL RULES — Always Follow These

### 1. Windows Line Endings (CRLF)
Server PHP files use `\r\n` (CRLF). **Always preserve them.** If writing Python scripts to modify files, use binary read/write. Stripping to `\n` causes 500 errors on the server.

### 2. File Naming Convention in This Project Folder
Files named `index-{foldername}.php` (e.g., `index-courier.php`, `index-fulfillment.php`, `index-dispatch.php`) are **reference copies**. On the live server, these are simply `index.php` inside their respective subdirectories. The same applies to `api-{foldername}.php` files (e.g., `api-fulfillment.php` → `fulfillment/api.php`, `api-dispatch.php` → `dispatch/api.php`, `api-courier.php` → `courier/api.php`).

**Known typo:** `index-reprots.php` should be `index-reports.php`. On the server this is `reports/index.php`.

### 3. Pre-Migration File Path Translations
Some files reference paths that have been migrated. Apply these translations:
- `email-functions.php` → `email-order-confirmation.php`
- Root-level `*.json` data files → `data/*.json`
- `uploads/` directory stays in place (not migrated)

A migration script exists (`migrate.php`) that handles these translations.

### 4. Minimal, Precise Changes Only
**Do NOT change code unrelated to the current task.** Unnecessary modifications cause frustration and risk introducing bugs. If the task is "fix the download button," do not also refactor CSS, rename variables, or restructure unrelated code.

### 5. Complete File Delivery
Always provide **complete, ready-to-deploy files** rather than partial diffs or manual integration instructions. Files are deployed directly to the server via FTP.

### 6. Icons — Use Constants, Never Raw Emoji
All emoji/icons must use PHP constants from `includes/icons.php` (e.g., `ICON_CALENDAR`, `ICON_CHECK_GREEN`). **Never use raw emoji literals in PHP files.** Dreamweaver corrupts UTF-8 emoji into mojibake. Icon constants use HTML numeric entities (`&#128197;`) which survive encoding round-trips.

For JavaScript, use the `window.ICONS` object output by `outputIconsScript()` in `icons.php`.

### 7. Iterative Development with Testing Gates
Features are implemented **one at a time** with confirmation before proceeding. Don't bundle multiple unrelated changes.

### 8. Event Delegation Over Inline onclick
Use event delegation for dynamically rendered elements. More reliable than inline `onclick` handlers.

### 9. JSON File Locking
Always use `LOCK_EX` flag when writing JSON data files to prevent corruption under concurrent access: `file_put_contents($file, $json, LOCK_EX);`

---

## Directory Structure (Server Layout)

```
/ (web root: mtcc.print-stuff.ca)
│
├── admin/                          ← Admin sub-pages
│   ├── events-manager.php            Event CRUD
│   ├── events.json                   Active events data
│   ├── events-archive.json           Archived events
│   ├── get-events.php                Events API endpoint
│   ├── save-events.php               Events save handler
│   ├── production.php                Production queue (207KB)
│   ├── production-analytics.php      Production stats & insights
│   ├── production-order-card.php     Shared order card component
│   ├── problem-orders.php            Problem order detection page
│   ├── problem-actions.php           Problem resolution actions
│   ├── problem-data.php              Problem data loading
│   ├── problem-detection.php         Problem detection algorithms
│   ├── problem-dispatch.php          Dispatch problem detection
│   ├── problem-production.php        Production problem detection
│   ├── problem-js.js                 Problem UI interactions
│   ├── problem-styles.css            Problem page styles
│   ├── events-styles.css             Events manager styles
│   ├── production-styles.css         Production queue styles
│   └── production-analytics.css      Production analytics styles
│
├── courier/                        ← Courier PWA
│   ├── index.php                     Courier app entry
│   ├── api.php                       Courier API
│   ├── app.js / app.css              PWA frontend (147KB JS, 103KB CSS)
│   ├── courier-issues.js / .css      Issue reporting UI
│   ├── manifest.json / sw.js         PWA config & service worker
│   ├── google-maps-config.php        Google Maps API key
│   ├── routes-api.php                Google Routes API
│   ├── weather-api.php               Weather data for dispatch
│   └── transit-prototype.html        Transit routing prototype
│
├── css/                            ← Shared admin CSS (modular)
│   ├── admin-base.css                Variables, resets, shared animations
│   ├── admin-layout.css              Sidebar, content grid
│   ├── admin-components.css          Buttons, badges, modals
│   ├── admin-tables.css              Table styles
│   ├── admin-orders.css              Order dashboard styles
│   ├── admin-sidebar.css             Sidebar navigation
│   ├── admin-responsive.css          Media queries
│   ├── admin-print.css               Print stylesheet
│   └── timer-styles.css              Timer system styles
│
├── data/                           ← JSON data files
│   ├── activity-log.json             System activity log
│   ├── admin-sessions.json           Who's online tracking
│   ├── admin-users.json              User accounts + roles + permissions
│   ├── batches.json                  Dispatch batches
│   ├── courier-earnings.json         Courier pay tracking
│   ├── delivery-issues.json          Delivery issue records
│   ├── dispatch-log.json             Dispatch event log
│   ├── dispatch-notifications.json   Dispatch notification queue
│   ├── dispatch-settings.json        Dispatch configuration
│   ├── fulfillment-batches.json      Fulfillment batch tracking
│   ├── mtcc-locations.json           MTCC buildings with coords & pickup instructions
│   ├── order_counter.txt             Sequential order number counter
│   ├── payment-sessions.json         Stripe session tracking
│   ├── preflight-log.json            Vendor preflight/approval tracking
│   ├── problem-resolutions.json      Problem resolution records
│   ├── problem-snapshot.json         Problem detection state cache
│   ├── reminder-config.json          Email reminder settings
│   ├── statuses.json                 Order status map {refCode: status}
│   ├── vendor-sessions.json          Vendor portal sessions
│   ├── vendor-tokens.json            Vendor portal access tokens
│   └── vendors.json                  Vendor profiles + settings + default vendor
│
├── dispatch/                       ← Dispatch hub (admin-facing)
│   ├── index.php                     Dispatch hub entry
│   ├── api.php                       Dispatch API
│   ├── batch-functions.php           Batch CRUD, routing, payout (59KB)
│   ├── batch-suggestions.php         Auto-detect batchable orders
│   ├── dispatch-functions.php        Core dispatch helpers (42KB)
│   ├── dispatch-analytics.php        Delivery analytics engine
│   ├── analytics.php                 Analytics page
│   ├── couriers.php                  Courier management CRUD
│   ├── couriers.json                 Courier profiles with PINs
│   ├── earnings.php                  Courier earnings tracking
│   ├── rate-optimization.php         Pricing analytics & tier optimization
│   ├── scanner.php                   Barcode scanning (haptic/audio)
│   ├── settings.php                  Dispatch settings page
│   ├── dispatch-hub.js / .css        Dispatch UI
│   ├── dispatch-haptics.js           Vibration (Android) / audio (iOS)
│   ├── dispatch-styles.css           Additional styles
│   └── weather-cache.json            Cached weather data
│
├── fulfillment/                    ← Vendor portal
│   ├── index.php                     Vendor login
│   ├── api.php                       Vendor API (60KB)
│   ├── dashboard.php                 Vendor order dashboard (160KB)
│   ├── vendor-auth.php               PIN-based auth
│   ├── fulfillment.css               Styles (72KB)
│   └── fulfillment-audio.js          Web Audio notification tones
│
├── includes/                       ← Shared PHP components
│   ├── admin-header.php              Global top bar
│   ├── admin-sidebar.php             Collapsible nav with permission gates
│   ├── icons.php                     Master icon constants (31KB)
│   ├── analytics-calculations.php    AnalyticsCalculator class
│   ├── problem-detection.php         Problem detection utilities
│   ├── refund-utilities.php          Stripe refund helpers
│   ├── request-handlers.php          AJAX request routing
│   ├── sidebar-init.php              Sidebar state init
│   ├── timer-helpers.php             PHP timer attribute generators
│   ├── timing-calculations.php       TimingCalculator class
│   ├── tracking-utilities.php        Order tracking helpers
│   ├── utilities.php                 General utilities (28KB)
│   ├── vendor-deadline.php           Production deadline calculation
│   └── vendor-functions.php          Vendor CRUD operations
│
├── logs/
│   ├── index.php                     Log viewer
│   └── payment-actions.log
│
├── reports/
│   ├── index.php                     Reports page
│   ├── export.php                    CSV/Excel export
│   └── reports-styles.css
│
├── uploads/
│   ├── orders/                       Order JSON files
│   ├── files/                        Customer print files
│   ├── delivery-photos/              Courier proof photos
│   └── temp/                         Temp uploads during checkout
│
└── vendor/                         ← Composer (Stripe, PHPMailer)
    ├── autoload.php
    ├── composer/ and stripe/
```

├── js/                             ← Admin JavaScript (organized)
│   ├── shared/
│   │   └── utils.js                  Shared utilities (formatFileSize, escapeHtml, showNotification)
│   ├── admin-actions-menu.js         Context menu actions
│   ├── admin-analytics.js            Analytics dashboard
│   ├── admin-bulk-selection.js       Bulk order selection
│   ├── admin-bulk-upload.js          Bulk upload form (extracted from PHP)
│   ├── admin-create-order.js         Create order form (extracted from PHP)
│   ├── admin-dashboard.js            Dashboard interactions
│   ├── admin-drag-drop.js            Drag-and-drop functionality
│   ├── admin-menu-system.js          Menu system
│   ├── admin-payment-link.js         Payment link generation
│   ├── admin-sidebar.js              Sidebar navigation
│   ├── admin-utilities.js            Admin utility functions
│   ├── order-detail.js               Order detail view
│   ├── simple-filters.js             Filter system
│   └── timer-utils.js                Timer engine

**Root-level files** include 40+ PHP files, `script.js` (customer-facing), `styles.css` (customer-facing), 2 CSV pricing files, and config files.

---

## Order Lifecycle & Statuses

```
unpaid → paid → preflight → printing → ready → shipped → delivered → pickedup
                    ↓
               file_issue (vendor flags, can be resolved)
```

Terminal statuses: `cancelled`, `refunded`, `unclaimed`, `missing`

**Status storage:** `data/statuses.json` — flat map: `{ "COMIC-042": "paid", "TECH-017": "printing" }`

**Reference codes:** Format `{EVENT}-{NUMBER}` (e.g., `COMIC-042`). Event prefix maps to `admin/events.json`. Sequential numbering tracked per event.

---

## Order JSON Data Structure

Files stored as `{refCode}_{date}-order.json` in `uploads/orders/`:

```json
{
  "referenceCode": "COMIC-042",
  "event": { "acronym": "COMIC", "name": "Comic Convention 2026", "building": "north" },
  "customerInfo": {
    "name": "Jane Smith", "email": "jane@example.com",
    "phone": "+14165551234", "countryCode": "ca", "company": ""
  },
  "dimensions": { "width": 36, "height": 48 },
  "material": "poster",
  "selectedDate": "2026-04-15",
  "deliveryTime": "12pm",
  "deliveryOption": "mtcc",
  "deliveryAddress": { "attn":"", "company":"", "address":"", "unit":"", "city":"", "province":"", "postal":"", "instructions":"" },
  "pricing": {
    "basePrice": 47.00, "deliveryFee": 0,
    "tax": 6.11, "total": 53.11, "tier": "Standard", "tierKey": "standard", "sqft": 12
  },
  "uploadedFile": { "originalName": "poster.pdf", "savedName": "1710000000_poster.pdf", "path": "uploads/files/1710000000_poster.pdf", "size": 5242880 },
  "submittedAt": "2026-03-09 14:30:00",
  "paidAt": "2026-03-09 14:32:00",
  "stripeSessionId": "cs_test_...",
  "paymentInfo": { "paid": true, "amount": 53.11, "method": "stripe" }
}
```

**Admin-created orders** may use flat fields (`order['email']` vs `order['customerInfo']['email']`). Always check both.

**Delivery times:** `anytime`, `9am`, `12pm`, `3pm`, `6pm`

---

## Delivery Configuration (`delivery-config.php`)

**Single source of truth** for all pricing/timing business logic. Returns a PHP array used by the order form, checkout, admin creation, and vendor deadline systems.

| Tier Key | Label | CSS Class | Lead Days | Cutoff |
|----------|-------|-----------|-----------|--------|
| early | Early | priority-early | 10+ | 5 PM |
| standard | Standard | priority-standard | 5 | 5 PM |
| 3days | Rush | priority-rush | 3 | 5 PM |
| 2days | Urgent | priority-urgent | 2 | 5 PM |
| nextday | Critical | priority-critical | 1 | 3 PM |
| sameday | Last Minute | priority-lastminute | 0 | 3 PM |

**Key business rules:** No weekend vendor printing. Friday 3 PM cutoff for weekend orders. Next-day 9 AM = same-day pricing. After 3 PM, next-day → same-day pricing. Vendor hours: 9 AM–6 PM weekdays.

---

## Authentication Systems

### Admin Auth (`admin-auth.php`)
Five roles: `god_mode` (untracked, full access), `super_admin`, `admin`, `staff`, `reports_only`. Default users: zeus/george/mtcc. Features: session tracking, login history, remember-me (30 days), permission-gated sidebar.

### Vendor Auth (`fulfillment/vendor-auth.php`)
Email + 6-digit PIN. God Mode bypass. Lockout after 5 failures for 15 min.

### Courier Auth
PIN-based, same pattern. Profiles in `dispatch/couriers.json`.

---

## Stripe Payment Flows

**Flow 1 (New Order):** `index.php` → `create-checkout-session.php` → Stripe checkout → `payment-success.php?sid={tempRef}` (uses short `sid` to avoid ModSecurity)

**Flow 2 (Admin Payment Link):** `admin-create-order.php` → `send-payment-link.php` → customer pays → `payment-success.php?ref={refCode}&existing=1`

**Flow 3 (Webhook Recovery):** `stripe-webhook.php` catches `checkout.session.completed` when browser closes. Creates order from metadata, marks `processedVia: "webhook"`.

Config: `stripe-config.php` — currently using **test keys**.

---

## Email System

| File | Triggers | Sender |
|------|----------|--------|
| `email-order-confirmation.php` | Order submitted + paid | orders@ |
| `email-status-notifications.php` | printing, shipped, delivered, pickedup | noreply@ |
| `email-fulfillment.php` | price_submitted/approved/rejected, order_confirmed/ready | orders@ |
| `send-reminders.php` | Cron: vendor reminders at 2h, 4h, 8h (max 3) | orders@ |
| `stripe-webhook.php` | Webhook recovery orders | orders@ |

Transport: PHPMailer SMTP primary, `mail()` fallback. Config: `smtp-config.php`.

---

## MTCC Locations (`data/mtcc-locations.json`)

| Building | Address | Postal | Pickup Location | GPS |
|----------|---------|--------|-----------------|-----|
| North | 255 Front St W | M5V 2W6 | Business Centre, 300 Level, outside Hall C | 43.6445, -79.3871 |
| South | 222 Bremner Blvd | M5V 3L9 | Business Centre, 800 Level, outside Hall D | 43.6426, -79.3857 |

Hours: Mon–Fri 8am–4pm | Phone: 416-585-8387

---

## Key Subsystems

### Timer System
`timer-helpers.php` (PHP attributes) + `timer-utils.js` (live engine) + `timer-styles.css` + `timing-calculations.php` (TimingCalculator class). Color-coded: purple → amber (30min) → red pulse (10min).

### Problem Detection (10 files)
Detects: overdue confirmations (4h/8h), past due orders, stuck statuses (3 days), uncollected (2 days post-event), max reminders hit, unpaid rush orders.

### Fulfillment Audio (`fulfillment-audio.js`)
Web Audio API tones: triple beep (800Hz, same-day), double beep (600Hz, next-day), single beep (400Hz, standard), descending chime (file issues), rising chime (new order).

---

## CSS Architecture

**Design tokens:** Primary `#7c3aed`, Success `#059669`, Error `#dc2626`, Font `Montserrat`, Radius `0.75rem`

**Modular admin CSS** in `css/`: base (canonical variables + animations), layout, components, tables, orders, sidebar, responsive, print. **Page-specific CSS** in subdirectories. All hardcoded design-token colors replaced with CSS variables. `css/admin-base.css` is the single source of truth for `:root` variables and shared `@keyframes`.

**Admin JS** in `js/`: All admin JavaScript organized here. `js/shared/utils.js` provides canonical `formatFileSize`, `escapeHtml`, `showNotification` — load it before page-specific JS. PHP-injected data passed via small inline `<script>` config objects (e.g., `BULK_UPLOAD_CONFIG`, `CREATE_ORDER_CONFIG`).

**Customer-facing** files (`script.js`, `styles.css`, `index.php`) remain at root — intentionally separate from admin.

### .htaccess
Clean URLs (removes `.php`), protects JSON files and order data, disables directory listing, caches assets, security headers.

---

## File Upload & Download

**Allowed types:** pdf, ai, eps, psd, png, jpg, jpeg, tiff, tif, webp, gif, bmp, svg, pptx, indd. **Max:** 100MB. Flow: `uploads/temp/` → `uploads/files/` on payment.

**Dual path issue:** Customer orders store relative paths, admin orders store absolute. Fulfillment uses 5-strategy resolver. **Download naming:** `REFCODE-01-originalname.ext` (no size in filename).

---

## Known Gotchas

- **CSS overflow:** `overflow-x:hidden` + `overflow-y:visible` collapse to `auto`. Use `position:fixed` with `getBoundingClientRect()` for dropdowns.
- **UTF-8 corruption:** Dreamweaver mangles emoji. Use `icons.php` constants. Fix with Python byte-level replacement.
- **Tab switching:** Use `querySelectorAll` index, not `nth-child` selectors.
- **Notification dedup:** Use stable tags, update `lastKnownTimestamp` after detection.
- **Vendor API guards:** Don't block vendor-accessible endpoints with admin-only checks.
- **Pricing UX:** Frame urgency positively ("save by acting now"), countdown timers beat static tier displays.

---

## Development Preferences

- **Consistency over cleverness** — Follow established patterns
- **Modular architecture** — 200–300 line files ideal, new features get own files
- **No unnecessary changes** — Don't refactor unrelated code
- **Complete file output** — Whole files, not diffs
- **Event delegation** — For dynamic elements
- **Robust selectors** — Class-based or `querySelectorAll` index

---

## Cleanup Completed (March 2026)

Pre-launch codebase restructuring completed:
- Dead code removed: `backup-pre-migration/`, fulfillment backups, orphaned JS/CSS files (~10.3MB freed)
- CSS consolidated: variables and animations canonicalized in `css/admin-base.css`, 321 hardcoded colors replaced with CSS variables
- JS organized: 11 admin JS files moved from root to `js/`, shared utilities extracted to `js/shared/utils.js`, inline JS extracted from `admin-bulk-upload.php` and `admin-create-order.php`
- 231 `console.log` statements removed from production files
- JSON data files consolidated in `data/` (admin-auth.php paths updated)
- `.htaccess` redirects in place for all moved files (backwards compatibility)

---

## What's Been Completed (March 2026)

Full customer order form with Stripe (3 flows), admin dashboard (216KB) with filtering/analytics/COGS, production queue with vendor assignment/timers, vendor fulfillment portal with audio alerts, problem detection system (10 files), dispatch system with batching/scanning/routing, courier PWA, events manager, auth system (3 types), email system (5 trigger files), timer system, reports with export, rate optimization analytics.

## What's On the Horizon

- Dispatch Phase 2A expansion
- File path migration (migrate.php exists)
- **Deferred: PHP monolith splitting** — Split `admin-orders.php` (4,956 lines) and `admin/production.php` (4,093 lines) into page + API files. Create `includes/data-access.php`, eliminate duplicate `logOrderHistory()`. High-risk, recommended post-launch.
- Potential planned features TBD
