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

        if ( empty( $this->repo ) ) {
            return;
        }

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugins_api' ], 10, 3 );
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

        $package = sprintf(
            'https://github.com/%s/archive/refs/tags/%s.zip',
            rawurlencode( $this->repo ),
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
        $info->name         = 'Google News Helper';
        $info->slug         = $this->slug;
        $info->version      = (string) GNH_VERSION;
        $info->author       = '<a href="https://buy-it.gr">Ioannis Kokkinis</a>';
        $info->homepage     = sprintf( 'https://github.com/%s', $this->repo );
        $info->download_link = sprintf(
            'https://github.com/%s/archive/refs/tags/v%s.zip',
            rawurlencode( $this->repo ),
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
        $url = sprintf( 'https://api.github.com/repos/%s/tags', $this->repo );

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 10,
                'headers' => [
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'google-news-helper',
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
