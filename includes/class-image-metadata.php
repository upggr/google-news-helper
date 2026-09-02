<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Strips XMP / C2PA provenance metadata from uploaded images.
 *
 * Adobe tools (and most generative AI tools) embed a C2PA "Content Credentials"
 * manifest plus an XMP packet carrying dcterms:provenance. Meta reads those on
 * upload and attaches an "AI Info" label to the post, which is not what a news
 * site wants on its photos.
 *
 * The metadata carries no SEO value — Google reads the page, not the file — so
 * removing it is safe, and it makes files smaller.
 *
 * Implementation note: this rewrites container structure directly rather than
 * re-encoding through GD/Imagick, so the compressed image data is preserved
 * byte-for-byte and nothing is re-compressed.
 */
class GNH_Image_Metadata {

    public const OPTION = 'gnh_strip_image_metadata';

    /** JPEG APP segments that carry provenance/editor metadata. */
    private const JPEG_STRIP_MARKERS = [
        0xE1, // APP1  — EXIF / XMP
        0xE2, // APP2  — ICC / C2PA (JUMBF)
        0xE5, // APP5
        0xEB, // APP11 — JUMBF / C2PA
        0xED, // APP13 — Photoshop IRB
        0xEE, // APP14 — Adobe
        0xFE, // COM   — comment
    ];

    /** WebP chunks that carry provenance/editor metadata. */
    private const WEBP_STRIP_CHUNKS = [ 'XMP ', 'EXIF', 'PSAI', 'C2PA', 'JUMB' ];

    /** PNG chunks that carry provenance/editor metadata. */
    private const PNG_STRIP_CHUNKS = [ 'iTXt', 'tEXt', 'zTXt', 'eXIf', 'caBX' ];

    public function __construct() {
        // Runs after WordPress has moved the file into uploads but before
        // attachment metadata and resized variants are generated, so the
        // intermediate sizes inherit the cleaned original.
        add_filter( 'wp_handle_upload', [ $this, 'strip_on_upload' ], 10, 2 );
    }

    public static function is_enabled(): bool {
        return (bool) get_option( self::OPTION, false );
    }

    /**
     * @param array $upload  {file, url, type}
     * @param string $context
     * @return array
     */
    public function strip_on_upload( $upload, $context = '' ) {
        if ( ! is_array( $upload ) || empty( $upload['file'] ) || ! self::is_enabled() ) {
            return $upload;
        }

        $type = isset( $upload['type'] ) ? (string) $upload['type'] : '';
        if ( strpos( $type, 'image/' ) !== 0 ) {
            return $upload;
        }

        self::strip_file( (string) $upload['file'] );

        return $upload;
    }

    /**
     * Remove provenance metadata from an image file in place.
     *
     * @return bool True when the file was rewritten.
     */
    public static function strip_file( string $path ): bool {
        if ( ! is_readable( $path ) || ! is_writable( $path ) ) {
            return false;
        }

        $data = file_get_contents( $path );
        if ( ! is_string( $data ) || $data === '' ) {
            return false;
        }

        $clean = self::strip_bytes( $data );

        if ( $clean === null || $clean === $data || $clean === '' ) {
            return false;
        }

        // Never grow the file or truncate it to something implausible.
        if ( strlen( $clean ) > strlen( $data ) || strlen( $clean ) < 64 ) {
            return false;
        }

        return self::atomic_write( $path, $clean );
    }

    /**
     * Dispatch on container format.
     *
     * @return string|null Cleaned bytes, or null when the format is unhandled.
     */
    public static function strip_bytes( string $data ): ?string {
        if ( substr( $data, 0, 2 ) === "\xFF\xD8" ) {
            return self::strip_jpeg( $data );
        }
        if ( substr( $data, 0, 4 ) === 'RIFF' && substr( $data, 8, 4 ) === 'WEBP' ) {
            return self::strip_webp( $data );
        }
        if ( substr( $data, 0, 8 ) === "\x89PNG\r\n\x1A\n" ) {
            return self::strip_png( $data );
        }
        return null;
    }

    /**
     * Walk JPEG marker segments, dropping metadata APP segments.
     */
    private static function strip_jpeg( string $d ): ?string {
        $len = strlen( $d );
        $out = "\xFF\xD8";
        $i   = 2;

        while ( $i + 4 <= $len ) {
            if ( $d[ $i ] !== "\xFF" ) {
                return null; // Not a marker boundary — refuse rather than corrupt.
            }

            $marker = ord( $d[ $i + 1 ] );

            // Start of scan: copy the compressed remainder verbatim.
            if ( $marker === 0xDA ) {
                return $out . substr( $d, $i );
            }

            // Standalone markers carry no length payload.
            if ( $marker === 0x01 || ( $marker >= 0xD0 && $marker <= 0xD7 ) ) {
                $out .= substr( $d, $i, 2 );
                $i   += 2;
                continue;
            }

            $seg_len = unpack( 'n', substr( $d, $i + 2, 2 ) )[1];
            if ( $seg_len < 2 || $i + 2 + $seg_len > $len ) {
                return null;
            }

            $segment = substr( $d, $i, 2 + $seg_len );

            if ( ! self::jpeg_segment_is_droppable( $marker, $segment ) ) {
                $out .= $segment;
            }

            $i += 2 + $seg_len;
        }

        return $out;
    }

    /**
     * Keep the ICC colour profile (APP2 "ICC_PROFILE") — dropping it shifts colours.
     */
    private static function jpeg_segment_is_droppable( int $marker, string $segment ): bool {
        if ( ! in_array( $marker, self::JPEG_STRIP_MARKERS, true ) ) {
            return false;
        }

        if ( $marker === 0xE2 && strpos( $segment, 'ICC_PROFILE' ) !== false ) {
            return false;
        }

        return true;
    }

    /**
     * Rebuild the RIFF chunk list without metadata chunks.
     */
    private static function strip_webp( string $d ): ?string {
        $len = strlen( $d );
        if ( $len < 16 ) {
            return null;
        }

        $out     = [];
        $offset  = 12;
        $dropped = false;

        while ( $offset + 8 <= $len ) {
            $id   = substr( $d, $offset, 4 );
            $size = unpack( 'V', substr( $d, $offset + 4, 4 ) )[1];

            // Chunks are word-aligned; odd sizes carry a pad byte.
            $advance = 8 + $size + ( $size % 2 );
            if ( $size < 0 || $offset + $advance > $len ) {
                return null;
            }

            if ( in_array( $id, self::WEBP_STRIP_CHUNKS, true ) ) {
                $dropped = true;
            } else {
                $body = substr( $d, $offset, $advance );

                // VP8X advertises which optional chunks follow; clear the
                // EXIF (bit 3) and XMP (bit 2) flags now that they are gone.
                if ( $id === 'VP8X' && $size >= 1 ) {
                    $flags = ord( $body[8] ) & ~0b00001100;
                    $body  = substr( $body, 0, 8 ) . chr( $flags ) . substr( $body, 9 );
                }

                $out[] = $body;
            }

            $offset += $advance;
        }

        if ( ! $dropped || empty( $out ) ) {
            return null;
        }

        $payload = implode( '', $out );
        return 'RIFF' . pack( 'V', 4 + strlen( $payload ) ) . 'WEBP' . $payload;
    }

    /**
     * Rebuild the PNG chunk stream without metadata chunks.
     */
    private static function strip_png( string $d ): ?string {
        $len     = strlen( $d );
        $out     = substr( $d, 0, 8 );
        $offset  = 8;
        $dropped = false;

        while ( $offset + 12 <= $len ) {
            $size = unpack( 'N', substr( $d, $offset, 4 ) )[1];
            $type = substr( $d, $offset + 4, 4 );

            $advance = 12 + $size;
            if ( $offset + $advance > $len ) {
                return null;
            }

            if ( in_array( $type, self::PNG_STRIP_CHUNKS, true ) ) {
                $dropped = true;
            } else {
                $out .= substr( $d, $offset, $advance );
            }

            $offset += $advance;

            if ( $type === 'IEND' ) {
                break;
            }
        }

        return $dropped ? $out : null;
    }

    /**
     * Write via a temp file in the same directory so a failure cannot leave a
     * half-written image in place.
     */
    private static function atomic_write( string $path, string $bytes ): bool {
        $tmp = $path . '.gnh-tmp';

        if ( file_put_contents( $tmp, $bytes, LOCK_EX ) === false ) {
            @unlink( $tmp );
            return false;
        }

        $perms = @fileperms( $path );
        if ( $perms !== false ) {
            @chmod( $tmp, $perms & 0777 );
        }

        if ( ! @rename( $tmp, $path ) ) {
            @unlink( $tmp );
            return false;
        }

        clearstatcache( true, $path );
        return true;
    }
}
