# Roll Fed Calc (drag-and-drop fork)

WordPress plugin for [ArtMedia Studio](https://artmedia.studio) that embeds roll-fed print pricing calculators for **Archival Fine Art** and **Inkjet**, routes configured orders through WooCommerce, and — in this fork — lets shoppers **compose a gang of files on a true-scale roll** before checkout.

**Current version:** 2.23.7 · **Bootstrap file:** `roll-fed-calc.php` · **GitHub:** [eduardofrank/roll-fed-dd-calc](https://github.com/eduardofrank/roll-fed-dd-calc)

This repository is a fork of [`eduardofrank/roll-fed-calc`](https://github.com/eduardofrank/roll-fed-calc) (local clone: `~/Documents/roll-fed-calc`). Shared core: paper catalogs, nesting math, cart/checkout parity, quote links, shipping class handling, and the pre-built React calculator. **Fork-specific work** lives mainly in the Print Layout Planner and the PHP that prices and fulfills from that layout.

## Requirements

- WordPress 5.8+
- WooCommerce (required for add-to-cart, sessions, and checkout)
- PHP 7.4+

## Installation

Deploy into `wp-content/plugins/roll-fed-calc/` (canonical plugin folder name — match it so upgrades replace the same plugin rather than installing a second copy). Symlink from this repo is fine for local work:

```bash
ln -sfn ~/Documents/roll-fed-dd-calc \
  "~/Local Sites/<site>/app/public/wp-content/plugins/roll-fed-calc"
```

1. Activate **Roll Fed Calc** in WordPress admin.
2. Go to **Roll Fed Calc → WooCommerce** and select Simple Products for archival and inkjet prints (base price $0.00 each).
3. Embed calculators on dedicated pages (not the WooCommerce Shop page):

```
[fine_art_calculator_embed]
[inkjet_calculator_embed]
```

## Admin

| Menu | Purpose |
|------|---------|
| **Archival Paper Options** | CRUD for museum-quality paper catalog (brand → finish → papers) with inline image picker |
| **Inkjet Paper Options** | CRUD for inkjet papers (flat list, dropdown on front end) |
| **Roll Widths** | Edit printer roll sizes and usable widths (shared by both calculators) |
| **Rates & Pricing** | Mounting and turnaround multipliers (shared) |
| **WooCommerce** | Select shell products for archival and inkjet; optional Daily Ops Digest |
| **Quote Links — Archival / Inkjet** | Opens the live calculator in admin authoring mode (`?fac_quote_admin=1`) |
| **⬆↓ Export / Import** | Transfer `fac_*` settings between sites as JSON |

## Moving settings between sites

Built-in export/import lives at **Roll Fed Calc → ⬆↓ Export / Import** (`includes/export-import.php`). The standalone `fac-exporter` companion plugin is no longer needed.

**Export**

1. Choose which settings to include (only options that exist in the database are selectable).
2. Click **Download Export File** — saves `fac-settings-YYYY-MM-DD.json`.
3. Site-owned image URLs are stored as relative paths (`/wp-content/uploads/…`). External/CDN URLs are unchanged.

**Import**

1. Upload the `.json` file; the form previews contents and source site.
2. Select keys to import (WooCommerce product IDs are flagged as site-specific).
3. Leave **Rewrite image URLs** checked when moving between domains.
4. After import, open **Roll Fed Calc → WooCommerce** and confirm archival and inkjet product IDs.

Exportable keys (`fac_get_exportable_option_keys()` in `includes/settings.php`): `fac_paper_data`, `fac_inkjet_paper_data`, `fac_roll_widths`, `fac_mounting_rates`, `fac_turnaround_rates`, `fac_woocommerce_product_id`, `fac_inkjet_woocommerce_product_id`, `fac_paper_images`, `fac_ops_digest`.

Imported values are validated per option key; malformed entries are rejected with an admin error instead of being written to `wp_options`.

## Shortcodes

| Shortcode | Branch | Paper selection |
|-----------|--------|-----------------|
| `[fine_art_calculator_embed]` | Archival museum quality | Brand → finish → paper cards |
| `[inkjet_calculator_embed]` | Inkjet | Roll width → paper dropdown |

## Print Layout Planner

Customer-facing companion (`assets/layout-planner.js` + `assets/layout-planner.css`) that mounts beside the React calculator (`#fac-layout-planner` before `#root`) and lets visitors lay artwork out on the selected roll by direct manipulation.

- **Drag to place, drag to resize.** Drop JPG/PNG/WebP (or browse), move prints on a true-scale roll, resize with aspect lock (Shift to distort), rotate 90°, type exact W×H (fields accept simple arithmetic), multi-select (Shift-click or marquee), group move/scale. Double-click swaps an image. Keyboard: arrows nudge, R rotates, Delete removes, Ctrl/⌘-D duplicates.
- **Prints never overlap.** Collision resolution slides/resizes against neighbours; add/duplicate/rotate that would collide relocate to the nearest free slot.
- **Nests like the press.** New prints auto-place across the usable width; **Arrange to fit** packs tightly. Summary chips show count, feed used, utilisation, and free strip width.
- **Drives the calculator.** Quantity and (for a uniform size) W×H are written through the calculator’s own DOM inputs. Mixed sizes expose per-size “apply” chips for the one-size-at-a-time cart flow. A live total is mirrored into the planner header (read from the calculator, not recomputed).
- **Layout-driven pricing** (`FAC_LAYOUT_DRIVEN_PRICING`, currently on): the roll length the shopper actually lays out can raise the billed feed (never trusted downward). Server geometry from the session manifest is authoritative at add-to-cart. Add-to-cart waits for a pending manifest sync so checkout does not price a stale arrangement.
- **Printer minimum feed:** billed length is floored at ~279 mm / 10.985 in (`FAC_MIN_PRINT_LENGTH_CM`) regardless of tiny jobs.
- **400 PPI guidance.** Under-resolved files are flagged **LOW RES** with the largest sharp size shown; they are not blocked.
- **Placeholders.** File-less reserved spaces price like real prints for “pay now, send art later” (WeTransfer after checkout).
- **Artwork upload.** Placed images are chunk-uploaded (resume/retry; up to 2 GB/file, 40 files, 8 GB/session) into a protected stash under `uploads/fac-artwork/`, held in the WooCommerce session, then attached to the order. The order screen shows a scaled layout diagram with per-image/placeholder download and delete. Print-ready masters may still be requested via WeTransfer for the sharpest result.
- **Locale.** EN/ES via site locale. Hidden in quote-authoring mode (that workflow uses multi-print tabs instead).

The planner is vanilla JS and does not modify the React bundle: it reads roll/units from the mounted calculator DOM and `window.__FAC_ROLL_WIDTHS`, and writes quantity/dimensions/layout feed back through the calculator’s inputs.

## Pricing, quotes, and shipping

**Server pricing** (`includes/pricing.php`): nest across usable roll width → passes × feed → mounting (gatorboard; disabled above 48×96 in) → turnaround multiplier → weight estimate. Client and server must agree within $0.02 or add-to-cart is rejected; checkout re-quotes stored line items.

**Shareable quotes** (`includes/quotes.php`): `fac_quote` CPT with token link, multi-item apportioned pricing, optional negotiated total, expiry, single-use/reusable, lock flags. Authoring mode is toggled from the admin bar on the live calculator. Locked/custom-priced links sell the stored configuration; negotiated prices survive checkout re-validation. Quote records are kept on uninstall.

**Shipping** (`includes/shipping-method.php`): `FAC_Shipping_Quote` appears only for **gatorboard-mounted** lines, using per-item shipping classes (`rolled-print` vs `mounted-flat`) so rolled tubes and rigid panels rate separately. Pirate Ship has no public API; mounted shipping remains a manual / CSV-export follow-up.

## Architecture

```
WordPress options (fac_*)
    ↓
fac_build_js_data(type) → wp_localize_script → window.facData
    ↓
data-bridge.js → __FAC_* globals + __FAC_CALCULATOR_MODE
    ↓
calculator.js (React) + layout-planner.js (gang composer)
    ↓
chunk upload / manifest → WC session geometry
    ↓
AJAX add-to-cart → fac_validate_calculator_state() → fac_calculate_price()
    ↓
WooCommerce cart (calculator_data) → order meta + attached artwork
```

No REST API — public and admin traffic use `admin-ajax.php` / `admin-post.php`.

## Security hardening

- Public add-to-cart AJAX: request throttling, `product_data` size cap, nonce validation, structured JSON errors with HTTP status codes
- Server allowlists: `units` (`inches|centimeters`), `mounting` (`no_mounting|white_gatorboard|black_gatorboard`), `turnaround` (`standard|rush`)
- Dimension/quantity bounds before pricing/cart operations
- Layout feed from the client is overwritten with server-measured session geometry before pricing
- Settings import: `.json` only, MIME/extension checks, 2 MB file cap, sanitized per-key writes
- Artwork download/delete on orders: capability-gated (`edit_shop_orders`) and nonce-checked

## Project layout

| Path | Role |
|------|------|
| `roll-fed-calc.php` | Plugin bootstrap, activation, paper-image sync, HPOS compatibility |
| `includes/pricing.php` | Nesting math, min-feed floor, layout-driven pricing, state validation |
| `includes/ajax.php` | Public cart endpoint + admin save/search endpoints |
| `includes/cart-meta.php` | Cart/order display, dynamic price/weight/shipping class, checkout re-quote |
| `includes/layout-images.php` | Planner stash, chunked upload, order attachment, order-screen diagram |
| `includes/quotes.php` | Quote CPT, authoring mode, lock/custom-price rules |
| `includes/shipping-method.php` | `FAC_Shipping_Quote` for mounted prints |
| `includes/settings.php` | Option getters/sanitizers, `fac_build_js_data()`, export key registry |
| `includes/export-import.php` | Admin JSON export/import |
| `includes/ops-digest.php` | Daily WP-Cron ops email |
| `includes/shortcode.php` | Shortcodes, asset enqueue, planner/calculator DOM scaffold |
| `admin/admin-page.php` | Admin menu + settings markup |
| `assets/data-bridge.js` | Validates localized payload → `window.__FAC_*` |
| `assets/calculator.js` | Pre-built React calculator (committed bundle) |
| `assets/calculator.css` | Calculator styles (`--fac-*` tokens) |
| `assets/layout-planner.js` | Print Layout Planner |
| `assets/layout-planner.css` | Planner styles |
| `assets/admin-*.js` | Per-admin-page CRUD |

Admin menu slug and text domain remain `fine-art-calculator` so existing bookmarks and URLs keep working.

## What’s in this fork vs upstream

| | This fork (`roll-fed-dd-calc`) | Upstream (`roll-fed-calc`) |
|--|-------------------------------|----------------------------|
| Print Layout Planner | Yes (`layout-planner.js`, `layout-images.php`) | No (as of the parent clone used here) |
| Layout-driven pricing / min feed | Yes (PHP + hand-applied bits in `calculator.js`) | Check upstream version |
| React source (`frontend/src`) | Not shipped here — only `assets/calculator.js` | Present (Vite) |
| PHPUnit / Composer / package tooling | Not present in this tree | Present upstream |

`CHANGELOG.md` documents that some calculator pricing hooks (minimum feed length and `window.__FAC_LAYOUT_FEED_CM`) were patched directly into `assets/calculator.js`. A rebuild from upstream `frontend/src` without those patches would drop them until the source is updated to match.

## Development notes

- Planner and admin JS are plain assets — edit and reload; no build step for those files.
- Calculator UI changes that need React source should be done in upstream `frontend/`, rebuilt, then the relevant bundle changes ported here (or the missing patches re-applied — see `CHANGELOG.md` entries for 2.20–2.23).
- `assets/data-bridge.js` validates the localized payload before exposing `window.__FAC_*`; invalid config surfaces a bootstrap error instead of a hard crash when the React entry handles it.
- For admin UI changes, update the matching `assets/admin-*.js` and keep `fac_admin_scripts()` hook-to-handle mapping aligned.

## Upgrading

If you previously ran a release that used `fine-art-calculator.php` as the bootstrap file, pull v2.4.0+ and **reactivate Roll Fed Calc** once if WordPress shows it as inactive. Database options and shortcodes are unchanged. Remove the separate `fac-exporter` plugin if it is still installed.

## Repository

- **This fork (local):** `~/Documents/roll-fed-dd-calc`
- **Upstream (local):** `~/Documents/roll-fed-calc`
- **WordPress plugin folder:** `wp-content/plugins/roll-fed-calc/` (symlink or copy)
- **Uninstall:** deletes `fac_*` options; keeps order meta, attached artwork history, and `fac_quote` records
