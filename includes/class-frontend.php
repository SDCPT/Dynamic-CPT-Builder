<?php
/**
 * Frontend: [dcb_list] shortcode and field output on single pages.
 *
 * @package DynamicCPTBuilder
 */

defined( 'ABSPATH' ) || exit;

class DCB_Frontend {

	/** @var DCB_Registry */
	private $registry;

	public function __construct( DCB_Registry $registry ) {
		$this->registry = $registry;
	}

	public function init() {
		add_shortcode( 'dcb_list', array( $this, 'shortcode_list' ) );
		add_filter( 'the_content', array( $this, 'append_fields' ) );
	}

	/** Outputs a post's field values as a definition list. */
	public function render_fields( $post_id, array $def ) {
		$items = '';
		foreach ( $def['fields'] as $f ) {
			$value = (string) get_post_meta( $post_id, DCB_Registry::meta_key( $f['key'] ), true );
			if ( '' === $value ) {
				continue;
			}
			switch ( $f['type'] ) {
				case 'url':
					$out = '<a href="' . esc_url( $value ) . '" rel="noopener nofollow">' . esc_html( $value ) . '</a>';
					break;
				case 'email':
					$out = '<a href="mailto:' . esc_attr( antispambot( $value ) ) . '">' . esc_html( antispambot( $value ) ) . '</a>';
					break;
				case 'checkbox':
					$out = esc_html__( 'Yes', 'dcb' );
					break;
				case 'textarea':
					$out = nl2br( esc_html( $value ) );
					break;
				case 'select':
					$out = esc_html( DCB_I18n::t( $value, DCB_I18n::name( $def['key'], 'field', $f['key'], 'option', DCB_I18n::option_id( $value ) ) ) );
					break;
				case 'date':
					$ts  = strtotime( $value );
					$out = esc_html( $ts ? date_i18n( get_option( 'date_format' ), $ts ) : $value );
					break;
				default:
					$out = esc_html( $value );
			}
			$label  = DCB_I18n::t( $f['label'], DCB_I18n::name( $def['key'], 'field', $f['key'], 'label' ) );
			$items .= '<dt>' . esc_html( $label ) . '</dt><dd>' . $out . '</dd>';
		}
		return '' === $items ? '' : '<dl class="dcb-fields">' . $items . '</dl>';
	}

	public function append_fields( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$def = $this->registry->get( get_post_type() );
		if ( ! $def ) {
			return $content;
		}
		return $content . $this->render_fields( get_the_ID(), $def );
	}

	/** [dcb_list cpt="ki_bot" per_page="10"] */
	public function shortcode_list( $atts ) {
		$atts = shortcode_atts(
			array(
				'cpt'      => '',
				'per_page' => 10,
			),
			$atts,
			'dcb_list'
		);

		$key = sanitize_key( $atts['cpt'] );
		$def = $this->registry->get( $key );
		if ( ! $def || ! post_type_exists( $key ) ) {
			return current_user_can( 'manage_options' ) ? '<p>' . esc_html__( 'dcb_list: unknown CPT.', 'dcb' ) . '</p>' : '';
		}

		$query = new WP_Query(
			array(
				'post_type'      => $key,
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 50, (int) $atts['per_page'] ) ),
				'no_found_rows'  => true,
			)
		);
		if ( ! $query->have_posts() ) {
			return '<p>' . esc_html__( 'No entries found.', 'dcb' ) . '</p>';
		}

		$html = '<div class="dcb-list">';
		while ( $query->have_posts() ) {
			$query->the_post();
			$html .= '<article class="dcb-item">';
			$html .= '<h3><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h3>';
			$html .= '<div class="dcb-excerpt">' . wp_kses_post( get_the_excerpt() ) . '</div>';
			$html .= $this->render_fields( get_the_ID(), $def );
			$html .= '</article>';
		}
		wp_reset_postdata();

		return $html . '</div>';
	}
}
