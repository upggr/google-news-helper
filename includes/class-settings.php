<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GNH_Settings {

    public function __construct() {
        add_action( 'admin_init', [ $this, 'register' ] );
    }

    public function register(): void {
        register_setting(
            'gnh_options_group',
            'gnh_enabled',
            [
                'type'              => 'boolean',
                'default'           => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ]
        );
    }
}
