<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GNH_Meta_Tags {

    public function __construct() {
        add_action( 'wp_head', [ $this, 'output_front_page_snippet' ], 4 );
        // Early priority so our og:title / Twitter title win the “first tag in document” race (Facebook uses the first og:title).
        add_action( 'wp_head', [ $this, 'output_tags' ], 2 );

        add_filter( 'pre_get_document_title', [ $this, 'filter_document_title_for_post' ], 15 );

        add_filter( 'wpseo_metadesc', [ $this, 'filter_seo_meta_description' ], 20, 1 );
        add_filter( 'rank_math/frontend/description', [ $this, 'filter_seo_meta_description' ], 20 );
        add_filter( 'aioseo_description', [ $this, 'filter_seo_meta_description' ], 20, 1 );

        add_filter( 'wpseo_title', [ $this, 'filter_seo_title' ], 20, 1 );
        add_filter( 'rank_math/frontend/title', [ $this, 'filter_seo_title' ], 20 );
        add_filter( 'aioseo_title', [ $this, 'filter_seo_title' ], 20, 1 );
    }

    /**
     * Homepage &lt;meta name="description"&gt; and matching Open Graph when no major SEO plugin outputs them.
     * Major SEO plugins are handled via filters registered in the constructor.
     */
    public function output_front_page_snippet(): void {
        if ( ! get_option( 'gnh_enabled', true ) || ! is_front_page() ) {
            return;
        }

        $desc = $this->get_front_meta_description();
        if ( $desc === '' ) {
            return;
        }

        if ( $this->has_major_seo_plugin() ) {
            return;
        }

        echo "\n<!-- Google News Helper: front page snippet -->\n";
        printf(
            '<meta name="description" content="%s">' . "\n",
            esc_attr( $desc )
        );
        printf(
            '<meta property="og:description" content="%s">' . "\n",
            esc_attr( $desc )
        );
        echo "<!-- /Google News Helper: front page snippet -->\n";
    }

    /**
     * Homepage option + per-post meta description override (metabox) for compatible SEO plugins.
     *
     * @param mixed $description Previous meta description from SEO plugin.
     * @return mixed
     */
    public function filter_seo_meta_description( $description ) {
        if ( ! get_option( 'gnh_enabled', true ) ) {
            return $description;
        }

        if ( is_front_page() ) {
            $custom = $this->get_front_meta_description();
            return $custom !== '' ? $custom : $description;
        }

        if ( ! is_singular( GNH_Post_SEO::seo_post_types() ) ) {
            return $description;
        }

        global $post;
        if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, GNH_Post_SEO::seo_post_types(), true ) || (int) get_queried_object_id() !== (int) $post->ID ) {
            return $description;
        }

        if ( class_exists( 'GNH_Post_SEO' ) && GNH_Post_SEO::is_noindex( $post->ID ) ) {
            return $description;
        }

        $custom = class_exists( 'GNH_Post_SEO' ) ? trim( wp_strip_all_tags( GNH_Post_SEO::get_desc( $post->ID ) ) ) : '';
        return $custom !== '' ? $custom : $description;
    }

    /**
     * Per-post SEO title override for compatible SEO plugins.
     *
     * @param mixed $title Previous title from SEO plugin.
     * @return mixed
     */
    public function filter_seo_title( $title ) {
        if ( ! get_option( 'gnh_enabled', true ) || ! is_singular( GNH_Post_SEO::seo_post_types() ) ) {
            return $title;
        }

        global $post;
        if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, GNH_Post_SEO::seo_post_types(), true ) || (int) get_queried_object_id() !== (int) $post->ID ) {
            return $title;
        }

        if ( class_exists( 'GNH_Post_SEO' ) && GNH_Post_SEO::is_noindex( $post->ID ) ) {
            return $title;
        }

        $custom = class_exists( 'GNH_Post_SEO' ) ? trim( (string) GNH_Post_SEO::get_title( $post->ID ) ) : '';
        return $custom !== '' ? $custom : $title;
    }

    /**
     * HTML document &lt;title&gt; when no major SEO plugin (they use filter_seo_title instead).
     *
     * @param mixed $title WordPress-computed title.
     * @return mixed
     */
    public function filter_document_title_for_post( $title ) {
        if ( ! get_option( 'gnh_enabled', true ) || $this->has_major_seo_plugin() || ! is_singular( GNH_Post_SEO::seo_post_types() ) ) {
            return $title;
        }

        global $post;
        if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, GNH_Post_SEO::seo_post_types(), true ) || (int) get_queried_object_id() !== (int) $post->ID ) {
            return $title;
        }

        if ( class_exists( 'GNH_Post_SEO' ) && GNH_Post_SEO::is_noindex( $post->ID ) ) {
            return $title;
        }

        $custom = class_exists( 'GNH_Post_SEO' ) ? trim( (string) GNH_Post_SEO::get_title( $post->ID ) ) : '';
        return $custom !== '' ? $custom : $title;
    }

    public function output_tags(): void {
        if ( ! get_option( 'gnh_enabled', true ) ) {
            return;
        }

        if ( ! is_singular( GNH_Post_SEO::seo_post_types() ) ) {
            return;
        }

        $post = get_queried_object();
        if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, GNH_Post_SEO::seo_post_types(), true ) ) {
            global $post;
            if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, GNH_Post_SEO::seo_post_types(), true ) ) {
                return;
            }
        }

        $is_post = ( $post->post_type === 'post' );

        // Respect per-content noindex — skip all tags if set to noindex
        if ( class_exists( 'GNH_Post_SEO' ) && GNH_Post_SEO::is_noindex( $post->ID ) ) {
            return;
        }

        // Per-content overrides (same meta keys for posts and pages)
        $custom_title = class_exists( 'GNH_Post_SEO' ) ? GNH_Post_SEO::get_title( $post->ID ) : '';
        $custom_desc  = class_exists( 'GNH_Post_SEO' ) ? GNH_Post_SEO::get_desc( $post->ID )  : '';

        $title = $this->resolve_post_og_title( $post, $custom_title );
        $url         = get_permalink( $post );
        $excerpt     = $custom_desc  ?: $this->get_excerpt( $post );
        $site_name   = get_bloginfo( 'name' );
        $locale      = get_locale();
        $pub_date    = get_the_date( 'c', $post );
        $mod_date    = get_the_modified_date( 'c', $post );
        $author      = get_the_author_meta( 'display_name', $post->post_author );
        $categories  = $is_post ? get_the_category( $post->ID ) : [];
        $section     = ! empty( $categories ) ? esc_html( $categories[0]->name ) : '';
        $tags        = $is_post ? get_the_tags( $post->ID ) : false;
        $keywords    = $is_post ? $this->get_keywords( $tags ) : '';
        $headline    = mb_substr( $title, 0, 110 );

        // Featured image
        $image_url   = '';
        $image_w     = 0;
        $image_h     = 0;

        if ( has_post_thumbnail( $post->ID ) ) {
            $thumb_id = get_post_thumbnail_id( $post->ID );
            $src      = wp_get_attachment_image_src( $thumb_id, 'full' );
            if ( $src ) {
                $image_url = $src[0];
                $image_w   = (int) $src[1];
                $image_h   = (int) $src[2];
            }
        }

        // Fallback: site logo
        if ( empty( $image_url ) ) {
            $logo_id = get_theme_mod( 'custom_logo' );
            if ( $logo_id ) {
                $logo_src = wp_get_attachment_image_src( $logo_id, 'full' );
                if ( $logo_src ) {
                    $image_url = $logo_src[0];
                    $image_w   = (int) $logo_src[1];
                    $image_h   = (int) $logo_src[2];
                }
            }
        }

        // Publisher logo for JSON-LD
        $publisher_logo = '';
        $logo_id = get_theme_mod( 'custom_logo' );
        if ( $logo_id ) {
            $logo_src = wp_get_attachment_image_src( $logo_id, 'full' );
            if ( $logo_src ) {
                $publisher_logo = $logo_src[0];
            }
        }

        // Detect other SEO plugins to avoid duplicate og: tags
        $has_seo_plugin = $this->has_major_seo_plugin();

        echo "\n<!-- Google News Helper v" . esc_html( GNH_VERSION ) . " -->\n";

        if ( ! $has_seo_plugin ) {
            printf(
                '<meta name="description" content="%s">' . "\n",
                esc_attr( $excerpt )
            );
        }

        // ── Open Graph (skip if another SEO plugin already outputs these) ──
        if ( ! $has_seo_plugin ) {
            $this->meta( 'property', 'og:type',        $is_post ? 'article' : 'website' );
            $this->meta( 'property', 'og:title',       $title );
            $this->meta( 'property', 'og:url',         $url );
            $this->meta( 'property', 'og:description', $excerpt );
            $this->meta( 'property', 'og:site_name',   $site_name );
            $this->meta( 'property', 'og:locale',      $locale );

            if ( $image_url ) {
                $this->meta( 'property', 'og:image',        $image_url );
                $this->meta( 'property', 'og:image:type',   $this->get_image_mime( $image_url ) );
                $this->meta( 'property', 'og:image:alt',    $title );
                if ( $image_w ) {
                    $this->meta( 'property', 'og:image:width',  (string) $image_w );
                    $this->meta( 'property', 'og:image:height', (string) $image_h );
                }
            }

            if ( $is_post ) {
                $this->meta( 'property', 'article:published_time', $pub_date );
                $this->meta( 'property', 'article:modified_time',  $mod_date );
                $this->meta( 'property', 'article:author',         $author );
                if ( $section ) {
                    $this->meta( 'property', 'article:section', $section );
                }
                if ( is_array( $tags ) ) {
                    foreach ( $tags as $tag ) {
                        $this->meta( 'property', 'article:tag', $tag->name );
                    }
                }
            }

            // Twitter Card
            $this->meta( 'name', 'twitter:card',        'summary_large_image' );
            $this->meta( 'name', 'twitter:title',       $title );
            $this->meta( 'name', 'twitter:description', $excerpt );
            if ( $image_url ) {
                $this->meta( 'name', 'twitter:image', $image_url );
            }
        }

        // ── Google News-specific meta (posts only) ───────────────────────────
        if ( $is_post && $keywords ) {
            $this->meta( 'name', 'news_keywords', $keywords );
        }
        $this->meta( 'name', 'author', $author );
        echo '<meta name="robots" content="max-image-preview:large">' . "\n";

        // ── JSON-LD: NewsArticle (posts) or WebPage (pages) ─────────────────
        if ( $is_post ) {
            $schema = [
                '@context'        => 'https://schema.org',
                '@type'           => 'NewsArticle',
                'headline'        => $headline,
                'datePublished'   => $pub_date,
                'dateModified'    => $mod_date,
                'author'          => [
                    '@type' => 'Person',
                    'name'  => $author,
                ],
                'publisher'       => [
                    '@type' => 'Organization',
                    'name'  => $site_name,
                ],
                'description'     => $excerpt,
                'mainEntityOfPage' => [
                    '@type' => 'WebPage',
                    '@id'   => $url,
                ],
            ];

            if ( $image_url ) {
                $schema['image'] = [ $image_url ];
            }

            if ( $publisher_logo ) {
                $schema['publisher']['logo'] = [
                    '@type' => 'ImageObject',
                    'url'   => $publisher_logo,
                ];
            }

            if ( $section ) {
                $schema['articleSection'] = $section;
            }

            if ( $keywords ) {
                $schema['keywords'] = $keywords;
            }
        } else {
            $schema = [
                '@context'    => 'https://schema.org',
                '@type'       => 'WebPage',
                'name'        => $title,
                'url'         => $url,
                'description' => $excerpt,
                'datePublished' => $pub_date,
                'dateModified'  => $mod_date,
                'isPartOf'    => [
                    '@type' => 'WebSite',
                    'name'  => $site_name,
                    'url'   => home_url( '/' ),
                ],
            ];
            if ( $image_url ) {
                $schema['primaryImageOfPage'] = [
                    '@type' => 'ImageObject',
                    'url'   => $image_url,
                ];
                if ( $image_w && $image_h ) {
                    $schema['primaryImageOfPage']['width']  = $image_w;
                    $schema['primaryImageOfPage']['height'] = $image_h;
                }
            }
            if ( $publisher_logo ) {
                $schema['publisher'] = [
                    '@type' => 'Organization',
                    'name'  => $site_name,
                    'logo'  => [
                        '@type' => 'ImageObject',
                        'url'   => $publisher_logo,
                    ],
                ];
            }
        }

        echo '<script type="application/ld+json">' . "\n";
        echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        echo "\n</script>\n";
        echo "<!-- /Google News Helper -->\n";
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Stable headline for Open Graph / Twitter (never empty when the content exists).
     *
     * @param WP_Post $post           Queried post or page.
     * @param string  $custom_title   Raw value from GNH metabox (may be empty).
     */
    private function resolve_post_og_title( WP_Post $post, string $custom_title ): string {
        $custom_title = trim( wp_strip_all_tags( $custom_title ) );
        if ( $custom_title !== '' ) {
            $title = $custom_title;
        } else {
            $title = trim( wp_strip_all_tags( get_the_title( $post ) ) );
        }
        if ( $title === '' && trim( (string) $post->post_title ) !== '' ) {
            $title = trim( wp_strip_all_tags( (string) $post->post_title ) );
        }
        if ( $title === '' ) {
            $text = wp_strip_all_tags( (string) $post->post_content );
            $text = preg_replace( '/\s+/', ' ', $text );
            $text = trim( (string) $text );
            if ( $text !== '' ) {
                $title = mb_strlen( $text ) > 110 ? mb_substr( $text, 0, 107 ) . '...' : $text;
            }
        }
        if ( $title === '' ) {
            $title = get_bloginfo( 'name' );
        }

        /**
         * Filter the Open Graph / Twitter title for singular posts and pages.
         *
         * @param string  $title Resolved title.
         * @param WP_Post $post  Post or page object.
         */
        return (string) apply_filters( 'gnh_open_graph_title', $title, $post );
    }

    private function get_front_meta_description(): string {
        $raw = get_option( 'gnh_front_meta_description', '' );
        if ( ! is_string( $raw ) ) {
            return '';
        }
        $raw = trim( wp_strip_all_tags( $raw ) );
        return $raw;
    }

    private function has_major_seo_plugin(): bool {
        return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEOP_VERSION' );
    }

    private function meta( string $attr, string $name, string $content ): void {
        printf(
            '<meta %s="%s" content="%s">' . "\n",
            esc_attr( $attr ),
            esc_attr( $name ),
            esc_attr( $content )
        );
    }

    private function get_excerpt( WP_Post $post ): string {
        $text = $post->post_excerpt ?: $post->post_content;
        $text = wp_strip_all_tags( $text );
        $text = preg_replace( '/\s+/', ' ', $text );
        $text = trim( (string) $text );
        if ( mb_strlen( $text ) > 200 ) {
            $text = mb_substr( $text, 0, 197 ) . '...';
        }
        return $text;
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

    private function get_keywords( $tags ): string {
        if ( ! is_array( $tags ) || empty( $tags ) ) {
            return '';
        }
        $names = array_slice( array_map( static fn( $t ) => $t->name, $tags ), 0, 10 );
        return implode( ', ', $names );
    }
}
