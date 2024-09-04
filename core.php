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

// Register the custom post type
function register_dynamic_post_type() {
    $portal_settings = get_option('current_portal_settings', array());

    // Adjust labels based on portal settings
    $labels = array(
        'name'                  => !empty($portal_settings['post_type_name']) ? esc_html($portal_settings['post_type_name']) : __('Entries', 'dynamic-cpt'),
        'singular_name'         => !empty($portal_settings['singular_name']) ? esc_html($portal_settings['singular_name']) : __('Entry', 'dynamic-cpt'),
        'menu_name'             => !empty($portal_settings['menu_name']) ? esc_html($portal_settings['menu_name']) : __('Entry Management', 'dynamic-cpt'),
        'name_admin_bar'        => !empty($portal_settings['name_admin_bar']) ? esc_html($portal_settings['name_admin_bar']) : __('Entry', 'dynamic-cpt'),
        'add_new_item'          => __('Add New', 'dynamic-cpt') . ' ' . (!empty($portal_settings['singular_name']) ? esc_html($portal_settings['singular_name']) : __('Entry', 'dynamic-cpt')),
        'edit_item'             => __('Edit Entry', 'dynamic-cpt'),
        'view_item'             => __('View Entry', 'dynamic-cpt'),
        'all_items'             => __('All Entries', 'dynamic-cpt'),
    );

    $args = array(
        'label'                 => !empty($portal_settings['post_type_name']) ? esc_html($portal_settings['post_type_name']) : __('Entries', 'dynamic-cpt'),
        'description'           => __('Dynamic Entries', 'dynamic-cpt'),
        'labels'                => $labels,
        'supports'              => array( 'title', 'editor', 'custom-fields' ),
        'hierarchical'          => false,
        'public'                => true,
        'show_ui'               => true,
        'show_in_menu'          => true,
        'menu_position'         => 5,
        'show_in_admin_bar'     => true,
        'show_in_nav_menus'     => true,
        'can_export'            => true,
        'has_archive'           => true,
        'exclude_from_search'   => false,
        'publicly_queryable'    => true,
        'capability_type'       => 'post',
    );

    register_post_type( 'dynamic_post_type', $args );
}
add_action('init', 'register_dynamic_post_type');

// Portal settings page
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
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
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

// Frontend form for submission
function dynamic_post_type_submission_form($atts) {
    global $wpdb;

    // Table name for dynamic entries
    $table_name = $wpdb->prefix . 'dynamic_cpt_entries';

    // Define form fields
    $fields = array(
        'title' => __('Name', 'dynamic-cpt'),
        'address' => __('Address', 'dynamic-cpt'),
        'phone' => __('Phone Number', 'dynamic-cpt'),
        'email' => __('Email Address', 'dynamic-cpt'),
        'hours' => __('Opening Hours', 'dynamic-cpt'),
        'description' => __('Description', 'dynamic-cpt'),
        'website' => __('Website URL', 'dynamic-cpt')
    );

    // Check if the form was submitted
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Sanitize and validate form data
        $title = sanitize_text_field($_POST['title']);
        $address = sanitize_text_field($_POST['address']);
        $phone = sanitize_text_field($_POST['phone']);
        $email = sanitize_email($_POST['email']);
        $hours = sanitize_textarea_field($_POST['hours']);
        $description = sanitize_textarea_field($_POST['description']);
        $website = esc_url_raw($_POST['website']);

        // Insert data into the database
        $wpdb->insert(
            $table_name,
            array(
                'title' => $title,
                'address' => $address,
                'phone' => $phone,
                'email' => $email,
                'hours' => $hours,
                'description' => $description,
                'website' => $website,
                'created_at' => current_time('mysql') // Save current timestamp
            )
        );

        // Check for database insertion errors
        if ($wpdb->last_error) {
            return '<div class="dynamic_post_type-submission-error">' . __('An error occurred while saving your entry. Please try again.', 'dynamic-cpt') . '</div>';
        }

        // Show success message
        return '<div class="dynamic_post_type-submission-success">' . __('Your entry has been successfully submitted and is under review.', 'dynamic-cpt') . '</div>';
    }

    // Display the form
    $form = '<form method="post" class="dynamic_post_type-submission-form">';
    foreach ($fields as $name => $label) {
        $form .= '<label for="' . $name . '">' . esc_html($label) . '</label>';
        $form .= '<input type="text" id="' . $name . '" name="' . $name . '" required>';
    }
    $form .= '<button type="submit">' . __('Submit', 'dynamic-cpt') . '</button>';
    $form .= '</form>';

    return $form;
}
