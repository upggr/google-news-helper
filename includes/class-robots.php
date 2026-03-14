<?php
/**
 * Manages robots.txt rules for Google News bot access,
 * and verifies Googlebot-News can reach post pages.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GNH_Robots {

    public function __construct() {
        add_filter( 'robots_txt', [ $this, 'add_rules' ], 10, 2 );
        $this->maybe_bypass_cache_for_crawlers();
    }

    /**
     * If a social/news crawler is detected, tell cache plugins to skip
     * serving a cached version so they always get a fresh full response.
     */
    private function maybe_bypass_cache_for_crawlers(): void {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ( empty( $ua ) ) {
            return;
        }

        $crawlers = [
            'facebookexternalhit',
            'Facebot',
            'Twitterbot',
            'LinkedInBot',
            'WhatsApp',
            'Googlebot-News',
            'Slackbot',
            'TelegramBot',
        ];

        foreach ( $crawlers as $bot ) {
            if ( stripos( $ua, $bot ) !== false ) {
                // Cache Enabler: define constant it checks
                if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                    define( 'DONOTCACHEPAGE', true );
                }
                // W3 Total Cache / WP Super Cache compat
                if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
                    define( 'DONOTCACHEOBJECT', true );
                }
                break;
            }
        }
    }

    /**
     * Append Google News-friendly rules to the virtual robots.txt.
     */
    public function add_rules( string $output, bool $public ): string {
        if ( ! $public ) {
            return $output;
        }

        $sitemap_url = home_url( '/news-sitemap.xml' );

        $additions  = "\n# Google News Helper\n";
        $additions .= "User-agent: Googlebot-News\n";
        $additions .= "Allow: /\n";
        $additions .= "\n";
        $additions .= "User-agent: Googlebot\n";
        $additions .= "Allow: /wp-content/uploads/\n";
        $additions .= "\n";
        $additions .= "Sitemap: " . esc_url_raw( $sitemap_url ) . "\n";

        return $output . $additions;
    }

    /**
     * Fetch a URL as Googlebot-News and return status + redirect info.
     *
     * @return array{reachable: bool, code: int, final_url: string, error: string}
     */
    public static function test_googlebot_access( string $url ): array {
        $response = wp_remote_get( $url, [
            'timeout'    => 15,
            'user-agent' => 'Mozilla/5.0 (compatible; Googlebot-News; +http://www.google.com/bot.html)',
            'sslverify'  => false,
            'redirection'=> 5,
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'reachable' => false,
                'code'      => 0,
                'final_url' => $url,
                'error'     => $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        return [
            'reachable' => $code >= 200 && $code < 400,
            'code'      => $code,
            'final_url' => $url,
            'error'     => '',
        ];
    }
}
