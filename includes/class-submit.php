<?php
/**
 * Public submission form: [dcb_submit]
 *
 * Visitors pick a category (a CPT that has submissions switched on); the fields shown are exactly the
 * fields of that CPT. Entries are saved as "pending" and never published automatically.
 *
 * Protection, in order: honeypot, signed time token (too fast / too old / forged), rate limit per IP,
 * strict whitelisting of every value, flood cap on pending entries, consent checkbox, and an
 * image-only upload that is checked and re-encoded (see DCB_Upload). Nothing a visitor types is ever
 * output unescaped, and no HTML is stored.
 *
 * @package DynamicCPTBuilder
 */

defined( 'ABSPATH' ) || exit;

class DCB_Submit {

	const MIN_SECONDS = 4;
	const MAX_SECONDS = 86400;

	/** @var DCB_Registry */
	private $registry;

	/** @var array|null Result of a failed submission in this request (errors and entered values). */
	private $failed = null;

	public function __construct( DCB_Registry $registry ) {
		$this->registry = $registry;
	}

	public function init() {
		add_shortcode( 'dcb_submit', array( $this, 'shortcode' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle' ) );
	}

	/** CPTs that accept public submissions, optionally limited to a comma-separated list of keys. */
	public function submittable( $only = '' ) {
		$only = array_filter( array_map( 'sanitize_key', explode( ',', (string) $only ) ) );
		$out  = array();
		foreach ( $this->registry->all() as $key => $def ) {
			$o = DCB_Registry::opts( $def );
			if ( ! $o['submit'] || ! $o['public'] || ! post_type_exists( $key ) ) {
				continue;
			}
			if ( $only && ! in_array( $key, $only, true ) ) {
				continue;
			}
			$out[ $key ] = $def;
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Time token: proves the form was loaded a moment ago, works with page caches
	 * ---------------------------------------------------------------- */

	public static function make_token( $ts = null ) {
		$ts = null === $ts ? time() : (int) $ts;
		return $ts . '.' . hash_hmac( 'sha256', (string) $ts, wp_salt( 'auth' ) );
	}

	/** @return true|string true if fine, otherwise "token", "fast" or "old". */
	public static function check_token( $token, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( ! is_string( $token ) ) {
			return 'token';
		}
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return 'token';
		}
		if ( ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) ), $parts[1] ) ) {
			return 'token';
		}
		$age = $now - (int) $parts[0];
		if ( $age < self::MIN_SECONDS ) {
			return 'fast';
		}
		return $age > self::MAX_SECONDS ? 'old' : true;
	}

	private static function token_message( $code ) {
		if ( 'fast' === $code ) {
			return __( 'That was very fast. Please check your entry and send it again.', 'dcb' );
		}
		if ( 'old' === $code ) {
			return __( 'The form has expired. Please reload the page and try again.', 'dcb' );
		}
		return __( 'The form could not be verified. Please reload the page and try again.', 'dcb' );
	}

	/** Counts attempts per hour and per IP (only a hash of the IP is stored, and only temporarily). */
	private function rate_limited( $ip ) {
		$key = 'dcb_rl_' . substr( hash_hmac( 'sha256', (string) $ip, wp_salt( 'auth' ) ), 0, 24 );
		$n   = (int) get_transient( $key );
		if ( $n >= (int) apply_filters( 'dcb_submit_rate_limit', 5 ) ) {
			return true;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return false;
	}

	private function client_ip() {
		// REMOTE_ADDR only: forwarding headers can be forged by the visitor.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/* ------------------------------------------------------------------
	 * Validation: every value is whitelisted, nothing from the form is trusted
	 * ---------------------------------------------------------------- */

	/**
	 * @param array $in         Raw (unslashed) form data.
	 * @param array $candidates CPT definitions that accept submissions.
	 * @return array{cpt:string,title:string,email:string,content:string,fields:array,errors:string[]}
	 */
	public function validate( array $in, array $candidates ) {
		$v   = array( 'cpt' => '', 'title' => '', 'email' => '', 'content' => '', 'fields' => array(), 'errors' => array() );
		$cpt = isset( $in['dcb_cpt'] ) && is_string( $in['dcb_cpt'] ) ? sanitize_key( $in['dcb_cpt'] ) : '';

		if ( ! isset( $candidates[ $cpt ] ) ) {
			$v['errors'][] = __( 'Please choose a valid category.', 'dcb' );
			return $v;
		}
		$def       = $candidates[ $cpt ];
		$o         = DCB_Registry::opts( $def );
		$v['cpt'] = $cpt;

		$v['title'] = mb_substr( sanitize_text_field( isset( $in['dcb_title'] ) && is_string( $in['dcb_title'] ) ? $in['dcb_title'] : '' ), 0, 150 );
		if ( '' === $v['title'] ) {
			$v['errors'][] = __( 'Please enter a name or title.', 'dcb' );
		}

		$v['email'] = sanitize_email( isset( $in['dcb_email'] ) && is_string( $in['dcb_email'] ) ? $in['dcb_email'] : '' );
		if ( '' === $v['email'] || ! is_email( $v['email'] ) ) {
			$v['errors'][] = __( 'Please enter a valid email address.', 'dcb' );
		}
		if ( empty( $in['dcb_consent'] ) ) {
			$v['errors'][] = __( 'Please agree to the storage and publication of your entry.', 'dcb' );
		}

		$raw_fields = isset( $in['dcb_f'][ $cpt ] ) && is_array( $in['dcb_f'][ $cpt ] ) ? $in['dcb_f'][ $cpt ] : array();
		foreach ( $def['fields'] as $f ) {
			$raw = isset( $raw_fields[ $f['key'] ] ) ? $raw_fields[ $f['key'] ] : '';
			if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
				continue;
			}
			$clean = DCB_Registry::sanitize_value( $f, $raw );
			$clean = mb_substr( $clean, 0, 'textarea' === $f['type'] ? 5000 : 500 );
			if ( '' === $clean ) {
				/* translators: %s field label */
				$v['errors'][] = sprintf( __( 'The value for "%s" is not valid.', 'dcb' ), $f['label'] );
				continue;
			}
			$v['fields'][ $f['key'] ] = $clean;
		}

		if ( in_array( 'editor', $o['supports'], true ) ) {
			$c = isset( $in['dcb_content'][ $cpt ] ) && is_string( $in['dcb_content'][ $cpt ] ) ? $in['dcb_content'][ $cpt ] : '';
			// Plain text only: all tags are removed, so there is nothing to execute.
			$v['content'] = mb_substr( sanitize_textarea_field( $c ), 0, 5000 );
		}
		return $v;
	}

	/* ------------------------------------------------------------------
	 * Processing
	 * ---------------------------------------------------------------- */

	private static function failure( $message, array $values = array() ) {
		return array( 'status' => 'error', 'errors' => array( $message ), 'values' => $values );
	}

	/**
	 * @param array  $in    Raw (unslashed) POST data.
	 * @param array  $files $_FILES.
	 * @param string $ip    Visitor IP (REMOTE_ADDR).
	 * @return array{status:string,errors?:string[],values?:array,post_id?:int}
	 */
	public function process( array $in, array $files, $ip ) {
		// Honeypot: a human never sees this field. Pretend it worked and store nothing.
		if ( ! empty( $in['dcb_hp'] ) ) {
			return array( 'status' => 'ok' );
		}

		$token = self::check_token( isset( $in['dcb_t'] ) && is_string( $in['dcb_t'] ) ? $in['dcb_t'] : '' );
		if ( true !== $token ) {
			return self::failure( self::token_message( $token ) );
		}
		if ( $this->rate_limited( $ip ) ) {
			return self::failure( __( 'Too many submissions from your connection. Please try again later.', 'dcb' ) );
		}

		$candidates = $this->submittable();
		$v          = $this->validate( $in, $candidates );
		if ( $v['errors'] ) {
			return array( 'status' => 'error', 'errors' => $v['errors'], 'values' => $v );
		}

		$cpt = $v['cpt'];
		$def = $candidates[ $cpt ];
		$o   = DCB_Registry::opts( $def );

		$counts = wp_count_posts( $cpt );
		if ( isset( $counts->pending ) && (int) $counts->pending >= (int) apply_filters( 'dcb_submit_max_pending', 200, $cpt ) ) {
			return self::failure( __( 'We cannot accept new entries right now. Please try again later.', 'dcb' ), $v );
		}

		// Check the image before anything is stored. A file sent to a CPT without image upload is ignored.
		$image = null;
		if ( $o['submit_image'] && isset( $files[ 'dcb_image_' . $cpt ] ) && is_array( $files[ 'dcb_image_' . $cpt ] ) ) {
			$f   = $files[ 'dcb_image_' . $cpt ];
			$err = isset( $f['error'] ) ? (int) $f['error'] : UPLOAD_ERR_NO_FILE;
			if ( UPLOAD_ERR_NO_FILE !== $err ) {
				if ( UPLOAD_ERR_INI_SIZE === $err || UPLOAD_ERR_FORM_SIZE === $err ) {
					return self::failure( DCB_Upload::message( 'too_big' ), $v );
				}
				$tmp = isset( $f['tmp_name'] ) && is_string( $f['tmp_name'] ) ? $f['tmp_name'] : '';
				if ( UPLOAD_ERR_OK !== $err || '' === $tmp || ( apply_filters( 'dcb_verify_uploaded_file', true ) && ! is_uploaded_file( $tmp ) ) ) {
					return self::failure( __( 'The image could not be uploaded.', 'dcb' ), $v );
				}
				$check = DCB_Upload::inspect( $tmp, isset( $f['size'] ) ? (int) $f['size'] : 0, isset( $f['name'] ) ? (string) $f['name'] : '' );
				if ( ! $check['ok'] ) {
					return self::failure( DCB_Upload::message( $check['error'] ), $v );
				}
				$image = array( 'tmp_name' => $tmp, 'ext' => $check['ext'] );
			}
		}

		$post_id = wp_insert_post(
			array(
				'post_type'      => $cpt,
				'post_status'    => 'pending', // Never published automatically.
				'post_title'     => $v['title'],
				'post_content'   => $v['content'],
				'post_author'    => 0,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return self::failure( __( 'Your entry could not be saved. Please try again later.', 'dcb' ), $v );
		}

		foreach ( $v['fields'] as $key => $value ) {
			update_post_meta( $post_id, DCB_Registry::meta_key( $key ), $value );
		}
		// Private (underscore) meta: not shown on the website and not available through the REST API.
		update_post_meta( $post_id, '_dcb_submitter_email', $v['email'] );
		update_post_meta( $post_id, '_dcb_consent_at', gmdate( 'c' ) );

		if ( $image && '' !== $this->attach_image( $post_id, $image ) ) {
			wp_delete_post( $post_id, true ); // All or nothing.
			return self::failure( DCB_Upload::message( 'image' ), $v );
		}

		if ( function_exists( 'pll_set_post_language' ) && function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language();
			if ( $lang ) {
				pll_set_post_language( $post_id, $lang );
			}
		}

		$this->notify( $post_id, $def, $v['title'] );
		return array( 'status' => 'ok', 'post_id' => (int) $post_id );
	}

	/**
	 * Stores an already inspected image as the featured image: re-encoded, random file name,
	 * media types restricted to jpg/png/webp. Returns an error code, or '' on success.
	 */
	protected function attach_image( $post_id, array $image ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$clean = DCB_Upload::reencode( $image['tmp_name'], $image['ext'] );
		if ( ! $clean ) {
			return 'reencode'; // No image editor available: refuse rather than store the original.
		}

		$only_images = function () {
			return array(
				'jpg|jpeg' => 'image/jpeg',
				'png'      => 'image/png',
				'webp'     => 'image/webp',
			);
		};
		add_filter( 'upload_mimes', $only_images, 9999 );
		$attachment = media_handle_sideload(
			array(
				'name'     => 'dcb-' . wp_generate_password( 16, false ) . '.' . $image['ext'],
				'tmp_name' => $clean,
			),
			$post_id
		);
		remove_filter( 'upload_mimes', $only_images, 9999 );

		if ( is_wp_error( $attachment ) ) {
			if ( file_exists( $clean ) ) {
				wp_delete_file( $clean );
			}
			return 'store';
		}
		set_post_thumbnail( $post_id, $attachment );
		return '';
	}

	private function notify( $post_id, array $def, $title ) {
		if ( ! apply_filters( 'dcb_notify_admin', true ) ) {
			return;
		}
		$subject = sprintf(
			/* translators: 1 site name, 2 entry title */
			__( '[%1$s] New submission: %2$s', 'dcb' ),
			wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ),
			$title
		);
		$body = sprintf(
			/* translators: 1 category name, 2 entry title, 3 admin link */
			__( "A visitor submitted a new entry (%1\$s):\n%2\$s\n\nReview it here:\n%3\$s", 'dcb' ),
			$def['singular'],
			$title,
			admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' )
		);
		wp_mail( get_option( 'admin_email' ), str_replace( array( "\r", "\n" ), ' ', $subject ), $body );
	}

	/* ------------------------------------------------------------------
	 * Request handling and output
	 * ---------------------------------------------------------------- */

	public function maybe_handle() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST['dcb_submit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- own signed token, see process().
			return;
		}
		$out = $this->process( wp_unslash( $_POST ), $_FILES, $this->client_ip() ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( 'ok' === $out['status'] ) {
			$url = is_singular() ? get_permalink( get_queried_object_id() ) : home_url( '/' );
			wp_safe_redirect( add_query_arg( 'dcb_sent', '1', $url ) . '#dcb-form' );
			exit;
		}
		$this->failed = $out;
	}

	/** One labelled input for a field definition. */
	private function input( array $f, $cpt, $value ) {
		$id    = 'dcb-' . $cpt . '-' . $f['key'];
		$name  = 'dcb_f[' . $cpt . '][' . $f['key'] . ']';
		$label = esc_html( DCB_I18n::t( $f['label'], DCB_I18n::name( $cpt, 'field', $f['key'], 'label' ) ) );
		$value = (string) $value;

		switch ( $f['type'] ) {
			case 'textarea':
				$input = '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="5" maxlength="5000">' . esc_textarea( $value ) . '</textarea>';
				break;
			case 'select':
				$input = '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="">—</option>';
				foreach ( $f['options'] as $opt ) {
					$shown  = DCB_I18n::t( $opt, DCB_I18n::name( $cpt, 'field', $f['key'], 'option', DCB_I18n::option_id( $opt ) ) );
					$input .= '<option value="' . esc_attr( $opt ) . '"' . selected( $value, $opt, false ) . '>' . esc_html( $shown ) . '</option>';
				}
				$input .= '</select>';
				break;
			case 'checkbox':
				return '<p class="dcb-row"><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( $value, '1', false ) . '> ' . $label . '</label></p>';
			default:
				$type  = in_array( $f['type'], array( 'url', 'email', 'number', 'date' ), true ) ? $f['type'] : 'text';
				$step  = 'number' === $type ? ' step="any"' : '';
				$input = '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" maxlength="500"' . $step . '>';
		}
		return '<p class="dcb-row"><label for="' . esc_attr( $id ) . '">' . $label . '</label>' . $input . '</p>';
	}

	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'cpt' => '' ), $atts, 'dcb_submit' );
		$list = $this->submittable( $atts['cpt'] );
		if ( ! $list ) {
			return current_user_can( 'manage_options' ) ? '<p>' . esc_html__( 'dcb_submit: no CPT accepts submissions yet. Switch it on in the CPT settings.', 'dcb' ) . '</p>' : '';
		}

		DCB_Design::enqueue();
		$skin = esc_attr( DCB_Design::classes( 'dcb-form' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['dcb_sent'] ) ) {
			return '<div id="dcb-form" class="' . $skin . '"><p class="dcb-notice dcb-notice-ok" role="status">' . esc_html__( 'Thank you! Your entry was received and will be published after it has been reviewed.', 'dcb' ) . '</p></div>';
		}

		$old  = $this->failed && ! empty( $this->failed['values'] ) ? $this->failed['values'] : array();
		$html = '<form id="dcb-form" class="' . $skin . '" method="post" enctype="multipart/form-data" action="">';

		if ( $this->failed && ! empty( $this->failed['errors'] ) ) {
			$html .= '<div class="dcb-notice dcb-notice-error" role="alert"><ul>';
			foreach ( $this->failed['errors'] as $msg ) {
				$html .= '<li>' . esc_html( $msg ) . '</li>';
			}
			$html .= '</ul></div>';
		}

		$html .= '<input type="hidden" name="dcb_submit" value="1">';
		$html .= '<input type="hidden" name="dcb_t" value="' . esc_attr( self::make_token() ) . '">';
		// Honeypot, hidden from people and screen readers.
		$html .= '<p class="dcb-hp" aria-hidden="true"><label>' . esc_html__( 'Leave this field empty', 'dcb' ) . ' <input type="text" name="dcb_hp" value="" tabindex="-1" autocomplete="off"></label></p>';

		$selected = isset( $old['cpt'] ) && isset( $list[ $old['cpt'] ] ) ? $old['cpt'] : (string) key( $list );
		if ( count( $list ) > 1 ) {
			$html .= '<p class="dcb-row"><label for="dcb-cpt">' . esc_html__( 'I want to register as', 'dcb' ) . '</label><select id="dcb-cpt" name="dcb_cpt">';
			foreach ( $list as $key => $def ) {
				$name  = DCB_I18n::t( $def['singular'], DCB_I18n::name( $key, 'singular' ) );
				$html .= '<option value="' . esc_attr( $key ) . '"' . selected( $selected, $key, false ) . '>' . esc_html( $name ) . '</option>';
			}
			$html .= '</select></p>';
		} else {
			$html .= '<input type="hidden" name="dcb_cpt" value="' . esc_attr( $selected ) . '">';
		}

		$html .= '<p class="dcb-row"><label for="dcb-title">' . esc_html__( 'Name / title', 'dcb' ) . ' *</label><input type="text" id="dcb-title" name="dcb_title" maxlength="150" required value="' . esc_attr( isset( $old['title'] ) ? $old['title'] : '' ) . '"></p>';
		$html .= '<p class="dcb-row"><label for="dcb-email">' . esc_html__( 'Email (not published)', 'dcb' ) . ' *</label><input type="email" id="dcb-email" name="dcb_email" maxlength="150" required value="' . esc_attr( isset( $old['email'] ) ? $old['email'] : '' ) . '"></p>';

		// One block of fields per CPT; the script shows only the chosen one, the server reads only the chosen one.
		foreach ( $list as $key => $def ) {
			$o     = DCB_Registry::opts( $def );
			$html .= '<fieldset class="dcb-cpt-fields" data-cpt="' . esc_attr( $key ) . '"><legend>' . esc_html( DCB_I18n::t( $def['singular'], DCB_I18n::name( $key, 'singular' ) ) ) . '</legend>';
			foreach ( $def['fields'] as $f ) {
				$value = ( isset( $old['cpt'] ) && $old['cpt'] === $key && isset( $old['fields'][ $f['key'] ] ) ) ? $old['fields'][ $f['key'] ] : '';
				$html .= $this->input( $f, $key, $value );
			}
			if ( in_array( 'editor', $o['supports'], true ) ) {
				$text  = ( isset( $old['cpt'] ) && $old['cpt'] === $key ) ? $old['content'] : '';
				$html .= '<p class="dcb-row"><label for="dcb-content-' . esc_attr( $key ) . '">' . esc_html__( 'Description', 'dcb' ) . '</label><textarea id="dcb-content-' . esc_attr( $key ) . '" name="dcb_content[' . esc_attr( $key ) . ']" rows="6" maxlength="5000">' . esc_textarea( $text ) . '</textarea></p>';
			}
			if ( $o['submit_image'] ) {
				$html .= '<p class="dcb-row"><label for="dcb-image-' . esc_attr( $key ) . '">' . esc_html__( 'Image (JPG, PNG or WebP, max. 2 MB)', 'dcb' ) . '</label><input type="file" id="dcb-image-' . esc_attr( $key ) . '" name="dcb_image_' . esc_attr( $key ) . '" accept="image/jpeg,image/png,image/webp"></p>';
			}
			$html .= '</fieldset>';
		}

		$consent = apply_filters( 'dcb_consent_text', __( 'I agree that my entry is stored and, after review, published on this website. My email address is only used to contact me about my entry and is not published.', 'dcb' ) );
		$privacy = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		$html   .= '<p class="dcb-row"><label><input type="checkbox" name="dcb_consent" value="1" required> ' . esc_html( $consent );
		if ( $privacy ) {
			$html .= ' <a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener">' . esc_html__( 'Privacy policy', 'dcb' ) . '</a>';
		}
		$html .= ' *</label></p>';
		$html .= '<p><button type="submit" class="dcb-button">' . esc_html__( 'Submit entry', 'dcb' ) . '</button></p>';
		$html .= '</form>';

		$html .= "<script>(function(){var f=document.getElementById('dcb-form');if(!f){return;}var s=f.querySelector('#dcb-cpt');var sets=f.querySelectorAll('.dcb-cpt-fields');function u(){var v=s?s.value:sets[0].getAttribute('data-cpt');for(var i=0;i<sets.length;i++){var on=sets[i].getAttribute('data-cpt')===v;sets[i].hidden=!on;sets[i].disabled=!on;}}if(s){s.addEventListener('change',u);}u();})();</script>";
		return $html;
	}
}
