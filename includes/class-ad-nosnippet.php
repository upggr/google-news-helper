<?php
/**
 * Two-layer approach to fix Google News thumbnail selection:
 *
 * LAYER 1: Serve clean minimal HTML to Googlebot
 * When Googlebot crawls, it gets a purpose-built minimal page with only:
 *   - Essential <head> meta tags (OG, JSON-LD, canonical, etc.)
 *   - The post featured image with fetchpriority="high"
 *   - The post title and body text
 * No theme stylesheets, ads, or logo distractions.
 *
 * LAYER 2: Mark ads with data-nosnippet on full pages
 * For all pages (including when Google News crawls the full page),
 * we add data-nosnippet to Elementor image widgets that link to external domains.
 * This tells Google to ignore ads, so featured image is prioritized.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GNH_Ad_Nosnippet {

    public function __construct() {
        // Layer 1: Serve clean page to Googlebot
        add_filter( 'template_include', [ $this, 'maybe_serve_googlebot_via_filter' ], 1 );

        // Layer 2: Mark ads with data-nosnippet as fallback
        add_action( 'template_redirect', [ $this, 'maybe_buffer_output' ], 2 );
    }

    public function maybe_serve_googlebot_via_filter( string $template ): string {
        if ( ! get_option( 'gnh_enabled', true ) ) {
            return $template;
        }
        if ( is_admin() || wp_doing_ajax() ) {
            return $template;
        }
        if ( ! is_singular( 'post' ) ) {
            return $template;
        }
        if ( ! $this->is_googlebot() ) {
            return $template;
        }

        $this->serve_clean_page();
        // This function calls exit, so we never reach here
        return $template;
    }

    public function maybe_buffer_output(): void {
        if ( ! get_option( 'gnh_enabled', true ) ) {
            return;
        }
        if ( is_admin() || wp_doing_ajax() ) {
            return;
        }
        if ( ! is_singular( 'post' ) ) {
            return;
        }

        // Start output buffering to add data-nosnippet to ad images
        ob_start( [ $this, 'process_output_nosnippet' ] );
    }

    public function process_output_nosnippet( string $html ): string {
        if ( ! is_string( $html ) || $html === '' ) {
            return $html;
        }

        $site_host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $featured_url = $this->get_featured_image_url();

        // Find Elementor image widget blocks linking to external domains (ads)
        $html = preg_replace_callback(
            '/<div([^>]*class="[^"]*elementor-widget-image[^"]*"[^>]*)>(.*?)<\/div>\s*<\/div>\s*<\/div>/si',
            static function ( array $m ) use ( $site_host ): string {
                $block = $m[0];

                if ( ! preg_match( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $block, $link_m ) ) {
                    return $block;
                }

                $link_host = (string) wp_parse_url( $link_m[1], PHP_URL_HOST );

                // External link = ad or sponsored content
                if ( $link_host && $link_host !== $site_host && strpos( $link_host, $site_host ) === false ) {
                    // Add data-nosnippet to the container
                    $block = preg_replace(
                        '/(<div[^>]*class="[^"]*elementor-widget-container[^"]*"[^>]*)(>)/i',
                        '$1 data-nosnippet$2',
                        $block,
                        1
                    ) ?? $block;

                    // Set fetchpriority="low" on the image
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

        // Boost the featured image with high priority
        if ( $featured_url ) {
            $escaped = preg_quote( $featured_url, '/' );
            $html = preg_replace_callback(
                '/(<img\s[^>]*src=["\'])(' . $escaped . ')(["\'][^>]*>)/i',
                static function ( array $m ): string {
                    $tag = $m[1] . $m[2] . $m[3];
                    // Remove any existing fetchpriority
                    $tag = preg_replace( '/\s*fetchpriority=["\'][^"\']*["\']/i', '', $tag ) ?? $tag;
                    // Add fetchpriority="high"
                    return preg_replace( '/(<img\s)/i', '$1fetchpriority="high" ', $tag, 1 ) ?? $tag;
                },
                $html
            ) ?? $html;
        }

        return $html;
    }

    private function get_featured_image_url(): string {
        if ( ! is_singular( 'post' ) ) {
            return '';
        }
        $post_id = get_the_ID();
        if ( ! $post_id || ! has_post_thumbnail( $post_id ) ) {
            return '';
        }

        $thumb_id = get_post_thumbnail_id( $post_id );
        $src = wp_get_attachment_image_src( (int) $thumb_id, 'full' );

        return ( $src && ! empty( $src[0] ) ) ? (string) $src[0] : '';
    }

    public function maybe_serve_googlebot(): void {
        // Legacy method kept for compatibility
        return;
    }

    private function is_googlebot(): bool {
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
        // Detect all Google crawlers that should see clean minimal page:
        // - Googlebot/2.1 (main crawler)
        // - Googlebot-News (News indexer)
        // - Googlebot-Image (Image indexer - important for Google News thumbnails!)
        // - Google-InspectionTool (Preview tool)
        // - GoogleOther (Other Google services)
        return strpos( $ua, 'googlebot' ) !== false
            || strpos( $ua, 'google-inspectiontool' ) !== false
            || strpos( $ua, 'googleother' ) !== false;
    }

    private function get_image_mime( string $url ): string {
        $ext = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION ) );
        $map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
            'avif' => 'image/avif',
        ];
        return $map[ $ext ] ?? 'image/jpeg';
    }

    private function serve_clean_page(): void {
        global $post;

        if ( ! $post instanceof WP_Post ) {
            return;
        }

        $title       = get_the_title( $post );
        $content     = apply_filters( 'the_content', $post->post_content );
        $url         = get_permalink( $post );
        $site_name   = get_bloginfo( 'name' );
        $pub_date    = get_the_date( 'c', $post );
        $mod_date    = get_the_modified_date( 'c', $post );
        $author      = get_the_author_meta( 'display_name', $post->post_author );
        $excerpt     = $post->post_excerpt ?: wp_trim_words( wp_strip_all_tags( $post->post_content ), 40, '...' );
        $locale      = get_locale();
        $categories  = get_the_category( $post->ID );
        $section     = ! empty( $categories ) ? esc_html( $categories[0]->name ) : '';
        $tags        = get_the_tags( $post->ID );

        // Featured image
        $image_url = '';
        $image_w   = 0;
        $image_h   = 0;
        if ( has_post_thumbnail( $post->ID ) ) {
            $src = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'full' );
            if ( $src ) {
                $image_url = $src[0];
                $image_w   = (int) $src[1];
                $image_h   = (int) $src[2];
            }
        }

        // Publisher logo
        $publisher_logo = '';
        $logo_id = get_theme_mod( 'custom_logo' );
        if ( $logo_id ) {
            $logo_src = wp_get_attachment_image_src( $logo_id, 'full' );
            if ( $logo_src ) {
                $publisher_logo = $logo_src[0];
            }
        }

        // JSON-LD schema
        $schema = [
            '@context'         => 'https://schema.org',
            '@type'            => 'NewsArticle',
            'headline'         => mb_substr( $title, 0, 110 ),
            'datePublished'    => $pub_date,
            'dateModified'     => $mod_date,
            'author'           => [ '@type' => 'Person', 'name' => $author ],
            'publisher'        => [ '@type' => 'Organization', 'name' => $site_name ],
            'description'      => $excerpt,
            'mainEntityOfPage' => [ '@type' => 'WebPage', '@id' => $url ],
        ];
        if ( $image_url ) {
            $schema['image'] = [ $image_url ];
        }
        if ( $publisher_logo ) {
            $schema['publisher']['logo'] = [ '@type' => 'ImageObject', 'url' => $publisher_logo ];
        }
        if ( $section ) {
            $schema['articleSection'] = $section;
        }
        if ( is_array( $tags ) && ! empty( $tags ) ) {
            $schema['keywords'] = implode( ', ', array_slice( array_map( static fn( $t ) => $t->name, $tags ), 0, 10 ) );
        }

        // Keywords meta
        $keywords = ( is_array( $tags ) && ! empty( $tags ) )
            ? implode( ', ', array_slice( array_map( static fn( $t ) => $t->name, $tags ), 0, 10 ) )
            : '';

        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );

        // Output clean minimal HTML
        echo '<!DOCTYPE html>' . "\n";
        echo '<html lang="' . esc_attr( str_replace( '_', '-', $locale ) ) . '">' . "\n";
        echo '<head>' . "\n";
        echo '<meta charset="UTF-8">' . "\n";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
        echo '<title>' . esc_html( $title ) . ' – ' . esc_html( $site_name ) . '</title>' . "\n";
        echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
        echo '<meta name="robots" content="max-image-preview:large">' . "\n";

        // OG tags
        echo '<meta property="og:type" content="article">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_attr( $url ) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr( $excerpt ) . '">' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '">' . "\n";
        echo '<meta property="og:locale" content="' . esc_attr( $locale ) . '">' . "\n";
        echo '<meta property="article:published_time" content="' . esc_attr( $pub_date ) . '">' . "\n";
        echo '<meta property="article:modified_time" content="' . esc_attr( $mod_date ) . '">' . "\n";
        echo '<meta property="article:author" content="' . esc_attr( $author ) . '">' . "\n";
        if ( $section ) {
            echo '<meta property="article:section" content="' . esc_attr( $section ) . '">' . "\n";
        }
        if ( $image_url ) {
            echo '<meta property="og:image" content="' . esc_attr( $image_url ) . '">' . "\n";
            echo '<meta property="og:image:type" content="' . esc_attr( $this->get_image_mime( $image_url ) ) . '">' . "\n";
            echo '<meta property="og:image:width" content="' . esc_attr( (string) $image_w ) . '">' . "\n";
            echo '<meta property="og:image:height" content="' . esc_attr( (string) $image_h ) . '">' . "\n";
            echo '<meta property="og:image:alt" content="' . esc_attr( $title ) . '">' . "\n";
        }

        // Twitter card
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
        if ( $image_url ) {
            echo '<meta name="twitter:image" content="' . esc_attr( $image_url ) . '">' . "\n";
        }

        // Google News meta
        if ( $keywords ) {
            echo '<meta name="news_keywords" content="' . esc_attr( $keywords ) . '">' . "\n";
        }
        echo '<meta name="author" content="' . esc_attr( $author ) . '">' . "\n";

        // JSON-LD
        echo '<script type="application/ld+json">' . "\n";
        echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        echo "\n</script>\n";

        echo '</head>' . "\n";
        echo '<body>' . "\n";
        echo '<article>' . "\n";
        echo '<h1>' . esc_html( $title ) . '</h1>' . "\n";

        if ( $image_url ) {
            echo '<figure>' . "\n";
            echo '<img src="' . esc_url( $image_url ) . '" width="' . esc_attr( (string) $image_w ) . '" height="' . esc_attr( (string) $image_h ) . '" alt="' . esc_attr( $title ) . '" fetchpriority="high">' . "\n";
            echo '</figure>' . "\n";
        }

        echo $content . "\n";
        echo '</article>' . "\n";
        echo '</body>' . "\n";
        echo '</html>';

        exit;
    }
}
