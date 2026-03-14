<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GNH_Admin_Page {

    public function __construct() {
        add_action( 'admin_menu',             [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_gnh_toggle_enabled', [ $this, 'ajax_toggle' ] );
        add_action( 'wp_ajax_gnh_test_tags',      [ $this, 'ajax_test_tags' ] );
    }

    public function register_menu(): void {
        add_menu_page(
            __( 'Google News Helper', 'google-news-helper' ),
            __( 'Google News', 'google-news-helper' ),
            'manage_options',
            'google-news-helper',
            [ $this, 'render_page' ],
            'dashicons-rss',
            80
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== 'toplevel_page_google-news-helper' ) {
            return;
        }

        wp_enqueue_style(
            'gnh-admin',
            GNH_PLUGIN_URL . 'assets/css/admin.css',
            [],
            GNH_VERSION
        );

        wp_enqueue_script(
            'gnh-admin',
            GNH_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            GNH_VERSION,
            true
        );

        wp_localize_script( 'gnh-admin', 'gnhData', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'gnh_toggle_nonce' ),
        ] );
    }

    public function ajax_test_tags(): void {
        check_ajax_referer( 'gnh_toggle_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'No post ID supplied.' ] );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( [ 'message' => 'Post not found.' ] );
        }

        $url = get_permalink( $post );

        // Fetch the post's front-end HTML
        $response = wp_remote_get( $url, [
            'timeout'    => 15,
            'user-agent' => 'Google News Helper Tag Tester/1.0 (WordPress/' . get_bloginfo( 'version' ) . ')',
            'sslverify'  => false, // local dev may have self-signed certs
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => 'Could not fetch URL: ' . $response->get_error_message() ] );
        }

        $html = wp_remote_retrieve_body( $response );

        // Tags we care about
        $tags_to_check = [
            'og:title'              => [ 'label' => 'og:title',              'type' => 'og',       'required' => true  ],
            'og:type'               => [ 'label' => 'og:type',               'type' => 'og',       'required' => true  ],
            'og:url'                => [ 'label' => 'og:url',                'type' => 'og',       'required' => true  ],
            'og:description'        => [ 'label' => 'og:description',        'type' => 'og',       'required' => true  ],
            'og:image'              => [ 'label' => 'og:image',              'type' => 'og',       'required' => true  ],
            'og:site_name'          => [ 'label' => 'og:site_name',          'type' => 'og',       'required' => false ],
            'article:published_time'=> [ 'label' => 'article:published_time','type' => 'og',       'required' => true  ],
            'article:modified_time' => [ 'label' => 'article:modified_time', 'type' => 'og',       'required' => false ],
            'article:author'        => [ 'label' => 'article:author',        'type' => 'og',       'required' => false ],
            'article:section'       => [ 'label' => 'article:section',       'type' => 'og',       'required' => false ],
            'twitter:card'          => [ 'label' => 'twitter:card',          'type' => 'twitter',  'required' => false ],
            'twitter:image'         => [ 'label' => 'twitter:image',         'type' => 'twitter',  'required' => false ],
            'news_keywords'         => [ 'label' => 'news_keywords',         'type' => 'standard', 'required' => false ],
        ];

        $results  = [];
        $has_ld   = false;
        $ld_type  = null;

        foreach ( $tags_to_check as $key => $info ) {
            // Match both attribute orders and capture the content value
            $pattern = '/(?:property|name)=["\']' . preg_quote( $key, '/' ) . '["\'][^>]*content=["\']([^"\']*)["\']|content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']' . preg_quote( $key, '/' ) . '["\']]/i';
            $count   = preg_match_all( $pattern, $html, $matches );

            // Grab first captured value from either capture group
            $value = '';
            if ( $count > 0 ) {
                foreach ( $matches[1] as $i => $m ) {
                    $candidate = $m !== '' ? $m : ( $matches[2][ $i ] ?? '' );
                    if ( $candidate !== '' ) {
                        $value = $candidate;
                        break;
                    }
                }
            }

            $results[ $key ] = [
                'label'    => $info['label'],
                'type'     => $info['type'],
                'required' => $info['required'],
                'count'    => $count,
                'found'    => $count > 0,
                'duplicate'=> $count > 1,
                'value'    => $value,
            ];
        }

        // Check for NewsArticle JSON-LD
        if ( preg_match( '/"@type"\s*:\s*"NewsArticle"/i', $html ) ) {
            $has_ld  = true;
            $ld_type = 'NewsArticle';
        } elseif ( preg_match( '/"@type"\s*:\s*"Article"/i', $html ) ) {
            $has_ld  = true;
            $ld_type = 'Article (not NewsArticle — may need Google News Helper active)';
        }

        // Detect active SEO / OG plugins from HTML signals
        $seo_plugins = [];
        if ( strpos( $html, 'yoast' ) !== false || strpos( $html, 'wpseo' ) !== false ) {
            $seo_plugins[] = 'Yoast SEO';
        }
        if ( strpos( $html, 'rank-math' ) !== false || strpos( $html, 'rank_math' ) !== false ) {
            $seo_plugins[] = 'Rank Math';
        }
        if ( strpos( $html, 'aioseo' ) !== false || strpos( $html, 'all-in-one-seo' ) !== false ) {
            $seo_plugins[] = 'All-in-One SEO';
        }
        if ( strpos( $html, 'the-seo-framework' ) !== false ) {
            $seo_plugins[] = 'The SEO Framework';
        }

        // ── Conflict detection via WordPress plugin/mu-plugin APIs ──────────

        // Known plugins that output og: / twitter: / JSON-LD tags, keyed by folder slug
        $known_og_plugins = [
            'wordpress-seo'          => 'Yoast SEO',
            'wordpress-seo-premium'  => 'Yoast SEO Premium',
            'seo-by-rank-math'       => 'Rank Math',
            'seo-by-rank-math-pro'   => 'Rank Math Pro',
            'all-in-one-seo-pack'    => 'All-in-One SEO',
            'all-in-one-seo'         => 'All-in-One SEO (Pro)',
            'the-seo-framework'      => 'The SEO Framework',
            'squirrly-seo'           => 'Squirrly SEO',
            'wp-seopress'            => 'SEOPress',
            'seopress'               => 'SEOPress Pro',
            'autodescription'        => 'The SEO Framework (autodescription)',
            'jetpack'                => 'Jetpack (Open Graph module)',
            'wpsso'                  => 'WPSSO Core',
            'add-meta-tags'          => 'Add Meta Tags',
            'wp-og'                  => 'WP Open Graph',
            'open-graph-protocol-framework' => 'Open Graph Protocol Framework',
        ];

        $active_conflict_plugins = [];
        $active_plugins = (array) get_option( 'active_plugins', [] );
        // Also check network-activated plugins on multisite
        if ( is_multisite() ) {
            $network_plugins = array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) );
            $active_plugins  = array_merge( $active_plugins, $network_plugins );
        }

        foreach ( $active_plugins as $plugin_file ) {
            $slug = explode( '/', $plugin_file )[0];
            if ( isset( $known_og_plugins[ $slug ] ) && $slug !== 'google-news-helper' ) {
                $active_conflict_plugins[] = [
                    'file' => $plugin_file,
                    'name' => $known_og_plugins[ $slug ],
                    'type' => 'plugin',
                ];
            }
        }

        // Check mu-plugins
        $mu_conflict_plugins = [];
        $mu_plugin_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : ( WP_CONTENT_DIR . '/mu-plugins' );
        if ( is_dir( $mu_plugin_dir ) ) {
            $mu_files = glob( $mu_plugin_dir . '/*.php' ) ?: [];
            foreach ( $mu_files as $mu_file ) {
                $mu_data = get_file_data( $mu_file, [ 'Name' => 'Plugin Name' ] );
                $mu_name = $mu_data['Name'] ?: basename( $mu_file );
                // Read first 4KB to look for og-related code
                $mu_snippet = file_get_contents( $mu_file, false, null, 0, 4096 ) ?: '';
                if ( preg_match( '/og:(title|image|description|type)|twitter:card|open.?graph|NewsArticle/i', $mu_snippet ) ) {
                    $mu_conflict_plugins[] = [
                        'file' => basename( $mu_file ),
                        'name' => $mu_name,
                        'type' => 'mu-plugin',
                    ];
                }
            }
        }

        wp_send_json_success( [
            'url'                    => $url,
            'tags'                   => $results,
            'json_ld'                => $has_ld,
            'json_ld_type'           => $ld_type,
            'seo_plugins'            => $seo_plugins,
            'conflict_plugins'       => $active_conflict_plugins,
            'mu_conflict_plugins'    => $mu_conflict_plugins,
        ] );
    }

    public function ajax_toggle(): void {
        check_ajax_referer( 'gnh_toggle_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $enabled = rest_sanitize_boolean( $_POST['enabled'] ?? false );
        update_option( 'gnh_enabled', $enabled );
        wp_send_json_success( [ 'enabled' => $enabled ] );
    }

    public function render_page(): void {
        $enabled = (bool) get_option( 'gnh_enabled', true );
        $posts   = $this->get_recent_posts();
        ?>
        <div class="wrap gnh-wrap">
            <h1>
                <span class="dashicons dashicons-rss" style="font-size:30px;line-height:1;vertical-align:middle;margin-right:6px;color:#e8612d;"></span>
                <?php esc_html_e( 'Google News Helper', 'google-news-helper' ); ?>
                <span class="gnh-version">v<?php echo esc_html( GNH_VERSION ); ?></span>
            </h1>

            <?php if ( isset( $_GET['updated'] ) ): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'google-news-helper' ); ?></p></div>
            <?php endif; ?>

            <!-- ── Enable toggle ── -->
            <div class="gnh-card gnh-toggle-card">
                <h2><?php esc_html_e( 'Enable Google News Optimization', 'google-news-helper' ); ?></h2>
                <p class="description"><?php esc_html_e( 'When enabled, the plugin adds Google News meta tags, Open Graph tags, and NewsArticle JSON-LD structured data to every post.', 'google-news-helper' ); ?></p>
                <label class="gnh-switch" for="gnh-enable-toggle">
                    <input type="checkbox" id="gnh-enable-toggle" <?php checked( $enabled ); ?>>
                    <span class="gnh-slider"></span>
                </label>
                <span class="gnh-toggle-label">
                    <?php echo $enabled
                        ? '<span class="gnh-status-on">' . esc_html__( 'Active', 'google-news-helper' ) . '</span>'
                        : '<span class="gnh-status-off">' . esc_html__( 'Inactive', 'google-news-helper' ) . '</span>';
                    ?>
                </span>
                <span class="gnh-save-notice" style="display:none;margin-left:12px;color:#46b450;font-weight:600;">
                    &#10003; <?php esc_html_e( 'Saved', 'google-news-helper' ); ?>
                </span>
            </div>

            <!-- ── Article previews ── -->
            <div class="gnh-card">
                <h2><?php esc_html_e( 'How Your Latest Posts Appear on Google News', 'google-news-helper' ); ?></h2>
                <p class="description"><?php esc_html_e( 'A preview of the last 5 published posts as they would appear in Google News.', 'google-news-helper' ); ?></p>

                <?php if ( empty( $posts ) ): ?>
                    <p><?php esc_html_e( 'No published posts found.', 'google-news-helper' ); ?></p>
                <?php else: ?>
                <div class="gnh-preview-grid">
                    <?php foreach ( $posts as $post ):
                        $this->render_preview_card( $post );
                    endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Tag Tester ── -->
            <div class="gnh-card">
                <h2><?php esc_html_e( 'Meta Tag Tester', 'google-news-helper' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Fetch any post\'s front-end HTML and check which tags are present, missing, or duplicated by another plugin.', 'google-news-helper' ); ?>
                </p>
                <div class="gnh-test-row">
                    <select id="gnh-test-post-select" style="min-width:280px;">
                        <option value=""><?php esc_html_e( '— Select a recent post —', 'google-news-helper' ); ?></option>
                        <?php foreach ( $posts as $p ): ?>
                        <option value="<?php echo esc_attr( $p->ID ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button id="gnh-run-test" class="button button-primary">
                        <?php esc_html_e( 'Run Test', 'google-news-helper' ); ?>
                    </button>
                    <span class="gnh-test-spinner spinner" style="float:none;visibility:hidden;"></span>
                </div>
                <div id="gnh-test-results" style="margin-top:16px;display:none;"></div>
            </div>

            <!-- ── Info box ── -->
            <div class="gnh-card gnh-info-card">
                <h3><?php esc_html_e( 'What this plugin adds', 'google-news-helper' ); ?></h3>
                <ul>
                    <li><?php esc_html_e( 'Open Graph tags (og:title, og:image, og:description, og:type=article …)', 'google-news-helper' ); ?></li>
                    <li><?php esc_html_e( 'Article Open Graph tags (published time, modified time, author, section, tags)', 'google-news-helper' ); ?></li>
                    <li><?php esc_html_e( 'Twitter Card tags (summary_large_image)', 'google-news-helper' ); ?></li>
                    <li><?php esc_html_e( 'news_keywords meta tag (up to 10 post tags)', 'google-news-helper' ); ?></li>
                    <li><?php esc_html_e( 'robots meta: max-image-preview:large', 'google-news-helper' ); ?></li>
                    <li><?php esc_html_e( 'NewsArticle JSON-LD structured data (Schema.org)', 'google-news-helper' ); ?></li>
                    <li><?php esc_html_e( 'OG tags are skipped if Yoast SEO, Rank Math, or All-in-One SEO is active (no conflicts)', 'google-news-helper' ); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return WP_Post[] */
    private function get_recent_posts(): array {
        $query = new WP_Query( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 5,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ] );
        return $query->posts ?: [];
    }

    private function render_preview_card( WP_Post $post ): void {
        $title      = get_the_title( $post );
        $headline   = mb_strlen( $title ) > 110 ? mb_substr( $title, 0, 107 ) . '...' : $title;
        $url        = get_permalink( $post );
        $site_name  = get_bloginfo( 'name' );
        $cats       = get_the_category( $post->ID );
        $section    = ! empty( $cats ) ? $cats[0]->name : '';
        $pub_ts     = get_post_time( 'U', false, $post );
        $pub_human  = human_time_diff( (int) $pub_ts, time() ) . ' ' . __( 'ago', 'google-news-helper' );
        $pub_iso    = get_the_date( 'c', $post );

        // Checks
        $has_image   = has_post_thumbnail( $post->ID );
        $has_excerpt = ! empty( $post->post_excerpt ) || ! empty( $post->post_content );
        $all_good    = $has_image && $has_excerpt;

        // Thumbnail
        $thumb_html = '';
        if ( $has_image ) {
            $thumb_html = get_the_post_thumbnail( $post->ID, [ 96, 96 ], [ 'class' => 'gnh-card-thumb' ] );
        } else {
            $thumb_html = '<div class="gnh-card-thumb gnh-thumb-placeholder"><span class="dashicons dashicons-format-image"></span></div>';
        }

        // Status
        $warnings = [];
        if ( ! $has_image ) {
            $warnings[] = __( 'No featured image — Google News may not show a thumbnail.', 'google-news-helper' );
        }
        if ( ! $has_excerpt ) {
            $warnings[] = __( 'No excerpt or content — description will be empty.', 'google-news-helper' );
        }
        ?>
        <div class="gnh-preview-card">
            <div class="gnh-preview-body">
                <div class="gnh-preview-meta-top">
                    <span class="gnh-preview-source"><?php echo esc_html( $site_name ); ?></span>
                    <?php if ( $section ): ?>
                        <span class="gnh-preview-dot">&middot;</span>
                        <span class="gnh-preview-section"><?php echo esc_html( $section ); ?></span>
                    <?php endif; ?>
                </div>
                <a href="<?php echo esc_url( $url ); ?>" target="_blank" class="gnh-preview-title">
                    <?php echo esc_html( $headline ); ?>
                </a>
                <div class="gnh-preview-meta-bottom">
                    <span class="gnh-preview-time" title="<?php echo esc_attr( $pub_iso ); ?>">
                        <?php echo esc_html( $pub_human ); ?>
                    </span>
                </div>
                <div class="gnh-preview-status <?php echo $all_good ? 'gnh-status-good' : 'gnh-status-warn'; ?>">
                    <?php if ( $all_good ): ?>
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e( 'Ready for Google News', 'google-news-helper' ); ?>
                    <?php else: ?>
                        <span class="dashicons dashicons-warning"></span>
                        <?php echo esc_html( implode( ' ', $warnings ) ); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="gnh-preview-thumb">
                <?php echo $thumb_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>
        </div>
        <?php
    }
}
