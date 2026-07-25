<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
   Register admin menu
--------------------------------------------------------------- */
add_action( 'admin_menu', 'fac_admin_menu' );
function fac_admin_menu() {
    add_menu_page(
        'Roll Fed Calc',
        'Roll Fed Calc',
        'manage_options',
        'fine-art-calculator',
        'fac_admin_page',
        'dashicons-images-alt2',
        58
    );
    add_submenu_page(
        'fine-art-calculator',
        'Archival Paper Options',
        'Archival Paper Options',
        'manage_options',
        'fine-art-calculator',
        'fac_admin_page'
    );
    add_submenu_page(
        'fine-art-calculator',
        'Inkjet Paper Options',
        'Inkjet Paper Options',
        'manage_options',
        'fine-art-calculator-inkjet',
        'fac_admin_inkjet_page'
    );
    // Paper Images sub-page removed — image management is now inline on Paper Options
    add_submenu_page(
        'fine-art-calculator',
        'Roll Widths',
        'Roll Widths',
        'manage_options',
        'fine-art-calculator-rolls',
        'fac_admin_rolls_page'
    );
    add_submenu_page(
        'fine-art-calculator',
        'Rates & Pricing',
        'Rates & Pricing',
        'manage_options',
        'fine-art-calculator-rates',
        'fac_admin_rates_page'
    );
    add_submenu_page(
        'fine-art-calculator',
        'WooCommerce Settings',
        '🛒 WooCommerce',
        'manage_options',
        'fine-art-calculator-woo',
        'fac_admin_woo_page'
    );

    /*
     * One submenu per calculator. Neither renders a form — they redirect into
     * the matching front-end page in authoring mode, where the real calculator
     * is the configuration UI. See fac_quote_admin_redirect().
     */
    $archival_hook = add_submenu_page(
        'fine-art-calculator',
        'Quote Links — Archival',
        '🔗 Quote Links — Archival',
        'manage_options',
        'fine-art-calculator-quotes-archival',
        'fac_admin_quotes_archival_page'
    );
    if ( $archival_hook ) {
        add_action( 'load-' . $archival_hook, 'fac_admin_quotes_archival_redirect' );
    }

    $inkjet_hook = add_submenu_page(
        'fine-art-calculator',
        'Quote Links — Inkjet',
        '🔗 Quote Links — Inkjet',
        'manage_options',
        'fine-art-calculator-quotes-inkjet',
        'fac_admin_quotes_inkjet_page'
    );
    if ( $inkjet_hook ) {
        add_action( 'load-' . $inkjet_hook, 'fac_admin_quotes_inkjet_redirect' );
    }
}

function fac_admin_quotes_archival_redirect() {
    fac_quote_admin_redirect( 'archival' );
}

function fac_admin_quotes_inkjet_redirect() {
    fac_quote_admin_redirect( 'inkjet' );
}

/**
 * Send the admin to the front-end calculator in authoring mode.
 *
 * Runs on load-{hook} rather than in the page callback, because by the time a
 * callback renders WordPress has already sent headers and wp_safe_redirect()
 * would be a no-op. When no page carries the shortcode there is nowhere to go,
 * so this returns and lets the callback explain that instead.
 *
 * @param string $type archival|inkjet
 * @return void
 */
function fac_quote_admin_redirect( $type ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $url = fac_quote_admin_entry_url( $type );
    if ( ! $url ) {
        return;
    }

    wp_safe_redirect( $url );
    exit;
}

add_action( 'admin_enqueue_scripts', 'fac_admin_scripts' );
function fac_admin_scripts( $hook ) {
    if ( strpos( $hook, 'fine-art-calculator' ) === false ) return;

    wp_enqueue_script( 'jquery' );
    wp_enqueue_style( 'wp-color-picker' );
    wp_enqueue_style(
        'fac-admin-css',
        FAC_PLUGIN_URL . 'assets/admin.css',
        array(),
        FAC_VERSION
    );
    wp_enqueue_script(
        'fac-admin-shared',
        FAC_PLUGIN_URL . 'assets/admin-shared.js',
        array( 'jquery' ),
        FAC_VERSION,
        true
    );

    // Enqueue WordPress media library
    wp_enqueue_media();

    wp_localize_script( 'jquery', 'facAdmin', array(
        'nonce'           => wp_create_nonce( 'fac_admin_nonce' ),
        'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
        'paperData'       => fac_get_paper_data(),
        'rollWidths'      => fac_get_roll_widths(),
        'mountingRates'   => fac_get_mounting_rates(),
        'turnaroundRates' => fac_get_turnaround_rates(),
        'savedProductId'       => fac_get_product_id(),
        'savedInkjetProductId' => fac_get_inkjet_product_id(),
        'inkjetPaperData'      => fac_get_inkjet_paper_data(),
        'wooActive'       => class_exists( 'WooCommerce' ),
        'paperImages'     => fac_get_paper_images(),
        'paperSlugs'      => fac_all_paper_slugs(),
    ) );

    if ( strpos( $hook, 'fine-art-calculator-rolls' ) !== false ) {
        wp_enqueue_script(
            'fac-admin-rolls',
            FAC_PLUGIN_URL . 'assets/admin-rolls.js',
            array( 'jquery', 'fac-admin-shared' ),
            FAC_VERSION,
            true
        );
    }

    if ( $hook === 'toplevel_page_fine-art-calculator' ) {
        wp_enqueue_script(
            'fac-admin-archival',
            FAC_PLUGIN_URL . 'assets/admin-archival.js',
            array( 'jquery', 'fac-admin-shared' ),
            FAC_VERSION,
            true
        );
    }

    if ( strpos( $hook, 'fine-art-calculator-rates' ) !== false ) {
        wp_enqueue_script(
            'fac-admin-rates',
            FAC_PLUGIN_URL . 'assets/admin-rates.js',
            array( 'jquery', 'fac-admin-shared' ),
            FAC_VERSION,
            true
        );
    }

    if ( strpos( $hook, 'fine-art-calculator-inkjet' ) !== false ) {
        wp_enqueue_script(
            'fac-admin-inkjet',
            FAC_PLUGIN_URL . 'assets/admin-inkjet.js',
            array( 'jquery', 'fac-admin-shared' ),
            FAC_VERSION,
            true
        );
    }

    if ( strpos( $hook, 'fine-art-calculator-woo' ) !== false ) {
        wp_enqueue_script(
            'fac-admin-woo',
            FAC_PLUGIN_URL . 'assets/admin-woo.js',
            array( 'jquery', 'fac-admin-shared' ),
            FAC_VERSION,
            true
        );
    }

}

/* ---------------------------------------------------------------
   PAPER OPTIONS PAGE
--------------------------------------------------------------- */
function fac_admin_page() {
    ?>
    <div class="wrap fac-admin">
        <h1>Roll Fed Calc — Archival Paper Options</h1>
        <p>Add, edit, or remove archival museum-quality paper options. Each brand → finish → paper hierarchy maps directly to what customers see on the Fine Art calculator.</p>

        <div id="fac-notice" style="display:none;" class="notice is-dismissible"><p></p></div>

        <style>
        /* ── Toolbar ── */
        .fac-toolbar { margin: 16px 0; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .fac-toolbar select { height: 32px; padding: 0 8px; font-size: 13px; min-width: 160px; border: 1px solid #c3c4c7; border-radius: 3px; }
        .fac-toolbar input[type=search] { height: 32px; padding: 0 10px; font-size: 13px; border: 1px solid #c3c4c7; border-radius: 3px; min-width: 200px; }

        /* ── Group headings ── */
        .fac-group { margin-bottom: 28px; }
        .fac-group-header { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .fac-group-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .07em; color: #646970; white-space: nowrap; }
        .fac-group-rule { flex: 1; height: 1px; background: #e0e0e0; }
        .fac-group-count { font-size: 11px; color: #646970; background: #f0f0f1; border: 1px solid #e0e0e0; border-radius: 10px; padding: 1px 8px; }

        /* ── Card grid ── */
        .fac-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 12px; }

        /* ── Individual card ── */
        .fac-card {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 8px;
            overflow: hidden;
            transition: border-color .15s, box-shadow .15s;
        }
        .fac-card:hover { border-color: #a7aaad; box-shadow: 0 1px 6px rgba(0,0,0,.08); }

        .fac-card-img {
            width: 100%; height: 80px;
            background: #f6f7f7;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden; position: relative; cursor: pointer;
        }
        .fac-card-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .fac-card-img .fac-img-placeholder {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 4px; color: #a7aaad; font-size: 11px; text-align: center; width: 100%; height: 100%;
        }
        .fac-card-img .fac-img-placeholder span.dashicons { font-size: 28px; width: 28px; height: 28px; color: #c3c4c7; }
        .fac-card-img-overlay {
            position: absolute; inset: 0;
            background: rgba(0,0,0,.45);
            display: flex; align-items: center; justify-content: center;
            opacity: 0; transition: opacity .15s;
            color: #fff; font-size: 12px; font-weight: 500; gap: 5px;
        }
        .fac-card-img:hover .fac-img-overlay { opacity: 1; }
        .fac-card-img-overlay .dashicons { font-size: 16px; width: 16px; height: 16px; }

        .fac-card-body { padding: 10px 12px 0; }
        .fac-card-name { font-size: 13px; font-weight: 600; color: #1d2327; margin: 0 0 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .fac-card-slug { font-size: 11px; color: #8c8f94; font-family: monospace; margin: 0 0 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .fac-card-stats { display: flex; gap: 6px; margin-bottom: 8px; flex-wrap: wrap; }
        .fac-stat-pill {
            font-size: 11px; padding: 2px 8px;
            background: #f6f7f7; border: 1px solid #e0e0e0; border-radius: 10px;
            color: #50575e; white-space: nowrap;
        }

        .fac-rolls { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 8px; }
        .fac-roll-tag {
            font-size: 10px; padding: 1px 7px;
            background: #e8f0fa; color: #2271b1;
            border: 1px solid #b5cce8; border-radius: 10px;
        }

        .fac-card-desc {
            font-size: 11px; color: #646970; line-height: 1.5; margin: 0 0 8px;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }

        .fac-card-actions {
            display: flex; gap: 6px; align-items: center;
            border-top: 1px solid #f0f0f1; padding: 8px 12px;
            margin: 0 -12px;
        }
        .fac-card-actions .button { font-size: 11px; line-height: 1.8; }
        .fac-card-actions .fac-del-btn { color: #b32d2e; border-color: #b32d2e; }
        .fac-card-actions .fac-del-btn:hover { background: #fcf0f1; }
        .fac-spacer { flex: 1; }

        .fac-empty { text-align: center; padding: 32px; color: #8c8f94; font-size: 14px; }

        /* ── Modal ── */
        #fac-modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.55); z-index: 9999;
        }
        .fac-modal {
            background: #fff; border-radius: 8px; max-width: fit-content; width: 90%;
            margin: 60px auto; padding: 28px 28px 20px; position: relative;
            max-height: 90vh; overflow-y: auto;
        }
        .fac-modal h2 { margin-top: 0; font-size: 18px; }
        .fac-modal-close {
            position: absolute; top: 12px; right: 14px;
            font-size: 20px; background: none; border: none; cursor: pointer; color: #646970;
        }
        .fac-modal-close:hover { color: #1d2327; }

        /* Two-column form layout */
        .fac-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 16px; }
        .fac-form-full { grid-column: 1 / -1; }
        .fac-form-group { display: flex; flex-direction: column; gap: 4px; }
        .fac-form-group label { font-size: 12px; font-weight: 600; color: #1d2327; }
        .fac-form-group input[type=text],
        .fac-form-group input[type=number],
        .fac-form-group input[type=url],
        .fac-form-group textarea,
        .fac-form-group select { width: 100%; font-size: 13px; }
        .fac-form-group .description { font-size: 11px; color: #8c8f94; margin: 2px 0 0; }

        /* Media picker row */
        .fac-media-row { display: flex; align-items: center; gap: 10px; }
        .fac-media-preview-wrap {
            width: 72px; height: 48px; border-radius: 4px; border: 1px solid #dcdcde;
            overflow: hidden; background: #f6f7f7; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
        }
        .fac-media-preview-wrap img { width: 100%; height: 100%; object-fit: cover; }
        .fac-media-preview-wrap .dashicons { color: #c3c4c7; font-size: 22px; width: 22px; height: 22px; }
        .fac-media-row .button { white-space: nowrap; }

        /* Rolls checkboxes */
        .fac-rolls-cb-wrap { display: flex; flex-wrap: wrap; gap: 8px; }
        .fac-rolls-cb-wrap label {
            display: flex; align-items: center; gap: 5px;
            font-size: 12px; font-weight: 400; color: #1d2327; cursor: pointer;
        }

        /* Save bar */
        .fac-save-bar {
            background: #fff; border: 1px solid #dcdcde; border-radius: 6px;
            padding: 10px 16px; margin-bottom: 20px;
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
        }
        .fac-modal-actions { display: flex; gap: 10px; margin-top: 18px; padding-top: 14px; border-top: 1px solid #f0f0f1; }
        </style>

        <!-- Toolbar -->
        <div class="fac-toolbar">
            <input type="search" id="fac-search" placeholder="Search papers…" />
            <select id="fac-filter-brand"><option value="">All brands</option></select>
            <select id="fac-filter-finish"><option value="">All finishes</option></select>
            <div style="flex:1"></div>
            <button class="button" id="fac-add-brand">+ Add Brand</button>
            <button class="button" id="fac-add-finish">+ Add Finish</button>
            <button class="button button-primary" id="fac-add-paper">+ Add Paper</button>
            <button class="button button-primary" id="fac-save-papers" style="background:#00a32a; border-color:#00a32a;">💾 Save All Changes</button>
        </div>

        <!-- Card groups -->
        <div id="fac-groups"></div>

        <!-- Add/Edit Modal -->
        <div id="fac-modal-overlay">
            <div class="fac-modal">
                <button class="fac-modal-close" id="fac-modal-close" aria-label="Close">✕</button>
                <h2 id="fac-modal-title">Add Paper</h2>

                <div class="fac-form-grid">
                    <div class="fac-form-group">
                        <label for="fm-brand">Brand</label>
                        <input type="text" id="fm-brand" list="fm-brand-list" placeholder="e.g. Hahnemühle" />
                        <datalist id="fm-brand-list"></datalist>
                    </div>
                    <div class="fac-form-group">
                        <label for="fm-finish">Finish category</label>
                        <input type="text" id="fm-finish" list="fm-finish-list" placeholder="e.g. Matt Smooth" />
                        <datalist id="fm-finish-list"></datalist>
                    </div>
                    <div class="fac-form-group">
                        <label for="fm-name">Paper name</label>
                        <input type="text" id="fm-name" placeholder="e.g. Photo Rag" />
                    </div>
                    <div class="fac-form-group">
                        <label for="fm-slug">Slug <span style="font-weight:400;color:#8c8f94;">(auto)</span></label>
                        <input type="text" id="fm-slug" placeholder="photo_rag" />
                    </div>
                    <div class="fac-form-group">
                        <label for="fm-rate">Rate ($/cm²)</label>
                        <div class="fac-num-stepper" data-step="0.0001" data-min="0" data-decimals="4">
                            <button type="button" class="fac-num-stepper__btn" data-dir="-1" aria-label="Decrease rate">−</button>
                            <input type="number" id="fm-rate" class="fac-num-stepper__input" step="0.0001" min="0" value="0.0414" />
                            <button type="button" class="fac-num-stepper__btn" data-dir="1" aria-label="Increase rate">+</button>
                        </div>
                    </div>
                    <div class="fac-form-group">
                        <label for="fm-gsm">GSM</label>
                        <div class="fac-num-stepper" data-step="1" data-min="0" data-decimals="0">
                            <button type="button" class="fac-num-stepper__btn" data-dir="-1" aria-label="Decrease GSM">−</button>
                            <input type="number" id="fm-gsm" class="fac-num-stepper__input" step="1" min="0" value="310" />
                            <button type="button" class="fac-num-stepper__btn" data-dir="1" aria-label="Increase GSM">+</button>
                        </div>
                    </div>
                    <div class="fac-form-group fac-form-full">
                        <label>Available rolls</label>
                        <div class="fac-rolls-cb-wrap" id="fm-rolls-wrap"></div>
                    </div>
                    <div class="fac-form-group fac-form-full">
                        <label for="fm-description">Description</label>
                        <textarea id="fm-description" rows="2"></textarea>
                    </div>
                    <div class="fac-form-group fac-form-full">
                        <label>Paper image</label>
                        <div class="fac-media-row">
                            <div class="fac-media-preview-wrap" id="fm-img-preview-wrap">
                                <span class="dashicons dashicons-format-image"></span>
                            </div>
                            <button type="button" class="button" id="fm-media-btn">
                                <span class="dashicons dashicons-admin-media" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;"></span>
                                Select from media library
                            </button>
                            <button type="button" class="button fac-del-btn" id="fm-img-remove" style="display:none;">Remove</button>
                        </div>
                        <input type="hidden" id="fm-imageurl" />
                        <p class="description">Optional texture image shown in the calculator.</p>
                    </div>
                </div>

                <input type="hidden" id="fm-edit-key" />
                <div class="fac-modal-actions">
                    <button class="button button-primary" id="fac-modal-save">Save paper</button>
                    <button class="button" id="fac-modal-cancel">Cancel</button>
                </div>
            </div>
        </div>

        <!-- Add Brand/Finish Modal -->
        <div id="fac-bf-modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:9999;">
            <div style="background:#fff; border-radius:8px; max-width:420px; width:90%; margin:120px auto; padding:28px; position:relative;">
                <button id="fac-bf-modal-close" style="position:absolute;top:12px;right:14px;font-size:20px;background:none;border:none;cursor:pointer;color:#646970;">✕</button>
                <h2 id="fac-bf-modal-title" style="margin-top:0;">Add Brand</h2>
                <div class="fac-form-grid" style="grid-template-columns:1fr;">
                    <div class="fac-form-group">
                        <label id="fac-bf-label" for="fac-bf-value">Brand name</label>
                        <input type="text" id="fac-bf-value" class="regular-text" />
                    </div>
                    <div class="fac-form-group" id="fac-bf-brand-row" style="display:none;">
                        <label for="fac-bf-brand-select">Under brand</label>
                        <select id="fac-bf-brand-select" style="width:100%;"></select>
                    </div>
                </div>
                <div class="fac-modal-actions">
                    <button class="button button-primary" id="fac-bf-modal-save">Add</button>
                    <button class="button" id="fac-bf-modal-cancel">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Archival page script moved to assets/admin-archival.js -->
    <?php
}

/* ---------------------------------------------------------------
   ROLL WIDTHS PAGE
--------------------------------------------------------------- */
function fac_admin_rolls_page() {
    ?>
    <div class="wrap fac-admin">
        <h1>Roll Fed Calc — Roll Widths</h1>
        <p>Define the printer roll sizes available in your studio. Roll widths drive nesting calculations.</p>

        <div id="fac-roll-notice" style="display:none;" class="notice is-dismissible"><p></p></div>

        <table class="wp-list-table widefat fixed striped" id="fac-roll-table" style="max-width:860px">
            <thead>
                <tr>
                    <th style="width:72px">Key (inches)</th>
                    <th style="width:200px">Label</th>
                    <th style="width:120px">Width (inches)</th>
                    <th style="width:120px">Usable Width (in)</th>
                    <th style="width:120px">Usable Width (cm)</th>
                    <th style="width:80px">Actions</th>
                </tr>
            </thead>
            <tbody id="fac-roll-tbody"></tbody>
        </table>

        <div style="margin-top:14px; display:flex; gap:10px;">
            <button class="button button-primary" id="fac-add-roll">+ Add Roll Width</button>
            <button class="button button-primary" id="fac-save-rolls" style="background:#00a32a; border-color:#00a32a;">💾 Save Roll Widths</button>
        </div>
    </div>

    <!-- Roll Widths page script moved to assets/admin-rolls.js -->
    <?php
}

/* ---------------------------------------------------------------
   RATES & PRICING PAGE
--------------------------------------------------------------- */
function fac_admin_rates_page() {
    ?>
    <div class="wrap fac-admin">
        <h1>Roll Fed Calc — Rates &amp; Pricing</h1>

        <div id="fac-rates-notice" style="display:none;" class="notice is-dismissible"><p></p></div>

        <h2>Mounting Rates (per in² or cm²)</h2>
        <p>Rates applied on top of paper cost when a mounting option is selected.</p>
        <table class="form-table" style="max-width:560px">
            <tr><th>White Gatorboard ($/in²)</th><td><div class="fac-num-stepper" data-step="0.0001" data-min="0" data-decimals="4"><button type="button" class="fac-num-stepper__btn" data-dir="-1" aria-label="Decrease">−</button><input type="number" id="r-wg-in" class="fac-num-stepper__input" step="0.0001" min="0"><button type="button" class="fac-num-stepper__btn" data-dir="1" aria-label="Increase">+</button></div></td></tr>
            <tr><th>Black Gatorboard ($/in²)</th><td><div class="fac-num-stepper" data-step="0.0001" data-min="0" data-decimals="4"><button type="button" class="fac-num-stepper__btn" data-dir="-1" aria-label="Decrease">−</button><input type="number" id="r-bg-in" class="fac-num-stepper__input" step="0.0001" min="0"><button type="button" class="fac-num-stepper__btn" data-dir="1" aria-label="Increase">+</button></div></td></tr>
            <tr><th>White Gatorboard ($/cm²)</th><td><div class="fac-num-stepper" data-step="0.0001" data-min="0" data-decimals="4"><button type="button" class="fac-num-stepper__btn" data-dir="-1" aria-label="Decrease">−</button><input type="number" id="r-wg-cm" class="fac-num-stepper__input" step="0.0001" min="0"><button type="button" class="fac-num-stepper__btn" data-dir="1" aria-label="Increase">+</button></div></td></tr>
            <tr><th>Black Gatorboard ($/cm²)</th><td><div class="fac-num-stepper" data-step="0.0001" data-min="0" data-decimals="4"><button type="button" class="fac-num-stepper__btn" data-dir="-1" aria-label="Decrease">−</button><input type="number" id="r-bg-cm" class="fac-num-stepper__input" step="0.0001" min="0"><button type="button" class="fac-num-stepper__btn" data-dir="1" aria-label="Increase">+</button></div></td></tr>
        </table>

        <h2>Turnaround Multipliers</h2>
        <p>Multipliers applied to the total price based on the customer's chosen turnaround.</p>
        <table class="form-table" style="max-width:560px">
            <tr><th>Standard (multiplier)</th><td><div class="fac-num-stepper" data-step="0.01" data-min="0" data-decimals="2"><button type="button" class="fac-num-stepper__btn" data-dir="-1" aria-label="Decrease">−</button><input type="number" id="r-standard" class="fac-num-stepper__input" step="0.01" min="0"><button type="button" class="fac-num-stepper__btn" data-dir="1" aria-label="Increase">+</button></div> <span class="description">e.g. 1 = no change</span></td></tr>
            <tr><th>Rush (multiplier)</th><td><div class="fac-num-stepper" data-step="0.01" data-min="0" data-decimals="2"><button type="button" class="fac-num-stepper__btn" data-dir="-1" aria-label="Decrease">−</button><input type="number" id="r-rush" class="fac-num-stepper__input" step="0.01" min="0"><button type="button" class="fac-num-stepper__btn" data-dir="1" aria-label="Increase">+</button></div> <span class="description">e.g. 1.15 = +15%</span></td></tr>
        </table>

        <button class="button button-primary" id="fac-save-rates" style="background:#00a32a; border-color:#00a32a; margin-top:10px;">💾 Save Rates</button>
    </div>

    <!-- Rates page script moved to assets/admin-rates.js -->
    <?php
}

/* ---------------------------------------------------------------
   INKJET PAPER OPTIONS PAGE
--------------------------------------------------------------- */
function fac_admin_inkjet_page() {
    ?>
    <div class="wrap fac-admin">
        <h1>Roll Fed Calc — Inkjet Paper Options</h1>
        <p>Manage inkjet papers grouped by category on the Inkjet calculator. No paper images are used for this branch.</p>

        <div id="fac-inkjet-notice" style="display:none;" class="notice is-dismissible"><p></p></div>

        <div class="fac-save-bar">
            <button type="button" class="button button-primary" id="fac-inkjet-save">Save Inkjet Papers</button>
            <button type="button" class="button" id="fac-inkjet-add">+ Add Paper</button>
            <span id="fac-inkjet-save-status" style="color:#646970;font-size:13px;"></span>
        </div>

        <div class="fac-toolbar">
            <input type="search" id="fac-inkjet-search" placeholder="Search inkjet papers…" />
        </div>

        <table class="widefat striped" id="fac-inkjet-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Slug</th>
                    <th>Rate</th>
                    <th>GSM</th>
                    <th>Rolls</th>
                    <th>Description</th>
                    <th style="width:120px;">Actions</th>
                </tr>
            </thead>
            <tbody id="fac-inkjet-tbody"></tbody>
        </table>

        <div id="fac-inkjet-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;">
            <div class="fac-modal" style="background:#fff;border-radius:8px;max-width:580px;width:90%;margin:60px auto;padding:28px;position:relative;max-height:90vh;overflow-y:auto;">
                <button type="button" class="fac-modal-close" id="fac-inkjet-modal-close" style="position:absolute;top:12px;right:14px;font-size:20px;background:none;border:none;cursor:pointer;">&times;</button>
                <h2 id="fac-inkjet-modal-title" style="margin-top:0;">Edit Inkjet Paper</h2>
                <div class="fac-form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:14px 16px;">
                    <div class="fac-form-group" style="grid-column:1/-1;display:flex;flex-direction:column;gap:4px;">
                        <label for="fac-inkjet-name">Name</label>
                        <input type="text" id="fac-inkjet-name" class="regular-text" />
                    </div>
                    <div class="fac-form-group" style="display:flex;flex-direction:column;gap:4px;">
                        <label for="fac-inkjet-category">Category</label>
                        <select id="fac-inkjet-category" class="regular-text">
                            <option value="papers">Papers</option>
                            <option value="canvas">Canvas</option>
                            <option value="vinyl_fabric">Vinyl &amp; Fabric</option>
                            <option value="other">Other Choices</option>
                        </select>
                    </div>
                    <div class="fac-form-group" style="display:flex;flex-direction:column;gap:4px;">
                        <label for="fac-inkjet-slug">Slug</label>
                        <input type="text" id="fac-inkjet-slug" class="regular-text" />
                    </div>
                    <div class="fac-form-group" style="display:flex;flex-direction:column;gap:4px;">
                        <label for="fac-inkjet-rate">Rate ($/cm²)</label>
                        <div class="fac-num-stepper" data-step="0.0001" data-min="0" data-decimals="4">
                            <button type="button" class="fac-num-stepper__btn" data-dir="-1" aria-label="Decrease rate">−</button>
                            <input type="number" id="fac-inkjet-rate" class="fac-num-stepper__input" step="0.0001" min="0" />
                            <button type="button" class="fac-num-stepper__btn" data-dir="1" aria-label="Increase rate">+</button>
                        </div>
                    </div>
                    <div class="fac-form-group" style="display:flex;flex-direction:column;gap:4px;">
                        <label for="fac-inkjet-gsm">GSM (0 if N/A)</label>
                        <div class="fac-num-stepper" data-step="1" data-min="0" data-decimals="0">
                            <button type="button" class="fac-num-stepper__btn" data-dir="-1" aria-label="Decrease GSM">−</button>
                            <input type="number" id="fac-inkjet-gsm" class="fac-num-stepper__input" step="1" min="0" />
                            <button type="button" class="fac-num-stepper__btn" data-dir="1" aria-label="Increase GSM">+</button>
                        </div>
                    </div>
                    <div class="fac-form-group" style="grid-column:1/-1;display:flex;flex-direction:column;gap:4px;">
                        <label>Available Rolls</label>
                        <div id="fac-inkjet-rolls" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
                    </div>
                    <div class="fac-form-group" style="grid-column:1/-1;display:flex;flex-direction:column;gap:4px;">
                        <label for="fac-inkjet-desc">Description</label>
                        <textarea id="fac-inkjet-desc" rows="3" class="large-text"></textarea>
                    </div>
                </div>
                <div style="display:flex;gap:10px;margin-top:18px;padding-top:14px;border-top:1px solid #f0f0f1;">
                    <button type="button" class="button button-primary" id="fac-inkjet-modal-save">Save Paper</button>
                    <button type="button" class="button" id="fac-inkjet-modal-cancel">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Inkjet page script moved to assets/admin-inkjet.js -->
    <?php
}

/* ---------------------------------------------------------------
   WOOCOMMERCE SETTINGS PAGE
--------------------------------------------------------------- */
function fac_admin_woo_page() {
    $woo_active = class_exists( 'WooCommerce' );
    ?>
    <div class="wrap fac-admin">
        <h1>Roll Fed Calc — WooCommerce Settings</h1>

        <?php if ( ! $woo_active ) : ?>
            <div class="notice notice-warning"><p>⚠️ <strong>WooCommerce is not active.</strong> Please install and activate WooCommerce to use these settings. The calculators will still display on the front end, but adding to cart will not work.</p></div>
        <?php endif; ?>

        <div id="fac-woo-notice" style="display:none;" class="notice is-dismissible"><p></p></div>

        <div style="max-width:680px; margin-top:20px;">
            <div style="background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:24px 28px; margin-bottom:24px;">
                <h2 style="margin-top:0;">🛒 Archival Print Product</h2>
                <p>Select the <strong>Simple Product</strong> for custom archival museum-quality prints (<code>[fine_art_calculator_embed]</code>).</p>

                <?php if ( $woo_active ) : ?>
                <table class="form-table">
                    <tr>
                        <th style="width:180px"><label for="fac-product-search">Search Products</label></th>
                        <td>
                            <div style="position:relative; max-width:420px;">
                                <input type="text" id="fac-product-search" class="regular-text" placeholder="Type a product name or SKU…" autocomplete="off" style="width:100%; padding-right:36px;">
                                <span id="fac-search-spinner" style="display:none; position:absolute; right:10px; top:8px;">⏳</span>
                            </div>
                            <div id="fac-product-results" style="display:none; position:absolute; z-index:9999; background:#fff; border:1px solid #c3c4c7; border-radius:4px; max-width:420px; max-height:240px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,.12);"></div>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Selected Product</label></th>
                        <td>
                            <div id="fac-selected-product" style="padding:12px 16px; border:2px solid #e0e0e0; border-radius:6px; background:#f9f9f9; min-height:52px; display:flex; align-items:center; gap:12px; max-width:420px;">
                                <span id="fac-selected-text" style="color:#888; font-style:italic;">No product selected</span>
                            </div>
                            <input type="hidden" id="fac-product-id-field" value="<?php echo esc_attr( fac_get_product_id() ); ?>">
                        </td>
                    </tr>
                </table>
                <?php else : ?>
                <p style="color:#888;">Product search is unavailable while WooCommerce is inactive.</p>
                <input type="number" id="fac-product-id-field" min="0" value="<?php echo esc_attr( fac_get_product_id() ); ?>" class="regular-text" placeholder="Archival product ID">
                <?php endif; ?>

                <?php if ( $woo_active && fac_get_product_id() ) :
                    $pid = fac_get_product_id(); $p = wc_get_product( $pid );
                    if ( $p ) : ?>
                    <div style="margin-top:16px; padding:14px 16px; border-left:4px solid #00a32a; background:#f0faf3;">
                        <strong>Currently saved:</strong>
                        <a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>" target="_blank"><?php echo esc_html( $p->get_name() ); ?></a>
                        (ID: <?php echo $pid; ?>)
                    </div>
                    <?php endif; endif; ?>
            </div>

            <div style="background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:24px 28px; margin-bottom:24px;">
                <h2 style="margin-top:0;">🛒 Inkjet Print Product</h2>
                <p>Select the <strong>Simple Product</strong> for custom inkjet prints (<code>[inkjet_calculator_embed]</code>).</p>

                <?php if ( $woo_active ) : ?>
                <table class="form-table">
                    <tr>
                        <th style="width:180px"><label for="fac-inkjet-product-search">Search Products</label></th>
                        <td>
                            <div style="position:relative; max-width:420px;">
                                <input type="text" id="fac-inkjet-product-search" class="regular-text" placeholder="Type a product name or SKU…" autocomplete="off" style="width:100%; padding-right:36px;">
                                <span id="fac-inkjet-search-spinner" style="display:none; position:absolute; right:10px; top:8px;">⏳</span>
                            </div>
                            <div id="fac-inkjet-product-results" style="display:none; position:absolute; z-index:9999; background:#fff; border:1px solid #c3c4c7; border-radius:4px; max-width:420px; max-height:240px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,.12);"></div>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Selected Product</label></th>
                        <td>
                            <div id="fac-inkjet-selected-product" style="padding:12px 16px; border:2px solid #e0e0e0; border-radius:6px; background:#f9f9f9; min-height:52px; display:flex; align-items:center; gap:12px; max-width:420px;">
                                <span id="fac-inkjet-selected-text" style="color:#888; font-style:italic;">No product selected</span>
                            </div>
                            <input type="hidden" id="fac-inkjet-product-id-field" value="<?php echo esc_attr( fac_get_inkjet_product_id() ); ?>">
                        </td>
                    </tr>
                </table>
                <?php else : ?>
                <input type="number" id="fac-inkjet-product-id-field" min="0" value="<?php echo esc_attr( fac_get_inkjet_product_id() ); ?>" class="regular-text" placeholder="Inkjet product ID">
                <?php endif; ?>

                <?php if ( $woo_active && fac_get_inkjet_product_id() ) :
                    $ipid = fac_get_inkjet_product_id(); $ip = wc_get_product( $ipid );
                    if ( $ip ) : ?>
                    <div style="margin-top:16px; padding:14px 16px; border-left:4px solid #00a32a; background:#f0faf3;">
                        <strong>Currently saved:</strong>
                        <a href="<?php echo esc_url( get_edit_post_link( $ipid ) ); ?>" target="_blank"><?php echo esc_html( $ip->get_name() ); ?></a>
                        (ID: <?php echo $ipid; ?>)
                    </div>
                    <?php endif; endif; ?>

                <div style="margin-top:20px;">
            <?php $fac_digest = fac_get_ops_digest_settings(); ?>
            <div style="background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:24px 28px; margin-bottom:24px;">
                <h2 style="margin-top:0;">📧 Daily Ops Digest</h2>
                <p>Morning email (07:00 site time): orders in production and yesterday's error count.</p>
                <table class="form-table">
                    <tr>
                        <th style="width:180px"><label for="fac-digest-enabled">Enabled</label></th>
                        <td><input type="checkbox" id="fac-digest-enabled" value="1" <?php checked( ! empty( $fac_digest['enabled'] ) ); ?>></td>
                    </tr>
                    <tr>
                        <th><label for="fac-digest-recipient">Recipient</label></th>
                        <td>
                            <input type="email" id="fac-digest-recipient" class="regular-text" value="<?php echo esc_attr( $fac_digest['recipient'] ); ?>">
                            <p class="description">Defaults to the site admin email when left empty.</p>
                        </td>
                    </tr>
                </table>
            </div>

                    <button class="button button-primary" id="fac-save-woo" style="background:#00a32a; border-color:#00a32a;">💾 Save WooCommerce Settings</button>
                </div>
            </div>

            <div style="background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:20px 28px;">
                <h3 style="margin-top:0;">⚙️ Setup Tips</h3>
                <ul style="line-height:1.9; margin-left:16px;">
                    <li>Create two <strong>Simple Products</strong> — one for archival prints and one for inkjet prints.</li>
                    <li>Set each base price to <strong>$0.00</strong> — the calculator will override it dynamically.</li>
                    <li>Make sure both products are <strong>Published</strong> and set to <strong>Taxable</strong> if you charge tax.</li>
                    <li>Set <strong>stock</strong> to unlimited (or a high number) so they are always purchasable.</li>
                    <li>Use <code>[fine_art_calculator_embed]</code> on the Fine Art page and <code>[inkjet_calculator_embed]</code> on the Inkjet page.</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- WooCommerce settings script moved to assets/admin-woo.js -->
    <?php
}

/* ---------------------------------------------------------------
   QUOTE LINKS — fallback screens

   Only reached when the redirect above had nowhere to send the admin,
   i.e. no published page carries the shortcode yet.
--------------------------------------------------------------- */
function fac_admin_quotes_archival_page() {
    fac_admin_quotes_missing_page_notice( 'archival' );
}

function fac_admin_quotes_inkjet_page() {
    fac_admin_quotes_missing_page_notice( 'inkjet' );
}

/**
 * @param string $type archival|inkjet
 * @return void
 */
function fac_admin_quotes_missing_page_notice( $type ) {
    $shortcode = ( $type === 'inkjet' ) ? '[inkjet_calculator_embed]' : '[fine_art_calculator_embed]';
    $label     = ( $type === 'inkjet' ) ? 'Inkjet' : 'Archival';
    ?>
    <div class="wrap fac-admin">
        <h1>Roll Fed Calc — Quote Links — <?php echo esc_html( $label ); ?></h1>
        <div class="notice notice-warning">
            <p>
                <strong>Roll Fed Calc hasn't found your <?php echo esc_html( strtolower( $label ) ); ?> calculator yet.</strong>
                Quote links are configured in the calculator itself, so this page needs somewhere to send you.
            </p>
            <p>
                Put the <code><?php echo esc_html( $shortcode ); ?></code> shortcode on a published page,
                product, or template, then <strong>view it once on the front end</strong>. The plugin remembers
                where the calculator rendered and this menu will open it from then on.
            </p>
            <p>
                Viewing it is the step that matters. If you build with Oxygen, Elementor, or anything else that
                keeps its layout outside the post content, the shortcode is invisible to a search — but the moment
                the page actually renders, the calculator reports where it is.
            </p>
            <p>
                In a hurry: add <code>?fac_quote_admin=1</code> to that page's URL and you can start authoring
                right now.
            </p>
        </div>
    </div>
    <?php
}
