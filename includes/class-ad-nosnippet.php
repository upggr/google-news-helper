<?php
/**
 * Adds data-nosnippet to advertiser image containers so Google News
 * does not pick ad banners as the article thumbnail.
 *
 * Strategy: on singular post pages, buffer the output and add
 * data-nosnippet to any Elementor image widget whose <a> link points
 * to an external domain (i.e. not this site) — those are ads.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GNH_Ad_Nosnippet {

    public function __construct() {
        // Only needed on front-end singular post pages
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

        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );

        // Match Elementor image widget containers that contain a link to an external domain.
        // Pattern: <div class="...elementor-widget-image...">...</div> containing <a href="external">
        $html = preg_replace_callback(
            '/<div([^>]*class="[^"]*elementor-widget-image[^"]*"[^>]*)>(.*?)<\/div>\s*<\/div>\s*<\/div>/si',
            static function ( array $m ) use ( $site_host ): string {
                $block = $m[0];

                // Find the first <a href="..."> inside the block
                if ( ! preg_match( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $block, $link_m ) ) {
                    return $block; // no link — leave alone
                }

                $href      = $link_m[1];
                $link_host = wp_parse_url( $href, PHP_URL_HOST );

                // If link goes to an external site → it's an ad
                if ( $link_host && $link_host !== $site_host && strpos( $link_host, $site_host ) === false ) {
                    // Add data-nosnippet to the outer elementor-widget-container div
                    $block = preg_replace(
                        '/(<div[^>]*class="[^"]*elementor-widget-container[^"]*"[^>]*)(>)/i',
                        '$1 data-nosnippet$2',
                        $block,
                        1
                    );
                }

                return $block;
            },
            $html
        );

        return (string) $html;
    }
}
