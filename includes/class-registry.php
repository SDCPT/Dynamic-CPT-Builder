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

	const BASE_OPTION = 'dcb_base';
	const LAYOUTS     = array( 'list', 'cards', 'mosaic' );

	/** Words that would collide with WordPress' own URLs and cannot be used as URL base. */
	const RESERVED_BASES = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'admin', 'login', 'page', 'category', 'tag', 'author', 'search', 'embed', 'comments', 'trackback', 'attachment' );

	/** WordPress features a CPT can switch on (post type "supports"). */
	const SUPPORTS = array( 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'comments', 'trackbacks', 'revisions', 'page-attributes', 'post-formats' );

	/** WordPress' built-in taxonomies that can be attached to a CPT. */
	const BUILTIN_TAX = array( 'category', 'post_tag' );

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
				array( 'key' => 'bot_vendor', 'label' => 'Vendor', 'hierarchical' => true ),
			),
			'supports'    => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			'builtin_tax' => array(),
			'hierarchical' => false,
			'public'      => true,
			'archive'     => true,
			'rest'        => true,
			'archive_page' => 0,
			'layout'      => '',
		);
	}

	/* ------------------------------------------------------------------
	 * URL base and archive pages
	 * ---------------------------------------------------------------- */

	/** URL prefix for all CPTs, "p" by default. An empty string means no prefix. */
	public static function base() {
		return sanitize_title( (string) get_option( self::BASE_OPTION, 'p' ) );
	}

	/**
	 * Cleans a user-entered URL base.
	 *
	 * @return string|null Clean base ("" is allowed) or null if the word is reserved by WordPress.
	 */
	public static function sanitize_base( $raw ) {
		$base = sanitize_title( trim( (string) $raw, " \t\n\r/" ) );
		return in_array( $base, self::RESERVED_BASES, true ) ? null : $base;
	}

	/** URL path of a CPT without slashes at the ends, e.g. "p/bots". */
	public static function path_for( array $def ) {
		$base = self::base();
		return '' === $base ? $def['slug'] : $base . '/' . $def['slug'];
	}

	/** ID of the WordPress page that serves as archive page of this CPT, or 0 if none / not usable. */
	public static function archive_page_id( array $def ) {
		$o  = self::opts( $def );
		$id = (int) $o['archive_page'];
		if ( $id < 1 || ! $o['public'] ) {
			return 0;
		}
		return ( 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) ? $id : 0;
	}

	/** Key of the CPT whose archive page is $page_id, or null. */
	public function archive_owner( $page_id ) {
		$page_id = (int) $page_id;
		foreach ( $this->all() as $key => $def ) {
			if ( (int) self::opts( $def )['archive_page'] === $page_id && self::archive_page_id( $def ) === $page_id ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Feature switches of a definition, with defaults for definitions saved by older versions.
	 *
	 * @return array{supports:string[],builtin_tax:string[],hierarchical:bool,public:bool,archive:bool,rest:bool}
	 */
	public static function opts( array $def ) {
		$defaults = array(
			'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			'builtin_tax'  => array(),
			'hierarchical' => false,
			'public'       => true,
			'archive'      => true,
			'rest'         => true,
			'archive_page' => 0,
			'layout'       => '',
		);
		return array_merge( $defaults, array_intersect_key( $def, $defaults ) );
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
			$o        = self::opts( $def );

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
					'public'              => (bool) $o['public'],
					'publicly_queryable'  => (bool) $o['public'],
					'exclude_from_search' => ! $o['public'],
					'show_ui'             => true,
					'show_in_menu'        => true,
					// With an archive page the page is the archive, so WordPress must not build a second one.
					'has_archive'         => $o['public'] && $o['archive'] && ! self::archive_page_id( $def ),
					'hierarchical'        => (bool) $o['hierarchical'],
					'show_in_rest'        => (bool) $o['rest'],
					'menu_icon'           => $def['icon'],
					// "custom-fields" is required for the meta fields to appear in the REST API.
					'supports'            => array_merge( $o['supports'], array( 'custom-fields' ) ),
					'taxonomies'          => $o['builtin_tax'],
					'rewrite'             => array(
						'slug'       => self::path_for( $def ),
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
						'hierarchical'      => ! isset( $tax['hierarchical'] ) || $tax['hierarchical'],
						'public'            => (bool) $o['public'],
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
		// After a plugin update the URL structure may have changed, so flush once per version.
		if ( get_option( 'dcb_version' ) !== DCB_VERSION ) {
			update_option( 'dcb_version', DCB_VERSION, true );
			update_option( self::FLUSH, 1, false );
		}
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

	/**
	 * Line format: key|Label|kind   (kind: "category" = hierarchical, default; "tags" = flat like tags)
	 */
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
			$kind  = isset( $parts[2] ) && '' !== $parts[2] ? strtolower( $parts[2] ) : 'category';

			if ( ! in_array( $kind, array( 'category', 'tags' ), true ) ) {
				$errors[] = sprintf( __( 'Taxonomies, line %1$d: unknown kind "%2$s" (use "category" or "tags").', 'dcb' ), $n, $kind );
				continue;
			}
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
				'key'          => $key,
				'label'        => '' !== $label ? $label : $key,
				'hierarchical' => 'tags' !== $kind,
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

		// Feature switches: whitelist everything, never trust the form.
		$supports     = $this->whitelist( isset( $in['supports'] ) ? $in['supports'] : array(), self::SUPPORTS );
		$builtin_tax  = $this->whitelist( isset( $in['builtin_tax'] ) ? $in['builtin_tax'] : array(), self::BUILTIN_TAX );
		$hierarchical = ! empty( $in['hierarchical'] );
		if ( $hierarchical && ! in_array( 'page-attributes', $supports, true ) ) {
			$supports[] = 'page-attributes'; // Needed for the "parent" selector.
		}

		$archive_page = isset( $in['archive_page'] ) ? absint( $in['archive_page'] ) : 0;
		if ( $archive_page ) {
			if ( 'page' !== get_post_type( $archive_page ) ) {
				$errors[] = __( 'The archive page must be a normal WordPress page.', 'dcb' );
			} else {
				foreach ( $all as $other_key => $other ) {
					if ( $other_key !== $key && (int) self::opts( $other )['archive_page'] === $archive_page ) {
						$errors[] = __( 'This page is already the archive page of another CPT.', 'dcb' );
						break;
					}
				}
			}
		}
		$layout = isset( $in['layout'] ) && in_array( $in['layout'], self::LAYOUTS, true ) ? $in['layout'] : '';

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
			'supports'     => $supports,
			'builtin_tax'  => $builtin_tax,
			'hierarchical' => $hierarchical,
			'public'       => ! empty( $in['public'] ),
			'archive'      => ! empty( $in['archive'] ),
			'rest'         => ! empty( $in['rest'] ),
			'archive_page' => $archive_page,
			'layout'       => $layout,
		);
	}

	/** Keeps only known values from a submitted list, in the order of the whitelist. */
	private function whitelist( $submitted, array $allowed ) {
		$submitted = is_array( $submitted ) ? array_map( 'strval', $submitted ) : array();
		return array_values( array_intersect( $allowed, $submitted ) );
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
			$line = $t['key'] . '|' . $t['label'];
			if ( isset( $t['hierarchical'] ) && ! $t['hierarchical'] ) {
				$line .= '|tags';
			}
			$lines[] = $line;
		}
		return implode( "\n", $lines );
	}
}
