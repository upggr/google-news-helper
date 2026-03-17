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
        // Use 'wp' hook which fires after query parsing but before other processing
        add_action( 'wp', [ $this, 'maybe_serve_googlebot_early' ], 1 );

        // Layer 2: Add CSS to hide non-featured images from Google
        add_action( 'wp_footer', [ $this, 'maybe_add_nosnippet_script' ], 999 );
    }

    public function maybe_serve_googlebot_early(): void {
        // Disabled: serving clean page doesn't work - Google News still picks logo
        // Now relying on data-nosnippet marking on full page instead
        return;
    }


    private function log_crawler_access( string $ip, string $ua, bool $is_bot, bool $unused ): void {
        // Log crawler requests to help identify IP ranges
        // Only log detected crawlers
        if ( $is_bot ) {
            $log_entry = sprintf(
                "[%s] IP: %s | Bot: %s | UA: %s | URL: %s\n",
                gmdate( 'Y-m-d H:i:s' ),
                $ip ?: 'UNKNOWN',
                $is_bot ? 'YES' : 'NO',
                substr( $ua, 0, 100 ), // First 100 chars of UA
                isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : ''
            );

            $log_file = GNH_PLUGIN_DIR . 'logs/crawler-access.log';
            $log_dir = dirname( $log_file );

            // Create logs directory if it doesn't exist
            if ( ! is_dir( $log_dir ) ) {
                wp_mkdir_p( $log_dir );
            }

            // Append to log file (with size limit to prevent excessive disk usage)
            if ( is_writable( $log_dir ) ) {
                // Keep log under 10MB by rotating if needed
                if ( file_exists( $log_file ) && filesize( $log_file ) > 10485760 ) {
                    rename( $log_file, $log_file . '.' . gmdate( 'Y-m-d-His' ) );
                }
                file_put_contents( $log_file, $log_entry, FILE_APPEND );
            }
        }
    }

    private function is_crawler_ip(): bool {
        // Get client IP
        $ip = $this->get_client_ip();
        if ( ! $ip ) {
            return false; // Default to showing ads if IP detection fails
        }

        // Known crawler IP ranges (Google, Bing, etc.)
        // These crawlers get the clean minimal page to ensure correct thumbnail selection
        $crawler_ranges = [
            '66.249.',    // Google Search bot
            '66.102.',    // Google Ads, Analytics
            '40.77.',     // Bing Search
            '207.46.',    // Bing Search
            '2001:4860:', // Google IPv6 (simplified check)
        ];

        foreach ( $crawler_ranges as $range ) {
            if ( strpos( $ip, $range ) === 0 ) {
                return true;
            }
        }

        return false;
    }

    private function get_client_ip(): string {
        $ip_keys = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR' ];

        foreach ( $ip_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ips = explode( ',', $_SERVER[ $key ] );
                $ip = trim( $ips[0] );
                // Validate IP
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '';
    }

    public static function get_crawler_logs(): string {
        $log_file = GNH_PLUGIN_DIR . 'logs/crawler-access.log';

        if ( ! file_exists( $log_file ) ) {
            return "No crawler logs found yet. They will appear as crawlers access the site.\n";
        }

        $content = file_get_contents( $log_file );
        if ( ! $content ) {
            return "Log file is empty.\n";
        }

        // Return last 100 lines
        $lines = explode( "\n", $content );
        $lines = array_slice( $lines, -100 );

        return implode( "\n", $lines );
    }

    public function maybe_add_nosnippet_script(): void {
        // Add inline JS to add data-nosnippet to all images except featured
        if ( ! get_option( 'gnh_enabled', true ) ) {
            return;
        }
        if ( is_admin() || wp_doing_ajax() ) {
            return;
        }
        if ( ! is_singular( 'post' ) ) {
            return;
        }

        $featured_url = $this->get_featured_image_url();
        if ( ! $featured_url ) {
            return;
        }

        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            const featured_src = ' . wp_json_encode( $featured_url ) . ';
            const images = document.querySelectorAll("img");
            images.forEach(img => {
                // Skip featured image
                if (img.src && img.src.includes(featured_src)) {
                    img.setAttribute("fetchpriority", "high");
                    return;
                }
                // Mark all others with data-nosnippet
                if (!img.hasAttribute("data-nosnippet")) {
                    img.setAttribute("data-nosnippet", "true");
                }
            });
        });
        </script>' . "\n";
    }

    public function process_output_nosnippet( string $html ): string {
        if ( ! is_string( $html ) || $html === '' ) {
            return $html;
        }

        $featured_url = $this->get_featured_image_url();
        error_log( 'GNH: process_output_nosnippet called. Featured URL: ' . $featured_url );

        // Mark ALL images except the featured image with data-nosnippet
        // This ensures Google can ONLY pick the featured image for the thumbnail
        try {
            $img_count = 0;
            $modified_count = 0;
            $html = preg_replace_callback(
                '/(<img\s[^>]*src=["\']([^"\']+)["\'][^>]*>)/i',
                static function ( array $m ) use ( $featured_url, &$img_count, &$modified_count ): string {
                    $img_count++;
                    $img_tag = $m[1];
                    $src = $m[2];

                    // Don't mark the featured image - we want Google to see it!
                    if ( $featured_url && strpos( $src, $featured_url ) !== false ) {
                        // Boost featured image priority
                        if ( strpos( $img_tag, 'fetchpriority' ) === false ) {
                            $img_tag = preg_replace( '/(<img\s)/i', '$1fetchpriority="high" ', $img_tag, 1 ) ?? $img_tag;
                        }
                        return $img_tag;
                    }

                    // Mark everything else with data-nosnippet
                    if ( strpos( $img_tag, 'data-nosnippet' ) === false ) {
                        $modified_count++;
                        $img_tag = preg_replace( '/\s*\/>/', ' data-nosnippet />', $img_tag ) ?? $img_tag;
                        $img_tag = preg_replace( '/\s*>/', ' data-nosnippet >', $img_tag ) ?? $img_tag;
                    }

                    return $img_tag;
                },
                $html
            ) ?? $html;
            error_log( "GNH: Found $img_count img tags, modified $modified_count with data-nosnippet" );
        } catch ( Exception $e ) {
            error_log( 'GNH: Error in process_output_nosnippet: ' . $e->getMessage() );
        }

        error_log( 'GNH: process_output_nosnippet returning HTML of length: ' . strlen( $html ) );
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
