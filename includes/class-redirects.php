<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GNH_Redirects — lightweight 301/302/410 redirect manager.
 *
 * Redirects are stored in the option `gnh_redirects` as an array of:
 *   [ 'from' => '/old-path/', 'to' => '/new-path/', 'type' => 301, 'hits' => 0 ]
 */
class GNH_Redirects {

    const OPTION = 'gnh_redirects';

    public function __construct() {
        add_action( 'template_redirect', [ $this, 'handle' ], 1 );

        if ( is_admin() ) {
            add_action( 'admin_post_gnh_save_redirect',   [ $this, 'handle_save' ] );
            add_action( 'admin_post_gnh_delete_redirect', [ $this, 'handle_delete' ] );
            add_action( 'admin_enqueue_scripts',          [ $this, 'enqueue' ] );
        }
    }

    // ── Front-end: intercept matching requests ────────────────────────────────

    public function handle(): void {
        if ( is_admin() ) {
            return;
        }

        $redirects = $this->get_all();
        if ( empty( $redirects ) ) {
            return;
        }

        $request_path = '/' . ltrim( parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ?: '', '/' );

        foreach ( $redirects as $index => $r ) {
            $from = '/' . ltrim( $r['from'], '/' );

            if ( $from === $request_path ) {
                // Update hit counter
                $redirects[ $index ]['hits'] = ( (int) ( $r['hits'] ?? 0 ) ) + 1;
                update_option( self::OPTION, $redirects, false );

                $type = (int) ( $r['type'] ?? 301 );

                if ( $type === 410 ) {
                    status_header( 410 );
                    nocache_headers();
                    exit( 'Gone' );
                }

                wp_redirect( $r['to'], $type );
                exit;
            }
        }
    }

    // ── Admin menu ────────────────────────────────────────────────────────────

    public function register_menu(): void {
        add_submenu_page(
            'google-news-helper',
            __( 'Redirects', 'google-news-helper' ),
            __( 'Redirects', 'google-news-helper' ),
            'manage_options',
            'gnh-redirects',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue( string $hook ): void {
        if ( strpos( $hook, 'gnh-redirects' ) === false ) {
            return;
        }
        wp_enqueue_style( 'gnh-admin', GNH_PLUGIN_URL . 'assets/css/admin.css', [], GNH_VERSION );
    }

    // ── Admin page ────────────────────────────────────────────────────────────

    public static function render_static(): void {
        ( new self() )->render_page();
    }

    public function render_page(): void {
        $redirects = $this->get_all();
        $nonce     = wp_create_nonce( 'gnh_redirect_action' );
        ?>
        <div class="wrap gnh-wrap">
            <h1>
                <span class="dashicons dashicons-randomize" style="font-size:26px;line-height:1.2;vertical-align:middle;margin-right:6px;color:#2271b1;"></span>
                <?php esc_html_e( 'Redirect Manager', 'google-news-helper' ); ?>
                <span class="gnh-version">Google News Helper v<?php echo esc_html( GNH_VERSION ); ?></span>
            </h1>

            <?php if ( isset( $_GET['saved'] ) ): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Redirect saved.', 'google-news-helper' ); ?></p></div>
            <?php elseif ( isset( $_GET['deleted'] ) ): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Redirect deleted.', 'google-news-helper' ); ?></p></div>
            <?php endif; ?>

            <!-- Add new redirect -->
            <div class="gnh-card">
                <h2><?php esc_html_e( 'Add Redirect', 'google-news-helper' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="gnh_save_redirect">
                    <?php wp_nonce_field( 'gnh_redirect_action', 'gnh_nonce' ); ?>
                    <table class="form-table" style="max-width:700px;">
                        <tr>
                            <th><label for="gnh-from"><?php esc_html_e( 'From (path)', 'google-news-helper' ); ?></label></th>
                            <td>
                                <input type="text" id="gnh-from" name="gnh_from" class="regular-text" placeholder="/old-article-slug/" required>
                                <p class="description"><?php esc_html_e( 'Relative path, e.g. /2023/05/old-title/ or /category/old/', 'google-news-helper' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="gnh-to"><?php esc_html_e( 'To (destination)', 'google-news-helper' ); ?></label></th>
                            <td>
                                <input type="text" id="gnh-to" name="gnh_to" class="regular-text" placeholder="/new-article-slug/ or https://example.com/page/" required>
                                <p class="description"><?php esc_html_e( 'Relative path or full URL. Leave empty for 410 Gone.', 'google-news-helper' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="gnh-type"><?php esc_html_e( 'Type', 'google-news-helper' ); ?></label></th>
                            <td>
                                <select id="gnh-type" name="gnh_type">
                                    <option value="301">301 — Moved Permanently</option>
                                    <option value="302">302 — Found (Temporary)</option>
                                    <option value="410">410 — Gone (removed, no destination)</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( __( 'Add Redirect', 'google-news-helper' ), 'primary', 'submit', false ); ?>
                </form>
            </div>

            <!-- Existing redirects -->
            <div class="gnh-card">
                <h2><?php esc_html_e( 'Active Redirects', 'google-news-helper' ); ?>
                    <span class="gnh-version"><?php echo count( $redirects ); ?> total</span>
                </h2>

                <?php if ( empty( $redirects ) ): ?>
                    <p><?php esc_html_e( 'No redirects configured yet.', 'google-news-helper' ); ?></p>
                <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'From', 'google-news-helper' ); ?></th>
                            <th><?php esc_html_e( 'To', 'google-news-helper' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'google-news-helper' ); ?></th>
                            <th><?php esc_html_e( 'Hits', 'google-news-helper' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'google-news-helper' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $redirects as $i => $r ): ?>
                        <tr>
                            <td><code><?php echo esc_html( $r['from'] ); ?></code></td>
                            <td>
                                <?php if ( ! empty( $r['to'] ) ): ?>
                                    <a href="<?php echo esc_url( $r['to'] ); ?>" target="_blank"><?php echo esc_html( $r['to'] ); ?></a>
                                <?php else: ?>
                                    <em><?php esc_html_e( '— (410 Gone)', 'google-news-helper' ); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $type = (int) ( $r['type'] ?? 301 );
                                $labels = [ 301 => '301 Permanent', 302 => '302 Temporary', 410 => '410 Gone' ];
                                echo esc_html( $labels[ $type ] ?? $type );
                                ?>
                            </td>
                            <td><?php echo (int) ( $r['hits'] ?? 0 ); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="gnh_delete_redirect">
                                    <input type="hidden" name="gnh_index" value="<?php echo (int) $i; ?>">
                                    <?php wp_nonce_field( 'gnh_redirect_action', 'gnh_nonce' ); ?>
                                    <button type="submit" class="button button-link-delete"
                                        onclick="return confirm('<?php esc_attr_e( 'Delete this redirect?', 'google-news-helper' ); ?>')">
                                        <?php esc_html_e( 'Delete', 'google-news-helper' ); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // ── Form handlers ─────────────────────────────────────────────────────────

    public function handle_save(): void {
        check_admin_referer( 'gnh_redirect_action', 'gnh_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $from = '/' . ltrim( sanitize_text_field( wp_unslash( $_POST['gnh_from'] ?? '' ) ), '/' );
        $to   = sanitize_text_field( wp_unslash( $_POST['gnh_to'] ?? '' ) );
        $type = (int) ( $_POST['gnh_type'] ?? 301 );

        if ( empty( $from ) ) {
            wp_redirect( add_query_arg( 'error', '1', admin_url( 'admin.php?page=gnh-redirects' ) ) );
            exit;
        }

        if ( ! in_array( $type, [ 301, 302, 410 ], true ) ) {
            $type = 301;
        }

        $redirects   = $this->get_all();
        $redirects[] = [
            'from' => $from,
            'to'   => $to,
            'type' => $type,
            'hits' => 0,
        ];
        update_option( self::OPTION, $redirects, false );

        wp_redirect( add_query_arg( 'saved', '1', admin_url( 'admin.php?page=gnh-redirects' ) ) );
        exit;
    }

    public function handle_delete(): void {
        check_admin_referer( 'gnh_redirect_action', 'gnh_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $index     = (int) ( $_POST['gnh_index'] ?? -1 );
        $redirects = $this->get_all();

        if ( isset( $redirects[ $index ] ) ) {
            array_splice( $redirects, $index, 1 );
            update_option( self::OPTION, $redirects, false );
        }

        wp_redirect( add_query_arg( 'deleted', '1', admin_url( 'admin.php?page=gnh-redirects' ) ) );
        exit;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<int, array<string,mixed>> */
    public static function get_all(): array {
        $data = get_option( self::OPTION, [] );
        return is_array( $data ) ? $data : [];
    }
}
