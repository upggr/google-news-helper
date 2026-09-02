<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GNH_GitHub_Updater {

    /** @var string */
    private $repo;

    /** @var string */
    private $plugin_basename;

    /** @var string */
    private $slug;

    public function __construct() {
        $this->repo             = (string) GNH_GITHUB_REPO;
        $this->plugin_basename  = plugin_basename( GNH_PLUGIN_FILE );
        $this->slug             = dirname( $this->plugin_basename );

        if ( empty( $this->repo ) || ! self::self_hosted_updates_enabled() ) {
            return;
        }

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugins_api' ], 10, 3 );
        add_filter( 'upgrader_source_selection', [ $this, 'fix_source_folder' ], 10, 4 );
        add_filter( 'upgrader_post_install', [ $this, 'reactivate_after_install' ], 10, 3 );
    }

    /**
     * GitHub tag archives unpack as "<repo>-<tag>" rather than the plugin slug, so
     * WordPress installs the update into a new folder, leaves the old one behind and
     * silently deactivates the plugin. Rename the unpacked folder back to the slug.
     *
     * @param  string      $source        Directory the update was unpacked into.
     * @param  string      $remote_source Top-level upgrade working directory.
     * @param  WP_Upgrader $upgrader
     * @param  array       $args
     * @return string|WP_Error
     */
    public function fix_source_folder( $source, $remote_source, $upgrader = null, $args = [] ) {
        if ( ! is_string( $source ) || ! is_string( $remote_source ) ) {
            return $source;
        }

        // Only touch our own update.
        $plugin = is_array( $args ) && isset( $args['plugin'] ) ? (string) $args['plugin'] : '';
        if ( $plugin !== $this->plugin_basename ) {
            return $source;
        }

        $desired = trailingslashit( $remote_source ) . $this->slug;

        if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
            return $source;
        }

        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            return $source;
        }

        // A leftover target from an interrupted run would make move() fail.
        if ( $wp_filesystem->exists( $desired ) ) {
            $wp_filesystem->delete( $desired, true );
        }

        if ( ! $wp_filesystem->move( $source, $desired ) ) {
            return new WP_Error(
                'gnh_rename_failed',
                __( 'Could not rename the downloaded plugin folder to its expected name.', 'news-seo-helper' )
            );
        }

        return trailingslashit( $desired );
    }

    /**
     * Restore the active state if an earlier broken update left the plugin deactivated.
     *
     * @param  bool  $response
     * @param  array $hook_extra
     * @param  array $result
     * @return mixed
     */
    public function reactivate_after_install( $response, $hook_extra, $result ) {
        $plugin = is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) ? (string) $hook_extra['plugin'] : '';
        if ( $plugin !== $this->plugin_basename ) {
            return $response;
        }

        if ( function_exists( 'is_plugin_active' ) && ! is_plugin_active( $this->plugin_basename ) ) {
            activate_plugin( $this->plugin_basename );
        }

        return $response;
    }

    /**
     * Whether this copy should update itself from GitHub.
     *
     * Builds distributed through WordPress.org must not self-update — the
     * directory serves their updates, and a plugin that fetches its own code
     * from elsewhere is not allowed there. The build script drops a marker file
     * into the .org package, so a copy carrying it defers to WordPress.org while
     * the GitHub-distributed copy keeps updating itself.
     *
     * Can also be turned off explicitly with:
     *   define( 'GNH_DISABLE_GITHUB_UPDATER', true );
     * or the gnh_enable_github_updater filter.
     */
    public static function self_hosted_updates_enabled(): bool {
        $enabled = true;

        if ( file_exists( GNH_PLUGIN_DIR . '.wporg' ) ) {
            $enabled = false;
        }

        if ( defined( 'GNH_DISABLE_GITHUB_UPDATER' ) && GNH_DISABLE_GITHUB_UPDATER ) {
            $enabled = false;
        }

        /**
         * Filter whether the GitHub self-updater runs.
         *
         * @param bool $enabled
         */
        return (bool) apply_filters( 'gnh_enable_github_updater', $enabled );
    }

    /**
     * Check GitHub for a newer release and inject it into the WP update transient.
     *
     * @param  stdClass $transient
     * @return stdClass
     */
    public function check_for_update( $transient ) {
        if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
            return $transient;
        }

        $current_version = (string) GNH_VERSION;
        $release         = $this->get_latest_tag();

        if ( ! $release || empty( $release['tag_name'] ) ) {
            return $transient;
        }

        $remote_version = ltrim( (string) $release['tag_name'], 'v' );
        if ( version_compare( $remote_version, $current_version, '<=' ) ) {
            return $transient;
        }

        // Do not rawurlencode $this->repo — slashes must stay as path separators (encoding breaks GitHub URLs).
        $package = sprintf(
            'https://github.com/%s/archive/refs/tags/%s.zip',
            $this->repo,
            rawurlencode( $release['tag_name'] )
        );

        $update              = new stdClass();
        $update->slug        = $this->slug;
        $update->plugin      = $this->plugin_basename;
        $update->new_version = $remote_version;
        $update->url         = sprintf( 'https://github.com/%s', $this->repo );
        $update->package     = $package;

        $transient->response[ $this->plugin_basename ] = $update;

        return $transient;
    }

    /**
     * Provide plugin information for the "View details" modal in the plugins screen.
     *
     * @param  mixed  $result
     * @param  string $action
     * @param  object $args
     * @return mixed
     */
    public function plugins_api( $result, $action, $args ) {
        if ( $action !== 'plugin_information' || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
            return $result;
        }

        $info               = new stdClass();
        $info->name         = 'News SEO Helper';
        $info->slug         = $this->slug;
        $info->version      = (string) GNH_VERSION;
        $info->author       = '<a href="https://buy-it.gr">Ioannis Kokkinis</a>';
        $info->homepage     = sprintf( 'https://github.com/%s', $this->repo );
        $info->download_link = sprintf(
            'https://github.com/%s/archive/refs/tags/v%s.zip',
            $this->repo,
            rawurlencode( (string) GNH_VERSION )
        );
        $info->sections     = [
            'description' => 'Optimizes your WordPress site for Google News. Adds Open Graph tags, NewsArticle JSON-LD structured data, and a preview dashboard. Created by <a href="https://buy-it.gr">Ioannis Kokkinis</a>.',
        ];

        return $info;
    }

    /**
     * Fetch the latest tag from the GitHub API.
     *
     * @return array<string,mixed>|null
     */
    private function get_latest_tag(): ?array {
        $url = sprintf( 'https://api.github.com/repos/%s/tags?per_page=100', $this->repo );

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 10,
                'headers' => [
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'news-seo-helper',
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return null;
        }

        $body = wp_remote_retrieve_body( $response );
        if ( ! is_string( $body ) || $body === '' ) {
            return null;
        }

        $data = json_decode( $body, true );
        if ( ! is_array( $data ) || $data === [] ) {
            return null;
        }

        $best_name    = null;
        $best_version = '0';

        foreach ( $data as $row ) {
            if ( ! is_array( $row ) || empty( $row['name'] ) || ! is_string( $row['name'] ) ) {
                continue;
            }
            $name    = $row['name'];
            $version = ltrim( $name, 'vV' );
            if ( $version === '' || ! preg_match( '/^\d+(\.\d+){0,3}/', $version ) ) {
                continue;
            }
            if ( version_compare( $version, $best_version, '>' ) ) {
                $best_version = $version;
                $best_name    = $name;
            }
        }

        if ( $best_name === null ) {
            return null;
        }

        return [
            'tag_name' => $best_name,
        ];
    }
}
