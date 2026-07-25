# Roll Fed Calc

WordPress plugin for [ArtMedia Studio](https://artmedia.studio) that embeds roll-fed print pricing calculators for **Archival Fine Art** and **Inkjet** on any page and routes configured orders through WooCommerce.

**Current version:** 2.23.1 · **Bootstrap file:** `roll-fed-calc.php` · **GitHub:** [eduardofrank/roll-fed-calc](https://github.com/eduardofrank/roll-fed-calc)

## Requirements

- WordPress 5.8+
- WooCommerce (required for add-to-cart and checkout)
- PHP 7.4+ (CI tests 7.4, 8.2, and 8.3)
- Node.js 18+ (for front-end development only)

## Installation

Clone this repository as `roll-fed-calc` and deploy it into `wp-content/plugins/roll-fed-calc/` (the canonical plugin folder name — match it exactly so uploads upgrade in place rather than creating a second copy). See [DEPLOYMENT.md](DEPLOYMENT.md) for symlink, copy, staging, and upgrade steps.

1. Activate **Roll Fed Calc** in WordPress admin.
2. Go to **Roll Fed Calc → WooCommerce** and select Simple Products for archival and inkjet prints (base price $0.00 each).
3. Embed calculators on dedicated pages:

```
[fine_art_calculator_embed]
[inkjet_calculator_embed]
```

> Do not place shortcodes on the WooCommerce **Shop** page — WooCommerce replaces that page with the product catalog.

## Admin

| Menu | Purpose |
|------|---------|
| **Archival Paper Options** | CRUD for museum-quality paper catalog (brand → finish → papers) with inline image picker |
| **Inkjet Paper Options** | CRUD for inkjet papers (flat list, dropdown on front end) |
| **Roll Widths** | Edit printer roll sizes and usable widths (shared by both calculators) |
| **Rates & Pricing** | Mounting and turnaround multipliers (shared) |
| **WooCommerce** | Select shell products for archival and inkjet cart/checkout |
| **⬆↓ Export / Import** | Transfer all `fac_*` settings between sites as JSON |

## Moving settings between sites

Built-in export/import lives at **Roll Fed Calc → ⬆↓ Export / Import** (`includes/export-import.php`). The standalone `fac-exporter` companion plugin is no longer needed.

**Export**

1. Choose which settings to include (only options that exist in the database are selectable).
2. Click **Download Export File** — saves `fac-settings-YYYY-MM-DD.json`.
3. Site-owned image URLs (e.g. `https://yoursite.com/wp-content/uploads/…`) are stored as relative paths (`/wp-content/uploads/…`). External/CDN URLs are unchanged.

**Import**

1. Upload the `.json` file; the form previews contents and source site.
2. Select keys to import (WooCommerce product IDs are flagged as site-specific).
3. Leave **Rewrite image URLs** checked when moving between domains.
4. After import, open **Roll Fed Calc → WooCommerce** and confirm archival and inkjet product IDs.

Exportable keys are defined in `fac_get_exportable_option_keys()` in `includes/settings.php`.
Imported values are validated per option key; malformed entries are rejected with an admin error instead of being written to `wp_options`.

## Shortcodes

| Shortcode | Branch | Paper selection |
|-----------|--------|-----------------|
| `[fine_art_calculator_embed]` | Archival museum quality | Brand → finish → paper cards |
| `[inkjet_calculator_embed]` | Inkjet | Roll width → paper dropdown |

## Print Layout Planner

A customer-facing companion to the calculator (`assets/layout-planner.js` +
`assets/layout-planner.css`) that renders beneath it on the storefront and lets
visitors lay their own artwork out on the selected roll by direct manipulation
before ordering.

- **Drag to place, drag to resize — no dialogs.** Drop JPG/PNG/WebP artwork (or
  click to browse) and drag each print around the roll at true size. Drag the
  corner/edge handles to resize with the aspect ratio locked by default (hold
  Shift to distort), rotate 90°, or type an exact W×H from a compact toolbar that
  floats on the selected print — there is no modal or side panel. Double-click a
  print to swap its image. Keyboard: arrows nudge, R rotates, Delete removes,
  Ctrl/⌘-D duplicates.
- **Prints never overlap.** Dragging slides a print against its neighbours,
  resizing stops at them, and add/duplicate/rotate/resize relocate to the nearest
  free slot if they would collide.
- **True-scale roll view, clearly labelled.** A light proof "sheet" of the chosen
  roll on the calculator's dark theme with sticky inch/centimetre rulers, hatched
  non-printable roll edges, a labelled printable-area width, and a roll-length-used
  marker. A legend names the printable area, the non-printable edge, and the
  off-roll background. The selected roll and its printable width appear in the
  header and on the canvas.
- **Nests like the press.** New and duplicated prints drop into the first free
  slot across the *usable* width; **Arrange to fit** nests everything tightly in
  one click. Summary chips show prints planned, roll length used (in/cm), width
  utilisation, and the widest free strip.
- **Drives the calculator quantity.** Adding/duplicating/removing a print operates
  the calculator's real +/− quantity stepper; a single uniform size also fills the
  width/height fields. Mixed sizes expose per-size "apply" chips for the
  one-size-at-a-time checkout flow.
- **Always prints at 400 PPI.** A file that lacks the pixels for its size is
  flagged **"LOW RES"**, with the largest size it prints sharp at 400 PPI shown on
  it — but it is never removed or blocked. Print-ready masters are still gathered
  through the post-checkout WeTransfer step. **Artwork is used for planning only
  and is never uploaded by the plugin** (object URLs held in the browser).

The planner integrates with the pre-built React bundle without modifying it: it
reads the selected roll and units from the mounted calculator DOM and the
`window.__FAC_ROLL_WIDTHS` catalog, and writes quantity/dimensions back through
the calculator's own inputs. It follows the site locale (EN/ES) and is hidden in
quote-authoring mode, which keeps its own multi-print tabs.

## Quotes and shipping

**Shareable quotes** (`includes/quotes.php`): the studio can author a saved quote — a
`fac_quote` custom post type with an unguessable token link, one or more line items with
apportioned pricing, an optional negotiated total override, expiry, single-use/reusable and
lock flags. Administrators toggle authoring mode from the admin bar (`QuoteAdminPanel.jsx`).
A locked or custom-priced link discards the customer's posted configuration and sells the
stored one, and its negotiated price is preserved through checkout re-validation (a mid-cart
rate change never overwrites an agreed number). Quote records are kept on uninstall.

**Shipping** (`includes/shipping-method.php`): `FAC_Shipping_Quote` (a `WC_Shipping_Method`)
becomes available only when the cart contains a **gatorboard-mounted** print, using the
per-item shipping classes set in `cart-meta.php` (`fac_get_shipping_class_for_mounting`). This
separates light rolled-tube prints from oversized rigid panels for carrier rating. Note: Pirate
Ship has no public API — see the ROADMAP for the recommended CSV-export follow-up.

## Architecture

```
WordPress options (fac_*)
    ↓
fac_build_js_data(type) → wp_localize_script → window.facData
    ↓
data-bridge.js → __FAC_* globals + __FAC_CALCULATOR_MODE
    ↓
calculator.js (React) → user configures print → AJAX add to cart
    ↓
fac_validate_calculator_state() → WooCommerce cart with calculator_data meta
```

## Security hardening

- Public add-to-cart AJAX now includes:
  - request throttling (short window, per request fingerprint)
  - payload size cap for `product_data`
  - nonce validation and mismatch logging in debug mode
  - structured JSON error responses with explicit HTTP status codes
- Server validation now enforces allowlists for:
  - `units` (`inches|centimeters`)
  - `mounting` (`no_mounting|white_gatorboard|black_gatorboard`)
  - `turnaround` (`standard|rush`)
- Server validation also enforces maximum bounds for dimensions and quantity before pricing/cart operations.
- Import now rejects non-`.json` uploads, invalid extension/type checks, oversized files (2 MB cap), and oversized decoded JSON payloads.

### Project layout

| Path | Role |
|------|------|
| `roll-fed-calc.php` | Plugin bootstrap, activation, paper-image sync |
| `includes/settings.php` | Option getters, JS data bridge, export key registry |
| `includes/ajax.php` | Public cart endpoint + admin save endpoints (now schema-sanitized) |
| `includes/export-import.php` | Admin export/import UI and JSON handlers |
| `includes/pricing.php` | Server-side price validation (must match JS) |
| `admin/admin-page.php` | Admin page markup + script enqueues (no inline admin JS) |
| `assets/admin-archival.js` | Archival paper admin interactions (cards, modal CRUD, media picker) |
| `assets/admin-inkjet.js` | Inkjet paper admin interactions (table CRUD + save) |
| `assets/admin-rolls.js` | Roll widths admin interactions |
| `assets/admin-rates.js` | Mounting/turnaround rates admin interactions |
| `assets/admin-woo.js` | WooCommerce product search/select and save interactions |
| `assets/data-bridge.js` | Runtime bootstrap validation + `window.__FAC_*` mapping |
| `assets/calculator.js` | Pre-built React bundle (commit after `npm run build`) |
| `assets/calculator.css` | Front-end styles |
| `assets/layout-planner.js` | Print Layout Planner (vanilla JS; renders into `#fac-layout-planner`, bridges to the calculator) |
| `assets/layout-planner.css` | Planner styles (inherit the `--fac-*` tokens from `calculator.css`) |
| `frontend/src/` | React source (Vite) |

Admin menu slug and text domain remain `fine-art-calculator` so existing bookmarks and URLs keep working.

## Development

Branch workflow: **`dev`** for day-to-day work, **`main`** for production (merge via PR only). See [CONTRIBUTING.md](CONTRIBUTING.md).

### Front-end

```bash
cd frontend
npm install
npm run dev      # local preview
npm run build    # writes assets/calculator.js
```

### PHP tests

```bash
composer install
composer test
```

Includes pricing parity fixtures, paper-image merge tests, and export/import URL round-trips (`tests/ExportImportTest.php`).
Reliability coverage also includes settings sanitization tests in `tests/SettingsSanitizeTest.php`.
Security regression coverage includes invalid enum and oversized input checks in `tests/PricingTest.php`.
AJAX request/security helper coverage lives in `tests/AjaxSecurityTest.php`.

### Front-end runtime guardrails

- `assets/data-bridge.js` validates localized payload shape before exposing `window.__FAC_*`.
- If critical data is missing/invalid, `frontend/src/main.jsx` renders a configuration error banner instead of crashing.
- This protects against malformed option structures and partial admin data writes.

### CI reliability checks

- Front-end CI now verifies the committed `assets/calculator.js` matches `frontend/src` output.
- If the bundle is stale, CI fails and requires rebuilding before merge.

### Admin JS modularization

- Admin-page behavior now lives in dedicated asset files (`assets/admin-*.js`) instead of inline `<script>` blocks in `admin/admin-page.php`.
- For admin UI changes, update the corresponding `assets/admin-*.js` module and keep `fac_admin_scripts()` hook-to-handle mapping aligned.

## Upgrading

If you previously ran a release that used `fine-art-calculator.php` as the bootstrap file, pull v2.4.0+ and **reactivate Roll Fed Calc** once if WordPress shows it as inactive. Database options and shortcodes are unchanged. Remove the separate `fac-exporter` plugin if it is still installed.

## Repository

- **Suggested local clone:** `~/Documents/roll-fed-calc`
- **WordPress plugin folder:** `wp-content/plugins/roll-fed-calc/` (symlink or copy from the repo)
