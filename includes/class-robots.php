<?php
/**
 * Manages robots.txt rules for Google News and social/news crawlers,
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
     * Append Google News–friendly and social-preview crawler rules to the virtual robots.txt.
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

        // Explicit Allow for crawlers used for link previews (same family as maybe_bypass_cache_for_crawlers).
        $additions .= "\n# Social & messenger link previews\n";
        $additions .= "User-agent: facebookexternalhit\n";
        $additions .= "User-agent: Facebot\n";
        $additions .= "User-agent: Twitterbot\n";
        $additions .= "User-agent: LinkedInBot\n";
        $additions .= "User-agent: WhatsApp\n";
        $additions .= "User-agent: Slackbot\n";
        $additions .= "User-agent: TelegramBot\n";
        $additions .= "Allow: /\n";

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

    /**
     * Build admin health report for robots.txt (live HTTP fetch + heuristics).
     *
     * @return array{
     *   overall: string,
     *   overall_label: string,
     *   robots_url: string,
     *   body: string,
     *   fetch_error: string,
     *   checks: list<array{label: string, hint: string, status: string, result: string}>,
     *   optimizations: list<array{title: string, detail: string}>
     * }
     */
    public static function get_health_report(): array {
        $robots_url = home_url( '/robots.txt' );
        $public     = '1' === (string) get_option( 'blog_public' );
        $fetch      = self::fetch_live_robots();
        $body       = $fetch['body'];
        $code       = $fetch['code'];
        $fetch_err  = $fetch['error'];
        $ctype      = $fetch['content_type'];

        $physical = file_exists( ABSPATH . 'robots.txt' ) && is_readable( ABSPATH . 'robots.txt' );

        $checks         = [];
        $has_fail       = false;
        $has_warn       = false;
        $optimizations  = self::baseline_optimizations();
        $groups         = $body !== '' ? self::parse_robots_groups( $body ) : [];
        $social_blocked = $body !== '' && self::social_preview_agents_blocked( $groups );
        $star_blocked   = $body !== '' && self::wildcard_agent_blocks_root( $groups );

        // 1 — Search engine visibility.
        if ( ! $public ) {
            $has_fail = true;
            $checks[] = [
                'label'  => __( 'Search engine visibility', 'google-news-helper' ),
                'hint'   => __( 'Settings → Reading → “Discourage search engines from indexing this site”.', 'google-news-helper' ),
                'status' => 'fail',
                'result' => __( 'Site is set to discourage indexing (robots will block crawlers).', 'google-news-helper' ),
            ];
            $optimizations[] = [
                'title'  => __( 'Allow indexing when you are ready to go live', 'google-news-helper' ),
                'detail' => __( 'Uncheck “Discourage search engines” in Reading settings so WordPress serves a useful virtual robots.txt.', 'google-news-helper' ),
            ];
        } else {
            $checks[] = [
                'label'  => __( 'Search engine visibility', 'google-news-helper' ),
                'hint'   => '',
                'status' => 'pass',
                'result' => __( 'Indexing is allowed.', 'google-news-helper' ),
            ];
        }

        // 2 — HTTP fetch.
        if ( $fetch_err !== '' ) {
            $has_fail = true;
            $checks[] = [
                'label'  => __( 'Live robots.txt fetch', 'google-news-helper' ),
                'hint'   => $robots_url,
                'status' => 'fail',
                'result' => $fetch_err,
            ];
        } elseif ( $code < 200 || $code >= 400 ) {
            $has_fail = true;
            $checks[] = [
                'label'  => __( 'HTTP status', 'google-news-helper' ),
                'hint'   => $robots_url,
                'status' => 'fail',
                /* translators: %d: HTTP status code */
                'result' => sprintf( __( 'Unexpected status %d (expected 200).', 'google-news-helper' ), $code ),
            ];
            $optimizations[] = [
                'title'  => __( 'Fix robots.txt URL', 'google-news-helper' ),
                'detail' => __( 'Ensure /robots.txt is not blocked by the server, a security plugin, or a CDN rule.', 'google-news-helper' ),
            ];
        } else {
            $checks[] = [
                'label'  => __( 'HTTP status', 'google-news-helper' ),
                'hint'   => $robots_url,
                'status' => 'pass',
                /* translators: %d: HTTP status code */
                'result' => sprintf( __( 'OK (%d)', 'google-news-helper' ), $code ),
            ];
        }

        // 3 — Content-Type (informational).
        if ( $fetch_err === '' && $code >= 200 && $code < 400 ) {
            $plain = $ctype !== '' && stripos( $ctype, 'text/plain' ) !== false;
            if ( ! $plain && $ctype !== '' ) {
                $has_warn = true;
                $checks[] = [
                    'label'  => __( 'Content-Type header', 'google-news-helper' ),
                    'hint'   => __( 'Crawlers expect text/plain.', 'google-news-helper' ),
                    'status' => 'warn',
                    'result' => $ctype,
                ];
            } elseif ( $ctype !== '' ) {
                $checks[] = [
                    'label'  => __( 'Content-Type header', 'google-news-helper' ),
                    'hint'   => '',
                    'status' => 'pass',
                    'result' => __( 'text/plain (or compatible)', 'google-news-helper' ),
                ];
            }
        }

        // 4 — Static file overrides WordPress.
        if ( $physical ) {
            $has_warn = true;
            $checks[] = [
                'label'  => __( 'Static robots.txt file', 'google-news-helper' ),
                'hint'   => ABSPATH . 'robots.txt',
                'status' => 'warn',
                'result' => __( 'A physical file exists; the web server may serve it instead of WordPress (plugin rules would not apply).', 'google-news-helper' ),
            ];
            $optimizations[] = [
                'title'  => __( 'Prefer one source of truth', 'google-news-helper' ),
                'detail' => __( 'Remove robots.txt from the site root if you want WordPress and this plugin to control the file, or edit the static file to include the same sitemap and crawler rules.', 'google-news-helper' ),
            ];
        } else {
            $checks[] = [
                'label'  => __( 'Static robots.txt file', 'google-news-helper' ),
                'hint'   => '',
                'status' => 'pass',
                'result' => __( 'No robots.txt in WordPress root — virtual robots.txt can apply.', 'google-news-helper' ),
            ];
        }

        // 5 — Sitemaps, plugin marker, crawl rules (only when indexing is allowed and response is usable).
        if ( $public && $body !== '' && $fetch_err === '' && $code >= 200 && $code < 400 ) {
            $has_sitemap = (bool) preg_match( '/^Sitemap:\s*\S/im', $body );
            if ( ! $has_sitemap ) {
                $has_warn = true;
                $checks[] = [
                    'label'  => __( 'Sitemap declarations', 'google-news-helper' ),
                    'hint'   => '',
                    'status' => 'warn',
                    'result' => __( 'No “Sitemap:” lines found.', 'google-news-helper' ),
                ];
            } else {
                $checks[] = [
                    'label'  => __( 'Sitemap declarations', 'google-news-helper' ),
                    'hint'   => '',
                    'status' => 'pass',
                    'result' => __( 'At least one Sitemap URL is present.', 'google-news-helper' ),
                ];
            }

            $news_map = stripos( $body, 'news-sitemap.xml' ) !== false;
            if ( ! $news_map ) {
                $has_warn = true;
                $checks[] = [
                    'label'  => __( 'Google News sitemap', 'google-news-helper' ),
                    'hint'   => '',
                    'status' => 'warn',
                    'result' => __( 'news-sitemap.xml is not referenced (this plugin adds it when indexing is allowed).', 'google-news-helper' ),
                ];
            } else {
                $checks[] = [
                    'label'  => __( 'Google News sitemap', 'google-news-helper' ),
                    'hint'   => '',
                    'status' => 'pass',
                    'result' => __( 'Referenced in robots.txt.', 'google-news-helper' ),
                ];
            }

            if ( $physical ) {
                $checks[] = [
                    'label'  => __( 'Plugin robots rules', 'google-news-helper' ),
                    'hint'   => '',
                    'status' => 'warn',
                    'result' => __( 'Not verified: a static robots.txt may be served instead of WordPress — confirm the file includes your sitemap and crawler rules.', 'google-news-helper' ),
                ];
            } else {
                $has_gnh = strpos( $body, 'Google News Helper' ) !== false;
                if ( ! $has_gnh ) {
                    $has_warn = true;
                    $checks[] = [
                        'label'  => __( 'Plugin robots rules', 'google-news-helper' ),
                        'hint'   => '',
                        'status' => 'warn',
                        'result' => __( 'Expected “Google News Helper” block not found — another plugin or theme may be replacing robots.txt output.', 'google-news-helper' ),
                    ];
                } else {
                    $checks[] = [
                        'label'  => __( 'Plugin robots rules', 'google-news-helper' ),
                        'hint'   => '',
                        'status' => 'pass',
                        'result' => __( 'Google News Helper block is present.', 'google-news-helper' ),
                    ];
                }
            }

            // 6 — Wildcard / social blocking.
            if ( $star_blocked ) {
                $has_fail = true;
                $checks[] = [
                    'label'  => __( 'Crawl rules for all bots (User-agent: *)', 'google-news-helper' ),
                    'hint'   => '',
                    'status' => 'fail',
                    'result' => __( 'A rule appears to disallow the entire site for *.', 'google-news-helper' ),
                ];
                $optimizations[] = [
                    'title'  => __( 'Relax overly broad Disallow rules', 'google-news-helper' ),
                    'detail' => __( 'Avoid “Disallow: /” for User-agent: * unless you intentionally block all crawlers.', 'google-news-helper' ),
                ];
            } else {
                $checks[] = [
                    'label'  => __( 'Crawl rules for all bots (User-agent: *)', 'google-news-helper' ),
                    'hint'   => '',
                    'status' => 'pass',
                    'result' => __( 'No full-site Disallow for * detected.', 'google-news-helper' ),
                ];
            }

            if ( $social_blocked ) {
                $has_fail = true;
                $checks[] = [
                    'label'  => __( 'Social / link-preview crawlers', 'google-news-helper' ),
                    'hint'   => __( 'Facebook, X, LinkedIn, etc.', 'google-news-helper' ),
                    'status' => 'fail',
                    'result' => __( 'A rule appears to block link-preview crawlers (e.g. Facebot / facebookexternalhit).', 'google-news-helper' ),
                ];
                $optimizations[] = [
                    'title'  => __( 'Allow social crawlers', 'google-news-helper' ),
                    'detail' => __( 'Remove Disallow rules that target Facebot or facebookexternalhit, or add explicit Allow: / for those user agents.', 'google-news-helper' ),
                ];
            } else {
                $checks[] = [
                    'label'  => __( 'Social / link-preview crawlers', 'google-news-helper' ),
                    'hint'   => __( 'Facebook, X, LinkedIn, etc.', 'google-news-helper' ),
                    'status' => 'pass',
                    'result' => __( 'No full-site block detected for common preview bots.', 'google-news-helper' ),
                ];
            }
        }

        $overall = 'good';
        if ( $has_fail ) {
            $overall = 'bad';
        } elseif ( $has_warn ) {
            $overall = 'warn';
        }

        $overall_labels = [
            'good' => __( 'Healthy', 'google-news-helper' ),
            'warn' => __( 'Needs attention', 'google-news-helper' ),
            'bad'  => __( 'Critical issues', 'google-news-helper' ),
        ];

        return [
            'overall'       => $overall,
            'overall_label' => $overall_labels[ $overall ] ?? $overall_labels['good'],
            'robots_url'    => $robots_url,
            'body'          => $body,
            'fetch_error'   => $fetch_err,
            'checks'        => $checks,
            'optimizations' => $optimizations,
        ];
    }

    /**
     * @return list<array{title: string, detail: string}>
     */
    private static function baseline_optimizations(): array {
        return [
            [
                'title'  => __( 'Google News', 'google-news-helper' ),
                'detail' => __( 'Adds Googlebot-News allow rules and a Sitemap line for news-sitemap.xml.', 'google-news-helper' ),
            ],
            [
                'title'  => __( 'Link previews', 'google-news-helper' ),
                'detail' => __( 'Declares explicit Allow: / for Facebook, X, LinkedIn, WhatsApp, Slack, and Telegram crawlers.', 'google-news-helper' ),
            ],
            [
                'title'  => __( 'Caching', 'google-news-helper' ),
                'detail' => __( 'Signals cache plugins to bypass the cache for those crawlers so previews stay fresh.', 'google-news-helper' ),
            ],
        ];
    }

    /**
     * @return array{body: string, code: int, error: string, content_type: string}
     */
    private static function fetch_live_robots(): array {
        $url = home_url( '/robots.txt' );

        $response = wp_remote_get(
            $url,
            [
                'timeout'     => 15,
                'user-agent'  => 'Google News Helper Robots Check/' . GNH_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
                'sslverify'   => false,
                'redirection' => 5,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [
                'body'          => '',
                'code'          => 0,
                'error'         => $response->get_error_message(),
                'content_type'  => '',
            ];
        }

        return [
            'body'         => (string) wp_remote_retrieve_body( $response ),
            'code'         => (int) wp_remote_retrieve_response_code( $response ),
            'error'        => '',
            'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
        ];
    }

    /**
     * @return list<array{agents: string[], rules: list<array{type: string, path: string}>}>
     */
    private static function parse_robots_groups( string $body ): array {
        $lines  = preg_split( '/\r\n|\r|\n/', $body ) ?: [];
        $groups = [];
        $agents = [];
        $rules  = [];

        $flush = static function () use ( &$groups, &$agents, &$rules ): void {
            if ( ! empty( $agents ) ) {
                $groups[] = [
                    'agents' => $agents,
                    'rules'  => $rules,
                ];
            }
            $agents = [];
            $rules  = [];
        };

        foreach ( $lines as $line ) {
            $line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
            if ( $line === '' ) {
                $flush();
                continue;
            }
            if ( preg_match( '/^(Sitemap|Host|Crawl-delay):/i', $line ) ) {
                continue;
            }
            if ( preg_match( '/^User-agent:\s*(.+)$/i', $line, $m ) ) {
                if ( ! empty( $rules ) ) {
                    $flush();
                }
                $agents[] = trim( $m[1] );
                continue;
            }
            if ( preg_match( '/^(Disallow|Allow):\s*(.*)$/i', $line, $m ) ) {
                $rules[] = [
                    'type' => strtoupper( $m[1] ),
                    'path' => trim( $m[2] ),
                ];
                continue;
            }
        }

        $flush();

        return $groups;
    }

    /**
     * True if * group has a path that blocks the whole site root.
     *
     * @param list<array{agents: string[], rules: list<array{type: string, path: string}>}> $groups
     */
    private static function wildcard_agent_blocks_root( array $groups ): bool {
        foreach ( $groups as $g ) {
            if ( ! self::group_has_agent( $g['agents'], [ '*' ] ) ) {
                continue;
            }
            foreach ( $g['rules'] as $r ) {
                if ( $r['type'] !== 'DISALLOW' ) {
                    continue;
                }
                if ( self::disallow_blocks_root( $r['path'] ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param list<array{agents: string[], rules: list<array{type: string, path: string}>}> $groups
     */
    private static function social_preview_agents_blocked( array $groups ): bool {
        $bots = [ 'facebookexternalhit', 'facebot', 'twitterbot', 'linkedinbot', 'whatsapp', 'slackbot', 'telegrambot' ];
        foreach ( $groups as $g ) {
            $match_bot = self::group_has_agent( $g['agents'], $bots );
            $match_all = self::group_has_agent( $g['agents'], [ '*' ] );
            if ( ! $match_bot && ! $match_all ) {
                continue;
            }
            foreach ( $g['rules'] as $r ) {
                if ( $r['type'] !== 'DISALLOW' ) {
                    continue;
                }
                if ( self::disallow_blocks_root( $r['path'] ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param string[] $agents
     * @param string[] $needles Lowercase names to match (substring match for * only exact).
     */
    private static function group_has_agent( array $agents, array $needles ): bool {
        foreach ( $agents as $a ) {
            $a = strtolower( trim( $a ) );
            foreach ( $needles as $n ) {
                if ( $n === '*' && $a === '*' ) {
                    return true;
                }
                if ( $n !== '*' && $a === $n ) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function disallow_blocks_root( string $path ): bool {
        $path = trim( $path );
        if ( $path === '' ) {
            return false;
        }
        if ( $path === '/' || $path === '/*' ) {
            return true;
        }
        return false;
    }
}
