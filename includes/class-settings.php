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

        // Its own group: options.php writes every option registered in a group,
        // so sharing gnh_options_group would let the dashboard form (which has no
        // checkbox for this) silently switch it back off on every save.
        register_setting(
            'gnh_image_options_group',
            GNH_Image_Metadata::OPTION,
            [
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ]
        );

        register_setting(
            'gnh_options_group',
            'gnh_front_meta_description',
            [
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => [ $this, 'sanitize_front_meta_description' ],
            ]
        );
    }

    /**
     * @param mixed $value Raw option value.
     */
    public function sanitize_front_meta_description( $value ): string {
        if ( ! is_string( $value ) ) {
            return '';
        }
        $value = sanitize_textarea_field( $value );
        if ( mb_strlen( $value ) > 320 ) {
            $value = mb_substr( $value, 0, 320 );
        }
        return $value;
    }
}
