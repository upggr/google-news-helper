<?php
/**
 * Generates custom Google News sitemap and adds RSS enclosures
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GNH_News_Sitemap {

	private const EXCLUDED_CAT_IDS = [ 322 ]; // ΔΙΑΦΗΜΙΣΤΙΚΑ ΑΡΘΡΑ

	public function __construct() {
		// Register rewrite rules
		add_action( 'init', [ $this, 'register_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );

		// Serve sitemap early to bypass plugin conflicts
		add_action( 'parse_request', [ $this, 'serve_news_sitemap' ], 0 );
		add_action( 'template_redirect', [ $this, 'serve_news_sitemap_redirect' ], 0 );

		// Exclude ad category from RSS feed
		add_filter( 'pre_get_posts', [ $this, 'exclude_ads_from_rss' ] );

		// Add RSS enclosure tags
		add_action( 'rss2_item', [ $this, 'add_rss_enclosure' ] );
	}

	public function register_rewrite_rules(): void {
		add_rewrite_rule( '^news-sitemap\\.xml$', 'index.php?zt_news_sitemap=1', 'top' );

		if ( ! get_option( 'gnh_news_sitemap_rules_flushed' ) ) {
			flush_rewrite_rules( false );
			update_option( 'gnh_news_sitemap_rules_flushed', 1, false );
		}
	}

	public function add_query_vars( $vars ) {
		$vars[] = 'zt_news_sitemap';
		return $vars;
	}

	public function serve_news_sitemap(): void {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
		if ( $path === '/news-sitemap.xml' ) {
			$this->render_news_sitemap();
		}
	}

	public function serve_news_sitemap_redirect(): void {
		if ( (int) get_query_var( 'zt_news_sitemap' ) === 1 ) {
			$this->render_news_sitemap();
		}
	}

	private function render_news_sitemap(): void {
		$publication_name = get_bloginfo( 'name' );
		$language         = str_replace( '_', '-', get_locale() );
		$after_gmt        = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );

		$posts = get_posts( [
			'post_type'       => 'post',
			'post_status'     => 'publish',
			'orderby'         => 'date',
			'order'           => 'DESC',
			'posts_per_page'  => 1000,
			'date_query'      => [
				[
					'column' => 'post_date_gmt',
					'after'  => $after_gmt,
				],
			],
			'category__not_in' => self::EXCLUDED_CAT_IDS,
			'fields'          => 'ids',
			'no_found_rows'   => true,
			'suppress_filters' => false,
		] );

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/xml; charset=' . get_bloginfo( 'charset' ) );

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

		foreach ( $posts as $post_id ) {
			$post       = get_post( $post_id );
			$permalink  = get_permalink( $post_id );
			$published  = get_the_time( 'c', $post_id );
			$title      = get_the_title( $post_id );
			$image_id   = get_post_thumbnail_id( $post_id );
			$image_url  = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';

			if ( ! $permalink ) {
				continue;
			}

			echo "\t<url>\n";
			echo "\t\t<loc><![CDATA[" . esc_url( $permalink ) . "]]></loc>\n";
			echo "\t\t<news:news>\n";
			echo "\t\t\t<news:publication>\n";
			echo "\t\t\t\t<news:name><![CDATA[" . esc_html( $publication_name ) . "]]></news:name>\n";
			echo "\t\t\t\t<news:language>" . esc_html( $language ) . "</news:language>\n";
			echo "\t\t\t</news:publication>\n";
			echo "\t\t\t<news:publication_date>" . esc_html( $published ) . "</news:publication_date>\n";
			echo "\t\t\t<news:title><![CDATA[" . esc_html( $title ) . "]]></news:title>\n";
			echo "\t\t</news:news>\n";

			if ( ! empty( $image_url ) ) {
				echo "\t\t<image:image><image:loc><![CDATA[" . esc_url( $image_url ) . "]]></image:loc></image:image>\n";
			}

			echo "\t</url>\n";
		}

		echo '</urlset>';
		exit;
	}

	public function exclude_ads_from_rss( $query ) {
		if ( $query->is_feed() && ! is_admin() ) {
			$query->set( 'category__not_in', self::EXCLUDED_CAT_IDS );
		}
	}

	public function add_rss_enclosure(): void {
		$image_id = get_post_thumbnail_id( get_the_ID() );
		if ( ! $image_id ) {
			return;
		}

		$image_url = wp_get_attachment_image_url( $image_id, 'full' );
		if ( ! $image_url ) {
			return;
		}

		$file_path  = get_attached_file( $image_id );
		$file_size  = ( $file_path && file_exists( $file_path ) ) ? (int) filesize( $file_path ) : 0;
		$mime_type  = get_post_mime_type( $image_id ) ?: 'image/jpeg';

		echo "\t<enclosure url=\"" . esc_url( $image_url ) . "\" length=\"" . $file_size . "\" type=\"" . esc_attr( $mime_type ) . "\" />\n";
	}
}
