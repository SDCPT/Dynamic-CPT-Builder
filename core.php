<?php 
/**
 * Plugin Name: Dynamic CPT
 * Plugin URI: https://github.com/VolkanSah/Dynamic-CPT/
 * Description: Allows users to submit entries for child and youth welfare providers via a frontend form. Use shortcode [childcare_submission]
 * Version: 1.0
 * Author: Volkan Sah
 * Author URI: https://github.com/VolkanSah/
 * Text Domain: dynamic-cpt
 * Domain Path: /languages
 */

// Abort if this file is called directly
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Load plugin text domain for translations
function dynamic_cpt_load_textdomain() {
    load_plugin_textdomain( 'dynamic-cpt', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'dynamic_cpt_load_textdomain' );

// Define the table name
global $wpdb;
$table_name = $wpdb->prefix . 'dynamic_cpt_entries';

// Create the table on plugin activation
function dynamic_post_create_database_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'dynamic_cpt_entries';
    
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        title varchar(255) NOT NULL,
        address text NOT NULL,
        phone varchar(20) NOT NULL,
        email varchar(100) NOT NULL,
        hours text NOT NULL,
        description longtext NOT NULL,
        website varchar(255) DEFAULT '' NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}
register_activation_hook( __FILE__, 'dynamic_post_create_database_table' );

/**
 * Register a dynamic custom post type.
 *
 * This function creates a custom post type with settings that can be dynamically
 * adjusted based on the values stored in the 'current_portal_settings' option.
 */
function register_dynamic_post_type() {
    // Retrieve the current portal settings
    $portal_settings = get_option('current_portal_settings', array());

    // Define the labels for the custom post type
    $labels = array(
        'name'                  => !empty($portal_settings['post_type_name']) ? esc_html($portal_settings['post_type_name']) : __('Entries', 'dynamic-cpt'),
        'singular_name'         => !empty($portal_settings['singular_name']) ? esc_html($portal_settings['singular_name']) : __('Entry', 'dynamic-cpt'),
        'menu_name'             => !empty($portal_settings['menu_name']) ? esc_html($portal_settings['menu_name']) : __('Entry Management', 'dynamic-cpt'),
        'name_admin_bar'        => !empty($portal_settings['name_admin_bar']) ? esc_html($portal_settings['name_admin_bar']) : __('Entry', 'dynamic-cpt'),
        'add_new_item'          => __('Add New', 'dynamic-cpt') . ' ' . (!empty($portal_settings['singular_name']) ? esc_html($portal_settings['singular_name']) : __('Entry', 'dynamic-cpt')),
        'edit_item'             => __('Edit Entry', 'dynamic-cpt'),
        'view_item'             => __('View Entry', 'dynamic-cpt'),
        'all_items'             => __('All Entries', 'dynamic-cpt'),
        'search_items'          => __('Search', 'dynamic-cpt') . ' ' . (!empty($portal_settings['post_type_name']) ? esc_html($portal_settings['post_type_name']) : __('Entries', 'dynamic-cpt')),
        'not_found'             => __('No entries found', 'dynamic-cpt'),
        'not_found_in_trash'    => __('No entries found in Trash', 'dynamic-cpt'),
    );

    // Define the arguments for registering the custom post type
    $args = array(
        'label'                 => !empty($portal_settings['post_type_name']) ? esc_html($portal_settings['post_type_name']) : __('Entries', 'dynamic-cpt'),
        'description'           => __('Dynamic Entries', 'dynamic-cpt'),
        'labels'                => $labels,
        'hierarchical'          => false,
        'has_archive' => !empty($portal_settings['has_archive']) && $portal_settings['has_archive'] !== 'false',
        'exclude_from_search'   => false,
        'publicly_queryable'    => true,
        'public'                => true,
        'show_ui'               => true,
        'show_in_menu'          => true,
        'menu_position'         => 5,
        'show_in_admin_bar'     => true,
        'show_in_nav_menus'     => true,
        'can_export'            => true,
        'has_archive'           => !empty($portal_settings['has_archive']) && $portal_settings['has_archive'] !== 'false',
        'exclude_from_search'   => false,
        'publicly_queryable'    => true,
        'capability_type'       => !empty($portal_settings['capability_type']) ? $portal_settings['capability_type'] : 'post',
        'rewrite'               => array(
            'slug'                  => !empty($portal_settings['slug']) ? sanitize_title($portal_settings['slug']) : 'entries',
            'with_front'            => true,
            'pages'                 => true,
            'feeds'                 => true,
        ),
        'supports'              => !empty($portal_settings['supports']) ? $portal_settings['supports'] : array('title', 'editor', 'custom-fields'),
        'taxonomies'            => !empty($portal_settings['taxonomies']) ? $portal_settings['taxonomies'] : array(),
        'show_in_rest'          => true,
        'rest_base'             => !empty($portal_settings['rest_base']) ? $portal_settings['rest_base'] : 'entries',
        'menu_icon'             => !empty($portal_settings['menu_icon']) ? esc_url($portal_settings['menu_icon']) : 'dashicons-admin-post',
        'map_meta_cap'          => !empty($portal_settings['map_meta_cap']) && $portal_settings['map_meta_cap'] !== 'false',
    );

    // Register the custom post type
    register_post_type('dynamic_post_type', $args);
}

// Hook the function to the 'init' action
add_action('init', 'register_dynamic_post_type');
// portal setting spage
function portal_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php _e('Portal Settings', 'dynamic-cpt'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('portal_settings_group');
            do_settings_sections('portal_settings_group');
            $settings = get_option('current_portal_settings');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php _e('Post Type Name', 'dynamic-cpt'); ?></th>
                    <td><input type="text" name="current_portal_settings[post_type_name]" value="<?php echo isset($settings['post_type_name']) ? esc_attr($settings['post_type_name']) : ''; ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php _e('Singular Name', 'dynamic-cpt'); ?></th>
                    <td><input type="text" name="current_portal_settings[singular_name]" value="<?php echo isset($settings['singular_name']) ? esc_attr($settings['singular_name']) : ''; ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php _e('Menu Name', 'dynamic-cpt'); ?></th>
                    <td><input type="text" name="current_portal_settings[menu_name]" value="<?php echo isset($settings['menu_name']) ? esc_attr($settings['menu_name']) : ''; ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php _e('Slug', 'dynamic-cpt'); ?></th>
                    <td><input type="text" name="current_portal_settings[slug]" value="<?php echo isset($settings['slug']) ? esc_attr($settings['slug']) : ''; ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php _e('Supports', 'dynamic-cpt'); ?></th>
                    <td>
                        <?php
                        $support_options = array('title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments', 'custom-fields');
                        $current_supports = isset($settings['supports']) ? $settings['supports'] : array();
                        foreach ($support_options as $option) {
                            echo '<label><input type="checkbox" name="current_portal_settings[supports][]" value="' . $option . '" ' . (in_array($option, $current_supports) ? 'checked' : '') . '> ' . ucfirst($option) . '</label><br>';
                        }
                        ?>
                    </td>
                </tr>
<tr valign="top">
    <th scope="row"><?php _e('REST API Endpoint', 'dynamic-cpt'); ?></th>
    <td>
        <input type="text" name="current_portal_settings[rest_base]" value="<?php echo isset($settings['rest_base']) ? esc_attr($settings['rest_base']) : ''; ?>" />
        <p class="description"><?php _e('Optional: Custom endpoint for REST API. Leave blank to use the post type name.', 'dynamic-cpt'); ?></p>
    </td>
</tr>
<tr valign="top">
    <th scope="row"><?php _e('Menu Icon', 'dynamic-cpt'); ?></th>
    <td>
        <select name="current_portal_settings[menu_icon]">
            <option value="dashicons-admin-post" <?php selected(isset($settings['menu_icon']) ? $settings['menu_icon'] : '', 'dashicons-admin-post'); ?>><?php _e('Default Post', 'dynamic-cpt'); ?></option>
            <option value="dashicons-admin-page" <?php selected(isset($settings['menu_icon']) ? $settings['menu_icon'] : '', 'dashicons-admin-page'); ?>><?php _e('Page', 'dynamic-cpt'); ?></option>
            <option value="dashicons-database" <?php selected(isset($settings['menu_icon']) ? $settings['menu_icon'] : '', 'dashicons-database'); ?>><?php _e('Database', 'dynamic-cpt'); ?></option>
            <option value="dashicons-cart" <?php selected(isset($settings['menu_icon']) ? $settings['menu_icon'] : '', 'dashicons-cart'); ?>><?php _e('Cart', 'dynamic-cpt'); ?></option>
            <option value="dashicons-products" <?php selected(isset($settings['menu_icon']) ? $settings['menu_icon'] : '', 'dashicons-products'); ?>><?php _e('Products', 'dynamic-cpt'); ?></option>
            <option value="dashicons-admin-users" <?php selected(isset($settings['menu_icon']) ? $settings['menu_icon'] : '', 'dashicons-admin-users'); ?>><?php _e('Users', 'dynamic-cpt'); ?></option>
        </select>
    </td>
</tr>
                <tr valign="top">
                    <th scope="row"><?php _e('Capability Type', 'dynamic-cpt'); ?></th>
                    <td>
                        <select name="current_portal_settings[capability_type]">
                            <option value="post" <?php selected(isset($settings['capability_type']) && $settings['capability_type'] == 'post'); ?>><?php _e('Post', 'dynamic-cpt'); ?></option>
                            <option value="page" <?php selected(isset($settings['capability_type']) && $settings['capability_type'] == 'page'); ?>><?php _e('Page', 'dynamic-cpt'); ?></option>
                            <option value="custom" <?php selected(isset($settings['capability_type']) && $settings['capability_type'] == 'custom'); ?>><?php _e('Custom', 'dynamic-cpt'); ?></option>
                        </select>
                    </td>
                </tr>
<tr valign="top">
    <th scope="row"><?php _e('Automatic Permission Mapping', 'dynamic-cpt'); ?></th>
    <td>
        <input type="checkbox" name="current_portal_settings[map_meta_cap]" value="1" <?php checked(isset($settings['map_meta_cap']) && $settings['map_meta_cap']); ?> />
        <label for="map_meta_cap"><?php _e('Enable (recommended)', 'dynamic-cpt'); ?></label>
        <p class="description"><?php _e('Allows WordPress to automatically manage permissions for this Custom Post Type.', 'dynamic-cpt'); ?></p>
    </td>
</tr>
                <tr valign="top">
                    <th scope="row"><?php _e('Taxonomies', 'dynamic-cpt'); ?></th>
                    <td>
                        <?php
                        $taxonomy_options = get_taxonomies(array('public' => true), 'names');
                        $current_taxonomies = isset($settings['taxonomies']) ? $settings['taxonomies'] : array();
                        foreach ($taxonomy_options as $taxonomy) {
                            echo '<label><input type="checkbox" name="current_portal_settings[taxonomies][]" value="' . $taxonomy . '" ' . (in_array($taxonomy, $current_taxonomies) ? 'checked' : '') . '> ' . $taxonomy . '</label><br>';
                        }
                        ?>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
function save_portal_settings() {
    register_setting('portal_settings_group', 'current_portal_settings', 'sanitize_portal_settings');
}
add_action('admin_init', 'save_portal_settings');

function sanitize_portal_settings($input) {
    $sanitized_input = array();
    
    // Sanitize each setting
    $sanitized_input['post_type_name'] = sanitize_text_field($input['post_type_name']);
    $sanitized_input['singular_name'] = sanitize_text_field($input['singular_name']);
    $sanitized_input['menu_name'] = sanitize_text_field($input['menu_name']);
    $sanitized_input['slug'] = sanitize_title($input['slug']);
    $sanitized_input['supports'] = isset($input['supports']) ? array_map('sanitize_text_field', $input['supports']) : array();
    $sanitized_input['rest_base'] = sanitize_text_field($input['rest_base']);
    $sanitized_input['menu_icon'] = sanitize_text_field($input['menu_icon']);
    $sanitized_input['capability_type'] = sanitize_text_field($input['capability_type']);
    $sanitized_input['map_meta_cap'] = isset($input['map_meta_cap']) ? 1 : 0;
    $sanitized_input['taxonomies'] = isset($input['taxonomies']) ? array_map('sanitize_text_field', $input['taxonomies']) : array();
    $sanitized_input['has_archive'] = isset($input['has_archive']) ? 1 : 0;

    return $sanitized_input;
}

function portal_settings_menu() {
    add_menu_page(__('Portal Settings', 'dynamic-cpt'), __('Portal Settings', 'dynamic-cpt'), 'manage_options', 'portal-settings', 'portal_settings_page');
}

add_action('admin_menu', 'portal_settings_menu');

// Register portal settings
function register_portal_settings() {
    register_setting('portal_settings_group', 'current_portal_settings');
}
add_action('admin_init', 'register_portal_settings');
