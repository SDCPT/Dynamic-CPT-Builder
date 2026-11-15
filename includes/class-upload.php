<?php
/**
 * Strict image-only upload checks for public submissions.
 *
 * Principle: deny by default. Only jpg, png and webp pass; every check must succeed (fail closed).
 * Accepted images are additionally re-encoded, which throws away everything that is not pixel data
 * (metadata, appended code, polyglot tricks), and are stored under a random file name.
 *
 * @package DynamicCPTBuilder
 */

defined( 'ABSPATH' ) || exit;

class DCB_Upload {

	const MAX_BYTES  = 2097152;  // 2 MB.
	const MAX_SIDE   = 6000;     // Pixels per side.
	const MAX_PIXELS = 25000000; // Protects against decompression bombs.

	/** Allowed extension => real MIME type. */
	const TYPES = array(
		'jpg'  => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
	);

	/** Anything that looks like code, markup or an executable, in any position of the file name. */
	const FORBIDDEN_NAME = '/\.(php\d*|phtml|phar|phps|pht|pl|py|rb|cgi|asp|aspx|jsp|sh|bash|exe|dll|bat|cmd|com|scr|html?|xhtml|js|mjs|svgz?|htaccess|ini|jar|swf|zip|gz|tar|rar|7z)(\.|$)/i';

	/** Byte patterns that must never appear inside an image we store. */
	const FORBIDDEN_BYTES = array( '<?php', '<?=', '<script', '<svg', '<html', '<iframe', '<%@' );

	/**
	 * Checks an uploaded file.
	 *
	 * @param string $path      Temporary path of the uploaded file.
	 * @param int    $size      Size in bytes as reported by PHP.
	 * @param string $orig_name Original file name from the visitor (untrusted).
	 * @return array{ok:bool,ext:string,mime:string,error:string} error is a short code, see message().
	 */
	public static function inspect( $path, $size, $orig_name ) {
		$fail = function ( $code ) {
			return array( 'ok' => false, 'ext' => '', 'mime' => '', 'error' => $code );
		};

		$name = (string) $orig_name;
		if ( '' === $name || strlen( $name ) > 255 || preg_match( '/[\x00-\x1f\x7f\/\\\\]/', $name ) ) {
			return $fail( 'name' );
		}
		if ( preg_match( self::FORBIDDEN_NAME, $name ) ) {
			return $fail( 'name' );
		}

		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$ext = 'jpeg' === $ext ? 'jpg' : $ext;
		if ( ! isset( self::TYPES[ $ext ] ) ) {
			return $fail( 'ext' );
		}

		$real_size = is_readable( $path ) ? (int) filesize( $path ) : 0;
		if ( $real_size < 1 || (int) $size < 1 ) {
			return $fail( 'empty' );
		}
		if ( $real_size > self::MAX_BYTES || (int) $size > self::MAX_BYTES ) {
			return $fail( 'too_big' );
		}

		// The real content decides, never the name or the type the browser claims.
		if ( ! function_exists( 'finfo_open' ) ) {
			return $fail( 'mime' );
		}
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime  = $finfo ? finfo_file( $finfo, $path ) : false;
		if ( $finfo ) {
			finfo_close( $finfo );
		}
		if ( self::TYPES[ $ext ] !== $mime ) {
			return $fail( 'mime' );
		}

		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || empty( $info[0] ) || empty( $info[1] ) || ( isset( $info['mime'] ) && $info['mime'] !== $mime ) ) {
			return $fail( 'image' );
		}
		$w = (int) $info[0];
		$h = (int) $info[1];
		if ( $w < 8 || $h < 8 || $w > self::MAX_SIDE || $h > self::MAX_SIDE || $w * $h > self::MAX_PIXELS ) {
			return $fail( 'dimensions' );
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $bytes ) {
			return $fail( 'image' );
		}
		foreach ( self::FORBIDDEN_BYTES as $needle ) {
			if ( false !== stripos( $bytes, $needle ) ) {
				return $fail( 'code' );
			}
		}

		return array( 'ok' => true, 'ext' => $ext, 'mime' => $mime, 'error' => '' );
	}

	/** Visitor-facing message for an error code. */
	public static function message( $code ) {
		switch ( $code ) {
			case 'too_big':
				return __( 'The image is too large (maximum 2 MB).', 'dcb' );
			case 'dimensions':
				return __( 'The image dimensions are not allowed.', 'dcb' );
			case 'empty':
				return __( 'The uploaded file is empty.', 'dcb' );
			default:
				return __( 'Only genuine JPG, PNG or WebP images are allowed.', 'dcb' );
		}
	}

	/**
	 * Re-encodes an accepted image with WordPress' image editor and returns the path of the clean copy.
	 * Returns false if that is not possible (no GD/Imagick), in which case the upload is refused.
	 *
	 * @return string|false
	 */
	public static function reencode( $path, $ext ) {
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return false;
		}
		$editor->resize( 2000, 2000, false ); // Never store more pixels than needed; errors are harmless here.

		$dir  = trailingslashit( get_temp_dir() );
		$dest = $dir . 'dcb-' . wp_generate_password( 16, false ) . '.' . $ext;
		$saved = $editor->save( $dest, self::TYPES[ $ext ] );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
			return false;
		}
		return $saved['path'];
	}
}
