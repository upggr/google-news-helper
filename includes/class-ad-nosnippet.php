<?php
/**
 * Fixes ad banners interfering with Google News thumbnail selection.
 *
 * Two things happen on singular post pages:
 *  1. Any Elementor image widget linking to an EXTERNAL domain (= ad) gets:
 *       - data-nosnippet on its container
 *       - fetchpriority="low" forced on its <img> (removes "high" if present)
 *  2. The actual post featured image gets fetchpriority="high" injected so
 *     Google News sees it as the most prominent image on the page.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GNH_Ad_Nosnippet {

    public function __construct() {
        add_action( 'template_redirect', [ $this, 'maybe_buffer' ], 1 );
    }

    public function maybe_buffer(): void {
        if ( ! get_option( 'gnh_enabled', true ) ) {
            return;
        }
        if ( is_admin() || wp_doing_ajax() ) {
            return;
        }
        if ( ! is_singular( 'post' ) ) {
            return;
        }

        ob_start( [ $this, 'process_output' ] );
    }

    public function process_output( string $html ): string {
        if ( ! is_string( $html ) || $html === '' ) {
            return $html;
        }

        $site_host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $featured_url = $this->get_featured_image_url();

        // ── 1. Fix ad banners ─────────────────────────────────────────────────
        // Match Elementor image widget blocks (3 nested divs deep)
        $html = preg_replace_callback(
            '/<div([^>]*class="[^"]*elementor-widget-image[^"]*"[^>]*)>(.*?)<\/div>\s*<\/div>\s*<\/div>/si',
            static function ( array $m ) use ( $site_host ): string {
                $block = $m[0];

                if ( ! preg_match( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $block, $link_m ) ) {
                    return $block;
                }

                $link_host = (string) wp_parse_url( $link_m[1], PHP_URL_HOST );

                if ( $link_host && $link_host !== $site_host && strpos( $link_host, $site_host ) === false ) {
                    // Mark container as non-snippet
                    $block = preg_replace(
                        '/(<div[^>]*class="[^"]*elementor-widget-container[^"]*"[^>]*)(>)/i',
                        '$1 data-nosnippet$2',
                        $block,
                        1
                    ) ?? $block;

                    // Remove fetchpriority="high" and replace with low
                    $block = preg_replace( '/\s*fetchpriority=["\']high["\']/i', '', $block ) ?? $block;
                    $block = preg_replace(
                        '/(<img\s)/i',
                        '$1fetchpriority="low" ',
                        $block,
                        1
                    ) ?? $block;
                }

                return $block;
            },
            $html
        ) ?? $html;

        // ── 2. Boost the actual featured image ────────────────────────────────
        if ( $featured_url ) {
            $escaped = preg_quote( $featured_url, '/' );

            // Find the <img> tag that contains the featured image URL and ensure fetchpriority=high
            $html = preg_replace_callback(
                '/(<img\s[^>]*src=["\'])(' . $escaped . ')(["\'][^>]*>)/i',
                static function ( array $m ): string {
                    $tag = $m[1] . $m[2] . $m[3];
                    // Remove any existing fetchpriority attr first
                    $tag = preg_replace( '/\s*fetchpriority=["\'][^"\']*["\']/i', '', $tag ) ?? $tag;
                    // Inject fetchpriority="high" right after <img
                    return preg_replace( '/(<img\s)/i', '$1fetchpriority="high" ', $tag, 1 ) ?? $tag;
                },
                $html
            ) ?? $html;
        }

        return $html;
    }

    private function get_featured_image_url(): string {
        $post_id = get_the_ID();
        if ( ! $post_id || ! has_post_thumbnail( $post_id ) ) {
            return '';
        }

        $thumb_id = get_post_thumbnail_id( $post_id );
        $src      = wp_get_attachment_image_src( (int) $thumb_id, 'full' );

        return ( $src && ! empty( $src[0] ) ) ? (string) $src[0] : '';
    }
}
