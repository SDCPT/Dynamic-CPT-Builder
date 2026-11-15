<?php
/**
 * Design: skins (small CSS files made of --dcb-* variables) plus a few settings on top.
 *
 * A skin is one CSS file that only sets variables, e.g. skins/dark.css. Put your own skins into
 * a "dcb-skins" folder inside your (child) theme; a file with the same name overrides the plugin's.
 *
 * @package DynamicCPTBuilder
 */

defined( 'ABSPATH' ) || exit;

class DCB_Design {

	const OPTION = 'dcb_settings';
	const PAGE   = 'dcb-design';
	const COLORS = array( 'accent', 'text', 'bg', 'border' );

	/** @var string|null Admin page hook suffix. */
	private $hook = null;

	/** @var bool Frontend assets are enqueued only once. */
	private static $enqueued = false;

	/** @var array|null */
	private static $skins = null;

	/* ------------------------------------------------------------------
	 * Data
	 * ---------------------------------------------------------------- */

	public static function defaults() {
		return array(
			'skin'   => 'default',
			'layout' => 'list',
			'accent' => '',
			'text'   => '',
			'bg'     => '',
			'border' => '',
			'font'   => 'inherit',
			'radius' => '',
			'gap'    => '',
		);
	}

	public static function get() {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? array_intersect_key( $stored, self::defaults() ) : array() );
	}

	/** Font stacks. "inherit" (empty) keeps the theme font. */
	public static function fonts() {
		return array(
			'inherit' => '',
			'system'  => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
			'serif'   => 'Georgia, "Times New Roman", serif',
			'mono'    => 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
		);
	}

	/**
	 * Whitelists raw form input. Colours must be hex, numbers are clamped, the skin must exist.
	 *
	 * @param array    $in          Raw (unslashed) input.
	 * @param string[] $skin_slugs  Known skin slugs.
	 */
	public static function sanitize( array $in, array $skin_slugs ) {
		$d   = self::defaults();
		$out = $d;

		$skin        = isset( $in['skin'] ) ? sanitize_key( $in['skin'] ) : '';
		$out['skin'] = in_array( $skin, $skin_slugs, true ) ? $skin : $d['skin'];

		$out['layout'] = ( isset( $in['layout'] ) && in_array( $in['layout'], DCB_Registry::LAYOUTS, true ) ) ? $in['layout'] : 'list';

		foreach ( self::COLORS as $c ) {
			$v          = isset( $in[ $c ] ) ? sanitize_hex_color( trim( (string) $in[ $c ] ) ) : '';
			$out[ $c ] = $v ? $v : '';
		}

		$font        = isset( $in['font'] ) ? (string) $in['font'] : '';
		$out['font'] = array_key_exists( $font, self::fonts() ) ? $font : 'inherit';

		foreach ( array( 'radius' => array( 0, 32 ), 'gap' => array( 0, 64 ) ) as $k => $range ) {
			$raw       = isset( $in[ $k ] ) ? trim( (string) $in[ $k ] ) : '';
			$out[ $k ] = ( '' !== $raw && is_numeric( $raw ) ) ? max( $range[0], min( $range[1], (int) $raw ) ) : '';
		}
		return $out;
	}

	/** Variables the user overrode on top of the skin. Every value is validated, so it is safe in CSS. */
	public static function overrides_css( array $s ) {
		$v   = array();
		$map = array(
			'accent' => '--dcb-accent',
			'text'   => '--dcb-text',
			'bg'     => '--dcb-bg',
			'border' => '--dcb-border',
		);
		foreach ( $map as $k => $prop ) {
			if ( '' !== $s[ $k ] && sanitize_hex_color( $s[ $k ] ) ) {
				$v[] = $prop . ':' . $s[ $k ];
			}
		}
		if ( '' !== $s['radius'] ) {
			$v[] = '--dcb-radius:' . (int) $s['radius'] . 'px';
		}
		if ( '' !== $s['gap'] ) {
			$v[] = '--dcb-gap:' . (int) $s['gap'] . 'px';
		}
		$fonts = self::fonts();
		if ( isset( $fonts[ $s['font'] ] ) && '' !== $fonts[ $s['font'] ] ) {
			$v[] = '--dcb-font:' . $fonts[ $s['font'] ];
		}
		return $v ? '.dcb{' . implode( ';', $v ) . '}' : '';
	}

	/* ------------------------------------------------------------------
	 * Skins
	 * ---------------------------------------------------------------- */

	/** @return array<string,array{name:string,url:string,ver:string}> slug => info */
	public static function skins() {
		if ( null !== self::$skins ) {
			return self::$skins;
		}
		$found = array();
		$dirs  = array(
			array( DCB_PATH . 'skins/', plugins_url( 'skins/', DCB_FILE ) ),
			// Theme skins come last so they override a plugin skin with the same name.
			array( trailingslashit( get_stylesheet_directory() ) . 'dcb-skins/', trailingslashit( get_stylesheet_directory_uri() ) . 'dcb-skins/' ),
		);
		foreach ( $dirs as $dir ) {
			foreach ( (array) glob( $dir[0] . '*.css' ) as $file ) {
				$slug = sanitize_key( basename( $file, '.css' ) );
				if ( '' === $slug ) {
					continue;
				}
				$head          = (string) file_get_contents( $file, false, null, 0, 300 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$name          = preg_match( '#/\*\s*Skin:\s*(.+?)\s*\*/#', $head, $m ) ? sanitize_text_field( $m[1] ) : ucfirst( $slug );
				$found[ $slug ] = array(
					'name' => $name,
					'url'  => $dir[1] . rawurlencode( basename( $file ) ),
					'ver'  => (string) filemtime( $file ),
				);
			}
		}
		self::$skins = $found;
		return $found;
	}

	/* ------------------------------------------------------------------
	 * Frontend
	 * ---------------------------------------------------------------- */

	/** CSS classes for a wrapper element, e.g. classes( 'dcb-fields' ). Output with esc_attr(). */
	public static function classes( $extra = '' ) {
		$s = self::get();
		return trim( 'dcb ' . $extra . ' dcb-skin-' . sanitize_key( $s['skin'] ) );
	}

	/** Loads base CSS, the chosen skin and the overrides. Safe to call repeatedly. */
	public static function enqueue() {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;

		$s      = self::get();
		$skins  = self::skins();
		$handle = 'dcb';

		wp_enqueue_style( 'dcb', plugins_url( 'assets/dcb.css', DCB_FILE ), array(), DCB_VERSION );
		if ( isset( $skins[ $s['skin'] ] ) ) {
			wp_enqueue_style( 'dcb-skin', $skins[ $s['skin'] ]['url'], array( 'dcb' ), $skins[ $s['skin'] ]['ver'] );
			$handle = 'dcb-skin';
		}
		$css = self::overrides_css( $s );
		if ( '' !== $css ) {
			wp_add_inline_style( $handle, $css );
		}
	}

	/* ------------------------------------------------------------------
	 * Admin page
	 * ---------------------------------------------------------------- */

	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ), 11 );
		add_action( 'admin_post_dcb_save_design', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
	}

	public function menu() {
		$this->hook = add_submenu_page(
			DCB_Admin::SLUG,
			__( 'Design', 'dcb' ),
			__( 'Design', 'dcb' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	public function admin_assets( $hook ) {
		if ( ! $this->hook || $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_style( 'dcb', plugins_url( 'assets/dcb.css', DCB_FILE ), array(), DCB_VERSION );
		foreach ( self::skins() as $slug => $skin ) {
			wp_enqueue_style( 'dcb-skin-' . $slug, $skin['url'], array( 'dcb' ), $skin['ver'] );
		}
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'dcb' ), 403 );
		}
		check_admin_referer( 'dcb_save_design' );

		if ( isset( $_POST['reset'] ) ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, self::sanitize( wp_unslash( $_POST ), array_keys( self::skins() ) ), true );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = self::get();
		$skins = self::skins();
		$fonts = array(
			'inherit' => __( 'Theme font (default)', 'dcb' ),
			'system'  => __( 'System', 'dcb' ),
			'serif'   => __( 'Serif', 'dcb' ),
			'mono'    => __( 'Monospace', 'dcb' ),
		);
		$colors = array(
			'accent' => __( 'Accent colour (links)', 'dcb' ),
			'text'   => __( 'Text colour', 'dcb' ),
			'bg'     => __( 'Background colour', 'dcb' ),
			'border' => __( 'Border colour', 'dcb' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Design', 'dcb' ); ?></h1>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only. ?>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Design saved.', 'dcb' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Colours, spacing and fonts of the lists and field boxes on your website. Empty values use the skin default.', 'dcb' ); ?></p>

			<form id="dcb-design-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dcb_save_design">
				<?php wp_nonce_field( 'dcb_save_design' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="dcb-skin"><?php esc_html_e( 'Skin', 'dcb' ); ?></label></th>
						<td>
							<select id="dcb-skin" name="skin">
								<?php foreach ( $skins as $slug => $skin ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['skin'], $slug ); ?>><?php echo esc_html( $skin['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Your own skin: put a CSS file into the folder "dcb-skins" of your theme. Use skins/dark.css of this plugin as a template.', 'dcb' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="dcb-layout"><?php esc_html_e( 'Layout', 'dcb' ); ?></label></th>
						<td>
							<select id="dcb-layout" name="layout">
								<option value="list" <?php selected( $s['layout'], 'list' ); ?>><?php esc_html_e( 'List', 'dcb' ); ?></option>
								<option value="cards" <?php selected( $s['layout'], 'cards' ); ?>><?php esc_html_e( 'Cards', 'dcb' ); ?></option>
								<option value="mosaic" <?php selected( $s['layout'], 'mosaic' ); ?>><?php esc_html_e( 'Mosaic', 'dcb' ); ?></option>
							</select>
						</td>
					</tr>
					<?php foreach ( $colors as $key => $label ) : ?>
						<tr>
							<th><label for="dcb-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td><input type="text" class="dcb-color" id="dcb-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>"></td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th><label for="dcb-font"><?php esc_html_e( 'Font', 'dcb' ); ?></label></th>
						<td>
							<select id="dcb-font" name="font">
								<?php foreach ( $fonts as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['font'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="dcb-radius"><?php esc_html_e( 'Corner radius (px)', 'dcb' ); ?></label></th>
						<td><input type="number" id="dcb-radius" name="radius" min="0" max="32" class="small-text" value="<?php echo esc_attr( $s['radius'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="dcb-gap"><?php esc_html_e( 'Spacing (px)', 'dcb' ); ?></label></th>
						<td><input type="number" id="dcb-gap" name="gap" min="0" max="64" class="small-text" value="<?php echo esc_attr( $s['gap'] ); ?>"></td>
					</tr>
				</table>

				<p class="submit">
					<?php submit_button( __( 'Save design', 'dcb' ), 'primary', 'submit', false ); ?>
					<?php submit_button( __( 'Reset to defaults', 'dcb' ), 'secondary', 'reset', false ); ?>
				</p>
			</form>

			<h2><?php esc_html_e( 'Preview', 'dcb' ); ?></h2>
			<div id="dcb-preview" class="dcb dcb-list" style="max-width:48rem">
				<?php for ( $i = 1; $i <= 2; $i++ ) : ?>
					<article class="dcb-item">
						<h3><a href="#" onclick="return false;"><?php esc_html_e( 'Sample entry', 'dcb' ); ?> <?php echo esc_html( $i ); ?></a></h3>
						<dl class="dcb-fields">
							<dt><?php esc_html_e( 'Model', 'dcb' ); ?></dt><dd><?php esc_html_e( 'Example model', 'dcb' ); ?></dd>
							<dt><?php esc_html_e( 'Website', 'dcb' ); ?></dt><dd><a href="#" onclick="return false;">https://example.org</a></dd>
						</dl>
					</article>
				<?php endfor; ?>
			</div>
			<div id="dcb-preview-mosaic" class="dcb dcb-list dcb-layout-mosaic" style="max-width:48rem;display:none">
				<?php for ( $i = 1; $i <= 6; $i++ ) : ?>
					<article class="dcb-item dcb-tile"><a href="#" onclick="return false;"><span class="dcb-tile-noimg"><span class="dcb-tile-title"><?php esc_html_e( 'Sample entry', 'dcb' ); ?> <?php echo esc_html( $i ); ?></span></span></a></article>
				<?php endfor; ?>
			</div>
		</div>

		<script>
		jQuery(function ($) {
			var fonts = <?php echo wp_json_encode( self::fonts() ); ?>;
			var listPreview = document.getElementById('dcb-preview');
			var mosaicPreview = document.getElementById('dcb-preview-mosaic');
			var preview = listPreview;
			var colors = { accent: '--dcb-accent', text: '--dcb-text', bg: '--dcb-bg', border: '--dcb-border' };

			function setVar(name, value) {
				[listPreview, mosaicPreview].forEach(function (el) {
					if (value) { el.style.setProperty(name, value); } else { el.style.removeProperty(name); }
				});
			}
			function px(v) { return v === '' || isNaN(v) ? '' : v + 'px'; }

			function update() {
				var layout = $('#dcb-layout').val();
				listPreview.style.display = layout === 'mosaic' ? 'none' : '';
				mosaicPreview.style.display = layout === 'mosaic' ? '' : 'none';
				preview = layout === 'mosaic' ? mosaicPreview : listPreview;
				// Variables set on the hidden one are harmless; copy them to both so switching is instant.
				[listPreview, mosaicPreview].forEach(function (el) {
					el.className = 'dcb dcb-list dcb-layout-' + (el === mosaicPreview ? 'mosaic' : layout) + ' dcb-skin-' + $('#dcb-skin').val();
				});
				$.each(colors, function (key, prop) { setVar(prop, $('#dcb-' + key).val()); });
				setVar('--dcb-radius', px($('#dcb-radius').val()));
				setVar('--dcb-gap', px($('#dcb-gap').val()));
				setVar('--dcb-font', fonts[$('#dcb-font').val()] || '');
			}

			$('.dcb-color').wpColorPicker({
				change: function () { setTimeout(update, 0); },
				clear: function () { setTimeout(update, 0); }
			});
			$('#dcb-design-form').on('input change', 'select, input', update);
			update();
		});
		</script>
		<?php
	}
}
