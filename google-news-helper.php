<?php
/**
 * Plugin Name: Google News Helper
 * Description: Optimizes your WordPress site for Google News: generates Google News sitemap, adds required meta tags, Open Graph, NewsArticle JSON-LD structured data, RSS enclosure tags, and a preview dashboard. Auto-updates from GitHub.
 * Version:     1.0.13
 * Author:      Ioannis Kokkinis
 * Author URI:  https://buy-it.gr/
 * License:     GPL-2.0-or-later
 * Text Domain: google-news-helper
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GNH_VERSION',     '1.0.13' );
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
}

// ── Load includes ─────────────────────────────────────────────────────────────

$_gnh_includes = [
    'includes/class-settings.php',
    'includes/class-post-seo.php',
    'includes/class-redirects.php',
    'includes/class-meta-tags.php',
    'includes/class-robots.php',
    'includes/class-ad-nosnippet.php',
    'includes/class-crawler-logs.php',
    'includes/class-news-sitemap.php',
    'includes/class-robots-admin.php',
    'includes/class-admin-page.php',
    'includes/class-updater.php',
];

foreach ( $_gnh_includes as $_gnh_file ) {
    $path = GNH_PLUGIN_DIR . $_gnh_file;
    if ( file_exists( $path ) ) {
        require_once $path;
    } else {
        error_log( 'Google News Helper: missing file ' . $path );
    }
}
unset( $_gnh_includes, $_gnh_file, $path );

// ── Bootstrap on plugins_loaded ───────────────────────────────────────────────

add_action( 'plugins_loaded', static function (): void {
    if ( class_exists( 'GNH_Settings' ) ) {
        new GNH_Settings();
    }
    if ( class_exists( 'GNH_Post_SEO' ) ) {
        new GNH_Post_SEO();
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
    if ( class_exists( 'GNH_Ad_Nosnippet' ) ) {
        new GNH_Ad_Nosnippet();
    }
    if ( class_exists( 'GNH_News_Sitemap' ) ) {
        new GNH_News_Sitemap();
    }
    // GitHub update checks run from wp_update_plugins() (admin, WP-Cron, etc.); must not be admin-only
    // or the pre_set_site_transient_update_plugins filter is missing when the transient is built.
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
    }
} );
