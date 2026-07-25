<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FAC_EI_IMAGE_PATTERN', '#\.(jpe?g|png|gif|webp|svg)(\?[^"]*)?$#i' );
define( 'FAC_EI_MAX_IMPORT_FILE_BYTES', 2 * 1024 * 1024 );
define( 'FAC_EI_MAX_IMPORT_JSON_BYTES', 2 * 1024 * 1024 );

/**
 * Convert a single absolute URL to a root-relative path if it belongs to $site_url.
 *
 * @param string $url
 * @param string $site_url Trailing slash stripped.
 * @return string
 */
function fac_ei_to_relative( $url, $site_url ) {
    if ( ! is_string( $url ) || $url === '' ) {
        return $url;
    }

    $normalised_url  = rtrim( $url, '/' );
    $normalised_site = rtrim( $site_url, '/' );

    if ( stripos( $normalised_url, $normalised_site ) === 0 ) {
        $relative = substr( $normalised_url, strlen( $normalised_site ) );
        return $relative !== '' ? $relative : '/';
    }

    return $url;
}

/**
 * Convert a root-relative path back to an absolute URL on the destination site.
 *
 * @param string $path
 * @param string $dest_site_url Trailing slash stripped.
 * @return string
 */
function fac_ei_to_absolute( $path, $dest_site_url ) {
    if ( ! is_string( $path ) || $path === '' ) {
        return $path;
    }
    if ( preg_match( '#^https?://#i', $path ) ) {
        return $path;
    }
    if ( $path[0] === '/' ) {
        return rtrim( $dest_site_url, '/' ) . $path;
    }

    return $path;
}

/**
 * Recursively relativize site-owned image URLs in nested data.
 *
 * @param mixed  $data
 * @param string $site_url
 * @return mixed
 */
function fac_ei_relativize_deep( $data, $site_url ) {
    if ( is_array( $data ) ) {
        foreach ( $data as $k => $v ) {
            $data[ $k ] = fac_ei_relativize_deep( $v, $site_url );
        }
        return $data;
    }

    if ( is_string( $data )
        && preg_match( '#^https?://#i', $data )
        && preg_match( FAC_EI_IMAGE_PATTERN, $data )
    ) {
        return fac_ei_to_relative( $data, $site_url );
    }

    return $data;
}

/**
 * Recursively absolutize relative image paths on the destination site.
 *
 * @param mixed  $data
 * @param string $dest_site_url
 * @return mixed
 */
function fac_ei_absolutize_deep( $data, $dest_site_url ) {
    if ( is_array( $data ) ) {
        foreach ( $data as $k => $v ) {
            $data[ $k ] = fac_ei_absolutize_deep( $v, $dest_site_url );
        }
        return $data;
    }

    if ( is_string( $data )
        && $data !== ''
        && $data[0] === '/'
        && preg_match( FAC_EI_IMAGE_PATTERN, $data )
    ) {
        return fac_ei_to_absolute( $data, $dest_site_url );
    }

    return $data;
}

/**
 * Gather selected options and build the export payload.
 *
 * @param array<string> $selected_keys
 * @return array
 */
function fac_ei_build_export( $selected_keys ) {
    $site_url = get_site_url();

    $payload = array(
        '_meta' => array(
            'plugin'      => 'roll-fed-calc',
            'exporter'    => FAC_VERSION,
            'source_site' => $site_url,
            'exported_at' => current_time( 'c' ),
            'wp_version'  => get_bloginfo( 'version' ),
        ),
        'settings' => array(),
    );

    foreach ( $selected_keys as $key ) {
        $raw = get_option( $key );
        if ( $raw === false ) {
            continue;
        }
        $payload['settings'][ $key ] = fac_ei_relativize_deep( $raw, $site_url );
    }

    return $payload;
}

/**
 * Write imported settings to wp_options.
 *
 * @param array         $payload
 * @param array<string> $selected_keys
 * @param bool          $rewrite_images
 * @return array|WP_Error
 */
function fac_ei_apply_import( $payload, $selected_keys, $rewrite_images ) {
    if ( empty( $payload['settings'] ) || ! is_array( $payload['settings'] ) ) {
        return new WP_Error( 'bad_payload', 'No settings array found in the import file.' );
    }

    $dest_url = get_site_url();
    $imported = array();
    $skipped  = array();

    foreach ( $selected_keys as $key ) {
        if ( ! array_key_exists( $key, $payload['settings'] ) ) {
            $skipped[] = $key;
            continue;
        }

        $value = $payload['settings'][ $key ];

        if ( $rewrite_images ) {
            $value = fac_ei_absolutize_deep( $value, $dest_url );
        }

        $validated = fac_ei_validate_option_value( $key, $value );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }

        fac_update_option_audited( $key, $validated, 'import' );
        $imported[] = $key;
    }

    if ( in_array( 'fac_paper_images', $imported, true ) ) {
        delete_option( 'fac_paper_images_version' );
    }

    return array(
        'imported' => count( $imported ),
        'skipped'  => count( $skipped ),
        'keys'     => $imported,
    );
}

/**
 * Validate and sanitize an imported option value by option key.
 *
 * @param string $key   Option key.
 * @param mixed  $value Raw imported value.
 * @return mixed|WP_Error
 */
function fac_ei_validate_option_value( $key, $value ) {
    switch ( $key ) {
        case 'fac_paper_data':
            return fac_sanitize_archival_paper_data( $value );

        case 'fac_inkjet_paper_data':
            return fac_sanitize_inkjet_paper_data( $value );

        case 'fac_roll_widths':
            return fac_sanitize_roll_widths_data( $value );

        case 'fac_mounting_rates':
            if ( ! is_array( $value ) ) {
                return new WP_Error( 'invalid_mounting_rates', 'Mounting rates payload is invalid.' );
            }
            return fac_sanitize_mounting_rates_data( $value );

        case 'fac_turnaround_rates':
            if ( ! is_array( $value ) ) {
                return new WP_Error( 'invalid_turnaround_rates', 'Turnaround rates payload is invalid.' );
            }
            return fac_sanitize_turnaround_rates_data( $value );

        case 'fac_ops_digest':
            return fac_sanitize_ops_digest_settings( $value );

        case 'fac_woocommerce_product_id':
        case 'fac_inkjet_woocommerce_product_id':
            return absint( $value );

        case 'fac_paper_images':
            if ( ! is_array( $value ) ) {
                return new WP_Error( 'invalid_paper_images', 'Paper image map is invalid.' );
            }
            $clean = array();
            foreach ( $value as $slug => $url ) {
                $slug = sanitize_key( (string) $slug );
                $url  = esc_url_raw( (string) $url );
                if ( $slug !== '' && $url !== '' ) {
                    $clean[ $slug ] = $url;
                }
            }
            return $clean;

        default:
            return new WP_Error( 'unsupported_option', sprintf( 'Unsupported option key in import payload: %s', $key ) );
    }
}

add_action( 'admin_menu', 'fac_ei_register_menu', 20 );
function fac_ei_register_menu() {
    add_submenu_page(
        'fine-art-calculator',
        'Export / Import Settings',
        '⬆↓ Export / Import',
        'manage_options',
        'fac-exporter',
        'fac_ei_render_page'
    );
}

add_action( 'admin_init', 'fac_ei_handle_form' );
function fac_ei_handle_form() {
    if ( ! isset( $_POST['fac_ei_action'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Insufficient permissions.' );
    }

    $action = sanitize_key( $_POST['fac_ei_action'] );

    if ( $action === 'export' ) {
        check_admin_referer( 'fac_ei_export_nonce', 'fac_ei_nonce' );

        $all_keys      = fac_get_exportable_option_keys();
        $raw_selection = isset( $_POST['fac_ei_keys'] ) ? (array) $_POST['fac_ei_keys'] : $all_keys;
        $selected      = array_values( array_intersect( $raw_selection, $all_keys ) );

        if ( empty( $selected ) ) {
            wp_die( 'Please select at least one setting to export.' );
        }

        $payload  = fac_ei_build_export( $selected );
        $json     = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $filename = 'fac-settings-' . gmdate( 'Y-m-d' ) . '.json';

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $json ) );
        echo $json;
        exit;
    }

    if ( $action === 'import' ) {
        check_admin_referer( 'fac_ei_import_nonce', 'fac_ei_nonce' );

        $redirect_base = add_query_arg( 'page', 'fac-exporter', admin_url( 'admin.php' ) );

        if ( empty( $_FILES['fac_ei_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['fac_ei_file']['tmp_name'] ) ) {
            wp_redirect( add_query_arg( 'fac_ei_err', 'no_file', $redirect_base ) );
            exit;
        }

        $uploaded_size = absint( $_FILES['fac_ei_file']['size'] ?? 0 );
        if ( $uploaded_size <= 0 || $uploaded_size > FAC_EI_MAX_IMPORT_FILE_BYTES ) {
            wp_redirect( add_query_arg( 'fac_ei_err', 'file_too_large', $redirect_base ) );
            exit;
        }

        $original_name = sanitize_file_name( (string) ( $_FILES['fac_ei_file']['name'] ?? '' ) );
        if ( $original_name === '' || strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) ) !== 'json' ) {
            wp_redirect( add_query_arg( 'fac_ei_err', 'bad_extension', $redirect_base ) );
            exit;
        }

        $finfo    = new finfo( FILEINFO_MIME_TYPE );
        $tmp_path = $_FILES['fac_ei_file']['tmp_name'];
        $mime     = $finfo->file( $tmp_path );
        if ( strpos( $mime, 'json' ) === false && strpos( $mime, 'text' ) === false ) {
            wp_redirect( add_query_arg( 'fac_ei_err', 'bad_mime', $redirect_base ) );
            exit;
        }

        $filecheck = wp_check_filetype_and_ext( $tmp_path, $original_name, array( 'json' => 'application/json' ) );
        if ( empty( $filecheck['ext'] ) || $filecheck['ext'] !== 'json' ) {
            wp_redirect( add_query_arg( 'fac_ei_err', 'bad_extension', $redirect_base ) );
            exit;
        }

        $raw     = file_get_contents( $tmp_path );
        if ( $raw === false || strlen( $raw ) > FAC_EI_MAX_IMPORT_JSON_BYTES ) {
            wp_redirect( add_query_arg( 'fac_ei_err', 'file_too_large', $redirect_base ) );
            exit;
        }
        $payload = json_decode( $raw, true );

        if ( json_last_error() !== JSON_ERROR_NONE || empty( $payload['_meta'] ) ) {
            wp_redirect( add_query_arg( 'fac_ei_err', 'bad_json', $redirect_base ) );
            exit;
        }

        $all_keys      = fac_get_exportable_option_keys();
        $raw_selection = isset( $_POST['fac_ei_import_keys'] ) ? (array) $_POST['fac_ei_import_keys'] : array_keys( $payload['settings'] ?? array() );
        $selected      = array_values( array_intersect( $raw_selection, $all_keys ) );
        $rewrite       = ! empty( $_POST['fac_ei_rewrite'] );

        $result = fac_ei_apply_import( $payload, $selected, $rewrite );

        if ( is_wp_error( $result ) ) {
            wp_redirect( add_query_arg( 'fac_ei_err', urlencode( $result->get_error_message() ), $redirect_base ) );
            exit;
        }

        wp_redirect( add_query_arg( array(
            'fac_ei_ok'      => '1',
            'fac_ei_count'   => $result['imported'],
            'fac_ei_skipped' => $result['skipped'],
        ), $redirect_base ) );
        exit;
    }
}

function fac_ei_render_page() {
    $all_keys  = fac_get_exportable_option_keys();
    $labels    = fac_get_exportable_option_labels();
    $site_keys = fac_get_exportable_site_specific_keys();
    $site_url  = get_site_url();
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px">
            <span class="dashicons dashicons-migrate" style="font-size:28px;width:28px;height:28px;color:#2271b1"></span>
            Roll Fed Calc — Export / Import
        </h1>
        <p style="color:#646970;margin-top:4px">
            Transfer all calculator settings between WordPress sites.
            Image URLs are stored as <strong>relative paths</strong> in the export file and rewritten to the destination site on import.
        </p>
        <hr>

        <?php fac_ei_render_notices(); ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px;max-width:1100px;margin-top:24px;align-items:start">

            <section>
                <h2 style="margin-top:0;padding-bottom:6px;border-bottom:2px solid #2271b1">
                    ⬆ Export Settings
                </h2>
                <p style="color:#646970;font-size:13px">
                    Choose which settings to include in the export file.
                    All image URLs belonging to <code><?php echo esc_html( $site_url ); ?></code>
                    will be stored as relative paths (e.g. <code>/wp-content/uploads/…</code>)
                    so they map correctly on any destination site.
                    External image URLs are preserved as-is.
                </p>

                <form method="post" action="" id="fac-ei-export-form">
                    <?php wp_nonce_field( 'fac_ei_export_nonce', 'fac_ei_nonce' ); ?>
                    <input type="hidden" name="fac_ei_action" value="export">

                    <table class="widefat striped" style="margin-bottom:16px;border-radius:4px;overflow:hidden">
                        <thead>
                            <tr>
                                <th style="width:36px">
                                    <input type="checkbox" id="fac-ei-exp-all" checked
                                        title="Toggle all" aria-label="Toggle all export checkboxes">
                                </th>
                                <th>Setting</th>
                                <th style="width:130px">Stored Value</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $all_keys as $key ) :
                            $raw    = get_option( $key );
                            $exists = ( $raw !== false );
                            if ( is_array( $raw ) ) {
                                $preview = count( $raw ) . ' item(s)';
                            } elseif ( is_numeric( $raw ) ) {
                                $preview = ( (int) $raw > 0 ) ? '#' . (int) $raw : '(not set)';
                            } else {
                                $preview = $exists ? '(set)' : '— not set —';
                            }
                            $is_site_specific = in_array( $key, $site_keys, true );
                        ?>
                            <tr>
                                <td>
                                    <input type="checkbox"
                                        name="fac_ei_keys[]"
                                        value="<?php echo esc_attr( $key ); ?>"
                                        class="fac-ei-exp-cb"
                                        <?php checked( $exists ); ?>
                                        <?php disabled( ! $exists ); ?>>
                                </td>
                                <td>
                                    <strong><?php echo esc_html( $labels[ $key ] ); ?></strong>
                                    <br><code style="font-size:11px;color:#8c8f94"><?php echo esc_html( $key ); ?></code>
                                    <?php if ( $is_site_specific ) : ?>
                                        <br><span style="font-size:11px;color:#d63638">
                                            ⚠ Site-specific — product IDs differ between sites
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:#646970;font-size:12px">
                                    <?php echo esc_html( $preview ); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                        <button type="submit" class="button button-primary button-large">
                            ⬇ Download Export File
                        </button>
                        <span style="font-size:12px;color:#646970">
                            Saves as <code>fac-settings-<?php echo gmdate( 'Y-m-d' ); ?>.json</code>
                        </span>
                    </p>
                </form>
            </section>

            <section>
                <h2 style="margin-top:0;padding-bottom:6px;border-bottom:2px solid #2271b1">
                    ⬇ Import Settings
                </h2>
                <p style="color:#646970;font-size:13px">
                    Upload an export file to restore settings.
                    Existing options are overwritten for each selected key.
                    Select the file first — the form will preview what is inside
                    before you commit.
                </p>

                <form method="post" action="" enctype="multipart/form-data" id="fac-ei-import-form">
                    <?php wp_nonce_field( 'fac_ei_import_nonce', 'fac_ei_nonce' ); ?>
                    <input type="hidden" name="fac_ei_action" value="import">

                    <table class="form-table" style="margin:0 0 0">
                        <tr>
                            <th style="width:130px;vertical-align:middle">
                                <label for="fac-ei-file">Export File</label>
                            </th>
                            <td>
                                <input type="file" name="fac_ei_file" id="fac-ei-file" accept=".json,application/json" required>
                                <br>
                                <span style="font-size:12px;color:#646970">
                                    A <code>.json</code> file produced by Roll Fed Calc on another site.
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th style="vertical-align:top;padding-top:12px">Image URLs</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="fac_ei_rewrite" value="1" id="fac-ei-rewrite" checked>
                                    Rewrite image URLs to this site
                                </label>
                                <br>
                                <span style="font-size:12px;color:#646970">
                                    Converts relative paths (<code>/wp-content/uploads/…</code>) to absolute
                                    URLs on <code><?php echo esc_html( $site_url ); ?></code>.
                                    External URLs (other domains) are always preserved unchanged.
                                    <strong>Keep this checked when moving between sites.</strong>
                                </span>
                            </td>
                        </tr>
                    </table>

                    <div id="fac-ei-file-info" style="display:none;margin:16px 0 0;padding:12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;font-size:13px">
                        <strong>File loaded:</strong> <span id="fac-ei-fname"></span>
                        <br>
                        Exported from: <code id="fac-ei-fsrc"></code>
                        <br>
                        Exported on: <span id="fac-ei-fdate"></span>
                        <div id="fac-ei-domain-warn" style="display:none;margin-top:10px;padding:10px 14px;background:#fff3cd;border:1px solid #ffc107;border-radius:3px;font-size:12px">
                            ⚠ <strong>Different domain detected.</strong>
                            The export is from a different site.
                            Make sure <em>Rewrite image URLs</em> is checked above.
                        </div>
                    </div>

                    <div id="fac-ei-key-section" style="display:none;margin-top:20px">
                        <h3 style="margin-bottom:8px">Select settings to import:</h3>
                        <table class="widefat striped" style="border-radius:4px;overflow:hidden">
                            <thead>
                                <tr>
                                    <th style="width:36px">
                                        <input type="checkbox" id="fac-ei-imp-all" checked
                                            aria-label="Toggle all import checkboxes">
                                    </th>
                                    <th>Setting</th>
                                    <th>In File</th>
                                </tr>
                            </thead>
                            <tbody id="fac-ei-key-tbody"></tbody>
                        </table>
                    </div>

                    <p style="margin-top:16px">
                        <button type="submit" class="button button-primary button-large"
                            id="fac-ei-import-btn" disabled>
                            ✓ Import Selected Settings
                        </button>
                        <span id="fac-ei-import-hint" style="font-size:12px;color:#646970;margin-left:10px">
                            Load a file above to continue.
                        </span>
                    </p>
                </form>
            </section>

        </div>
    </div>

    <?php fac_ei_render_js( $labels, $site_url ); ?>
    <?php
}

function fac_ei_render_notices() {
    if ( isset( $_GET['fac_ei_ok'] ) ) {
        $count   = absint( $_GET['fac_ei_count'] ?? 0 );
        $skipped = absint( $_GET['fac_ei_skipped'] ?? 0 );
        $msg     = sprintf( '%d setting%s imported successfully.', $count, $count === 1 ? '' : 's' );
        if ( $skipped > 0 ) {
            $msg .= sprintf( ' %d setting%s not found in the file and were skipped.', $skipped, $skipped === 1 ? ' was' : 's were' );
        }
        echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html( $msg ) . '</strong></p></div>';
    }

    $err_map = array(
        'no_file'  => 'No file was uploaded. Please select a .json export file.',
        'bad_mime' => 'The uploaded file does not appear to be a JSON file.',
        'bad_json' => 'Could not parse the uploaded file. Make sure it is a valid Roll Fed Calc export.',
        'bad_extension' => 'The uploaded file must use the .json extension.',
        'file_too_large' => 'The uploaded file is too large. Keep import files under 2 MB.',
    );

    if ( isset( $_GET['fac_ei_err'] ) ) {
        $err_key = sanitize_key( $_GET['fac_ei_err'] );
        $message = $err_map[ $err_key ] ?? urldecode( $_GET['fac_ei_err'] );
        echo '<div class="notice notice-error is-dismissible"><p><strong>Import error:</strong> ' . esc_html( $message ) . '</p></div>';
    }
}

function fac_ei_render_js( $labels, $site_url ) {
    $labels_json   = wp_json_encode( $labels );
    $site_url_json = wp_json_encode( $site_url );
    $site_keys     = wp_json_encode( fac_get_exportable_site_specific_keys() );
    ?>
    <script>
    (function () {
        'use strict';

        const LABELS    = <?php echo $labels_json; ?>;
        const SITE_URL  = <?php echo $site_url_json; ?>;
        const SITE_KEYS = <?php echo $site_keys; ?>;

        const expAll = document.getElementById('fac-ei-exp-all');
        expAll.addEventListener('change', () => {
            document.querySelectorAll('.fac-ei-exp-cb:not(:disabled)')
                .forEach(cb => cb.checked = expAll.checked);
        });

        const fileInput  = document.getElementById('fac-ei-file');
        const fileInfo   = document.getElementById('fac-ei-file-info');
        const domainWarn = document.getElementById('fac-ei-domain-warn');
        const keySection = document.getElementById('fac-ei-key-section');
        const tbody      = document.getElementById('fac-ei-key-tbody');
        const importBtn  = document.getElementById('fac-ei-import-btn');
        const importHint = document.getElementById('fac-ei-import-hint');
        const impAll     = document.getElementById('fac-ei-imp-all');

        fileInput.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function (ev) {
                let payload;
                try {
                    payload = JSON.parse(ev.target.result);
                } catch (e) {
                    alert('Error: Could not parse the file as JSON.\nMake sure you selected a valid Roll Fed Calc export.');
                    return;
                }

                if (!payload._meta) {
                    alert('Error: This does not look like a Roll Fed Calc export file (missing _meta block).');
                    return;
                }

                document.getElementById('fac-ei-fname').textContent = file.name;
                document.getElementById('fac-ei-fsrc').textContent  = payload._meta.source_site  || '(unknown)';
                document.getElementById('fac-ei-fdate').textContent = payload._meta.exported_at
                    ? new Date(payload._meta.exported_at).toLocaleString()
                    : '(unknown)';
                fileInfo.style.display = 'block';

                const srcSite = (payload._meta.source_site || '').replace(/\/$/, '');
                const thisSite = SITE_URL.replace(/\/$/, '');
                domainWarn.style.display = (srcSite && srcSite !== thisSite) ? 'block' : 'none';

                const settings = payload.settings || {};
                tbody.innerHTML = '';

                Object.entries(LABELS).forEach(([key, label]) => {
                    const inFile  = Object.prototype.hasOwnProperty.call(settings, key);
                    const value   = settings[key];
                    const isSiteSpecific = SITE_KEYS.includes(key);

                    let preview;
                    if (!inFile) {
                        preview = '<em style="color:#d63638">✗ Not in file</em>';
                    } else if (Array.isArray(value)) {
                        preview = `✓ ${value.length} item(s)`;
                    } else if (value !== null && typeof value === 'object') {
                        preview = `✓ ${Object.keys(value).length} item(s)`;
                    } else if (value === 0 || value === '0') {
                        preview = '✓ (not set / 0)';
                    } else {
                        preview = `✓ ${String(value).substring(0, 40)}`;
                    }

                    const siteNote = isSiteSpecific
                        ? '<br><em style="font-size:11px;color:#d63638">⚠ Site-specific — verify after import</em>'
                        : '';

                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td style="width:36px">
                            <input type="checkbox"
                                name="fac_ei_import_keys[]"
                                value="${escAttr(key)}"
                                class="fac-ei-imp-cb"
                                ${inFile ? 'checked' : 'disabled'}>
                        </td>
                        <td>
                            <strong>${escHtml(label)}</strong>
                            <br><code style="font-size:11px;color:#8c8f94">${escHtml(key)}</code>
                            ${siteNote}
                        </td>
                        <td style="font-size:12px;color:#1d2327">${preview}</td>`;
                    tbody.appendChild(tr);
                });

                keySection.style.display = 'block';
                importBtn.disabled       = false;
                importHint.textContent   = 'Review the selection above, then click Import.';
            };
            reader.readAsText(file);
        });

        impAll.addEventListener('change', () => {
            document.querySelectorAll('.fac-ei-imp-cb:not(:disabled)')
                .forEach(cb => cb.checked = impAll.checked);
        });

        function escHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }
        function escAttr(s) { return escHtml(s); }

    })();
    </script>
    <?php
}
