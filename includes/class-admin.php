<?php
/**
 * Backend page for creating, editing and removing CPT definitions.
 *
 * @package DynamicCPTBuilder
 */

defined( 'ABSPATH' ) || exit;

class DCB_Admin {

	const SLUG = 'dcb';

	/** @var DCB_Registry */
	private $registry;

	public function __construct( DCB_Registry $registry ) {
		$this->registry = $registry;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_dcb_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_dcb_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_dcb_save_base', array( $this, 'handle_save_base' ) );
	}

	public function menu() {
		add_menu_page(
			__( 'CPT Builder', 'dcb' ),
			__( 'CPT Builder', 'dcb' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-screenoptions',
			80
		);
		// Same slug as the parent: renames the automatic first submenu entry.
		add_submenu_page( self::SLUG, __( 'CPTs', 'dcb' ), __( 'CPTs', 'dcb' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	private function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/* ------------------------------------------------------------------
	 * Actions
	 * ---------------------------------------------------------------- */

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'dcb' ), 403 );
		}
		check_admin_referer( 'dcb_save' );

		$in      = wp_unslash( $_POST );
		$editing = isset( $in['editing'] ) && '' !== $in['editing'] ? sanitize_key( $in['editing'] ) : null;

		if ( $editing && ! $this->registry->get( $editing ) ) {
			wp_safe_redirect( $this->url() );
			exit;
		}

		$errors = array();
		$def    = $this->registry->build( $in, $editing, $errors );

		if ( null === $def ) {
			set_transient(
				'dcb_form_' . get_current_user_id(),
				array(
					'errors' => $errors,
					'input'  => array(
						'key'        => isset( $in['key'] ) ? sanitize_text_field( $in['key'] ) : '',
						'plural'     => isset( $in['plural'] ) ? sanitize_text_field( $in['plural'] ) : '',
						'singular'   => isset( $in['singular'] ) ? sanitize_text_field( $in['singular'] ) : '',
						'slug'       => isset( $in['slug'] ) ? sanitize_text_field( $in['slug'] ) : '',
						'icon'       => isset( $in['icon'] ) ? sanitize_text_field( $in['icon'] ) : '',
						'fields'     => isset( $in['fields'] ) ? sanitize_textarea_field( $in['fields'] ) : '',
						'taxonomies' => isset( $in['taxonomies'] ) ? sanitize_textarea_field( $in['taxonomies'] ) : '',
						// Unchecked boxes are absent from the POST data, so remember that this was a real submit.
						'posted'       => 1,
						'supports'     => isset( $in['supports'] ) && is_array( $in['supports'] ) ? array_map( 'sanitize_key', $in['supports'] ) : array(),
						'builtin_tax'  => isset( $in['builtin_tax'] ) && is_array( $in['builtin_tax'] ) ? array_map( 'sanitize_key', $in['builtin_tax'] ) : array(),
						'hierarchical' => ! empty( $in['hierarchical'] ),
						'public'       => ! empty( $in['public'] ),
						'archive'      => ! empty( $in['archive'] ),
						'rest'         => ! empty( $in['rest'] ),
						'archive_page' => isset( $in['archive_page'] ) ? absint( $in['archive_page'] ) : 0,
						'layout'       => isset( $in['layout'] ) ? sanitize_key( $in['layout'] ) : '',
					),
				),
				60
			);
			wp_safe_redirect( $this->url( $editing ? array( 'edit' => $editing ) : array( 'edit' => '_new' ) ) );
			exit;
		}

		if ( ! empty( $in['archive_create'] ) && ! $def['archive_page'] ) {
			$def['archive_page'] = $this->create_archive_page( $def );
		}

		$this->registry->save( $def );
		wp_safe_redirect( $this->url( array( 'saved' => 1 ) ) );
		exit;
	}

	public function handle_save_base() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'dcb' ), 403 );
		}
		check_admin_referer( 'dcb_save_base' );

		$base = DCB_Registry::sanitize_base( isset( $_POST['base'] ) ? wp_unslash( $_POST['base'] ) : '' );
		if ( null === $base ) {
			wp_safe_redirect( $this->url( array( 'base_error' => 1 ) ) );
			exit;
		}
		update_option( DCB_Registry::BASE_OPTION, $base, true );
		update_option( DCB_Registry::FLUSH, 1, false );
		wp_safe_redirect( $this->url( array( 'saved' => 1 ) ) );
		exit;
	}

	/** Creates a normal WordPress page to serve as archive page. Returns its ID or 0. */
	private function create_archive_page( array $def ) {
		if ( ! current_user_can( 'publish_pages' ) ) {
			return 0;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $def['plural'],
				'post_name'    => $def['slug'],
				'post_content' => '',
			),
			true
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	public function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'dcb' ), 403 );
		}
		check_admin_referer( 'dcb_delete' );

		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		if ( $key && $this->registry->get( $key ) ) {
			$this->registry->delete( $key );
		}
		wp_safe_redirect( $this->url( array( 'deleted' => 1 ) ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Ausgabe
	 * ---------------------------------------------------------------- */

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Dynamic CPT Builder', 'dcb' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['base_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'This URL base is reserved by WordPress. Please choose another one.', 'dcb' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'dcb' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Definition removed. Existing posts are kept in the database.', 'dcb' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';

		if ( '' !== $edit ) {
			$this->render_form( '_new' === $edit ? null : $edit );
		} else {
			$this->render_list();
		}
		echo '</div>';
	}

	private function render_list() {
		$defs = $this->registry->all();
		?>
		<?php if ( DCB_I18n::active() ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Names and labels can be translated with your translation plugin (Polylang: Languages → Translations, group "Dynamic CPT Builder").', 'dcb' ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1em 0">
			<input type="hidden" name="action" value="dcb_save_base">
			<?php wp_nonce_field( 'dcb_save_base' ); ?>
			<label for="dcb-base"><strong><?php esc_html_e( 'URL base', 'dcb' ); ?></strong></label>
			<code><?php echo esc_html( untrailingslashit( home_url() ) ); ?>/</code><input type="text" id="dcb-base" name="base" class="small-text" value="<?php echo esc_attr( DCB_Registry::base() ); ?>" placeholder="p"><code>/…</code>
			<button type="submit" class="button"><?php esc_html_e( 'Save', 'dcb' ); ?></button>
			<span class="description"><?php esc_html_e( 'Prefix for all CPT addresses, e.g. "p" gives /p/bots/. Leave empty for no prefix.', 'dcb' ); ?></span>
		</form>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $this->url( array( 'edit' => '_new' ) ) ); ?>"><?php esc_html_e( 'Add new CPT', 'dcb' ); ?></a>
		</p>

		<?php if ( empty( $defs ) ) : ?>
			<p><?php esc_html_e( 'No CPTs created yet.', 'dcb' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<table class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th><?php esc_html_e( 'Name', 'dcb' ); ?></th>
				<th><?php esc_html_e( 'Key', 'dcb' ); ?></th>
				<th><?php esc_html_e( 'Address', 'dcb' ); ?></th>
				<th><?php esc_html_e( 'Fields', 'dcb' ); ?></th>
				<th><?php esc_html_e( 'Shortcode', 'dcb' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'dcb' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $defs as $key => $def ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $def['plural'] ); ?></strong></td>
					<td><code><?php echo esc_html( $key ); ?></code></td>
					<td><code>/<?php echo esc_html( DCB_Registry::path_for( $def ) ); ?>/</code></td>
					<td><?php echo esc_html( count( $def['fields'] ) ); ?></td>
					<td><code>[dcb_list cpt="<?php echo esc_html( $key ); ?>"]</code></td>
					<td>
						<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $key ) ); ?>"><?php esc_html_e( 'Entries', 'dcb' ); ?></a>
						<a class="button" href="<?php echo esc_url( $this->url( array( 'edit' => $key ) ) ); ?>"><?php esc_html_e( 'Edit', 'dcb' ); ?></a>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
							onsubmit="return confirm('<?php echo esc_js( __( 'Really remove this definition? The posts stay in the database but become invisible until you create the CPT again with the same key.', 'dcb' ) ); ?>');">
							<input type="hidden" name="action" value="dcb_delete">
							<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
							<?php wp_nonce_field( 'dcb_delete' ); ?>
							<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Remove', 'dcb' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_form( $editing ) {
		$def = $editing ? $this->registry->get( $editing ) : null;
		if ( $editing && ! $def ) {
			echo '<p>' . esc_html__( 'CPT not found.', 'dcb' ) . '</p>';
			return;
		}

		$val = array(
			'key'        => $def ? $def['key'] : '',
			'plural'     => $def ? $def['plural'] : '',
			'singular'   => $def ? $def['singular'] : '',
			'slug'       => $def ? $def['slug'] : '',
			'icon'       => $def ? $def['icon'] : 'dashicons-admin-post',
			'fields'     => $def ? DCB_Registry::fields_to_text( $def['fields'] ) : "summary|Summary|textarea\nwebsite|Website|url",
			'taxonomies' => $def ? DCB_Registry::taxonomies_to_text( $def['taxonomies'] ) : '',
		);
		$val = array_merge( $val, DCB_Registry::opts( $def ? $def : array() ) );

		$tkey   = 'dcb_form_' . get_current_user_id();
		$stored = get_transient( $tkey );
		if ( is_array( $stored ) ) {
			delete_transient( $tkey );
			if ( ! empty( $stored['errors'] ) ) {
				echo '<div class="notice notice-error"><ul>';
				foreach ( $stored['errors'] as $err ) {
					echo '<li>' . esc_html( $err ) . '</li>';
				}
				echo '</ul></div>';
			}
			if ( ! empty( $stored['input'] ) ) {
				$val = array_merge( $val, $stored['input'] );
					unset( $val['posted'] );
				if ( $editing ) {
					$val['key'] = $editing;
				}
			}
		}
		?>
		<h2><?php echo $editing ? esc_html__( 'Edit CPT', 'dcb' ) : esc_html__( 'Add new CPT', 'dcb' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="dcb_save">
			<input type="hidden" name="editing" value="<?php echo esc_attr( (string) $editing ); ?>">
			<?php wp_nonce_field( 'dcb_save' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th><label for="dcb-key"><?php esc_html_e( 'Key', 'dcb' ); ?></label></th>
					<td>
						<input type="text" id="dcb-key" name="key" class="regular-text" maxlength="20" required pattern="[a-z][a-z0-9_]{1,19}"
							value="<?php echo esc_attr( $val['key'] ); ?>" <?php disabled( (bool) $editing ); ?> placeholder="ki_bot">
						<p class="description"><?php esc_html_e( '2 to 20 characters: a-z, 0-9, _. Cannot be changed later.', 'dcb' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="dcb-plural"><?php esc_html_e( 'Name (plural)', 'dcb' ); ?></label></th>
					<td><input type="text" id="dcb-plural" name="plural" class="regular-text" required value="<?php echo esc_attr( $val['plural'] ); ?>" placeholder="AI Bots"></td>
				</tr>
				<tr>
					<th><label for="dcb-singular"><?php esc_html_e( 'Name (singular)', 'dcb' ); ?></label></th>
					<td><input type="text" id="dcb-singular" name="singular" class="regular-text" required value="<?php echo esc_attr( $val['singular'] ); ?>" placeholder="AI Bot"></td>
				</tr>
				<tr>
					<th><label for="dcb-slug"><?php esc_html_e( 'URL slug', 'dcb' ); ?></label></th>
					<td>
						<input type="text" id="dcb-slug" name="slug" class="regular-text" required value="<?php echo esc_attr( $val['slug'] ); ?>" placeholder="bots">
						<p class="description"><?php esc_html_e( 'Permalinks are refreshed automatically after changes.', 'dcb' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="dcb-icon"><?php esc_html_e( 'Menu icon', 'dcb' ); ?></label></th>
					<td>
						<input type="text" id="dcb-icon" name="icon" class="regular-text" value="<?php echo esc_attr( $val['icon'] ); ?>" placeholder="dashicons-superhero">
						<p class="description"><a href="https://developer.wordpress.org/resource/dashicons/" target="_blank" rel="noopener"><?php esc_html_e( 'Dashicons list', 'dcb' ); ?></a></p>
					</td>
				</tr>
				<tr>
					<th><label for="dcb-fields"><?php esc_html_e( 'Fields', 'dcb' ); ?></label></th>
					<td>
						<textarea id="dcb-fields" name="fields" rows="8" class="large-text code"><?php echo esc_textarea( $val['fields'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'One line per field:', 'dcb' ); ?> <code>key|Label|type|options|flag</code><br>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s list of types */
									__( 'Types: %s. Options (comma-separated) only for "select". The last column, "neutral" or "translate", controls whether the field is translated per language (default: only textarea is translated).', 'dcb' ),
									implode( ', ', DCB_Registry::TYPES )
								)
							);
							?>
							<br><?php esc_html_e( 'Example:', 'dcb' ); ?> <code>license|License|select|MIT,Apache,AGPL,Proprietary</code>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="dcb-tax"><?php esc_html_e( 'Taxonomies', 'dcb' ); ?></label></th>
					<td>
						<textarea id="dcb-tax" name="taxonomies" rows="3" class="large-text code"><?php echo esc_textarea( $val['taxonomies'] ); ?></textarea>
						<p class="description">
								<?php esc_html_e( 'One line per taxonomy:', 'dcb' ); ?> <code>key|Label|kind</code><br>
								<?php esc_html_e( 'Kind: "category" (hierarchical, default) or "tags" (flat, like tags).', 'dcb' ); ?><br>
								<?php esc_html_e( 'Example:', 'dcb' ); ?> <code>bot_vendor|Vendor</code> &nbsp; <code>bot_tag|Tags|tags</code>
							</p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Editor features', 'dcb' ); ?></th>
					<td>
						<fieldset>
							<?php
							foreach ( self::support_labels() as $feature => $label ) {
								$this->checkbox( 'supports[]', $feature, in_array( $feature, $val['supports'], true ), $label );
							}
							?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Only switch on what this CPT needs. The additional fields above always work.', 'dcb' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'WordPress taxonomies', 'dcb' ); ?></th>
					<td>
						<fieldset>
							<?php
							$this->checkbox( 'builtin_tax[]', 'category', in_array( 'category', $val['builtin_tax'], true ), __( 'Categories', 'dcb' ) );
							$this->checkbox( 'builtin_tax[]', 'post_tag', in_array( 'post_tag', $val['builtin_tax'], true ), __( 'Tags', 'dcb' ) );
							?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Shares the built-in categories and tags with normal posts. For separate lists use your own taxonomies above.', 'dcb' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Behaviour', 'dcb' ); ?></th>
					<td>
						<fieldset>
							<?php
							$this->checkbox( 'public', '1', ! empty( $val['public'] ), __( 'Public (visible on the website)', 'dcb' ) );
							$this->checkbox( 'archive', '1', ! empty( $val['archive'] ), __( 'Archive page (list of all entries)', 'dcb' ) );
							$this->checkbox( 'hierarchical', '1', ! empty( $val['hierarchical'] ), __( 'Hierarchical (parent and child entries, like pages)', 'dcb' ) );
							$this->checkbox( 'rest', '1', ! empty( $val['rest'] ), __( 'Block editor and REST API', 'dcb' ) );
							?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Hierarchical switches on "Page attributes" automatically. Without the block editor you get the classic editor.', 'dcb' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="dcb-layout"><?php esc_html_e( 'List layout', 'dcb' ); ?></label></th>
					<td>
						<select id="dcb-layout" name="layout">
							<option value="" <?php selected( $val['layout'], '' ); ?>><?php esc_html_e( 'Design default', 'dcb' ); ?></option>
							<option value="list" <?php selected( $val['layout'], 'list' ); ?>><?php esc_html_e( 'List', 'dcb' ); ?></option>
							<option value="cards" <?php selected( $val['layout'], 'cards' ); ?>><?php esc_html_e( 'Cards', 'dcb' ); ?></option>
							<option value="mosaic" <?php selected( $val['layout'], 'mosaic' ); ?>><?php esc_html_e( 'Mosaic', 'dcb' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'How the entries look in lists. The mosaic uses the featured image, so switch that feature on above.', 'dcb' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="dcb-archive-page"><?php esc_html_e( 'Archive page', 'dcb' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_pages(
							array(
								'name'              => 'archive_page',
								'id'                => 'dcb-archive-page',
								'selected'          => (int) $val['archive_page'],
								'show_option_none'  => __( '— none (use the theme archive) —', 'dcb' ),
								'option_none_value' => '0',
							)
						);
						?>
						<?php $this->checkbox( 'archive_create', '1', false, __( 'Create a new page for me', 'dcb' ) ); ?>
						<p class="description"><?php esc_html_e( 'The chosen page shows all entries below its own text, so you can write an intro. Its address becomes /base/slug/ (see the list). No shortcode needed.', 'dcb' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( $editing ? __( 'Save', 'dcb' ) : __( 'Create CPT', 'dcb' ) ); ?>
			<a href="<?php echo esc_url( $this->url() ); ?>"><?php esc_html_e( 'Cancel', 'dcb' ); ?></a>
		</form>
		<?php
	}

	/** Human-readable names of the editor features. */
	public static function support_labels() {
		return array(
			'title'           => __( 'Title', 'dcb' ),
			'editor'          => __( 'Editor (main content)', 'dcb' ),
			'thumbnail'       => __( 'Featured image', 'dcb' ),
			'excerpt'         => __( 'Excerpt', 'dcb' ),
			'author'          => __( 'Author', 'dcb' ),
			'comments'        => __( 'Comments', 'dcb' ),
			'trackbacks'      => __( 'Trackbacks and pingbacks', 'dcb' ),
			'revisions'       => __( 'Revisions', 'dcb' ),
			'page-attributes' => __( 'Page attributes (order, parent)', 'dcb' ),
			'post-formats'    => __( 'Post formats', 'dcb' ),
		);
	}

	private function checkbox( $name, $value, $checked, $label ) {
		printf(
			'<label style="display:block;margin:2px 0"><input type="checkbox" name="%1$s" value="%2$s"%3$s> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( (bool) $checked, true, false ),
			esc_html( $label )
		);
	}
}
