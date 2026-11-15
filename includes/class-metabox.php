<?php
/**
 * Meta box with the defined fields in the post editor.
 *
 * @package DynamicCPTBuilder
 */

defined( 'ABSPATH' ) || exit;

class DCB_Metabox {

	/** @var DCB_Registry */
	private $registry;

	public function __construct( DCB_Registry $registry ) {
		$this->registry = $registry;
	}

	public function init() {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	public function add() {
		foreach ( $this->registry->all() as $key => $def ) {
			// "custom-fields" support is needed for REST, but the raw meta box would only duplicate our fields.
			remove_meta_box( 'postcustom', $key, 'normal' );
			if ( empty( $def['fields'] ) ) {
				continue;
			}
			add_meta_box( 'dcb_fields', __( 'Additional fields', 'dcb' ), array( $this, 'render' ), $key, 'normal', 'high' );
		}
	}

	public function render( $post ) {
		$def = $this->registry->get( $post->post_type );
		if ( ! $def ) {
			return;
		}
		wp_nonce_field( 'dcb_save_' . $post->post_type, 'dcb_nonce' );

		echo '<table class="form-table" role="presentation">';
		foreach ( $def['fields'] as $f ) {
			$id    = 'dcb_f_' . $f['key'];
			$name  = 'dcb_f[' . $f['key'] . ']';
			$value = (string) get_post_meta( $post->ID, DCB_Registry::meta_key( $f['key'] ), true );

			$label = DCB_I18n::t( $f['label'], DCB_I18n::name( $def['key'], 'field', $f['key'], 'label' ) );
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';

			switch ( $f['type'] ) {
				case 'textarea':
					printf( '<textarea id="%s" name="%s" rows="4" class="large-text">%s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $value ) );
					break;
				case 'select':
					printf( '<select id="%s" name="%s"><option value="">—</option>', esc_attr( $id ), esc_attr( $name ) );
					foreach ( $f['options'] as $opt ) {
						printf(
							'<option value="%1$s"%2$s>%3$s</option>',
							esc_attr( $opt ),
							selected( $value, $opt, false ),
							esc_html( DCB_I18n::t( $opt, DCB_I18n::name( $def['key'], 'field', $f['key'], 'option', DCB_I18n::option_id( $opt ) ) ) )
						);
					}
					echo '</select>';
					break;
				case 'checkbox':
					printf( '<input type="checkbox" id="%s" name="%s" value="1"%s>', esc_attr( $id ), esc_attr( $name ), checked( $value, '1', false ) );
					break;
				default:
					$html_type = in_array( $f['type'], array( 'url', 'email', 'number', 'date' ), true ) ? $f['type'] : 'text';
					$step      = 'number' === $html_type ? ' step="any"' : '';
					printf( '<input type="%s" id="%s" name="%s" value="%s" class="regular-text"%s>', esc_attr( $html_type ), esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), $step ); // phpcs:ignore WordPress.Security.EscapeOutput -- $step is a fixed string.
			}
			if ( DCB_I18n::active() && ! DCB_Registry::is_translatable( $f ) ) {
				echo '<p class="description">' . esc_html__( 'Language-neutral: same value in all translations.', 'dcb' ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
	}

	public function save( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$def = $this->registry->get( $post->post_type );
		if ( ! $def ) {
			return;
		}
		if ( ! isset( $_POST['dcb_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dcb_nonce'] ) ), 'dcb_save_' . $post->post_type ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$input = isset( $_POST['dcb_f'] ) && is_array( $_POST['dcb_f'] ) ? wp_unslash( $_POST['dcb_f'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field via DCB_Registry::sanitize_value.

		foreach ( $def['fields'] as $f ) {
			$raw   = isset( $input[ $f['key'] ] ) ? $input[ $f['key'] ] : '';
			$value = DCB_Registry::sanitize_value( $f, $raw );
			$mkey  = DCB_Registry::meta_key( $f['key'] );

			if ( '' === $value ) {
				delete_post_meta( $post_id, $mkey );
			} else {
				update_post_meta( $post_id, $mkey, $value );
			}
		}
	}
}
