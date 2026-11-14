<?php
/**
 * Store and validate definitions and register them as post types / taxonomies.
 *
 * @package DynamicCPTBuilder
 */

defined( 'ABSPATH' ) || exit;

class DCB_Registry {

	const OPTION     = 'dcb_definitions';
	const FLUSH      = 'dcb_flush_needed';
	const MAX_FIELDS = 30;
	const MAX_TAX    = 5;
	const TYPES      = array( 'text', 'textarea', 'url', 'email', 'number', 'date', 'select', 'checkbox' );

	/** @var array|null */
	private $defs = null;

	public function init() {
		add_action( 'init', array( $this, 'register' ), 5 );
		add_action( 'init', array( $this, 'maybe_flush' ), 99 );
	}

	/* ------------------------------------------------------------------
	 * Aktivierung
	 * ---------------------------------------------------------------- */

	public static function activate() {
		$registry = new self();
		if ( empty( $registry->all() ) ) {
			$registry->save( $registry->preset_ki_bots() );
		}
		update_option( self::FLUSH, 1, false );
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	/** Example definition for AI bots, created only on first activation. */
	public function preset_ki_bots() {
		return array(
			'key'        => 'ki_bot',
			'plural'     => 'AI Bots',
			'singular'   => 'AI Bot',
			'slug'       => 'bots',
			'icon'       => 'dashicons-superhero',
			'fields'     => array(
				array( 'key' => 'model',      'label' => 'Model',           'type' => 'text',     'options' => array() ),
				array( 'key' => 'website',    'label' => 'Website',         'type' => 'url',      'options' => array() ),
				array( 'key' => 'price',      'label' => 'Price',           'type' => 'text',     'options' => array() ),
				array( 'key' => 'opensource', 'label' => 'Open source',     'type' => 'checkbox', 'options' => array() ),
				array( 'key' => 'as_of',      'label' => 'As of (date)',    'type' => 'date',     'options' => array() ),
				array( 'key' => 'notes',      'label' => 'Notes',           'type' => 'textarea', 'options' => array() ),
			),
			'taxonomies' => array(
				array( 'key' => 'bot_vendor', 'label' => 'Vendor' ),
			),
		);
	}

	/* ------------------------------------------------------------------
	 * Zugriff auf gespeicherte Definitionen
	 * ---------------------------------------------------------------- */

	public function all() {
		if ( null === $this->defs ) {
			$stored     = get_option( self::OPTION, array() );
			$this->defs = is_array( $stored ) ? $stored : array();
		}
		return $this->defs;
	}

	public function get( $key ) {
		$all = $this->all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public function save( array $def ) {
		$all                = $this->all();
		$all[ $def['key'] ] = $def;
		update_option( self::OPTION, $all, true );
		$this->defs = $all;
		update_option( self::FLUSH, 1, false );
	}

	/** Removes the definition only. Existing posts stay in the database. */
	public function delete( $key ) {
		$all = $this->all();
		unset( $all[ $key ] );
		update_option( self::OPTION, $all, true );
		$this->defs = $all;
		update_option( self::FLUSH, 1, false );
	}

	/* ------------------------------------------------------------------
	 * Registrierung bei WordPress
	 * ---------------------------------------------------------------- */

	public function register() {
		foreach ( $this->all() as $key => $def ) {
			// Never override an already existing (e.g. core) type.
			if ( post_type_exists( $key ) ) {
				continue;
			}

			$plural   = DCB_I18n::t( $def['plural'], DCB_I18n::name( $key, 'plural' ) );
			$singular = DCB_I18n::t( $def['singular'], DCB_I18n::name( $key, 'singular' ) );

			register_post_type(
				$key,
				array(
					'labels'       => array(
						'name'          => $plural,
						'singular_name' => $singular,
						'add_new_item'  => sprintf( /* translators: %s singular name */ __( 'Add %s', 'dcb' ), $singular ),
						'edit_item'     => sprintf( __( 'Edit %s', 'dcb' ), $singular ),
						'all_items'     => $plural,
					),
					'public'       => true,
					'has_archive'  => true,
					'show_in_rest' => true,
					'menu_icon'    => $def['icon'],
					'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),
					'rewrite'      => array(
						'slug'       => $def['slug'],
						'with_front' => false,
					),
				)
			);

			foreach ( $def['taxonomies'] as $tax ) {
				if ( taxonomy_exists( $tax['key'] ) ) {
					continue;
				}
				register_taxonomy(
					$tax['key'],
					$key,
					array(
						'label'             => DCB_I18n::t( $tax['label'], DCB_I18n::name( $key, 'tax', $tax['key'], 'label' ) ),
						'hierarchical'      => true,
						'public'            => true,
						'show_in_rest'      => true,
						'show_admin_column' => true,
						'rewrite'           => array( 'slug' => $tax['key'] ),
					)
				);
			}

			foreach ( $def['fields'] as $field ) {
				register_post_meta(
					$key,
					self::meta_key( $field['key'] ),
					array(
						'single'            => true,
						'type'              => 'string',
						'show_in_rest'      => true,
						'sanitize_callback' => function ( $value ) use ( $field ) {
							return DCB_Registry::sanitize_value( $field, $value );
						},
						'auth_callback'     => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);
			}
		}
	}

	/** Rewrite rules are flushed only after a definition changed. */
	public function maybe_flush() {
		if ( get_option( self::FLUSH ) ) {
			flush_rewrite_rules( false );
			delete_option( self::FLUSH );
		}
	}

	/* ------------------------------------------------------------------
	 * Helfer
	 * ---------------------------------------------------------------- */

	/** Only free text is translated by default; technical values stay language-neutral. */
	public static function default_translate( $type ) {
		return 'textarea' === $type;
	}

	/** Works for definitions stored before the flag existed, too. */
	public static function is_translatable( array $field ) {
		return isset( $field['translate'] ) ? (bool) $field['translate'] : self::default_translate( $field['type'] );
	}

	public static function meta_key( $field_key ) {
		return 'dcb_' . $field_key;
	}

	/** Returns the sanitized value; an empty string means "delete meta". */
	public static function sanitize_value( array $field, $raw ) {
		if ( is_array( $raw ) ) {
			return '';
		}
		$raw = is_string( $raw ) ? trim( $raw ) : (string) $raw;

		switch ( $field['type'] ) {
			case 'textarea':
				return sanitize_textarea_field( $raw );
			case 'url':
				return esc_url_raw( $raw );
			case 'email':
				return sanitize_email( $raw );
			case 'number':
				return is_numeric( $raw ) ? $raw : '';
			case 'date':
				if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
					return $raw;
				}
				return '';
			case 'select':
				return in_array( $raw, $field['options'], true ) ? $raw : '';
			case 'checkbox':
				return ( '' !== $raw && '0' !== $raw ) ? '1' : '';
			default:
				return sanitize_text_field( $raw );
		}
	}

	/* ------------------------------------------------------------------
	 * Parse and validate input (admin form)
	 * ---------------------------------------------------------------- */

	/**
	 * Line format: key|Label|type|options|flag
	 * - options: only for "select", comma-separated
	 * - flag: "neutral" (identical in all languages, synced) or "translate" (translated per language).
	 *   Default: only "textarea" is translated, everything else is neutral.
	 * For non-select types the flag may directly follow the type: key|Label|text|neutral
	 */
	public function parse_fields( $text, array &$errors ) {
		$fields = array();
		$seen   = array();

		foreach ( preg_split( '/\R/', (string) $text ) as $i => $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$n     = $i + 1;
			$parts = array_map( 'trim', explode( '|', $line ) );
			$key   = substr( sanitize_key( $parts[0] ), 0, 40 );
			$label = sanitize_text_field( isset( $parts[1] ) ? $parts[1] : '' );
			$type  = isset( $parts[2] ) && '' !== $parts[2] ? strtolower( $parts[2] ) : 'text';

			if ( '' === $key ) {
				$errors[] = sprintf( __( 'Fields, line %d: key is missing.', 'dcb' ), $n );
				continue;
			}
			if ( isset( $seen[ $key ] ) ) {
				$errors[] = sprintf( __( 'Fields, line %1$d: key "%2$s" is used twice.', 'dcb' ), $n, $key );
				continue;
			}
			if ( ! in_array( $type, self::TYPES, true ) ) {
				$errors[] = sprintf( __( 'Fields, line %1$d: unknown type "%2$s".', 'dcb' ), $n, $type );
				continue;
			}

			$options  = array();
			$flag_raw = isset( $parts[3] ) ? $parts[3] : '';
			if ( 'select' === $type ) {
				$flag_raw = isset( $parts[4] ) ? $parts[4] : '';
				foreach ( explode( ',', isset( $parts[3] ) ? $parts[3] : '' ) as $opt ) {
					$opt = sanitize_text_field( trim( $opt ) );
					if ( '' !== $opt ) {
						$options[] = $opt;
					}
				}
				if ( empty( $options ) ) {
					$errors[] = sprintf( __( 'Fields, line %d: "select" needs options (comma-separated).', 'dcb' ), $n );
					continue;
				}
			}

			$flag = strtolower( $flag_raw );
			if ( '' !== $flag && ! in_array( $flag, array( 'neutral', 'translate' ), true ) ) {
				$errors[] = sprintf( __( 'Fields, line %1$d: unknown flag "%2$s" (use "neutral" or "translate").', 'dcb' ), $n, $flag );
				continue;
			}

			$seen[ $key ] = true;
			$fields[]     = array(
				'key'       => $key,
				'label'     => '' !== $label ? $label : $key,
				'type'      => $type,
				'options'   => $options,
				'translate' => '' === $flag ? self::default_translate( $type ) : ( 'translate' === $flag ),
			);
		}

		if ( count( $fields ) > self::MAX_FIELDS ) {
			$errors[] = sprintf( __( 'At most %d fields allowed.', 'dcb' ), self::MAX_FIELDS );
		}
		return $fields;
	}

	/** Line format: key|Label */
	public function parse_taxonomies( $text, $cpt_key, array &$errors ) {
		$taxes = array();
		$seen  = array();

		foreach ( preg_split( '/\R/', (string) $text ) as $i => $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$n     = $i + 1;
			$parts = array_map( 'trim', explode( '|', $line ) );
			$key   = substr( sanitize_key( $parts[0] ), 0, 32 );
			$label = sanitize_text_field( isset( $parts[1] ) ? $parts[1] : '' );

			if ( '' === $key || isset( $seen[ $key ] ) ) {
				$errors[] = sprintf( __( 'Taxonomies, line %d: key is missing or duplicated.', 'dcb' ), $n );
				continue;
			}
			$owner = $this->taxonomy_owner( $key );
			if ( ( taxonomy_exists( $key ) && $owner !== $cpt_key ) || ( null !== $owner && $owner !== $cpt_key ) ) {
				$errors[] = sprintf( __( 'Taxonomies, line %1$d: "%2$s" is already taken.', 'dcb' ), $n, $key );
				continue;
			}
			$seen[ $key ] = true;
			$taxes[]      = array(
				'key'   => $key,
				'label' => '' !== $label ? $label : $key,
			);
		}

		if ( count( $taxes ) > self::MAX_TAX ) {
			$errors[] = sprintf( __( 'At most %d taxonomies allowed.', 'dcb' ), self::MAX_TAX );
		}
		return $taxes;
	}

	private function taxonomy_owner( $tax_key ) {
		foreach ( $this->all() as $key => $def ) {
			foreach ( $def['taxonomies'] as $tax ) {
				if ( $tax['key'] === $tax_key ) {
					return $key;
				}
			}
		}
		return null;
	}

	/**
	 * Builds a clean definition from form data.
	 *
	 * @param array       $in      Raw (unslashed) input.
	 * @param string|null $editing Key when an existing definition is edited.
	 * @param array       $errors  Filled with error messages.
	 * @return array|null Definition or null on errors.
	 */
	public function build( array $in, $editing, array &$errors ) {
		$errors = array();
		$all    = $this->all();

		$key = $editing ? $editing : sanitize_key( isset( $in['key'] ) ? $in['key'] : '' );
		if ( ! preg_match( '/^[a-z][a-z0-9_]{1,19}$/', $key ) ) {
			$errors[] = __( 'Key: 2 to 20 characters, only a-z, 0-9 and _, starting with a letter.', 'dcb' );
		} elseif ( ! $editing && ( isset( $all[ $key ] ) || post_type_exists( $key ) ) ) {
			$errors[] = __( 'This key already exists (WordPress core types are reserved too).', 'dcb' );
		}

		$plural   = sanitize_text_field( isset( $in['plural'] ) ? $in['plural'] : '' );
		$singular = sanitize_text_field( isset( $in['singular'] ) ? $in['singular'] : '' );
		$slug     = sanitize_title( isset( $in['slug'] ) ? $in['slug'] : '' );
		if ( '' === $plural || '' === $singular || '' === $slug ) {
			$errors[] = __( 'Plural name, singular name and URL slug are required.', 'dcb' );
		}
		foreach ( $all as $other_key => $other ) {
			if ( $other_key !== $key && $other['slug'] === $slug ) {
				$errors[] = __( 'This URL slug is already used by another CPT.', 'dcb' );
				break;
			}
		}

		$icon = isset( $in['icon'] ) ? trim( $in['icon'] ) : '';
		if ( ! preg_match( '/^dashicons-[a-z0-9-]+$/', $icon ) ) {
			$icon = 'dashicons-admin-post';
		}

		$fields = $this->parse_fields( isset( $in['fields'] ) ? $in['fields'] : '', $errors );
		$taxes  = $this->parse_taxonomies( isset( $in['taxonomies'] ) ? $in['taxonomies'] : '', $key, $errors );

		if ( $errors ) {
			return null;
		}

		return array(
			'key'        => $key,
			'plural'     => $plural,
			'singular'   => $singular,
			'slug'       => $slug,
			'icon'       => $icon,
			'fields'     => $fields,
			'taxonomies' => $taxes,
		);
	}

	/** Reverse: fields as text lines for the form. */
	public static function fields_to_text( array $fields ) {
		$lines = array();
		foreach ( $fields as $f ) {
			$line = $f['key'] . '|' . $f['label'] . '|' . $f['type'];
			if ( 'select' === $f['type'] ) {
				$line .= '|' . implode( ',', $f['options'] );
			}
			if ( self::is_translatable( $f ) !== self::default_translate( $f['type'] ) ) {
				$line .= '|' . ( self::is_translatable( $f ) ? 'translate' : 'neutral' );
			}
			$lines[] = $line;
		}
		return implode( "\n", $lines );
	}

	public static function taxonomies_to_text( array $taxes ) {
		$lines = array();
		foreach ( $taxes as $t ) {
			$lines[] = $t['key'] . '|' . $t['label'];
		}
		return implode( "\n", $lines );
	}
}
