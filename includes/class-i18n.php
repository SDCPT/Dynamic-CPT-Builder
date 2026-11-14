<?php
/**
 * Internationalisation: plugin text domain plus Polylang / WPML integration.
 *
 * User-defined texts (CPT names, field labels, select options, taxonomy labels) live in an
 * option and are therefore invisible to .po files. They are registered as translatable strings
 * with the active translation plugin and translated again on output.
 *
 * @package DynamicCPTBuilder
 */

defined( 'ABSPATH' ) || exit;

class DCB_I18n {

	const CONTEXT = 'Dynamic CPT Builder';

	/** @var DCB_Registry */
	private $registry;

	public function __construct( DCB_Registry $registry ) {
		$this->registry = $registry;
	}

	public function init() {
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );

		// Polylang: make our post types / taxonomies translatable and sync language-neutral fields.
		add_filter( 'pll_get_post_types', array( $this, 'pll_post_types' ), 10, 2 );
		add_filter( 'pll_get_taxonomies', array( $this, 'pll_taxonomies' ), 10, 2 );
		add_filter( 'pll_copy_post_metas', array( $this, 'pll_copy_metas' ), 10, 3 );

		if ( is_admin() ) {
			add_action( 'init', array( $this, 'register_strings' ), 20 );
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'dcb', false, dirname( plugin_basename( DCB_FILE ) ) . '/languages' );
	}

	/* ------------------------------------------------------------------
	 * Detection and string helpers
	 * ---------------------------------------------------------------- */

	/** True if a supported translation plugin is active. */
	public static function active() {
		return function_exists( 'pll__' ) || has_filter( 'wpml_translate_single_string' );
	}

	/** Translates a user-defined string. Returns the original if no translation plugin is active. */
	public static function t( $value, $name ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return $value;
		}
		if ( function_exists( 'pll__' ) ) {
			return pll__( $value );
		}
		if ( has_filter( 'wpml_translate_single_string' ) ) {
			return apply_filters( 'wpml_translate_single_string', $value, self::CONTEXT, $name );
		}
		return $value;
	}

	/** Stable identifier for a string, used by WPML (Polylang identifies by the text itself). */
	public static function name() {
		return implode( ':', func_get_args() );
	}

	public static function option_id( $option ) {
		return substr( md5( (string) $option ), 0, 8 );
	}

	/** All user-defined strings of all definitions as name => value. */
	public function collect_strings() {
		$out = array();
		foreach ( $this->registry->all() as $key => $def ) {
			$out[ self::name( $key, 'plural' ) ]   = $def['plural'];
			$out[ self::name( $key, 'singular' ) ] = $def['singular'];
			foreach ( $def['fields'] as $f ) {
				$out[ self::name( $key, 'field', $f['key'], 'label' ) ] = $f['label'];
				foreach ( $f['options'] as $opt ) {
					$out[ self::name( $key, 'field', $f['key'], 'option', self::option_id( $opt ) ) ] = $opt;
				}
			}
			foreach ( $def['taxonomies'] as $tax ) {
				$out[ self::name( $key, 'tax', $tax['key'], 'label' ) ] = $tax['label'];
			}
		}
		return $out;
	}

	public function register_strings() {
		foreach ( $this->collect_strings() as $name => $value ) {
			if ( '' === $value ) {
				continue;
			}
			if ( function_exists( 'pll_register_string' ) ) {
				pll_register_string( $name, $value, self::CONTEXT );
			}
			if ( has_action( 'wpml_register_single_string' ) ) {
				do_action( 'wpml_register_single_string', self::CONTEXT, $name, $value );
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Polylang filters
	 * ---------------------------------------------------------------- */

	public function pll_post_types( $post_types, $is_settings ) {
		foreach ( array_keys( $this->registry->all() ) as $key ) {
			$post_types[ $key ] = $key;
		}
		return $post_types;
	}

	public function pll_taxonomies( $taxonomies, $is_settings ) {
		foreach ( $this->registry->all() as $def ) {
			foreach ( $def['taxonomies'] as $tax ) {
				$taxonomies[ $tax['key'] ] = $tax['key'];
			}
		}
		return $taxonomies;
	}

	/**
	 * Language-neutral fields are copied and kept in sync between translations.
	 * Translatable fields are only copied when a translation is created (as a starting point).
	 *
	 * @param string[] $keys Meta keys Polylang copies.
	 * @param bool     $sync True when synchronising existing translations, false when creating one.
	 * @param int      $from Source post ID.
	 */
	public function pll_copy_metas( $keys, $sync, $from ) {
		$def = $this->registry->get( get_post_type( $from ) );
		if ( ! $def ) {
			return $keys;
		}
		foreach ( $def['fields'] as $f ) {
			if ( ! $sync || ! DCB_Registry::is_translatable( $f ) ) {
				$keys[] = DCB_Registry::meta_key( $f['key'] );
			}
		}
		return array_values( array_unique( $keys ) );
	}
}
