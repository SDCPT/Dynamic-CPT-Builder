<?php
/**
 * Frontend: [dcb_list] shortcode, archive pages and field output on single pages.
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
		add_filter( 'the_content', array( $this, 'append_archive' ) );
	}

	/** Layout priority: explicit value (shortcode) > CPT setting > Design page. */
	public function resolve_layout( array $def, $override = '' ) {
		$valid = DCB_Registry::LAYOUTS;
		if ( in_array( $override, $valid, true ) ) {
			return $override;
		}
		$own = DCB_Registry::opts( $def )['layout'];
		if ( in_array( $own, $valid, true ) ) {
			return $own;
		}
		return DCB_Design::get()['layout'];
	}

	/**
	 * Renders the entries of a CPT as list, cards or mosaic.
	 *
	 * @param int    $paged    0 = no pagination (first page only), 1+ = current page with pagination links.
	 * @param string $base_url URL of the page the list sits on (needed for pagination links).
	 */
	public function render_list( $key, array $def, $layout, $per_page, $paged = 0, $base_url = '' ) {
		$query = new WP_Query(
			array(
				'post_type'      => $key,
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 50, (int) $per_page ) ),
				'paged'          => max( 1, (int) $paged ),
				'no_found_rows'  => $paged < 1,
			)
		);
		if ( ! $query->have_posts() ) {
			return '<p>' . esc_html__( 'No entries found.', 'dcb' ) . '</p>';
		}

		DCB_Design::enqueue();
		$html = '<div class="' . esc_attr( DCB_Design::classes( 'dcb-list dcb-layout-' . $layout ) ) . '">';
		while ( $query->have_posts() ) {
			$query->the_post();
			$link  = esc_url( get_permalink() );
			$title = esc_html( get_the_title() );

			if ( 'mosaic' === $layout ) {
				$thumb = get_the_post_thumbnail( get_the_ID(), 'medium_large' );
				$html .= '<article class="dcb-item dcb-tile"><a href="' . $link . '">';
				$html .= '' !== $thumb
					? $thumb . '<span class="dcb-tile-title">' . $title . '</span>'
					: '<span class="dcb-tile-noimg"><span class="dcb-tile-title">' . $title . '</span></span>';
				$html .= '</a></article>';
				continue;
			}

			$html .= '<article class="dcb-item">';
			$html .= '<h3><a href="' . $link . '">' . $title . '</a></h3>';
			$html .= '<div class="dcb-excerpt">' . wp_kses_post( get_the_excerpt() ) . '</div>';
			$html .= $this->render_fields( get_the_ID(), $def );
			$html .= '</article>';
		}
		wp_reset_postdata();
		$html .= '</div>';

		if ( $paged >= 1 && $query->max_num_pages > 1 && '' !== $base_url ) {
			$pretty = (bool) get_option( 'permalink_structure' );
			$links  = paginate_links(
				array(
					'base'    => $pretty ? trailingslashit( $base_url ) . '%#%/' : add_query_arg( 'page', '%#%', $base_url ),
					'format'  => '',
					'current' => max( 1, (int) $paged ),
					'total'   => (int) $query->max_num_pages,
				)
			);
			if ( $links ) {
				$html .= '<nav class="dcb-pagination" aria-label="' . esc_attr__( 'Pages', 'dcb' ) . '">' . $links . '</nav>';
			}
		}
		return $html;
	}

	/** Adds the entry list to a page that was chosen as the archive page of a CPT. */
	public function append_archive( $content ) {
		if ( ! is_page() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$key = $this->registry->archive_owner( get_the_ID() );
		$def = $key ? $this->registry->get( $key ) : null;
		if ( ! $def ) {
			return $content;
		}
		$per_page = (int) apply_filters( 'dcb_archive_per_page', 12, $key );
		$paged    = max( 1, (int) get_query_var( 'page' ) );
		return $content . $this->render_list( $key, $def, $this->resolve_layout( $def ), $per_page, $paged, get_permalink() );
	}

	/** Outputs a post's field values as a definition list. */
	public function render_fields( $post_id, array $def ) {
		DCB_Design::enqueue();
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
		return '' === $items ? '' : '<dl class="' . esc_attr( DCB_Design::classes( 'dcb-fields' ) ) . '">' . $items . '</dl>';
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

	/** [dcb_list cpt="ki_bot" per_page="10" layout="mosaic"] */
	public function shortcode_list( $atts ) {
		$atts = shortcode_atts(
			array(
				'cpt'      => '',
				'per_page' => 10,
				'layout'   => '', // "list", "cards" or "mosaic"; empty = CPT setting / Design page.
			),
			$atts,
			'dcb_list'
		);

		$key = sanitize_key( $atts['cpt'] );
		$def = $this->registry->get( $key );
		if ( ! $def || ! post_type_exists( $key ) ) {
			return current_user_can( 'manage_options' ) ? '<p>' . esc_html__( 'dcb_list: unknown CPT.', 'dcb' ) . '</p>' : '';
		}
		return $this->render_list( $key, $def, $this->resolve_layout( $def, (string) $atts['layout'] ), $atts['per_page'] );
	}
}
