<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GNH_Post_SEO — per-content SEO controls (posts and pages).
 *
 * Adds a metabox on supported edit screens with:
 *  - noindex / nofollow toggles
 *  - Custom SEO title override
 *  - Custom meta description override
 */
class GNH_Post_SEO {

    const META_NOINDEX  = '_gnh_noindex';
    const META_NOFOLLOW = '_gnh_nofollow';
    const META_TITLE    = '_gnh_seo_title';
    const META_DESC     = '_gnh_seo_desc';

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_metabox' ] );
        add_action( 'save_post',      [ $this, 'save_meta' ], 10, 2 );
        add_action( 'wp_head',        [ $this, 'output_robots' ], 1 );
    }

    /**
     * Post types that get the SEO metabox and front-end meta/OG integration.
     *
     * @return string[]
     */
    public static function seo_post_types(): array {
        return (array) apply_filters( 'gnh_seo_post_types', [ 'post', 'page' ] );
    }

    // ── Metabox ───────────────────────────────────────────────────────────────

    public function add_metabox(): void {
        foreach ( self::seo_post_types() as $post_type ) {
            if ( ! post_type_exists( $post_type ) ) {
                continue;
            }
            add_meta_box(
                'gnh-post-seo',
                '<span class="dashicons dashicons-rss" style="color:#e8612d;vertical-align:middle;margin-right:4px;"></span> ' . esc_html__( 'Google News Helper — SEO', 'google-news-helper' ),
                [ $this, 'render_metabox' ],
                $post_type,
                'normal',
                'high'
            );
        }
    }

    public function render_metabox( WP_Post $post ): void {
        wp_nonce_field( 'gnh_post_seo_save', 'gnh_post_seo_nonce' );

        $noindex  = (bool) get_post_meta( $post->ID, self::META_NOINDEX,  true );
        $nofollow = (bool) get_post_meta( $post->ID, self::META_NOFOLLOW, true );
        $title    = (string) get_post_meta( $post->ID, self::META_TITLE,  true );
        $desc     = (string) get_post_meta( $post->ID, self::META_DESC,   true );
        ?>
        <style>
            .gnh-meta-section { margin-bottom: 16px; }
            .gnh-meta-section label { font-weight: 600; display: block; margin-bottom: 4px; }
            .gnh-meta-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
            .gnh-meta-row input[type=checkbox] { width: 16px; height: 16px; }
            .gnh-char-count { font-size: 11px; color: #888; margin-top: 2px; }
            .gnh-char-ok   { color: #1e7e34; }
            .gnh-char-warn { color: #b91c1c; }
        </style>

        <!-- Robots -->
        <div class="gnh-meta-section">
            <label><?php esc_html_e( 'Search Engine Visibility', 'google-news-helper' ); ?></label>
            <div class="gnh-meta-row">
                <input type="checkbox" id="gnh-noindex" name="gnh_noindex" value="1" <?php checked( $noindex ); ?>>
                <label for="gnh-noindex" style="font-weight:400;">
                    <?php
                    if ( $post->post_type === 'post' ) {
                        esc_html_e( 'noindex — hide this post from Google (and Google News)', 'google-news-helper' );
                    } else {
                        esc_html_e( 'noindex — hide this page from Google search', 'google-news-helper' );
                    }
                    ?>
                </label>
            </div>
            <div class="gnh-meta-row">
                <input type="checkbox" id="gnh-nofollow" name="gnh_nofollow" value="1" <?php checked( $nofollow ); ?>>
                <label for="gnh-nofollow" style="font-weight:400;">
                    <?php
                    if ( $post->post_type === 'post' ) {
                        esc_html_e( 'nofollow — tell Google not to follow links in this post', 'google-news-helper' );
                    } else {
                        esc_html_e( 'nofollow — tell Google not to follow links on this page', 'google-news-helper' );
                    }
                    ?>
                </label>
            </div>
            <?php if ( $noindex ) : ?>
            <p style="margin:4px 0 0;padding:6px 10px;background:#fef2f2;border-left:3px solid #b91c1c;font-size:12px;color:#7f1d1d;">
                <?php
                if ( $post->post_type === 'post' ) {
                    esc_html_e( 'This post is set to noindex and will not appear in Google or Google News.', 'google-news-helper' );
                } else {
                    esc_html_e( 'This page is set to noindex and should not appear in Google search.', 'google-news-helper' );
                }
                ?>
            </p>
            <?php endif; ?>
        </div>

        <!-- Custom title -->
        <div class="gnh-meta-section">
            <label for="gnh-seo-title"><?php esc_html_e( 'SEO title (Google result title)', 'google-news-helper' ); ?></label>
            <input type="text" id="gnh-seo-title" name="gnh_seo_title" class="large-text"
                value="<?php echo esc_attr( $title ); ?>"
                placeholder="<?php echo esc_attr( get_the_title( $post ) ); ?>">
            <p class="gnh-char-count" id="gnh-title-count">
                <?php
                $tlen = mb_strlen( $title ?: get_the_title( $post ) );
                if ( $post->post_type === 'post' ) {
                    $cls = ( $tlen >= 30 && $tlen <= 110 ) ? 'gnh-char-ok' : 'gnh-char-warn';
                    /* translators: %d: character count */
                    printf( '<span class="%1$s">%2$d chars</span> — %3$s', esc_attr( $cls ), $tlen, esc_html__( 'Google News: 30–110 recommended', 'google-news-helper' ) );
                } else {
                    $cls = ( $tlen >= 0 && $tlen <= 70 ) ? 'gnh-char-ok' : 'gnh-char-warn';
                    /* translators: %d: character count */
                    printf( '<span class="%1$s">%2$d chars</span> — %3$s', esc_attr( $cls ), $tlen, esc_html__( 'Pages: ~50–60 characters typical', 'google-news-helper' ) );
                }
                ?>
            </p>
            <p class="description" style="margin-top:6px;">
                <?php esc_html_e( 'When filled, overrides the SEO title when a supported SEO plugin is active.', 'google-news-helper' ); ?>
            </p>
        </div>

        <!-- Custom description -->
        <div class="gnh-meta-section">
            <label for="gnh-seo-desc"><?php esc_html_e( 'Meta description (Google snippet text)', 'google-news-helper' ); ?></label>
            <textarea id="gnh-seo-desc" name="gnh_seo_desc" class="large-text" rows="3"
                placeholder="<?php esc_attr_e( 'Leave blank to use this plugin’s auto excerpt, or your SEO plugin’s description if set there', 'google-news-helper' ); ?>"><?php echo esc_textarea( $desc ); ?></textarea>
            <p class="gnh-char-count" id="gnh-desc-count">
                <?php
                $dlen = mb_strlen( $desc );
                $cls  = ( $dlen === 0 || ( $dlen >= 50 && $dlen <= 160 ) ) ? 'gnh-char-ok' : 'gnh-char-warn';
                printf( '<span class="%s">%d chars</span> — recommended: 50–160', esc_attr( $cls ), $dlen );
                ?>
            </p>
            <p class="description" style="margin-top:6px;">
                <?php esc_html_e( 'When this field is filled, it overrides the meta description when a supported SEO plugin is active.', 'google-news-helper' ); ?>
            </p>
        </div>

        <script>
        (function() {
            var gnhIsPost = <?php echo $post->post_type === 'post' ? 'true' : 'false'; ?>;
            function countChars(inputId, countId, min, max) {
                var el = document.getElementById(inputId);
                var ct = document.getElementById(countId);
                if (!el || !ct) return;
                el.addEventListener('input', function() {
                    var len = el.value.length;
                    var ok  = (len === 0 || (len >= min && len <= max));
                    ct.querySelector('span').className = ok ? 'gnh-char-ok' : 'gnh-char-warn';
                    ct.querySelector('span').textContent = len + ' chars';
                });
            }
            countChars('gnh-seo-title', 'gnh-title-count', gnhIsPost ? 30 : 0, gnhIsPost ? 110 : 70);
            countChars('gnh-seo-desc',  'gnh-desc-count',  50, 160);
        })();
        </script>
        <?php
    }

    public function save_meta( int $post_id, WP_Post $post ): void {
        if (
            ! isset( $_POST['gnh_post_seo_nonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gnh_post_seo_nonce'] ) ), 'gnh_post_seo_save' ) ||
            defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ||
            ! current_user_can( 'edit_post', $post_id ) ||
            ! in_array( $post->post_type, self::seo_post_types(), true )
        ) {
            return;
        }

        update_post_meta( $post_id, self::META_NOINDEX,  isset( $_POST['gnh_noindex'] ) ? '1' : '' );
        update_post_meta( $post_id, self::META_NOFOLLOW, isset( $_POST['gnh_nofollow'] ) ? '1' : '' );

        $title = sanitize_text_field( wp_unslash( $_POST['gnh_seo_title'] ?? '' ) );
        $desc  = sanitize_textarea_field( wp_unslash( $_POST['gnh_seo_desc'] ?? '' ) );

        update_post_meta( $post_id, self::META_TITLE, $title );
        update_post_meta( $post_id, self::META_DESC,  $desc );
    }

    // ── Front-end: output robots meta ─────────────────────────────────────────

    public function output_robots(): void {
        if ( ! is_singular( self::seo_post_types() ) ) {
            return;
        }

        global $post;
        if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::seo_post_types(), true ) ) {
            return;
        }

        $noindex  = (bool) get_post_meta( $post->ID, self::META_NOINDEX,  true );
        $nofollow = (bool) get_post_meta( $post->ID, self::META_NOFOLLOW, true );

        if ( ! $noindex && ! $nofollow ) {
            return;
        }

        $directives = [];
        if ( $noindex )  { $directives[] = 'noindex'; }
        if ( $nofollow ) { $directives[] = 'nofollow'; }

        printf(
            '<meta name="robots" content="%s">' . "\n",
            esc_attr( implode( ', ', $directives ) )
        );
    }

    // ── Static helpers used by GNH_Meta_Tags ─────────────────────────────────

    public static function get_title( int $post_id ): string {
        return (string) get_post_meta( $post_id, self::META_TITLE, true );
    }

    public static function get_desc( int $post_id ): string {
        return (string) get_post_meta( $post_id, self::META_DESC, true );
    }

    public static function is_noindex( int $post_id ): bool {
        return (bool) get_post_meta( $post_id, self::META_NOINDEX, true );
    }
}
