<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Meta description for category / tag archives.
 *
 * Without this, archives ship no <meta name="description"> and Google builds the
 * search snippet out of whatever text it finds first on the page — often a banner
 * alt text or menu labels, which leaves every category sharing one identical,
 * meaningless snippet.
 */
class GNH_Term_SEO {

    public const META_DESC = '_gnh_term_desc';

    public function __construct() {
        foreach ( self::seo_taxonomies() as $taxonomy ) {
            add_action( "{$taxonomy}_edit_form_fields", [ $this, 'render_field' ], 10, 1 );
            add_action( "edited_{$taxonomy}",           [ $this, 'save_field' ],   10, 1 );
            add_action( "created_{$taxonomy}",          [ $this, 'save_field' ],   10, 1 );
        }

        // Direct output when no major SEO plugin owns the <head>.
        add_action( 'wp_head', [ $this, 'output_tags' ], 3 );

        // Hand our text to the major SEO plugins when one is active.
        add_filter( 'wpseo_metadesc',                [ $this, 'filter_seo_meta_description' ], 20, 1 );
        add_filter( 'rank_math/frontend/description', [ $this, 'filter_seo_meta_description' ], 20 );
        add_filter( 'aioseo_description',            [ $this, 'filter_seo_meta_description' ], 20, 1 );
    }

    /**
     * Taxonomies that get an SEO description field.
     *
     * @return string[]
     */
    public static function seo_taxonomies(): array {
        /**
         * Filter which taxonomies expose a Google News Helper description field.
         *
         * @param string[] $taxonomies Taxonomy slugs.
         */
        return (array) apply_filters( 'gnh_seo_taxonomies', [ 'category', 'post_tag' ] );
    }

    /**
     * Stored description for a term, falling back to the term's own description.
     */
    public static function get_desc( int $term_id ): string {
        $raw = get_term_meta( $term_id, self::META_DESC, true );
        $raw = is_string( $raw ) ? trim( wp_strip_all_tags( $raw ) ) : '';

        if ( $raw !== '' ) {
            return $raw;
        }

        $term = get_term( $term_id );
        if ( $term instanceof WP_Term && $term->description !== '' ) {
            $fallback = trim( wp_strip_all_tags( $term->description ) );
            $fallback = (string) preg_replace( '/\s+/', ' ', $fallback );
            if ( mb_strlen( $fallback ) > 320 ) {
                $fallback = mb_substr( $fallback, 0, 317 ) . '...';
            }
            return $fallback;
        }

        return '';
    }

    public static function sanitize( $value ): string {
        if ( ! is_string( $value ) ) {
            return '';
        }
        $value = sanitize_textarea_field( $value );
        $value = (string) preg_replace( '/\s+/', ' ', $value );
        $value = trim( $value );
        if ( mb_strlen( $value ) > 320 ) {
            $value = mb_substr( $value, 0, 320 );
        }
        return $value;
    }

    // ── Admin field on the term edit screen ──────────────────────────────────

    /**
     * @param WP_Term $term Term being edited.
     */
    public function render_field( $term ): void {
        if ( ! $term instanceof WP_Term ) {
            return;
        }

        $value = get_term_meta( $term->term_id, self::META_DESC, true );
        $value = is_string( $value ) ? $value : '';
        wp_nonce_field( 'gnh_term_seo_' . $term->term_id, 'gnh_term_seo_nonce' );
        ?>
        <tr class="form-field">
            <th scope="row">
                <label for="gnh_term_desc"><?php esc_html_e( 'Google search description', 'google-news-helper' ); ?></label>
            </th>
            <td>
                <textarea
                    id="gnh_term_desc"
                    name="gnh_term_desc"
                    rows="4"
                    class="large-text"
                    maxlength="320"
                    placeholder="<?php esc_attr_e( 'e.g. Local news, reports and daily coverage from our newsroom.', 'google-news-helper' ); ?>"
                ><?php echo esc_textarea( $value ); ?></textarea>
                <p class="description">
                    <?php esc_html_e( 'Shown under the title in Google results for this archive. Recommended 50–160 characters. If left empty, the taxonomy description above is used; if that is empty too, Google picks its own text from the page.', 'google-news-helper' ); ?>
                </p>
            </td>
        </tr>
        <?php
    }

    public function save_field( int $term_id ): void {
        if ( ! isset( $_POST['gnh_term_desc'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_categories' ) ) {
            return;
        }

        $nonce = isset( $_POST['gnh_term_seo_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['gnh_term_seo_nonce'] ) ) : '';
        if ( $nonce !== '' && ! wp_verify_nonce( $nonce, 'gnh_term_seo_' . $term_id ) ) {
            return;
        }

        $value = self::sanitize( wp_unslash( $_POST['gnh_term_desc'] ) );

        if ( $value === '' ) {
            delete_term_meta( $term_id, self::META_DESC );
        } else {
            update_term_meta( $term_id, self::META_DESC, $value );
        }
    }

    // ── Front-end output ─────────────────────────────────────────────────────

    /**
     * Description for the archive currently being viewed, or '' when not applicable.
     */
    private function current_archive_desc(): string {
        if ( ! get_option( 'gnh_enabled', true ) ) {
            return '';
        }

        if ( ! is_category() && ! is_tag() && ! is_tax( self::seo_taxonomies() ) ) {
            return '';
        }

        // Paged archives repeat the description; that is expected and matches
        // how SEO plugins behave, but skip it so paginated pages stay distinct.
        if ( is_paged() ) {
            return '';
        }

        $term = get_queried_object();
        if ( ! $term instanceof WP_Term ) {
            return '';
        }

        return self::get_desc( $term->term_id );
    }

    public function output_tags(): void {
        if ( $this->has_major_seo_plugin() ) {
            return;
        }

        $desc = $this->current_archive_desc();
        if ( $desc === '' ) {
            return;
        }

        $term = get_queried_object();
        $url  = $term instanceof WP_Term ? get_term_link( $term ) : '';
        $url  = is_string( $url ) ? $url : '';

        echo "\n<!-- Google News Helper: archive snippet -->\n";
        printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
        printf( '<meta property="og:type" content="website">' . "\n" );
        printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $term instanceof WP_Term ? $term->name : '' ) );
        printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $desc ) );
        if ( $url !== '' ) {
            printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $url ) );
        }
        printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
        printf( '<meta name="twitter:card" content="summary_large_image">' . "\n" );
        printf( '<meta name="twitter:description" content="%s">' . "\n", esc_attr( $desc ) );
        echo "<!-- /Google News Helper: archive snippet -->\n";
    }

    /**
     * @param mixed $description Previous meta description from an SEO plugin.
     * @return mixed
     */
    public function filter_seo_meta_description( $description ) {
        $desc = $this->current_archive_desc();
        return $desc !== '' ? $desc : $description;
    }

    private function has_major_seo_plugin(): bool {
        return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEOP_VERSION' );
    }
}
