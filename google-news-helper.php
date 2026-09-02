<?php
/**
 * Plugin Name: News SEO Helper
 * Plugin URI:  https://github.com/upggr/google-news-helper
 * Description: Google News optimization for news sites: news sitemap, NewsArticle structured data, Open Graph and Twitter tags, per-post and per-category search descriptions, redirects, robots.txt tools, and removal of AI/provenance metadata from images.
 * Version:     1.1.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author:      Ioannis Kokkinis
 * Author URI:  https://buy-it.gr/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: news-seo-helper
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GNH_VERSION',     '1.1.0' );
define( 'GNH_PLUGIN_FILE', __FILE__ );
define( 'GNH_GITHUB_REPO', 'upggr/google-news-helper' );
define( 'GNH_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'GNH_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// ── Activation / deactivation / uninstall ────────────────────────────────────

register_activation_hook( __FILE__, 'gnh_activate' );
function gnh_activate(): void {
    add_option( 'gnh_enabled', true );
}

register_deactivation_hook( __FILE__, 'gnh_deactivate' );
function gnh_deactivate(): void {}

register_uninstall_hook( __FILE__, 'gnh_uninstall' );
function gnh_uninstall(): void {
    delete_option( 'gnh_enabled' );
    delete_option( 'gnh_front_meta_description' );
    delete_option( 'gnh_strip_image_metadata' );

    if ( class_exists( 'GNH_Term_SEO' ) ) {
        delete_metadata( 'term', 0, GNH_Term_SEO::META_DESC, '', true );
    }
}

// ── Load includes ─────────────────────────────────────────────────────────────

$_gnh_includes = [
    'includes/class-settings.php',
    'includes/class-post-seo.php',
    'includes/class-image-metadata.php',
    'includes/class-image-metadata-admin.php',
    'includes/class-term-seo.php',
    'includes/class-term-seo-admin.php',
    'includes/class-redirects.php',
    'includes/class-meta-tags.php',
    'includes/class-robots.php',
    'includes/class-crawler-logs.php',
    'includes/class-news-sitemap.php',
    'includes/class-robots-admin.php',
    'includes/class-admin-page.php',
];

/**
 * Optional files — absent from some distributions, so a missing one is not an error.
 *
 * The self-updater is stripped from the WordPress.org build: plugins hosted in the
 * directory update through the directory, and shipping updater code there is not
 * permitted. The GitHub distribution keeps it.
 */
$_gnh_optional_includes = [
    'includes/class-updater.php',
];

foreach ( $_gnh_includes as $_gnh_file ) {
    $path = GNH_PLUGIN_DIR . $_gnh_file;
    if ( file_exists( $path ) ) {
        require_once $path;
    } else {
        error_log( 'News SEO Helper: missing file ' . $path );
    }
}

foreach ( $_gnh_optional_includes as $_gnh_file ) {
    $path = GNH_PLUGIN_DIR . $_gnh_file;
    if ( file_exists( $path ) ) {
        require_once $path;
    }
}

unset( $_gnh_includes, $_gnh_optional_includes, $_gnh_file, $path );

// ── Bootstrap on plugins_loaded ───────────────────────────────────────────────

add_action( 'plugins_loaded', static function (): void {
    if ( class_exists( 'GNH_Settings' ) ) {
        new GNH_Settings();
    }
    if ( class_exists( 'GNH_Post_SEO' ) ) {
        new GNH_Post_SEO();
    }
    if ( class_exists( 'GNH_Term_SEO' ) ) {
        new GNH_Term_SEO();
    }
    if ( class_exists( 'GNH_Image_Metadata' ) ) {
        new GNH_Image_Metadata();
    }
    if ( class_exists( 'GNH_Redirects' ) ) {
        new GNH_Redirects();
    }
    if ( class_exists( 'GNH_Meta_Tags' ) ) {
        new GNH_Meta_Tags();
    }
    if ( class_exists( 'GNH_Robots' ) ) {
        new GNH_Robots();
    }
    if ( class_exists( 'GNH_News_Sitemap' ) ) {
        new GNH_News_Sitemap();
    }
    // Present only in the GitHub distribution; see $_gnh_optional_includes above.
    // Update checks run from wp_update_plugins() (admin, WP-Cron, etc.), so this
    // must not be admin-only or the filter is missing when the transient is built.
    if ( function_exists( 'wp_remote_get' ) && class_exists( 'GNH_GitHub_Updater' ) ) {
        new GNH_GitHub_Updater();
    }
    if ( is_admin() ) {
        if ( class_exists( 'GNH_Admin_Page' ) ) {
            new GNH_Admin_Page();
        }
        if ( class_exists( 'GNH_Crawler_Logs' ) ) {
            new GNH_Crawler_Logs();
        }
        if ( class_exists( 'GNH_Term_SEO_Admin' ) ) {
            new GNH_Term_SEO_Admin();
        }
        if ( class_exists( 'GNH_Image_Metadata_Admin' ) ) {
            new GNH_Image_Metadata_Admin();
        }
    }
} );
