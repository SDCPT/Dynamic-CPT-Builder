<?php
/**
 * Archive pages: a normal WordPress page is used as the archive of a CPT and answers at
 * /{base}/{slug}/ (e.g. /p/bots/). The entry list is added below the page's own text.
 *
 * Using a real page keeps this independent of the theme: it works with classic and block themes,
 * and editors can write an intro text, set a featured image or SEO data like on any other page.
 *
 * @package DynamicCPTBuilder
 */

defined( 'ABSPATH' ) || exit;

class DCB_Archive {

	/** @var DCB_Registry */
	private $registry;

	public function __construct( DCB_Registry $registry ) {
		$this->registry = $registry;
	}

	public function init() {
		add_action( 'init', array( $this, 'add_rules' ), 6 );
		add_filter( 'page_link', array( $this, 'page_link' ), 10, 2 );
		add_filter( 'post_type_archive_link', array( $this, 'archive_link' ), 10, 2 );
	}

	/** Maps /p/bots/ (and /p/bots/2/ for page 2) to the chosen page. */
	public function add_rules() {
		foreach ( $this->registry->all() as $def ) {
			$id = DCB_Registry::archive_page_id( $def );
			if ( ! $id ) {
				continue;
			}
			$path = preg_quote( DCB_Registry::path_for( $def ), '#' );
			add_rewrite_rule( '^' . $path . '/?$', 'index.php?page_id=' . $id, 'top' );
			add_rewrite_rule( '^' . $path . '/([0-9]{1,})/?$', 'index.php?page_id=' . $id . '&page=$matches[1]', 'top' );
		}
	}

	/**
	 * Makes the page report the nice address everywhere (menus, "View page", canonical URL).
	 * The page's old address then redirects to the new one by itself.
	 */
	public function page_link( $link, $post_id ) {
		if ( ! get_option( 'permalink_structure' ) ) {
			return $link; // Plain permalinks: no rewrite rules, keep ?page_id=.
		}
		$key = $this->registry->archive_owner( $post_id );
		$def = $key ? $this->registry->get( $key ) : null;
		return $def ? home_url( '/' . DCB_Registry::path_for( $def ) . '/' ) : $link;
	}

	/** Anything asking for the archive link of the CPT gets the page instead. */
	public function archive_link( $link, $post_type ) {
		$def = $this->registry->get( $post_type );
		$id  = $def ? DCB_Registry::archive_page_id( $def ) : 0;
		return $id ? get_permalink( $id ) : $link;
	}
}
