<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bulk editor for category / tag search descriptions.
 *
 * The per-term field lives on the native taxonomy screen (GNH_Term_SEO), which is
 * where people look first but costs one page load per term. This screen lists every
 * term at once so a site's categories can be filled in during a single sitting.
 */
class GNH_Term_SEO_Admin {

    public const NONCE_ACTION = 'gnh_bulk_term_desc';

    public function __construct() {
        add_action( 'admin_post_gnh_save_term_descs', [ $this, 'handle_save' ] );
    }

    public static function render_static(): void {
        ( new self() )->render();
    }

    /**
     * Terms shown in the bulk editor, grouped by taxonomy.
     *
     * @return array<string, WP_Term[]>
     */
    private function get_grouped_terms(): array {
        $grouped = [];

        foreach ( GNH_Term_SEO::seo_taxonomies() as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }

            $terms = get_terms(
                [
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => false,
                    'orderby'    => 'name',
                ]
            );

            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }

            $grouped[ $taxonomy ] = $terms;
        }

        return $grouped;
    }

    public function handle_save(): void {
        if ( ! current_user_can( 'manage_categories' ) ) {
            wp_die( esc_html__( 'You do not have permission to edit term descriptions.', 'news-seo-helper' ) );
        }

        check_admin_referer( self::NONCE_ACTION );

        $submitted = isset( $_POST['gnh_term_desc'] ) && is_array( $_POST['gnh_term_desc'] )
            ? wp_unslash( $_POST['gnh_term_desc'] )
            : [];

        $saved = 0;

        foreach ( $submitted as $term_id => $value ) {
            $term_id = (int) $term_id;
            if ( $term_id <= 0 ) {
                continue;
            }

            $term = get_term( $term_id );
            if ( ! $term instanceof WP_Term || ! in_array( $term->taxonomy, GNH_Term_SEO::seo_taxonomies(), true ) ) {
                continue;
            }

            $clean = GNH_Term_SEO::sanitize( $value );

            if ( $clean === '' ) {
                delete_term_meta( $term_id, GNH_Term_SEO::META_DESC );
            } else {
                update_term_meta( $term_id, GNH_Term_SEO::META_DESC, $clean );
            }

            $saved++;
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'        => 'gnh-term-descriptions',
                    'gnh-updated' => $saved,
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_categories' ) ) {
            return;
        }

        $grouped = $this->get_grouped_terms();
        ?>
        <div class="wrap gnh-wrap">
            <h1>
                <span class="dashicons dashicons-category" style="font-size:28px;line-height:1;vertical-align:middle;margin-right:6px;color:#e8612d;"></span>
                <?php esc_html_e( 'Category search descriptions', 'news-seo-helper' ); ?>
            </h1>

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only count for the success notice. ?>
            <?php if ( isset( $_GET['gnh-updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        printf(
                            /* translators: %d: number of terms saved. */
                            esc_html__( 'Saved %d descriptions.', 'news-seo-helper' ),
                            isset( $_GET['gnh-updated'] ) ? (int) $_GET['gnh-updated'] : 0 // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="gnh-card">
                <p class="description" style="margin-bottom:4px;">
                    <?php esc_html_e( 'This is the text Google shows under the category title in search results. Without it, Google invents a snippet from whatever text appears first on the page — often a banner alt text or menu labels, identical across every category.', 'news-seo-helper' ); ?>
                </p>
                <p class="description">
                    <?php esc_html_e( 'Aim for 50–160 characters, describing what a reader finds in this category. Changes can take days or weeks to appear in Google, and Google may still choose its own text for some searches.', 'news-seo-helper' ); ?>
                </p>
            </div>

            <?php if ( empty( $grouped ) ) : ?>
                <div class="gnh-card"><p><?php esc_html_e( 'No categories or tags found.', 'news-seo-helper' ); ?></p></div>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="gnh_save_term_descs">
                    <?php wp_nonce_field( self::NONCE_ACTION ); ?>

                    <?php foreach ( $grouped as $taxonomy => $terms ) :
                        $tax_obj   = get_taxonomy( $taxonomy );
                        $tax_label = $tax_obj ? $tax_obj->labels->name : $taxonomy;
                        ?>
                        <div class="gnh-card">
                            <h2><?php echo esc_html( $tax_label ); ?></h2>
                            <table class="widefat striped gnh-term-table">
                                <thead>
                                    <tr>
                                        <th style="width:22%;"><?php esc_html_e( 'Category', 'news-seo-helper' ); ?></th>
                                        <th><?php esc_html_e( 'Google search description', 'news-seo-helper' ); ?></th>
                                        <th style="width:70px;text-align:right;"><?php esc_html_e( 'Chars', 'news-seo-helper' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( $terms as $term ) :
                                    $stored   = get_term_meta( $term->term_id, GNH_Term_SEO::META_DESC, true );
                                    $stored   = is_string( $stored ) ? $stored : '';
                                    $inherits = $stored === '' && trim( (string) $term->description ) !== '';
                                    $field_id = 'gnh-term-' . (int) $term->term_id;
                                    ?>
                                    <tr>
                                        <td>
                                            <label for="<?php echo esc_attr( $field_id ); ?>">
                                                <strong><?php echo esc_html( $term->name ); ?></strong>
                                            </label>
                                            <div style="color:#787c82;font-size:11px;margin-top:2px;">
                                                <?php
                                                printf(
                                                    /* translators: %d: number of published posts in this category. */
                                                    esc_html__( '%d posts', 'news-seo-helper' ),
                                                    (int) $term->count
                                                );
                                                ?>
                                            </div>
                                            <?php if ( $stored === '' && ! $inherits ) : ?>
                                                <div style="color:#b32d2e;font-size:11px;margin-top:2px;">
                                                    &#9888; <?php esc_html_e( 'No description', 'news-seo-helper' ); ?>
                                                </div>
                                            <?php elseif ( $inherits ) : ?>
                                                <div style="color:#996800;font-size:11px;margin-top:2px;">
                                                    <?php esc_html_e( 'Using taxonomy description', 'news-seo-helper' ); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <textarea
                                                id="<?php echo esc_attr( $field_id ); ?>"
                                                name="gnh_term_desc[<?php echo (int) $term->term_id; ?>]"
                                                rows="2"
                                                class="large-text gnh-term-desc"
                                                maxlength="320"
                                                placeholder="<?php echo esc_attr( $inherits ? wp_strip_all_tags( $term->description ) : __( 'Describe what readers find in this category…', 'news-seo-helper' ) ); ?>"
                                            ><?php echo esc_textarea( $stored ); ?></textarea>
                                        </td>
                                        <td style="text-align:right;vertical-align:middle;">
                                            <span class="gnh-char-count" style="font-variant-numeric:tabular-nums;color:#787c82;">
                                                <?php echo (int) mb_strlen( $stored ); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>

                    <?php submit_button( __( 'Save all descriptions', 'news-seo-helper' ) ); ?>
                </form>

                <script>
                ( function () {
                    document.querySelectorAll( '.gnh-term-desc' ).forEach( function ( el ) {
                        var row   = el.closest( 'tr' );
                        var count = row ? row.querySelector( '.gnh-char-count' ) : null;
                        if ( ! count ) {
                            return;
                        }
                        var paint = function () {
                            var n = el.value.length;
                            count.textContent = n;
                            count.style.color = ( n === 0 ) ? '#b32d2e'
                                : ( n < 50 || n > 160 ) ? '#996800'
                                : '#00794b';
                        };
                        el.addEventListener( 'input', paint );
                        paint();
                    } );
                }() );
                </script>
            <?php endif; ?>
        </div>
        <?php
    }
}
