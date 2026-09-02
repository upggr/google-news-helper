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
            __( 'News SEO Helper', 'news-seo-helper' ),
            __( 'News SEO', 'news-seo-helper' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ],
            'dashicons-rss',
            80
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'News SEO Helper', 'news-seo-helper' ),
            __( 'Dashboard', 'news-seo-helper' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Category descriptions', 'news-seo-helper' ),
            __( 'Category descriptions', 'news-seo-helper' ),
            'manage_categories',
            'gnh-term-descriptions',
            [ GNH_Term_SEO_Admin::class, 'render_static' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Image metadata', 'news-seo-helper' ),
            __( 'Image metadata', 'news-seo-helper' ),
            'manage_options',
            'gnh-image-metadata',
            [ GNH_Image_Metadata_Admin::class, 'render_static' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Redirects', 'news-seo-helper' ),
            __( 'Redirects', 'news-seo-helper' ),
            'manage_options',
            'gnh-redirects',
            [ GNH_Redirects::class, 'render_static' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'robots.txt', 'news-seo-helper' ),
            __( 'robots.txt', 'news-seo-helper' ),
            'manage_options',
            'gnh-robots',
            [ GNH_Robots_Admin::class, 'render_static' ]
        );
    }

    /** Top-level menu slug; also the base of every submenu's admin page hook. */
    public const MENU_SLUG = 'news-seo-helper';

    public function enqueue_assets( string $hook ): void {
        // Hook names are derived from the menu slug rather than hardcoded, so a
        // renamed menu cannot silently leave admin screens unstyled.
        $pages = [ 'toplevel_page_' . self::MENU_SLUG ];
        foreach ( [ 'gnh-term-descriptions', 'gnh-image-metadata', 'gnh-redirects', 'gnh-robots', 'gnh-crawler-logs' ] as $sub ) {
            $pages[] = self::MENU_SLUG . '_page_' . $sub;
        }

        if ( ! in_array( $hook, $pages, true ) ) {
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
            'user-agent' => 'News SEO Helper Tag Tester/1.0 (WordPress/' . get_bloginfo( 'version' ) . ')',
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
            $ld_type = 'Article (not NewsArticle — may need News SEO Helper active)';
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
            if ( isset( $known_og_plugins[ $slug ] ) && $slug !== 'news-seo-helper' ) {
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

        // ── Googlebot-News access test ───────────────────────────────────────
        $googlebot_result = class_exists( 'GNH_Robots' )
            ? GNH_Robots::test_googlebot_access( $url )
            : [ 'reachable' => null, 'code' => 0, 'error' => 'GNH_Robots class not loaded' ];

        // ── Count ad images that have data-nosnippet (our fix applied) ───────
        $nosnippet_count = substr_count( $html, 'data-nosnippet' );

        wp_send_json_success( [
            'url'                    => $url,
            'tags'                   => $results,
            'json_ld'                => $has_ld,
            'json_ld_type'           => $ld_type,
            'seo_plugins'            => $seo_plugins,
            'conflict_plugins'       => $active_conflict_plugins,
            'mu_conflict_plugins'    => $mu_conflict_plugins,
            'googlebot'              => $googlebot_result,
            'nosnippet_count'        => $nosnippet_count,
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
                <?php esc_html_e( 'News SEO Helper', 'news-seo-helper' ); ?>
                <span class="gnh-version">v<?php echo esc_html( GNH_VERSION ); ?> &mdash; by <a href="https://buy-it.gr/" target="_blank">Ioannis Kokkinis</a></span>
            </h1>

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag set by options.php. ?>
            <?php if ( ! empty( $_GET['settings-updated'] ) || isset( $_GET['updated'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'news-seo-helper' ); ?></p></div>
            <?php endif; ?>

            <!-- ── Enable toggle ── -->
            <div class="gnh-card gnh-toggle-card">
                <h2><?php esc_html_e( 'Enable Google News Optimization', 'news-seo-helper' ); ?></h2>
                <p class="description"><?php esc_html_e( 'When enabled, the plugin adds Google News meta tags, Open Graph tags, and NewsArticle JSON-LD structured data to every post.', 'news-seo-helper' ); ?></p>
                <label class="gnh-switch" for="gnh-enable-toggle">
                    <input type="checkbox" id="gnh-enable-toggle" <?php checked( $enabled ); ?>>
                    <span class="gnh-slider"></span>
                </label>
                <span class="gnh-toggle-label">
                    <?php echo $enabled
                        ? '<span class="gnh-status-on">' . esc_html__( 'Active', 'news-seo-helper' ) . '</span>'
                        : '<span class="gnh-status-off">' . esc_html__( 'Inactive', 'news-seo-helper' ) . '</span>';
                    ?>
                </span>
                <span class="gnh-save-notice" style="display:none;margin-left:12px;color:#46b450;font-weight:600;">
                    &#10003; <?php esc_html_e( 'Saved', 'news-seo-helper' ); ?>
                </span>
            </div>

            <!-- ── Homepage meta description (Google snippet) ── -->
            <div class="gnh-card">
                <h2><?php esc_html_e( 'Homepage search snippet', 'news-seo-helper' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Text for the meta description on your site’s front page. Google often shows this under the title in results instead of random text from the menu or footer.', 'news-seo-helper' ); ?>
                </p>
                <form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="gnh-front-snippet-form">
                    <?php settings_fields( 'gnh_options_group' ); ?>
                    <label for="gnh_front_meta_description" class="screen-reader-text">
                        <?php esc_html_e( 'Homepage meta description', 'news-seo-helper' ); ?>
                    </label>
                    <textarea
                        id="gnh_front_meta_description"
                        name="gnh_front_meta_description"
                        class="large-text"
                        rows="4"
                        maxlength="320"
                        placeholder="<?php esc_attr_e( 'e.g. Breaking news, reports and analysis, updated around the clock.', 'news-seo-helper' ); ?>"
                    ><?php echo esc_textarea( (string) get_option( 'gnh_front_meta_description', '' ) ); ?></textarea>
                    <p class="description" style="margin-top:8px;">
                        <?php esc_html_e( 'Recommended length: about 50–160 characters. Maximum stored: 320. If a supported SEO plugin is active, a filled field here overrides its homepage meta description.', 'news-seo-helper' ); ?>
                    </p>
                    <?php submit_button( __( 'Save homepage snippet', 'news-seo-helper' ), 'primary', 'submit', false ); ?>
                </form>
            </div>

            <!-- ── Category search descriptions ── -->
            <?php
            $gnh_missing = $this->count_terms_without_desc();
            ?>
            <div class="gnh-card">
                <h2><?php esc_html_e( 'Category search snippets', 'news-seo-helper' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Text shown under each category title in Google results. Categories without one get an automatic snippet built from whatever text appears first on the page, which is usually identical across every category.', 'news-seo-helper' ); ?>
                </p>
                <p style="margin:12px 0;">
                    <?php if ( $gnh_missing > 0 ) : ?>
                        <span style="color:#b32d2e;font-weight:600;">
                            &#9888;
                            <?php
                            printf(
                                /* translators: %d: number of categories missing a description. */
                                esc_html( _n( '%d category has no search description.', '%d categories have no search description.', $gnh_missing, 'news-seo-helper' ) ),
                                (int) $gnh_missing
                            );
                            ?>
                        </span>
                    <?php else : ?>
                        <span style="color:#00794b;font-weight:600;">
                            &#10003; <?php esc_html_e( 'Every category has a search description.', 'news-seo-helper' ); ?>
                        </span>
                    <?php endif; ?>
                </p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=gnh-term-descriptions' ) ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Edit category descriptions', 'news-seo-helper' ); ?>
                </a>
            </div>

            <!-- ── Article previews ── -->
            <div class="gnh-card">
                <h2><?php esc_html_e( 'How Your Latest Posts Appear on Google News', 'news-seo-helper' ); ?></h2>
                <p class="description"><?php esc_html_e( 'A preview of the last 5 published posts as they would appear in Google News. Click the test icon to check meta tags.', 'news-seo-helper' ); ?></p>

                <?php if ( empty( $posts ) ): ?>
                    <p><?php esc_html_e( 'No published posts found.', 'news-seo-helper' ); ?></p>
                <?php else: ?>
                <div class="gnh-preview-grid">
                    <?php foreach ( $posts as $post ):
                        $this->render_preview_card( $post );
                    endforeach; ?>
                </div>
                <span class="gnh-test-spinner spinner" style="float:none;visibility:hidden;"></span>
                <div id="gnh-test-results" style="margin-top:16px;display:none;"></div>
                <?php endif; ?>
            </div>

            <!-- ── Info box ── -->
            <div class="gnh-card gnh-info-card">
                <h3><?php esc_html_e( 'What this plugin adds', 'news-seo-helper' ); ?></h3>
                <ul>
                    <li><?php esc_html_e( 'Open Graph tags (og:title, og:image, og:description, og:type=article …)', 'news-seo-helper' ); ?></li>
                    <li><?php esc_html_e( 'Article Open Graph tags (published time, modified time, author, section, tags)', 'news-seo-helper' ); ?></li>
                    <li><?php esc_html_e( 'Twitter Card tags (summary_large_image)', 'news-seo-helper' ); ?></li>
                    <li><?php esc_html_e( 'news_keywords meta tag (up to 10 post tags)', 'news-seo-helper' ); ?></li>
                    <li><?php esc_html_e( 'robots meta: max-image-preview:large', 'news-seo-helper' ); ?></li>
                    <li><?php esc_html_e( 'NewsArticle JSON-LD structured data (Schema.org)', 'news-seo-helper' ); ?></li>
                    <li><?php esc_html_e( 'Google News XML sitemap at /news-sitemap.xml (articles from last 48 hours)', 'news-seo-helper' ); ?></li>
                    <li><?php esc_html_e( 'RSS feed enclosure tags with image URL, size, and MIME type', 'news-seo-helper' ); ?></li>
                    <li><?php esc_html_e( 'OG tags are skipped if Yoast SEO, Rank Math, or All-in-One SEO is active (no conflicts)', 'news-seo-helper' ); ?></li>
                    <li><?php esc_html_e( 'Optional homepage meta description so Google can show your chosen text instead of navigation menus in search results', 'news-seo-helper' ); ?></li>
                    <li><?php esc_html_e( 'Per-post and per-page SEO title and meta description (editor metabox); when set, they override the SEO plugin’s values for that content where supported', 'news-seo-helper' ); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return WP_Post[] */
    private function get_recent_posts(): array {
        $query = new WP_Query( [
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'posts_per_page'   => 5,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ] );
        $posts = $query->posts ?: [];
        usort( $posts, static fn( $a, $b ) => strtotime( $b->post_date ) - strtotime( $a->post_date ) );
        return $posts;
    }

    /**
     * Categories with neither a News SEO Helper description nor a taxonomy description,
     * i.e. the ones Google will build a snippet for on its own.
     */
    private function count_terms_without_desc(): int {
        if ( ! class_exists( 'GNH_Term_SEO' ) ) {
            return 0;
        }

        $missing = 0;

        foreach ( GNH_Term_SEO::seo_taxonomies() as $taxonomy ) {
            if ( $taxonomy !== 'category' || ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }

            $terms = get_terms( [
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            ] );

            if ( is_wp_error( $terms ) ) {
                continue;
            }

            foreach ( $terms as $term ) {
                if ( GNH_Term_SEO::get_desc( $term->term_id ) === '' ) {
                    $missing++;
                }
            }
        }

        return $missing;
    }

    private function render_preview_card( WP_Post $post ): void {
        $title      = get_the_title( $post );
        $headline   = mb_strlen( $title ) > 110 ? mb_substr( $title, 0, 107 ) . '...' : $title;
        $url        = get_permalink( $post );
        $site_name  = get_bloginfo( 'name' );
        $cats       = get_the_category( $post->ID );
        $section    = ! empty( $cats ) ? $cats[0]->name : '';
        $pub_ts     = get_post_time( 'U', true, $post );
        $pub_human  = human_time_diff( (int) $pub_ts, time() ) . ' ' . __( 'ago', 'news-seo-helper' );
        $pub_iso    = get_the_date( 'c', $post );

        // Checks
        $has_image = has_post_thumbnail( $post->ID );

        // Thumbnail
        $thumb_html = '';
        if ( $has_image ) {
            $thumb_html = get_the_post_thumbnail( $post->ID, [ 96, 96 ], [ 'class' => 'gnh-card-thumb' ] );
        } else {
            $thumb_html = '<div class="gnh-card-thumb gnh-thumb-placeholder"><span class="dashicons dashicons-format-image"></span></div>';
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
                    <button class="gnh-test-icon-btn" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>" title="<?php esc_attr_e( 'Test meta tags for this post', 'news-seo-helper' ); ?>">
                        <span class="dashicons dashicons-search"></span>
                    </button>
                </div>
                <a href="<?php echo esc_url( $url ); ?>" target="_blank" class="gnh-preview-title">
                    <?php echo esc_html( $headline ); ?>
                </a>
                <div class="gnh-preview-meta-bottom">
                    <span class="gnh-preview-time" title="<?php echo esc_attr( $pub_iso ); ?>">
                        <?php echo esc_html( $pub_human ); ?>
                    </span>
                </div>
                <div class="gnh-preview-status <?php echo $has_image ? 'gnh-status-good' : 'gnh-status-warn'; ?>">
                    <?php if ( $has_image ): ?>
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e( 'Has featured image', 'news-seo-helper' ); ?>
                    <?php else: ?>
                        <span class="dashicons dashicons-warning"></span>
                        <?php esc_html_e( 'No featured image', 'news-seo-helper' ); ?>
                    <?php endif; ?>
                </div>
                <div class="gnh-preview-links">
                    <a href="https://search.google.com/test/rich-results?url=<?php echo urlencode( $url ); ?>" target="_blank">Google Rich Results</a>
                    <a href="https://developers.facebook.com/tools/debug/?q=<?php echo urlencode( $url ); ?>" target="_blank">Facebook</a>
                    <a href="https://cards-dev.twitter.com/validator" target="_blank">X/Twitter</a>
                    <a href="https://www.linkedin.com/post-inspector/inspect/<?php echo urlencode( $url ); ?>" target="_blank">LinkedIn</a>
                    <a href="https://metatags.io/?url=<?php echo urlencode( $url ); ?>" target="_blank">MetaTags.io</a>
                </div>
            </div>
            <div class="gnh-preview-thumb">
                <?php echo wp_kses_post( $thumb_html ); ?>
            </div>
        </div>
        <?php
    }
}
