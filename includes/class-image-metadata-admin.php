<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin screen for image provenance stripping: the on-upload toggle, plus a
 * batched cleaner for images that were uploaded before the toggle was switched on.
 *
 * The library can hold tens of thousands of files, so the cleaner walks the
 * attachment list in small batches over AJAX rather than in one request.
 */
class GNH_Image_Metadata_Admin {

    private const BATCH = 25;
    private const NONCE = 'gnh_image_meta';

    public function __construct() {
        add_action( 'wp_ajax_gnh_scan_images',  [ $this, 'ajax_scan' ] );
        add_action( 'wp_ajax_gnh_clean_images', [ $this, 'ajax_clean' ] );
    }

    public static function render_static(): void {
        ( new self() )->render();
    }

    /**
     * Attachment IDs for image types, ordered oldest first so a run is resumable
     * by offset even if new uploads arrive mid-run.
     *
     * @return int[]
     */
    private static function image_ids(): array {
        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type IN ('image/jpeg','image/png','image/webp')
             ORDER BY ID ASC"
        );

        return array_map( 'intval', (array) $ids );
    }

    /**
     * Every file backing an attachment: the original plus generated sizes.
     *
     * @return string[] Absolute paths.
     */
    private static function attachment_files( int $id ): array {
        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) {
            return [];
        }

        $files = [ $file ];
        $dir   = dirname( $file );
        $meta  = wp_get_attachment_metadata( $id );

        if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $size ) {
                if ( empty( $size['file'] ) ) {
                    continue;
                }
                $path = $dir . '/' . basename( (string) $size['file'] );
                if ( file_exists( $path ) ) {
                    $files[] = $path;
                }
            }
        }

        return array_unique( $files );
    }

    private function verify(): void {
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'google-news-helper' ) ], 403 );
        }
        check_ajax_referer( self::NONCE, 'nonce' );
    }

    /**
     * Report how many attachments still carry provenance metadata.
     */
    public function ajax_scan(): void {
        $this->verify();

        $ids    = self::image_ids();
        $offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
        $found  = isset( $_POST['found'] ) ? max( 0, (int) $_POST['found'] ) : 0;

        $slice = array_slice( $ids, $offset, self::BATCH );

        foreach ( $slice as $id ) {
            foreach ( self::attachment_files( $id ) as $path ) {
                if ( self::file_has_provenance( $path ) ) {
                    $found++;
                    break;
                }
            }
        }

        $offset += count( $slice );

        wp_send_json_success( [
            'offset' => $offset,
            'total'  => count( $ids ),
            'found'  => $found,
            'done'   => $offset >= count( $ids ),
        ] );
    }

    /**
     * Strip provenance from a batch of attachments.
     */
    public function ajax_clean(): void {
        $this->verify();

        $ids     = self::image_ids();
        $offset  = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
        $cleaned = isset( $_POST['cleaned'] ) ? max( 0, (int) $_POST['cleaned'] ) : 0;

        $slice = array_slice( $ids, $offset, self::BATCH );

        foreach ( $slice as $id ) {
            $touched = false;
            foreach ( self::attachment_files( $id ) as $path ) {
                if ( GNH_Image_Metadata::strip_file( $path ) ) {
                    $touched = true;
                }
            }
            if ( $touched ) {
                $cleaned++;
            }
        }

        $offset += count( $slice );

        wp_send_json_success( [
            'offset'  => $offset,
            'total'   => count( $ids ),
            'cleaned' => $cleaned,
            'done'    => $offset >= count( $ids ),
        ] );
    }

    /**
     * Cheap check for provenance markers without loading whole files where possible.
     */
    public static function file_has_provenance( string $path ): bool {
        if ( ! is_readable( $path ) ) {
            return false;
        }

        $data = file_get_contents( $path );
        if ( ! is_string( $data ) || $data === '' ) {
            return false;
        }

        foreach ( [ 'x:xmpmeta', 'dcterms:provenance', 'c2pa', 'jumbf', 'PSAI', 'contentauth' ] as $needle ) {
            if ( stripos( $data, $needle ) !== false ) {
                return true;
            }
        }

        return false;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $enabled = GNH_Image_Metadata::is_enabled();
        ?>
        <div class="wrap gnh-wrap">
            <h1>
                <span class="dashicons dashicons-format-image" style="font-size:28px;line-height:1;vertical-align:middle;margin-right:6px;color:#e8612d;"></span>
                <?php esc_html_e( 'Image metadata', 'google-news-helper' ); ?>
            </h1>

            <div class="gnh-card">
                <h2><?php esc_html_e( 'Remove AI / provenance metadata from images', 'google-news-helper' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Photoshop and other image editors embed C2PA “Content Credentials” and XMP provenance data inside image files. Some platforms read this data when a link is shared, which can affect whether the image appears in the post preview and can attach an AI-related label to the post.', 'google-news-helper' ); ?>
                </p>
                <p class="description">
                    <?php esc_html_e( 'When enabled, this data is removed from every image as it is uploaded. The picture itself is untouched — the image data is copied across unchanged, so there is no re-compression and no loss of quality. Colour profiles are preserved.', 'google-news-helper' ); ?>
                </p>

                <form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" style="margin-top:14px;">
                    <?php settings_fields( 'gnh_image_options_group' ); ?>
                    <label style="display:flex;align-items:center;gap:8px;font-weight:600;">
                        <input type="checkbox" name="<?php echo esc_attr( GNH_Image_Metadata::OPTION ); ?>" value="1" <?php checked( $enabled ); ?>>
                        <?php esc_html_e( 'Strip metadata from new uploads', 'google-news-helper' ); ?>
                    </label>
                    <p style="margin-top:12px;">
                        <?php submit_button( __( 'Save setting', 'google-news-helper' ), 'primary', 'submit', false ); ?>
                    </p>
                </form>
            </div>

            <div class="gnh-card">
                <h2><?php esc_html_e( 'Existing images', 'google-news-helper' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'The setting above only affects new uploads. Use these tools for images already in the media library. Scanning only reports; cleaning rewrites the files and cannot be undone, so take a backup of wp-content/uploads first.', 'google-news-helper' ); ?>
                </p>

                <p style="margin-top:14px;">
                    <button type="button" class="button" id="gnh-scan"><?php esc_html_e( 'Scan library', 'google-news-helper' ); ?></button>
                    <button type="button" class="button button-primary" id="gnh-clean" style="margin-left:6px;"><?php esc_html_e( 'Clean all images', 'google-news-helper' ); ?></button>
                    <button type="button" class="button" id="gnh-stop" style="margin-left:6px;display:none;"><?php esc_html_e( 'Stop', 'google-news-helper' ); ?></button>
                </p>

                <div id="gnh-progress-wrap" style="display:none;margin-top:14px;">
                    <div style="background:#e0e0e0;border-radius:3px;overflow:hidden;height:22px;max-width:520px;">
                        <div id="gnh-bar" style="background:#2271b1;height:100%;width:0;transition:width .2s;"></div>
                    </div>
                    <p id="gnh-status" style="margin-top:8px;font-weight:600;"></p>
                </div>
            </div>

            <script>
            ( function () {
                var nonce  = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
                var ajax   = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
                var stop   = false;

                var bar    = document.getElementById( 'gnh-bar' );
                var status = document.getElementById( 'gnh-status' );
                var wrap   = document.getElementById( 'gnh-progress-wrap' );
                var btns   = [ document.getElementById( 'gnh-scan' ), document.getElementById( 'gnh-clean' ) ];
                var stopBtn = document.getElementById( 'gnh-stop' );

                function busy( on ) {
                    btns.forEach( function ( b ) { b.disabled = on; } );
                    stopBtn.style.display = on ? '' : 'none';
                    if ( on ) { wrap.style.display = ''; stop = false; }
                }

                stopBtn.addEventListener( 'click', function () {
                    stop = true;
                    status.textContent = <?php echo wp_json_encode( __( 'Stopping…', 'google-news-helper' ) ); ?>;
                } );

                function run( action, state, label, done ) {
                    if ( stop ) { busy( false ); status.textContent += ' ' + <?php echo wp_json_encode( __( '(stopped)', 'google-news-helper' ) ); ?>; return; }

                    var body = new URLSearchParams();
                    body.set( 'action', action );
                    body.set( 'nonce', nonce );
                    Object.keys( state ).forEach( function ( k ) { body.set( k, state[ k ] ); } );

                    fetch( ajax, { method: 'POST', credentials: 'same-origin', body: body } )
                        .then( function ( r ) { return r.json(); } )
                        .then( function ( res ) {
                            if ( ! res || ! res.success ) {
                                status.textContent = 'Error: ' + ( res && res.data && res.data.message ? res.data.message : 'request failed' );
                                busy( false );
                                return;
                            }
                            var d = res.data;
                            var pct = d.total ? Math.round( d.offset / d.total * 100 ) : 100;
                            bar.style.width = pct + '%';
                            status.textContent = label( d, pct );

                            if ( d.done ) { busy( false ); done( d ); return; }
                            state.offset = d.offset;
                            run( action, state, label, done );
                        } )
                        .catch( function ( e ) {
                            status.textContent = 'Error: ' + e.message;
                            busy( false );
                        } );
                }

                document.getElementById( 'gnh-scan' ).addEventListener( 'click', function () {
                    busy( true );
                    var state = { offset: 0, found: 0 };
                    run( 'gnh_scan_images', state,
                        function ( d, pct ) {
                            state.found = d.found;
                            return pct + '% — ' + d.offset + ' / ' + d.total + ' checked, ' + d.found + ' with metadata';
                        },
                        function ( d ) {
                            status.textContent = d.found === 0
                                ? <?php echo wp_json_encode( __( 'Scan complete — no images carry provenance metadata.', 'google-news-helper' ) ); ?>
                                : d.found + ' ' + <?php echo wp_json_encode( __( 'images still carry provenance metadata.', 'google-news-helper' ) ); ?>;
                        } );
                } );

                document.getElementById( 'gnh-clean' ).addEventListener( 'click', function () {
                    if ( ! window.confirm( <?php echo wp_json_encode( __( 'This rewrites image files in the media library and cannot be undone. Make sure you have a backup of wp-content/uploads. Continue?', 'google-news-helper' ) ); ?> ) ) {
                        return;
                    }
                    busy( true );
                    var state = { offset: 0, cleaned: 0 };
                    run( 'gnh_clean_images', state,
                        function ( d, pct ) {
                            state.cleaned = d.cleaned;
                            return pct + '% — ' + d.offset + ' / ' + d.total + ' processed, ' + d.cleaned + ' cleaned';
                        },
                        function ( d ) {
                            status.textContent = <?php echo wp_json_encode( __( 'Done —', 'google-news-helper' ) ); ?> + ' ' + d.cleaned + ' ' + <?php echo wp_json_encode( __( 'images cleaned.', 'google-news-helper' ) ); ?>;
                        } );
                } );
            }() );
            </script>
        </div>
        <?php
    }
}
