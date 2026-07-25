<?php
/**
 * Print Layout Planner — artwork stash & order attachment.
 *
 * The customer-facing planner (assets/layout-planner.js) uploads the images a
 * shopper arranges on the roll to a protected upload folder as they work, and
 * keeps a small manifest (one entry per placed print: stash id, filename, and
 * the planned width/height/rotation) in the WooCommerce session. When the order
 * is placed, the stashed files that are still referenced by the manifest are
 * moved into a per-order folder and recorded in order meta, so the studio can
 * download exactly what the customer laid out from the order screen.
 *
 * Privacy note: unlike earlier versions (where artwork never left the browser),
 * placed images ARE uploaded so they can be attached to the order. Files live in
 * an unguessable, deny-listed folder and are only ever served through a
 * capability-gated endpoint. The shopper is still told to send print-ready
 * masters via the WeTransfer link for the sharpest result.
 *
 * @package Roll_Fed_Calc
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ---------------------------------------------------------------
   Caps. Enforced on the server; the planner mirrors them client side.

   Large masters arrive in slices through the chunk endpoint below, so
   the per-file ceiling is no longer bound by PHP's upload_max_filesize
   or post_max_size — only one chunk is ever in a single request. Chunk
   size is negotiated from what the server will actually accept, so a
   host with an 8 MB post_max_size still takes a 2 GB image.
--------------------------------------------------------------- */
define( 'FAC_ARTWORK_MAX_FILE_BYTES',  2147483648 );          // 2 GB per image.
define( 'FAC_ARTWORK_MAX_FILES',       40 );                  // Images kept per session.
define( 'FAC_ARTWORK_MAX_TOTAL_BYTES', 8589934592 );          // 8 GB total per session.
define( 'FAC_ARTWORK_CHUNK_BYTES',     8 * 1024 * 1024 );     // Preferred slice size.
define( 'FAC_ARTWORK_DIRNAME',         'fac-artwork' );       // Under wp-content/uploads.
define( 'FAC_ARTWORK_PARTS_DIRNAME',   'parts' );             // In-progress chunk assembly.
define( 'FAC_ARTWORK_SESSION_KEY',     'fac_layout_images' );
define( 'FAC_ARTWORK_SESSION_ROLL',    'fac_layout_roll' );
define( 'FAC_ARTWORK_ORDER_META',      '_fac_layout_images' );
define( 'FAC_ARTWORK_ORDER_ROLL_META', '_fac_layout_roll' );
define( 'FAC_ARTWORK_CLEANUP_HOOK',    'fac_artwork_cleanup' );
define( 'FAC_ARTWORK_STASH_TTL',       2 * DAY_IN_SECONDS );   // Abandoned stashes pruned after this.
define( 'FAC_ARTWORK_PRUNE_GRACE',     30 * MINUTE_IN_SECONDS ); // Never prune a file younger than this.

/**
 * Per-image byte ceiling. Filterable so a store can lower it for disk reasons.
 *
 * @return int
 */
function fac_artwork_max_file_bytes() {
    return (int) apply_filters( 'fac_artwork_max_file_bytes', FAC_ARTWORK_MAX_FILE_BYTES );
}

/**
 * Total bytes one shopping session may hold in its stash.
 *
 * @return int
 */
function fac_artwork_max_total_bytes() {
    return (int) apply_filters( 'fac_artwork_max_total_bytes', FAC_ARTWORK_MAX_TOTAL_BYTES );
}

/**
 * Slice size the browser should use, clamped to what this server accepts.
 *
 * wp_max_upload_size() is the smaller of upload_max_filesize and post_max_size.
 * A slice has to fit inside that with room for the rest of the multipart body,
 * so take 80% of it and never go below 256 KB.
 *
 * @return int
 */
function fac_artwork_chunk_bytes() {
    $server = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
    $chunk  = FAC_ARTWORK_CHUNK_BYTES;
    if ( $server > 0 ) {
        $chunk = min( $chunk, (int) floor( $server * 0.8 ) );
    }
    $chunk = max( 256 * 1024, $chunk );
    return (int) apply_filters( 'fac_artwork_chunk_bytes', $chunk );
}

/**
 * Allowed image mime types mapped to the extension used on disk.
 *
 * @return array<string,string>
 */
function fac_artwork_allowed_types() {
    return array(
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    );
}

/**
 * Absolute path to the artwork root inside the uploads directory.
 *
 * @param string $sub Optional sub-path appended to the root.
 * @return string|WP_Error Absolute path, or WP_Error if uploads is unavailable.
 */
function fac_artwork_base_dir( $sub = '' ) {
    $uploads = wp_upload_dir();
    if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
        return new WP_Error( 'fac_uploads', __( 'Uploads directory is not available.', 'fine-art-calculator' ) );
    }
    $path = trailingslashit( $uploads['basedir'] ) . FAC_ARTWORK_DIRNAME;
    if ( '' !== $sub ) {
        $path .= '/' . ltrim( $sub, '/' );
    }
    return $path;
}

/**
 * Ensure a directory exists and is hardened against direct web access.
 *
 * An index.html and a .htaccess deny are dropped at the artwork root. On Nginx
 * (which ignores .htaccess) protection instead relies on the unguessable file
 * names and the capability-gated download endpoint — never link these directly.
 *
 * @param string $dir Absolute directory path.
 * @return bool True on success.
 */
function fac_artwork_prepare_dir( $dir ) {
    if ( ! wp_mkdir_p( $dir ) ) {
        return false;
    }
    $root = fac_artwork_base_dir();
    if ( ! is_wp_error( $root ) ) {
        $index = trailingslashit( $root ) . 'index.html';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
        $ht = trailingslashit( $root ) . '.htaccess';
        if ( ! file_exists( $ht ) ) {
            @file_put_contents( $ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
    }
    return true;
}

/**
 * Per-session stash folder name (stable for one shopper, non-sequential).
 *
 * WooCommerce hands a guest a freshly generated customer id on every request
 * until something causes the session cookie to be set. Uploads now span many
 * requests — one per slice, plus the manifest — so relying on that default gave
 * each request its own folder: slice 0 wrote somewhere slice 1 could not find,
 * and a completed file was invisible to the manifest that referenced it. The
 * order then attached nothing at all.
 *
 * So establish the cookie and mark the session dirty the first time we touch
 * it, which pins the customer id for every later request in the upload.
 *
 * @return string|WP_Error Sanitised folder token, or WP_Error if no session.
 */
function fac_artwork_session_token() {
    if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
        return new WP_Error( 'fac_no_session', __( 'No active shopping session.', 'fine-art-calculator' ) );
    }

    $session = WC()->session;

    if ( method_exists( $session, 'set_customer_session_cookie' ) ) {
        $session->set_customer_session_cookie( true );
    }
    // A value in the session makes it dirty, so WooCommerce persists the row on
    // shutdown; without that the cookie would point at nothing next request.
    if ( ! $session->get( 'fac_artwork_session' ) ) {
        $session->set( 'fac_artwork_session', time() );
    }

    $customer_id = (string) $session->get_customer_id();
    if ( '' === $customer_id ) {
        return new WP_Error( 'fac_no_session', __( 'No active shopping session.', 'fine-art-calculator' ) );
    }
    return 'sess-' . md5( $customer_id );
}

/**
 * A stash id is the on-disk filename: 32 hex chars + an allowed extension.
 *
 * @param string $id Candidate id from the client manifest.
 * @return bool True if the shape is valid.
 */
function fac_artwork_valid_stash_id( $id ) {
    return (bool) preg_match( '/^[a-f0-9]{32}\.(jpg|jpeg|png|webp)$/', (string) $id );
}

/**
 * Roll geometry of the layout currently held in the shopper's session.
 *
 * The manifest already carries every print's placed footprint and position, so
 * the roll length a layout consumes can be measured server-side rather than
 * taken on trust from the browser. Mirrors feedUsedIn() in
 * assets/layout-planner.js: the run ends at the far edge of the lowest print.
 *
 * @param array|null $manifest Manifest to measure, or null to read the session.
 * @return array feedIn, areaIn, count, and distinct sizes => count.
 */
function fac_artwork_layout_geometry( $manifest = null ) {
    $out = array( 'feedIn' => 0.0, 'areaIn' => 0.0, 'count' => 0, 'sizes' => array() );

    if ( null === $manifest ) {
        $manifest = ( function_exists( 'WC' ) && WC()->session )
            ? WC()->session->get( FAC_ARTWORK_SESSION_KEY )
            : null;
    }

    if ( ! is_array( $manifest ) ) {
        return $out;
    }

    foreach ( $manifest as $entry ) {
        if ( ! is_array( $entry ) ) {
            continue;
        }

        $w = isset( $entry['w_in'] ) ? (float) $entry['w_in'] : 0.0;
        $h = isset( $entry['h_in'] ) ? (float) $entry['h_in'] : 0.0;
        $y = isset( $entry['y_in'] ) ? (float) $entry['y_in'] : 0.0;

        if ( $w <= 0 || $h <= 0 ) {
            continue;
        }

        $out['count']++;
        $out['areaIn'] += $w * $h;
        $out['feedIn']  = max( $out['feedIn'], $y + $h );

        $key                  = number_format( $w, 2, '.', '' ) . 'x' . number_format( $h, 2, '.', '' );
        $out['sizes'][ $key ] = isset( $out['sizes'][ $key ] ) ? $out['sizes'][ $key ] + 1 : 1;
    }

    return $out;
}

/**
 * The layout length that may price one cart line, in cm — or 0 for none.
 *
 * A layout belongs to the order, not to a line, so it can only price a line
 * that unambiguously *is* the whole layout: one footprint, matching this line's
 * size, and as many prints as the line's quantity. Anything else — a mixed-size
 * layout split across several lines, or the same size added twice — would
 * charge one run of roll two or more times over, so those fall back to nesting.
 *
 * @param array      $state Calculator state.
 * @param array|null $geo   Geometry, or null to read the session.
 * @return float Layout length in cm, or 0.0 when it must not price this line.
 */
function fac_layout_feed_cm_for_state( $state, $geo = null ) {
    if ( ! FAC_LAYOUT_DRIVEN_PRICING ) {
        return 0.0;
    }

    $geo = null === $geo ? fac_artwork_layout_geometry() : $geo;

    if ( $geo['count'] < 1 || count( $geo['sizes'] ) !== 1 ) {
        return 0.0;
    }

    $quantity = max( 1, (int) round( floatval( $state['quantity'] ?? 1 ) ) );
    if ( $geo['count'] !== $quantity ) {
        return 0.0;
    }

    $units  = ( $state['units'] ?? 'inches' ) === 'centimeters' ? 'centimeters' : 'inches';
    $width  = floatval( $state['width'] ?? 0 );
    $height = floatval( $state['height'] ?? 0 );
    if ( $units === 'centimeters' ) {
        $width  /= 2.54;
        $height /= 2.54;
    }

    $keys  = array_keys( $geo['sizes'] );
    $parts = explode( 'x', $keys[0] );
    $lw    = isset( $parts[0] ) ? (float) $parts[0] : 0.0;
    $lh    = isset( $parts[1] ) ? (float) $parts[1] : 0.0;

    // The planner may have turned a print a quarter, so either way round counts.
    $matches = ( abs( $lw - $width ) < 0.02 && abs( $lh - $height ) < 0.02 )
        || ( abs( $lw - $height ) < 0.02 && abs( $lh - $width ) < 0.02 );

    if ( ! $matches ) {
        return 0.0;
    }

    return $geo['feedIn'] * 2.54;
}

/**
 * Stamp the server-measured layout length onto a calculator state.
 *
 * @param array $state Calculator state.
 * @return array State with layoutFeedCm resolved server-side.
 */
function fac_apply_layout_feed_to_state( $state ) {
    if ( ! is_array( $state ) ) {
        return $state;
    }

    // Never trust a posted value: overwrite it, or clear it outright.
    unset( $state['layoutFeedCm'] );

    $feed_cm = fac_layout_feed_cm_for_state( $state );
    if ( $feed_cm > 0 ) {
        $state['layoutFeedCm'] = $feed_cm;
    }

    return $state;
}

/* ===============================================================
   AJAX: stash sync
   =============================================================== */

add_action( 'wp_ajax_fac_layout_save',        'fac_artwork_ajax_save' );
add_action( 'wp_ajax_nopriv_fac_layout_save', 'fac_artwork_ajax_save' );

/**
 * Receive the current planner manifest plus any new image parts.
 *
 * Stores new images under the session stash folder, prunes any previously
 * stashed files no longer referenced, persists the reconciled manifest to the
 * WooCommerce session, and returns a map of upload-token => stash id so the
 * client can remember what has already been uploaded.
 *
 * @return void Sends a JSON response and exits.
 */
function fac_artwork_ajax_save() {
    if ( ! check_ajax_referer( 'fac_artwork_nonce', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'bad_nonce' ), 403 );
    }

    $token = fac_artwork_session_token();
    if ( is_wp_error( $token ) ) {
        wp_send_json_error( array( 'message' => $token->get_error_code() ), 400 );
    }

    $dir = fac_artwork_base_dir( 'tmp/' . $token );
    if ( is_wp_error( $dir ) || ! fac_artwork_prepare_dir( $dir ) ) {
        wp_send_json_error( array( 'message' => 'no_storage' ), 500 );
    }

    // Decode the manifest. wp_unslash before JSON decode; sanitise per field.
    $raw      = isset( $_POST['manifest'] ) ? wp_unslash( $_POST['manifest'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $manifest = json_decode( (string) $raw, true );
    if ( ! is_array( $manifest ) ) {
        $manifest = array();
    }

    $allowed  = fac_artwork_allowed_types();
    $map      = array();   // upload token => new stash id.
    $existing = fac_artwork_list_stash( $dir );
    $total    = 0;
    foreach ( $existing as $name => $size ) {
        $total += $size;
    }

    // Store any new file parts, keyed by the upload tokens (u0, u1, ...).
    if ( ! empty( $_FILES ) ) {
        foreach ( $_FILES as $field => $file ) {
            if ( ! preg_match( '/^u\d+$/', (string) $field ) ) {
                continue;
            }
            if ( ! isset( $file['tmp_name'], $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
                continue;
            }
            if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
                continue;
            }
            $size = (int) $file['size'];
            if ( $size <= 0 || $size > fac_artwork_max_file_bytes() ) {
                continue;
            }
            if ( count( $existing ) >= FAC_ARTWORK_MAX_FILES || ( $total + $size ) > fac_artwork_max_total_bytes() ) {
                continue;
            }

            // Validate that this really is one of the allowed image types.
            $mime = fac_artwork_detect_mime( $file['tmp_name'] );
            if ( ! isset( $allowed[ $mime ] ) ) {
                continue;
            }
            $info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if ( false === $info || empty( $info[0] ) || empty( $info[1] ) ) {
                continue;
            }

            $stash_id = bin2hex( random_bytes( 16 ) ) . '.' . $allowed[ $mime ];
            $dest     = trailingslashit( $dir ) . $stash_id;
            if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                continue;
            }
            @chmod( $dest, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $existing[ $stash_id ] = $size;
            $total                += $size;
            $map[ $field ]         = $stash_id;
        }
    }

    // Reconcile the manifest: resolve each entry to a stash id that actually
    // exists in THIS session's folder (defeats referencing another session's
    // files by id), and keep only those.
    $clean   = array();
    $kept    = array();
    $dropped = 0;
    foreach ( $manifest as $entry ) {
        if ( ! is_array( $entry ) ) {
            continue;
        }
        // A placeholder reserves space for artwork the shopper will send later.
        // There is no file to resolve, so it skips the stash checks entirely.
        if ( ! empty( $entry['placeholder'] ) ) {
            $ph_id = isset( $entry['phId'] ) ? sanitize_text_field( (string) $entry['phId'] ) : '';
            if ( ! preg_match( '/^ph_[a-f0-9]{32}$/', $ph_id ) ) {
                $dropped++;
                continue;
            }
            $clean[] = array(
                'stash'       => '',
                'placeholder' => 1,
                'ph'          => $ph_id,
                'name'        => isset( $entry['name'] ) ? sanitize_text_field( (string) $entry['name'] ) : '',
                'w_in'        => isset( $entry['wIn'] ) ? max( 0, (float) $entry['wIn'] ) : 0,
                'h_in'        => isset( $entry['hIn'] ) ? max( 0, (float) $entry['hIn'] ) : 0,
                'x_in'        => isset( $entry['xIn'] ) ? max( 0, (float) $entry['xIn'] ) : 0,
                'y_in'        => isset( $entry['yIn'] ) ? max( 0, (float) $entry['yIn'] ) : 0,
                'rotation'    => isset( $entry['rotation'] ) ? ( (int) $entry['rotation'] % 360 ) : 0,
            );
            continue;
        }

        $upload   = isset( $entry['upload'] ) ? (string) $entry['upload'] : '';
        $stash_id = isset( $entry['stashId'] ) ? (string) $entry['stashId'] : '';
        if ( '' !== $upload && isset( $map[ $upload ] ) ) {
            $stash_id = $map[ $upload ];
        }
        if ( ! fac_artwork_valid_stash_id( $stash_id ) || ! isset( $existing[ $stash_id ] ) ) {
            $dropped++;
            continue;
        }
        $clean[] = array(
            'stash'    => $stash_id,
            'name'     => isset( $entry['name'] ) ? sanitize_text_field( (string) $entry['name'] ) : '',
            'w_in'     => isset( $entry['wIn'] ) ? max( 0, (float) $entry['wIn'] ) : 0,
            'h_in'     => isset( $entry['hIn'] ) ? max( 0, (float) $entry['hIn'] ) : 0,
            'x_in'     => isset( $entry['xIn'] ) ? max( 0, (float) $entry['xIn'] ) : 0,
            'y_in'     => isset( $entry['yIn'] ) ? max( 0, (float) $entry['yIn'] ) : 0,
            'rotation' => isset( $entry['rotation'] ) ? ( (int) $entry['rotation'] % 360 ) : 0,
        );
        $kept[ $stash_id ] = true;
    }

    /*
     * Two requests can be in flight at once (a debounced sync overlapping a
     * page-hide flush), and the later one may still report empty stash ids for
     * images the earlier one just stored. Treating every POST as the whole
     * truth then deleted freshly uploaded artwork and dropped its prints from
     * the order — duplicates especially, since they share one file.
     *
     * So: a manifest that resolves fewer prints than the one already stored is
     * treated as a stale snapshot and does not replace it, and files younger
     * than the grace window are never pruned no matter what any single request
     * claims. Explicitly partial posts (flush) never prune at all.
     */
    $partial = ! empty( $_POST['partial'] );
    $stored  = WC()->session->get( FAC_ARTWORK_SESSION_KEY );
    $stored  = is_array( $stored ) ? $stored : array();
    $stale   = ( $dropped > 0 && count( $clean ) < count( $stored ) );

    if ( ! $stale ) {
        WC()->session->set( FAC_ARTWORK_SESSION_KEY, $clean );
    } else {
        // Keep the fuller manifest, but honour any files it references that
        // have since disappeared.
        $kept = array();
        foreach ( $stored as $entry ) {
            if ( ! empty( $entry['stash'] ) && isset( $existing[ $entry['stash'] ] ) ) {
                $kept[ $entry['stash'] ] = true;
            }
        }
        foreach ( $clean as $entry ) {
            $kept[ $entry['stash'] ] = true;
        }
    }

    // Prune stash files the manifest no longer references — but only once they
    // are old enough that no in-flight request could still be about to claim.
    if ( ! $partial ) {
        $cutoff = time() - FAC_ARTWORK_PRUNE_GRACE;
        foreach ( $existing as $name => $size ) {
            if ( ! empty( $kept[ $name ] ) ) {
                continue;
            }
            $path = trailingslashit( $dir ) . $name;
            $mtime = (int) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if ( $mtime && $mtime > $cutoff ) {
                continue;
            }
            @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
    }

    // Roll context travels with the manifest so the order screen can redraw the
    // arrangement to scale. Bad or missing input just leaves the last good value.
    $roll_raw = isset( $_POST['roll'] ) ? wp_unslash( $_POST['roll'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $roll     = json_decode( (string) $roll_raw, true );
    if ( is_array( $roll ) && ! empty( $roll['usableIn'] ) && (float) $roll['usableIn'] > 0 ) {
        WC()->session->set(
            FAC_ARTWORK_SESSION_ROLL,
            array(
                'key'       => isset( $roll['key'] ) ? sanitize_text_field( (string) $roll['key'] ) : '',
                'usable_in' => (float) $roll['usableIn'],
                'width_in'  => isset( $roll['widthIn'] ) ? (float) $roll['widthIn'] : 0,
            )
        );
    }

    wp_send_json_success(
        array(
            'map' => $map,
            // The planner compares these. If the server kept fewer prints than
            // were sent, the shopper is told rather than finding out from the
            // studio after checkout.
            'stored'   => count( $clean ),
            'expected' => count( $manifest ),
        )
    );
}

/**
 * Best-effort mime detection for an uploaded temp file.
 *
 * @param string $path Temp file path.
 * @return string Detected mime type, or ''.
 */
function fac_artwork_detect_mime( $path ) {
    if ( function_exists( 'finfo_open' ) ) {
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        if ( $finfo ) {
            $mime = finfo_file( $finfo, $path );
            finfo_close( $finfo );
            if ( $mime ) {
                return strtolower( $mime );
            }
        }
    }
    $info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    if ( is_array( $info ) && ! empty( $info['mime'] ) ) {
        return strtolower( $info['mime'] );
    }
    return '';
}

/**
 * List stash files (name => size) in a session folder.
 *
 * @param string $dir Absolute stash directory.
 * @return array<string,int>
 */
function fac_artwork_list_stash( $dir ) {
    $out = array();
    if ( ! is_dir( $dir ) ) {
        return $out;
    }
    $items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    if ( ! is_array( $items ) ) {
        return $out;
    }
    foreach ( $items as $name ) {
        if ( fac_artwork_valid_stash_id( $name ) ) {
            $out[ $name ] = (int) @filesize( trailingslashit( $dir ) . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
    }
    return $out;
}

/* ===============================================================
   AJAX: chunked upload
   =============================================================== */

add_action( 'wp_ajax_fac_layout_chunk',        'fac_artwork_ajax_chunk' );
add_action( 'wp_ajax_nopriv_fac_layout_chunk', 'fac_artwork_ajax_chunk' );

/**
 * Receive one slice of an image and, on the last slice, admit it to the stash.
 *
 * The browser splits a file into sequential slices sized to what this server
 * accepts, so an image far larger than upload_max_filesize still arrives. Parts
 * are appended to a scratch file under the session's own parts/ folder and only
 * become a stashed image once the whole file is present and validates.
 *
 * Expects: uploadId (32 hex), index, total, size, name, and a `chunk` file part.
 *
 * @return void Sends a JSON response and exits.
 */
function fac_artwork_ajax_chunk() {
    if ( ! check_ajax_referer( 'fac_artwork_nonce', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'bad_nonce' ), 403 );
    }

    $token = fac_artwork_session_token();
    if ( is_wp_error( $token ) ) {
        wp_send_json_error( array( 'message' => $token->get_error_code() ), 400 );
    }

    if ( ! isset( $_FILES['chunk']['tmp_name'], $_FILES['chunk']['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES['chunk']['error'] ) {
        wp_send_json_error( array( 'message' => 'no_chunk' ), 400 );
    }
    $tmp = $_FILES['chunk']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    if ( ! is_uploaded_file( $tmp ) ) {
        wp_send_json_error( array( 'message' => 'no_chunk' ), 400 );
    }

    $result = fac_artwork_store_chunk(
        array(
            'token'     => $token,
            'upload_id' => isset( $_POST['uploadId'] ) ? sanitize_text_field( wp_unslash( $_POST['uploadId'] ) ) : '',
            'index'     => isset( $_POST['index'] ) ? (int) $_POST['index'] : -1,
            'total'     => isset( $_POST['total'] ) ? (int) $_POST['total'] : 0,
            'size'      => isset( $_POST['size'] ) ? (int) $_POST['size'] : 0,
            'name'      => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
            'source'    => $tmp,
        )
    );

    if ( is_wp_error( $result ) ) {
        $data = array( 'message' => $result->get_error_code() );
        $more = $result->get_error_data();
        if ( is_array( $more ) ) {
            $data = array_merge( $data, $more );
        }
        wp_send_json_error( $data, 400 );
    }

    wp_send_json_success( $result );
}

/**
 * Append one slice to a session's scratch file and, when the last slice lands,
 * validate the assembled image and admit it to the stash.
 *
 * Split out from the AJAX wrapper so the assembly path can be exercised
 * directly; the wrapper owns authentication and the is_uploaded_file() check.
 *
 * @param array $args token, upload_id, index, total, size, name, source.
 * @return array|WP_Error { complete, received, stashId } or an error.
 */
function fac_artwork_store_chunk( $args ) {
    $token     = isset( $args['token'] ) ? (string) $args['token'] : '';
    $upload_id = isset( $args['upload_id'] ) ? (string) $args['upload_id'] : '';
    $index     = isset( $args['index'] ) ? (int) $args['index'] : -1;
    $total     = isset( $args['total'] ) ? (int) $args['total'] : 0;
    $declared  = isset( $args['size'] ) ? (int) $args['size'] : 0;
    $source    = isset( $args['source'] ) ? (string) $args['source'] : '';

    if ( ! preg_match( '/^[a-f0-9]{32}$/', $upload_id ) || $index < 0 || $total < 1 || $index >= $total ) {
        return new WP_Error( 'bad_request' );
    }
    if ( $declared <= 0 || $declared > fac_artwork_max_file_bytes() ) {
        return new WP_Error( 'too_large' );
    }

    $stash_dir = fac_artwork_base_dir( 'tmp/' . $token );
    $parts_dir = fac_artwork_base_dir( 'tmp/' . $token . '/' . FAC_ARTWORK_PARTS_DIRNAME );
    if ( is_wp_error( $stash_dir ) || is_wp_error( $parts_dir ) || ! fac_artwork_prepare_dir( $parts_dir ) ) {
        return new WP_Error( 'no_storage' );
    }

    // Session-wide ceilings, checked before any bytes are written.
    $existing = fac_artwork_list_stash( $stash_dir );
    if ( 0 === $index ) {
        if ( count( $existing ) >= FAC_ARTWORK_MAX_FILES ) {
            return new WP_Error( 'too_many_files' );
        }
        if ( ( array_sum( $existing ) + $declared ) > fac_artwork_max_total_bytes() ) {
            return new WP_Error( 'session_full' );
        }
    }

    $part = trailingslashit( $parts_dir ) . $upload_id . '.part';
    $meta = trailingslashit( $parts_dir ) . $upload_id . '.json';

    // Slices must arrive in order; a restart at index 0 discards what was there.
    if ( 0 === $index && file_exists( $part ) ) {
        @unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }
    $state = array( 'received' => 0, 'name' => '' );
    if ( 0 !== $index && file_exists( $meta ) ) {
        $decoded = json_decode( (string) @file_get_contents( $meta ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
        if ( is_array( $decoded ) ) {
            $state = array_merge( $state, $decoded );
        }
    }
    if ( (int) $state['received'] !== $index ) {
        return new WP_Error( 'out_of_order', '', array( 'expected' => (int) $state['received'] ) );
    }

    $bytes = @file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
    if ( false === $bytes ) {
        return new WP_Error( 'read_failed' );
    }
    $have = file_exists( $part ) ? (int) filesize( $part ) : 0;
    if ( ( $have + strlen( $bytes ) ) > fac_artwork_max_file_bytes() ) {
        @unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @unlink( $meta ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        return new WP_Error( 'too_large' );
    }
    if ( false === @file_put_contents( $part, $bytes, FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
        return new WP_Error( 'write_failed' );
    }
    unset( $bytes );

    $state['received'] = $index + 1;
    if ( isset( $args['name'] ) && '' !== $args['name'] ) {
        $state['name'] = (string) $args['name'];
    }
    @file_put_contents( $meta, wp_json_encode( $state ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged

    // More to come.
    if ( $state['received'] < $total ) {
        return array(
            'complete' => false,
            'received' => (int) $state['received'],
        );
    }

    // Last slice: the assembled file has to be a real image of an allowed type.
    $allowed = fac_artwork_allowed_types();
    $mime    = fac_artwork_detect_mime( $part );
    $info    = @getimagesize( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    if ( ! isset( $allowed[ $mime ] ) || false === $info || empty( $info[0] ) || empty( $info[1] ) ) {
        @unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @unlink( $meta ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        return new WP_Error( 'bad_type' );
    }

    $stash_id = bin2hex( random_bytes( 16 ) ) . '.' . $allowed[ $mime ];
    $dest     = trailingslashit( $stash_dir ) . $stash_id;
    if ( ! fac_artwork_prepare_dir( $stash_dir ) || ! @rename( $part, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @unlink( $meta ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        return new WP_Error( 'store_failed' );
    }
    @chmod( $dest, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    @unlink( $meta );      // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

    return array(
        'complete' => true,
        'stashId'  => $stash_id,
    );
}

/* ===============================================================
   Order attachment (classic + Blocks checkout)
   =============================================================== */

add_action( 'woocommerce_checkout_order_processed', 'fac_artwork_attach_to_order', 20, 1 );
add_action( 'woocommerce_store_api_checkout_order_processed', 'fac_artwork_attach_to_order', 20, 1 );

/**
 * Move the session's stashed artwork into a per-order folder and record it.
 *
 * Accepts either an order id (classic checkout) or an order object (Store API).
 *
 * @param int|WC_Order $order_ref Order id or order object.
 * @return void
 */
function fac_artwork_attach_to_order( $order_ref ) {
    if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
        return;
    }
    $manifest = WC()->session->get( FAC_ARTWORK_SESSION_KEY );
    if ( empty( $manifest ) || ! is_array( $manifest ) ) {
        return;
    }

    $order = $order_ref instanceof WC_Order ? $order_ref : wc_get_order( $order_ref );
    if ( ! $order instanceof WC_Order ) {
        return;
    }
    $order_id = $order->get_id();

    $token = fac_artwork_session_token();
    if ( is_wp_error( $token ) ) {
        return;
    }
    $src_dir = fac_artwork_base_dir( 'tmp/' . $token );
    $dst_dir = fac_artwork_base_dir( 'order-' . $order_id );
    if ( is_wp_error( $src_dir ) || is_wp_error( $dst_dir ) || ! fac_artwork_prepare_dir( $dst_dir ) ) {
        return;
    }

    $saved = array();
    foreach ( $manifest as $entry ) {
        // Placeholders carry no file; they still belong on the order so the
        // layout stays complete and production knows artwork is owed.
        if ( ! empty( $entry['placeholder'] ) ) {
            $saved[] = array(
                'file'        => '',
                'placeholder' => 1,
                'ph'          => isset( $entry['ph'] ) ? (string) $entry['ph'] : '',
                'name'        => isset( $entry['name'] ) ? sanitize_text_field( (string) $entry['name'] ) : '',
                'w_in'        => isset( $entry['w_in'] ) ? (float) $entry['w_in'] : 0,
                'h_in'        => isset( $entry['h_in'] ) ? (float) $entry['h_in'] : 0,
                'x_in'        => isset( $entry['x_in'] ) ? (float) $entry['x_in'] : 0,
                'y_in'        => isset( $entry['y_in'] ) ? (float) $entry['y_in'] : 0,
                'rotation'    => isset( $entry['rotation'] ) ? (int) $entry['rotation'] : 0,
            );
            continue;
        }
        if ( empty( $entry['stash'] ) || ! fac_artwork_valid_stash_id( $entry['stash'] ) ) {
            continue;
        }
        $from = trailingslashit( $src_dir ) . $entry['stash'];
        $to   = trailingslashit( $dst_dir ) . $entry['stash'];

        /*
         * Duplicated prints share one stash file, so the second and later
         * entries find it already moved. That is success, not failure — each
         * entry is its own print and must stay on the order, or production
         * cannot see how many times the image runs.
         */
        if ( ! file_exists( $to ) ) {
            if ( ! file_exists( $from ) ) {
                continue;
            }
            if ( ! @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                if ( ! @copy( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                    continue;
                }
                @unlink( $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }
        $saved[] = array(
            'file'     => $entry['stash'],
            'name'     => isset( $entry['name'] ) ? sanitize_text_field( (string) $entry['name'] ) : '',
            'w_in'     => isset( $entry['w_in'] ) ? (float) $entry['w_in'] : 0,
            'h_in'     => isset( $entry['h_in'] ) ? (float) $entry['h_in'] : 0,
            'x_in'     => isset( $entry['x_in'] ) ? (float) $entry['x_in'] : 0,
            'y_in'     => isset( $entry['y_in'] ) ? (float) $entry['y_in'] : 0,
            'rotation' => isset( $entry['rotation'] ) ? (int) $entry['rotation'] : 0,
        );
    }

    if ( ! empty( $saved ) ) {
        $order->update_meta_data( FAC_ARTWORK_ORDER_META, $saved );
        $roll = WC()->session->get( FAC_ARTWORK_SESSION_ROLL );
        if ( is_array( $roll ) && ! empty( $roll['usable_in'] ) ) {
            $order->update_meta_data( FAC_ARTWORK_ORDER_ROLL_META, $roll );
        }
        $order->save();
    }

    // Clear the session manifest and remove the now-empty stash folder.
    WC()->session->set( FAC_ARTWORK_SESSION_KEY, array() );
    WC()->session->set( FAC_ARTWORK_SESSION_ROLL, array() );
    fac_artwork_rmdir_if_empty( $src_dir );
}

/**
 * Remove a directory if it contains no stash files.
 *
 * @param string $dir Absolute directory path.
 * @return void
 */
function fac_artwork_rmdir_if_empty( $dir ) {
    if ( ! is_dir( $dir ) ) {
        return;
    }
    if ( ! fac_artwork_list_stash( $dir ) ) {
        @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }
}

/* ===============================================================
   Admin: capability-gated download / inline view
   =============================================================== */

add_action( 'admin_post_fac_download_artwork', 'fac_artwork_download' );

/**
 * Stream an order's stashed artwork file to an authorised shop manager.
 *
 * Gated by the edit_shop_orders capability. The file argument is validated to a
 * bare stash id (no path traversal) and resolved inside the order's own folder.
 *
 * @return void Streams the file and exits, or dies with an error status.
 */
function fac_artwork_download() {
    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_die( esc_html__( 'You are not allowed to download this file.', 'fine-art-calculator' ), '', array( 'response' => 403 ) );
    }

    $order_id    = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
    $file        = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
    $disposition = ( isset( $_GET['view'] ) && '1' === $_GET['view'] ) ? 'inline' : 'attachment';

    check_admin_referer( 'fac_artwork_dl_' . $order_id );

    if ( ! $order_id || ! fac_artwork_valid_stash_id( $file ) ) {
        wp_die( esc_html__( 'Invalid request.', 'fine-art-calculator' ), '', array( 'response' => 400 ) );
    }

    // The file must be one recorded on this order.
    $order = wc_get_order( $order_id );
    if ( ! $order instanceof WC_Order ) {
        wp_die( esc_html__( 'Order not found.', 'fine-art-calculator' ), '', array( 'response' => 404 ) );
    }
    $items     = $order->get_meta( FAC_ARTWORK_ORDER_META );
    $download  = '';
    $orig_name = '';
    if ( is_array( $items ) ) {
        foreach ( $items as $item ) {
            if ( isset( $item['file'] ) && $item['file'] === $file ) {
                $download  = $item['file'];
                $orig_name = isset( $item['name'] ) ? (string) $item['name'] : '';
                break;
            }
        }
    }
    if ( '' === $download ) {
        wp_die( esc_html__( 'File is not part of this order.', 'fine-art-calculator' ), '', array( 'response' => 404 ) );
    }

    $dir = fac_artwork_base_dir( 'order-' . $order_id );
    if ( is_wp_error( $dir ) ) {
        wp_die( esc_html__( 'Storage unavailable.', 'fine-art-calculator' ), '', array( 'response' => 500 ) );
    }
    $path = trailingslashit( $dir ) . $download;
    if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
        wp_die( esc_html__( 'File no longer exists.', 'fine-art-calculator' ), '', array( 'response' => 404 ) );
    }

    $type = fac_artwork_detect_mime( $path );
    if ( '' === $type ) {
        $type = 'application/octet-stream';
    }

    /*
     * Files are stored under an unguessable id, but the studio should get back
     * the name the customer sent — that is what the order screen shows and what
     * production works from. Both header forms are emitted: a plain ASCII
     * fallback, and RFC 5987 for names with accents or other non-ASCII.
     */
    $filename = fac_artwork_download_filename( $orig_name, $download );
    $ascii    = fac_artwork_ascii_filename( $filename );

    nocache_headers();
    header( 'Content-Type: ' . $type );
    header( 'Content-Length: ' . (string) filesize( $path ) );
    header( 'Content-Disposition: ' . $disposition . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
    header( 'X-Content-Type-Options: nosniff' );
    readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
    exit;
}

/**
 * Work out the filename a download should arrive under.
 *
 * Prefers the name the customer uploaded, falling back to the stash id. The
 * extension always follows the file actually stored, so a JPEG sent as
 * "shot.jpeg" comes back as "shot.jpg" rather than claiming a type it is not.
 *
 * @param string $orig_name Name recorded on the order (may be empty).
 * @param string $stash_id  On-disk filename, e.g. "ab12….jpg".
 * @return string Safe, non-empty filename.
 */
function fac_artwork_download_filename( $orig_name, $stash_id ) {
    $stash_ext = strtolower( (string) pathinfo( $stash_id, PATHINFO_EXTENSION ) );

    // Drop any directory component and characters that have no business in a
    // filename or a header.
    $name = (string) $orig_name;
    $name = str_replace( array( "\r", "\n", "\0" ), '', $name );
    $name = basename( str_replace( '\\', '/', $name ) );
    $name = preg_replace( '/[<>:"|?*\x00-\x1F]/', '', $name );
    $name = trim( $name, " .\t" );

    if ( '' === $name ) {
        return $stash_id;
    }
    if ( function_exists( 'mb_substr' ) ) {
        $name = mb_substr( $name, 0, 150 );
    } else {
        $name = substr( $name, 0, 150 );
    }

    $has_ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
    if ( $has_ext === $stash_ext ) {
        return $name;
    }
    // Replace a misleading image extension; otherwise just append the real one.
    if ( in_array( $has_ext, array( 'jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff' ), true ) ) {
        $base = (string) pathinfo( $name, PATHINFO_FILENAME );
        $name = ( '' !== $base ) ? $base : $name;
    }
    return $name . '.' . ( '' !== $stash_ext ? $stash_ext : 'jpg' );
}

/**
 * ASCII fallback for the Content-Disposition filename parameter.
 *
 * @param string $filename Full filename.
 * @return string
 */
function fac_artwork_ascii_filename( $filename ) {
    $ascii = $filename;
    if ( function_exists( 'remove_accents' ) ) {
        $ascii = remove_accents( $ascii );
    }
    // Per character, not per UTF-8 byte, so one accented letter does not turn
    // into three underscores. Falls back if the name is not valid UTF-8.
    $per_char = preg_replace( '/[^\x20-\x7E]/u', '_', (string) $ascii );
    $ascii    = ( null === $per_char ) ? preg_replace( '/[^\x20-\x7E]/', '_', (string) $ascii ) : $per_char;
    $ascii = str_replace( array( '"', '\\' ), '_', (string) $ascii );
    $ascii = trim( (string) $ascii );
    return '' === $ascii ? 'artwork' : $ascii;
}

/**
 * Build a nonced admin URL for a stashed file (download or inline preview).
 *
 * @param int    $order_id Order id.
 * @param string $file     Stash id.
 * @param bool   $inline   Whether to request inline disposition (for <img>).
 * @return string Escaped URL.
 */
function fac_artwork_file_url( $order_id, $file, $inline = false ) {
    $args = array(
        'action' => 'fac_download_artwork',
        'order'  => $order_id,
        'file'   => $file,
    );
    if ( $inline ) {
        $args['view'] = '1';
    }
    $url = add_query_arg( $args, admin_url( 'admin-post.php' ) );
    return wp_nonce_url( $url, 'fac_artwork_dl_' . $order_id );
}

/**
 * Build a nonced admin URL for deleting a stored artwork file from an order.
 *
 * @param int    $order_id Order id.
 * @param string $file     Stash id.
 * @return string Escaped URL.
 */
function fac_artwork_delete_url( $order_id, $file ) {
    $url = add_query_arg(
        array(
            'action' => 'fac_delete_artwork',
            'order'  => $order_id,
            'file'   => $file,
        ),
        admin_url( 'admin-post.php' )
    );
    return wp_nonce_url( $url, 'fac_artwork_del_' . $order_id );
}

add_action( 'admin_post_fac_delete_artwork', 'fac_artwork_delete' );

/**
 * Delete one uploaded image from an order: file, preview, and order record.
 *
 * Intended for cleanup once the studio has downloaded and backed up the
 * originals, so large files do not sit in uploads and order meta forever.
 * Duplicated prints share a single stored file, so every manifest entry that
 * references it is removed together — leaving one behind would point the order
 * at a file that no longer exists.
 *
 * @return void Redirects back to the order screen and exits.
 */
function fac_artwork_delete() {
    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_die( esc_html__( 'You are not allowed to delete this file.', 'fine-art-calculator' ), '', array( 'response' => 403 ) );
    }

    $order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
    $file     = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';

    check_admin_referer( 'fac_artwork_del_' . $order_id );

    $is_ph = (bool) preg_match( '/^ph_[a-f0-9]{32}$/', $file );
    if ( ! $order_id || ( ! $is_ph && ! fac_artwork_valid_stash_id( $file ) ) ) {
        wp_die( esc_html__( 'Invalid request.', 'fine-art-calculator' ), '', array( 'response' => 400 ) );
    }

    $order = wc_get_order( $order_id );
    if ( ! $order instanceof WC_Order ) {
        wp_die( esc_html__( 'Order not found.', 'fine-art-calculator' ), '', array( 'response' => 404 ) );
    }

    $items = $order->get_meta( FAC_ARTWORK_ORDER_META );
    if ( ! is_array( $items ) ) {
        $items = array();
    }

    $kept    = array();
    $removed = 0;
    foreach ( $items as $item ) {
        if ( fac_artwork_item_ref( $item ) === $file ) {
            $removed++;
            continue;
        }
        $kept[] = $item;
    }

    if ( ! $removed ) {
        wp_die( esc_html__( 'File is not part of this order.', 'fine-art-calculator' ), '', array( 'response' => 404 ) );
    }

    // Remove the stored original and any generated preview beside it. A
    // placeholder has no file, so only its records go.
    $dir = fac_artwork_base_dir( 'order-' . $order_id );
    if ( ! $is_ph && ! is_wp_error( $dir ) ) {
        $base = trailingslashit( $dir ) . $file;
        if ( file_exists( $base ) ) {
            @unlink( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        foreach ( fac_artwork_preview_paths( $dir, $file ) as $preview ) {
            if ( file_exists( $preview ) ) {
                @unlink( $preview ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }
    }

    if ( empty( $kept ) ) {
        $order->delete_meta_data( FAC_ARTWORK_ORDER_META );
        $order->delete_meta_data( FAC_ARTWORK_ORDER_ROLL_META );
        if ( ! is_wp_error( $dir ) ) {
            fac_artwork_rmdir_if_empty( $dir );
        }
    } else {
        $order->update_meta_data( FAC_ARTWORK_ORDER_META, $kept );
    }

    $order->add_order_note(
        $is_ph
            ? sprintf(
                /* translators: %d: number of placements removed. */
                __( 'Placeholder removed by studio: %d reserved placement(s) cleared from the layout.', 'fine-art-calculator' ),
                (int) $removed
            )
            : sprintf(
                /* translators: 1: number of print placements removed, 2: file name. */
                __( 'Planner artwork deleted by studio: %1$d placement(s) of %2$s. File and record removed.', 'fine-art-calculator' ),
                (int) $removed,
                $file
            )
    );
    $order->save();

    fac_log_info(
        'Planner artwork deleted from order.',
        array(
            'order'      => $order_id,
            'file'       => $file,
            'placements' => $removed,
        )
    );

    wp_safe_redirect( fac_artwork_order_edit_url( $order_id ) );
    exit;
}

/**
 * Candidate preview paths for a stored image.
 *
 * Today the planner uploads a single browser-rendered image and no separate
 * preview is generated, so this normally returns paths that do not exist. It
 * keeps deletion complete if a preview variant is introduced later.
 *
 * @param string $dir  Order artwork directory.
 * @param string $file Stash id.
 * @return string[] Absolute paths.
 */
function fac_artwork_preview_paths( $dir, $file ) {
    $dot = strrpos( $file, '.' );
    if ( false === $dot ) {
        return array();
    }
    $stem = substr( $file, 0, $dot );
    $out  = array();
    foreach ( array( 'jpg', 'jpeg', 'png', 'webp' ) as $ext ) {
        $out[] = trailingslashit( $dir ) . $stem . '-preview.' . $ext;
    }
    return $out;
}

/**
 * Edit-screen URL for an order under either HPOS or the legacy post table.
 *
 * @param int $order_id Order id.
 * @return string
 */
function fac_artwork_order_edit_url( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( $order instanceof WC_Order && method_exists( $order, 'get_edit_order_url' ) ) {
        $url = $order->get_edit_order_url();
        if ( $url ) {
            return $url;
        }
    }
    return admin_url( 'post.php?post=' . (int) $order_id . '&action=edit' );
}

/* ===============================================================
   Admin: order-screen meta box (legacy + HPOS)
   =============================================================== */

add_action( 'add_meta_boxes', 'fac_artwork_register_meta_box', 20, 2 );

/**
 * Register the artwork meta box on both the legacy and HPOS order screens.
 *
 * @param string $screen_id Current screen id.
 * @param mixed  $post      Post or order object (unused).
 * @return void
 */
function fac_artwork_register_meta_box( $screen_id, $post = null ) {
    $screens = array( 'shop_order', 'woocommerce_page_wc-orders' );
    if ( ! in_array( $screen_id, $screens, true ) ) {
        return;
    }
    add_meta_box(
        'fac_layout_artwork',
        __( 'Print Layout Artwork', 'fine-art-calculator' ),
        'fac_artwork_render_meta_box',
        $screen_id,
        'normal',
        'default'
    );
}

/**
 * Render the artwork meta box: a thumbnail + planned size + download per image.
 *
 * @param mixed $post_or_order Post (legacy) or WC_Order (HPOS).
 * @return void
 */
function fac_artwork_render_meta_box( $post_or_order ) {
    $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : $post_or_order );
    if ( ! $order instanceof WC_Order ) {
        echo '<p>' . esc_html__( 'No order.', 'fine-art-calculator' ) . '</p>';
        return;
    }
    $items = $order->get_meta( FAC_ARTWORK_ORDER_META );
    if ( empty( $items ) || ! is_array( $items ) ) {
        echo '<p>' . esc_html__( 'No planner artwork was attached to this order.', 'fine-art-calculator' ) . '</p>';
        return;
    }
    $order_id = $order->get_id();

    // Keep only entries whose file id is well formed; everything below indexes
    // off this list so the diagram and the card list always agree.
    $items = array_values(
        array_filter(
            $items,
            function ( $item ) {
                return '' !== fac_artwork_item_ref( $item );
            }
        )
    );
    if ( empty( $items ) ) {
        echo '<p>' . esc_html__( 'No planner artwork remains on this order.', 'fine-art-calculator' ) . '</p>';
        return;
    }

    // Group by file: duplicated prints reference one stored image, and the
    // count is how many times that image runs.
    $groups = array();
    foreach ( $items as $i => $item ) {
        $ref = fac_artwork_item_ref( $item );
        if ( ! isset( $groups[ $ref ] ) ) {
            $groups[ $ref ] = array(
                'ref'         => $ref,
                'file'        => empty( $item['placeholder'] ) ? $ref : '',
                'placeholder' => ! empty( $item['placeholder'] ),
                'name'        => ! empty( $item['name'] ) ? $item['name'] : $ref,
                'count'       => 0,
                'indexes'     => array(),
                'ord'         => count( $groups ) + 1,
            );
        }
        $groups[ $ref ]['count']++;
        $groups[ $ref ]['indexes'][] = $i;
    }
    $placeholders = 0;
    foreach ( $groups as $g ) {
        if ( $g['placeholder'] ) {
            $placeholders += (int) $g['count'];
        }
    }

    echo '<p style="margin:0 0 12px;color:#555;">';
    printf(
        /* translators: 1: number of prints, 2: number of distinct images. */
        esc_html( _n( '%1$d print from %2$d image is arranged on the roll.', '%1$d prints from %2$d images are arranged on the roll.', count( $items ), 'fine-art-calculator' ) ),
        (int) count( $items ),
        (int) count( $groups )
    );
    echo ' ' . esc_html__( 'Sizes are the layout footprint; masters may also arrive via WeTransfer.', 'fine-art-calculator' ) . '</p>';

    if ( $placeholders > 0 ) {
        echo '<p style="margin:0 0 12px;padding:10px 12px;background:#fcf9e8;border-left:4px solid #dba617;font-size:12px;color:#3c434a;">';
        echo '<strong>';
        printf(
            /* translators: %d: number of placeholder prints. */
            esc_html( _n( '%d print is a placeholder — artwork still to come.', '%d prints are placeholders — artwork still to come.', $placeholders, 'fine-art-calculator' ) ),
            (int) $placeholders
        );
        echo '</strong> ';
        echo esc_html__( 'The customer reserved this space at checkout and sends the files afterwards, usually by WeTransfer. Do not print until they arrive.', 'fine-art-calculator' );
        echo '</p>';
    }

    fac_artwork_render_layout_diagram( $order, $items, $groups );
    fac_artwork_render_image_cards( $order_id, $groups, $items );
}

/**
 * Draw the arrangement the customer built, to scale, on the roll.
 *
 * Positions come from the planner manifest. Orders placed before positions were
 * recorded have every print at the origin — those get a note instead of a
 * diagram that would stack everything in one corner.
 *
 * @param WC_Order $order  Order being viewed.
 * @param array    $items  Filtered artwork entries.
 * @param array    $groups File id => group data (count, ordinal).
 * @return void
 */
function fac_artwork_render_layout_diagram( $order, $items, $groups ) {
    $order_id = $order->get_id();

    // Roll width: recorded with the order, else inferred from the layout.
    $roll     = $order->get_meta( FAC_ARTWORK_ORDER_ROLL_META );
    $usable   = ( is_array( $roll ) && ! empty( $roll['usable_in'] ) ) ? (float) $roll['usable_in'] : 0;
    $roll_key = ( is_array( $roll ) && ! empty( $roll['key'] ) ) ? (string) $roll['key'] : '';

    $max_right = 0;
    $max_below = 0;
    $placed    = false;
    foreach ( $items as $item ) {
        $x = isset( $item['x_in'] ) ? (float) $item['x_in'] : 0;
        $y = isset( $item['y_in'] ) ? (float) $item['y_in'] : 0;
        $w = isset( $item['w_in'] ) ? (float) $item['w_in'] : 0;
        $h = isset( $item['h_in'] ) ? (float) $item['h_in'] : 0;
        if ( $x > 0 || $y > 0 ) {
            $placed = true;
        }
        $max_right = max( $max_right, $x + $w );
        $max_below = max( $max_below, $y + $h );
    }

    if ( ! $placed && count( $items ) > 1 ) {
        echo '<p style="margin:0 0 16px;padding:10px 12px;background:#fcf9e8;border-left:4px solid #dba617;font-size:12px;color:#3c434a;">';
        echo esc_html__( 'This order was placed before layout positions were recorded, so the arrangement cannot be redrawn. The images below are listed in the order they were added.', 'fine-art-calculator' );
        echo '</p>';
        return;
    }

    if ( $usable <= 0 ) {
        $usable = max( $max_right, 44 );
    }
    if ( $max_below <= 0 ) {
        return;
    }

    // Fixed drawing width; everything else scales from it.
    $canvas_px = 760;
    $ppi       = $canvas_px / $usable;
    $height_px = max( 40, (int) round( $max_below * $ppi ) );

    echo '<div style="margin:0 0 18px;">';
    echo '<div style="display:flex;flex-wrap:wrap;gap:6px 16px;align-items:baseline;margin-bottom:8px;">';
    echo '<strong style="font-size:12px;color:#1d2327;">' . esc_html__( 'Roll layout', 'fine-art-calculator' ) . '</strong>';
    echo '<span style="font-size:11px;color:#646970;">';
    if ( '' !== $roll_key ) {
        printf(
            /* translators: 1: roll key in inches, 2: printable width. */
            esc_html__( '%1$s" roll · %2$s" printable width', 'fine-art-calculator' ),
            esc_html( $roll_key ),
            esc_html( fac_artwork_fmt_num( $usable ) )
        );
    } else {
        printf(
            /* translators: %s: printable width in inches. */
            esc_html__( '%s" printable width', 'fine-art-calculator' ),
            esc_html( fac_artwork_fmt_num( $usable ) )
        );
    }
    echo ' &middot; ';
    printf(
        /* translators: %s: roll length used in inches. */
        esc_html__( '%s" of roll length used', 'fine-art-calculator' ),
        esc_html( fac_artwork_fmt_num( $max_below ) )
    );
    echo '</span></div>';

    echo '<div style="position:relative;width:100%;max-width:' . (int) $canvas_px . 'px;overflow:hidden;">';
    echo '<div style="position:relative;width:100%;padding-top:' . esc_attr( number_format( ( $height_px / $canvas_px ) * 100, 4, '.', '' ) ) . '%;background:#fff;border:1px solid #c3c4c7;">';
    echo '<div style="position:absolute;inset:0;">';

    foreach ( $items as $i => $item ) {
        $file     = fac_artwork_item_ref( $item );
        $is_ph    = ! empty( $item['placeholder'] );
        $x        = isset( $item['x_in'] ) ? (float) $item['x_in'] : 0;
        $y        = isset( $item['y_in'] ) ? (float) $item['y_in'] : 0;
        $w        = isset( $item['w_in'] ) ? (float) $item['w_in'] : 0;
        $h        = isset( $item['h_in'] ) ? (float) $item['h_in'] : 0;
        $rotation = isset( $item['rotation'] ) ? (int) $item['rotation'] : 0;
        if ( $w <= 0 || $h <= 0 ) {
            continue;
        }
        $ord      = isset( $groups[ $file ] ) ? (int) $groups[ $file ]['ord'] : 0;
        $dupes    = isset( $groups[ $file ] ) ? (int) $groups[ $file ]['count'] : 1;
        $view_url = $is_ph ? '' : fac_artwork_file_url( $order_id, $file, true );

        // Percentages keep the drawing correct when the panel is narrower than
        // the nominal canvas width.
        $l_pct = ( $x / $usable ) * 100;
        $w_pct = ( $w / $usable ) * 100;
        $t_pct = ( $y / $max_below ) * 100;
        $h_pct = ( $h / $max_below ) * 100;

        // A quarter turn swaps the image box inside the footprint.
        $quarter   = ( 90 === abs( $rotation % 180 ) );
        $img_style = 'position:absolute;top:50%;left:50%;object-fit:contain;';
        if ( $quarter ) {
            $img_style .= 'width:' . esc_attr( number_format( ( $h / $w ) * 100, 4, '.', '' ) ) . '%;height:' . esc_attr( number_format( ( $w / $h ) * 100, 4, '.', '' ) ) . '%;';
        } else {
            $img_style .= 'width:100%;height:100%;';
        }
        $img_style .= 'transform:translate(-50%,-50%) rotate(' . (int) $rotation . 'deg);';

        $edge = $is_ph ? '1px dashed #6b6b6b' : '1px solid #1d2327';
        // container-type lets the placeholder label size itself from the box.
        $contain = $is_ph ? 'container-type:size;' : '';
        echo '<div title="' . esc_attr( ( ! empty( $item['name'] ) ? $item['name'] : $file ) . ' — ' . fac_artwork_fmt_num( $w ) . ' x ' . fac_artwork_fmt_num( $h ) . ' in' . ( $is_ph ? ' (placeholder)' : '' ) ) . '" style="position:absolute;overflow:hidden;box-sizing:border-box;' . $contain . 'border:' . esc_attr( $edge ) . ';background:#f6f7f7;'
            . 'left:' . esc_attr( number_format( $l_pct, 4, '.', '' ) ) . '%;'
            . 'top:' . esc_attr( number_format( $t_pct, 4, '.', '' ) ) . '%;'
            . 'width:' . esc_attr( number_format( $w_pct, 4, '.', '' ) ) . '%;'
            . 'height:' . esc_attr( number_format( $h_pct, 4, '.', '' ) ) . '%;">';
        if ( $is_ph ) {
            // Mirrors what the customer saw: a grey panel labelled with its size.
            // The plain font-size is the fallback; the clamp overrides it wherever
            // container query units are understood, so the label scales with the
            // print exactly as it does in the planner.
            echo '<span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;'
                . 'background:#e6e6e6;background-image:repeating-linear-gradient(45deg,rgba(0,0,0,.05) 0 8px,transparent 8px 16px);'
                . 'color:#111;font-weight:700;line-height:1.2;padding:0 2px;box-sizing:border-box;white-space:nowrap;overflow:hidden;'
                . 'font-variant-numeric:tabular-nums;font-size:12px;font-size:clamp(10px, min(12cqw, 30cqh), 28px);">';
            echo esc_html( fac_artwork_fmt_num( $w ) . ' × ' . fac_artwork_fmt_num( $h ) . '"' );
            echo '</span>';
        } else {
            echo '<img src="' . esc_url( $view_url ) . '" alt="" style="' . $img_style . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from numeric values above.
        }
        echo '<span style="position:absolute;top:2px;left:2px;background:#1d2327;color:#fff;font-size:10px;line-height:1;padding:3px 5px;border-radius:2px;font-weight:600;">';
        echo esc_html( '#' . ( $i + 1 ) );
        if ( $ord ) {
            echo esc_html( ' · ' . fac_artwork_group_letter( $ord ) );
        }
        if ( $dupes > 1 ) {
            echo esc_html( ' ×' . $dupes );
        }
        echo '</span>';
        if ( ! $is_ph ) {
            echo '<span style="position:absolute;bottom:2px;right:2px;background:rgba(255,255,255,.9);color:#1d2327;font-size:10px;line-height:1;padding:3px 5px;border-radius:2px;">';
            echo esc_html( fac_artwork_fmt_num( $w ) . ' × ' . fac_artwork_fmt_num( $h ) . '"' );
            echo '</span>';
        }
        echo '</div>';
    }

    echo '</div></div>';
    echo '<p style="margin:6px 0 0;font-size:11px;color:#646970;">' . esc_html__( 'Drawn to scale from the customer\'s arrangement. #n is print order; the letter identifies the image, and ×n is how many times it prints.', 'fine-art-calculator' ) . '</p>';
    echo '</div></div>';
}

/**
 * Label images A, B, C ... AA, AB for the diagram badges.
 *
 * @param int $ord 1-based group ordinal.
 * @return string
 */
function fac_artwork_group_letter( $ord ) {
    $ord   = max( 1, (int) $ord );
    $label = '';
    while ( $ord > 0 ) {
        $ord--;
        $label = chr( 65 + ( $ord % 26 ) ) . $label;
        $ord   = (int) floor( $ord / 26 );
    }
    return $label;
}

/**
 * Render one card per distinct image: preview, print count, download, delete.
 *
 * @param int   $order_id Order id.
 * @param array $groups   File id => group data.
 * @param array $items    Filtered artwork entries.
 * @return void
 */
function fac_artwork_render_image_cards( $order_id, $groups, $items ) {
    echo '<div style="display:flex;flex-wrap:wrap;gap:14px;">';
    foreach ( $groups as $group ) {
        $ref      = $group['ref'];
        $is_ph    = ! empty( $group['placeholder'] );
        $file     = $group['file'];
        $view_url = $is_ph ? '' : fac_artwork_file_url( $order_id, $file, true );
        $dl_url   = $is_ph ? '' : fac_artwork_file_url( $order_id, $file, false );
        $del_url  = fac_artwork_delete_url( $order_id, $ref );
        $first    = $items[ $group['indexes'][0] ];
        $w        = isset( $first['w_in'] ) ? (float) $first['w_in'] : 0;
        $h        = isset( $first['h_in'] ) ? (float) $first['h_in'] : 0;
        $rotation = isset( $first['rotation'] ) ? (int) $first['rotation'] : 0;
        $exists   = ! $is_ph && fac_artwork_order_file_exists( $order_id, $file );

        echo '<div style="width:170px;border:1px solid #dcdcde;border-radius:4px;padding:8px;background:#fff;">';
        echo '<div style="font-size:11px;font-weight:700;color:#1d2327;margin-bottom:6px;">';
        echo esc_html( fac_artwork_group_letter( (int) $group['ord'] ) );
        if ( (int) $group['count'] > 1 ) {
            echo ' <span style="font-weight:600;color:#2271b1;">' . esc_html( sprintf( /* translators: %d: number of prints. */ __( 'prints ×%d', 'fine-art-calculator' ), (int) $group['count'] ) ) . '</span>';
        } else {
            echo ' <span style="font-weight:400;color:#646970;">' . esc_html__( 'prints ×1', 'fine-art-calculator' ) . '</span>';
        }
        echo '</div>';

        if ( $is_ph ) {
            echo '<div style="width:100%;height:100px;background:#e6e6e6;background-image:repeating-linear-gradient(45deg,rgba(0,0,0,.05) 0 8px,transparent 8px 16px);border:1px dashed #6b6b6b;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#111;text-align:center;padding:6px;box-sizing:border-box;">';
            echo esc_html( fac_artwork_fmt_num( $w ) . ' × ' . fac_artwork_fmt_num( $h ) . '"' );
            echo '</div>';
        } elseif ( $exists ) {
            echo '<a href="' . esc_url( $dl_url ) . '">';
            echo '<img src="' . esc_url( $view_url ) . '" alt="" style="width:100%;height:100px;object-fit:contain;background:#f6f7f7;display:block;" />';
            echo '</a>';
        } else {
            echo '<div style="width:100%;height:100px;background:#f6f7f7;border:1px dashed #c3c4c7;display:flex;align-items:center;justify-content:center;font-size:11px;color:#646970;text-align:center;padding:6px;box-sizing:border-box;">';
            echo esc_html__( 'File deleted', 'fine-art-calculator' );
            echo '</div>';
        }

        echo '<div style="font-size:11px;color:#1d2327;margin-top:6px;word-break:break-word;font-weight:600;">';
        echo esc_html( $is_ph ? __( 'Placeholder — artwork to come', 'fine-art-calculator' ) : $group['name'] );
        echo '</div>';
        echo '<div style="font-size:11px;color:#646970;margin-top:2px;">';
        printf( '%s &times; %s in', esc_html( fac_artwork_fmt_num( $w ) ), esc_html( fac_artwork_fmt_num( $h ) ) );
        if ( $rotation ) {
            echo ' &middot; ' . esc_html( sprintf( /* translators: %d: rotation in degrees. */ __( 'rotated %d°', 'fine-art-calculator' ), $rotation ) );
        }
        echo '</div>';

        if ( $exists ) {
            echo '<a href="' . esc_url( $dl_url ) . '" class="button button-small" style="margin-top:8px;width:100%;text-align:center;box-sizing:border-box;">' . esc_html__( 'Download', 'fine-art-calculator' ) . '</a>';
        }
        $confirm = $is_ph
            ? __( 'Remove this placeholder from the order? The reserved space disappears from the layout diagram.', 'fine-art-calculator' )
            : __( 'Delete this image from the order? The uploaded file and its record are removed permanently. Download and back it up first.', 'fine-art-calculator' );
        echo '<a href="' . esc_url( $del_url ) . '" class="button button-small button-link-delete" style="margin-top:6px;width:100%;text-align:center;box-sizing:border-box;color:#b32d2e;"'
            . ' onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');">'
            . esc_html( $is_ph ? __( 'Remove', 'fine-art-calculator' ) : __( 'Delete', 'fine-art-calculator' ) ) . '</a>';
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Identity key for an order entry: the stored file, or the placeholder id.
 *
 * Duplicated prints share one key, which is how the order screen counts how
 * many times a given piece of artwork runs.
 *
 * @param array $item Order artwork entry.
 * @return string Empty when the entry is unusable.
 */
function fac_artwork_item_ref( $item ) {
    if ( ! empty( $item['placeholder'] ) ) {
        $ph = isset( $item['ph'] ) ? (string) $item['ph'] : '';
        return preg_match( '/^ph_[a-f0-9]{32}$/', $ph ) ? $ph : '';
    }
    $file = isset( $item['file'] ) ? (string) $item['file'] : '';
    return fac_artwork_valid_stash_id( $file ) ? $file : '';
}

/**
 * Whether an order's stored file is still on disk.
 *
 * @param int    $order_id Order id.
 * @param string $file     Stash id.
 * @return bool
 */
function fac_artwork_order_file_exists( $order_id, $file ) {
    $dir = fac_artwork_base_dir( 'order-' . (int) $order_id );
    if ( is_wp_error( $dir ) ) {
        return false;
    }
    return file_exists( trailingslashit( $dir ) . $file );
}

/**
 * Trim trailing zeros from a measurement for display.
 *
 * @param float $n Value in inches.
 * @return string
 */
function fac_artwork_fmt_num( $n ) {
    $s = number_format( (float) $n, 2, '.', '' );
    $s = rtrim( rtrim( $s, '0' ), '.' );
    return '' === $s ? '0' : $s;
}

/* ===============================================================
   Cleanup of abandoned stashes
   =============================================================== */

add_action( 'init', 'fac_artwork_schedule_cleanup' );

/**
 * Ensure the daily stash-cleanup event is scheduled.
 *
 * @return void
 */
function fac_artwork_schedule_cleanup() {
    if ( ! wp_next_scheduled( FAC_ARTWORK_CLEANUP_HOOK ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', FAC_ARTWORK_CLEANUP_HOOK );
    }
}

add_action( FAC_ARTWORK_CLEANUP_HOOK, 'fac_artwork_run_cleanup' );

/**
 * Delete session stash folders whose newest file is older than the TTL.
 *
 * Only the tmp/ (unsubmitted) stashes are pruned; per-order folders are kept.
 *
 * @return void
 */
function fac_artwork_run_cleanup() {
    $tmp = fac_artwork_base_dir( 'tmp' );
    if ( is_wp_error( $tmp ) || ! is_dir( $tmp ) ) {
        return;
    }
    $now  = time();
    $dirs = @scandir( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    if ( ! is_array( $dirs ) ) {
        return;
    }
    foreach ( $dirs as $name ) {
        if ( '.' === $name || '..' === $name || 0 !== strpos( $name, 'sess-' ) ) {
            continue;
        }
        $dir     = trailingslashit( $tmp ) . $name;
        $parts   = trailingslashit( $dir ) . FAC_ARTWORK_PARTS_DIRNAME;
        $files   = fac_artwork_list_stash( $dir );
        $newest  = 0;
        foreach ( array_keys( $files ) as $f ) {
            $newest = max( $newest, (int) @filemtime( trailingslashit( $dir ) . $f ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        // Interrupted chunk uploads leave scratch files behind; clear anything
        // that has not been touched for a day, and let its mtime keep the
        // session alive while an upload is genuinely still running.
        if ( is_dir( $parts ) ) {
            $scratch = @scandir( $parts ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if ( is_array( $scratch ) ) {
                foreach ( $scratch as $s ) {
                    if ( '.' === $s || '..' === $s ) {
                        continue;
                    }
                    $sp = trailingslashit( $parts ) . $s;
                    $sm = (int) @filemtime( $sp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                    if ( $sm && ( $now - $sm ) > DAY_IN_SECONDS ) {
                        @unlink( $sp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                    } else {
                        $newest = max( $newest, $sm );
                    }
                }
            }
            @rmdir( $parts ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        if ( 0 === $newest ) {
            $newest = (int) @filemtime( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        if ( $newest && ( $now - $newest ) > FAC_ARTWORK_STASH_TTL ) {
            foreach ( array_keys( $files ) as $f ) {
                @unlink( trailingslashit( $dir ) . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
            @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
    }
}
