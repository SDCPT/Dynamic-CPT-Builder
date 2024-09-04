<?php 
/**
 * Plugin Name: Dynamic CPT
 * Plugin URI: https://github.com/VolkanSah/Dynamic-CPT/
 * Description: Ermöglicht Benutzern, Einträge für Kinder- und Jugendhilfe-Anbieter über ein Frontend-Formular einzureichen. Nutze shortcode [childcare_submission]
 * Version: 1.0
 * Author: Volkan Sah
 * Author URI: https://github.com/VolkanSah/
 */
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Tabellenname definieren
global $wpdb;
$table_name = $wpdb->prefix . 'childcare_entries'; // Hier wird ein Tabellenname mit dem WordPress-Präfix erstellt

// Tabelle bei der Aktivierung erstellen
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
register_activation_hook( __FILE__, 'dynamic_cpt_database_table' );

function register_dynamic_post_type() {
    $portal_settings = get_option('current_portal_settings', array());

    // Labels anpassen je nach Portal
    $labels = array(
        'name'                  => !empty($portal_settings['post_type_name']) ? $portal_settings['post_type_name'] : 'Einträge',
        'singular_name'         => !empty($portal_settings['singular_name']) ? $portal_settings['singular_name'] : 'Eintrag',
        'menu_name'             => !empty($portal_settings['menu_name']) ? $portal_settings['menu_name'] : 'Eintrags-Verwaltung',
        'name_admin_bar'        => !empty($portal_settings['name_admin_bar']) ? $portal_settings['name_admin_bar'] : 'Eintrag',
        'add_new_item'          => 'Neuen ' . (!empty($portal_settings['singular_name']) ? $portal_settings['singular_name'] : 'Eintrag') . ' hinzufügen',
        'edit_item'             => 'Edit Post',
        'view_item'             => 'View Post',
        'all_items'             => 'All Posts',
    );

    $args = array(
        'label'                 => !empty($portal_settings['post_type_name']) ? $portal_settings['post_type_name'] : 'Einträge',
        'description'           => 'Dynamic Entries',
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

function portal_settings_page() {
    ?>
    <div class="wrap">
        <h1>Portal-Einstellungen</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('portal_settings_group');
            do_settings_sections('portal_settings_group');
            $settings = get_option('current_portal_settings');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Post Type Name</th>
                    <td><input type="text" name="current_portal_settings[post_type_name]" value="<?php echo isset($settings['post_type_name']) ? esc_attr($settings['post_type_name']) : ''; ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Singular Name</th>
                    <td><input type="text" name="current_portal_settings[singular_name]" value="<?php echo isset($settings['singular_name']) ? esc_attr($settings['singular_name']) : ''; ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Menu Name</th>
                    <td><input type="text" name="current_portal_settings[menu_name]" value="<?php echo isset($settings['menu_name']) ? esc_attr($settings['menu_name']) : ''; ?>" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function portal_settings_menu() {
    add_menu_page('Portal Einstellungen', 'Portal-Einstellungen', 'manage_options', 'portal-settings', 'portal_settings_page');
}

add_action('admin_menu', 'portal_settings_menu');

// Einstellungen registrieren
function register_portal_settings() {
    register_setting('portal_settings_group', 'current_portal_settings');
}
add_action('admin_init', 'register_portal_settings');


function dynamic_post_type_submission_form($atts) {
    // Define the form fields
    $fields = array(
        'title' => 'Name',
        'address' => 'Adresse',
        'phone' => 'Telefonnummer',
        'email' => 'E-Mail-Adresse',
        'hours' => 'Öffnungszeiten',
        'description' => 'Beschreibung',
        'website' => 'Website-URL'
    );

    // Check if the form was submitted
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Sanitize and validate the form data
        $title = sanitize_text_field($_POST['title']);
        $address = sanitize_text_field($_POST['address']);
        $phone = sanitize_text_field($_POST['phone']);
        $email = sanitize_email($_POST['email']);
        $hours = sanitize_textarea_field($_POST['hours']);
        $description = sanitize_textarea_field($_POST['description']);
        $website = esc_url_raw($_POST['website']);

        // Create a new post
        $post_id = wp_insert_post(array(
            'post_title' => $title,
            'post_content' => $description,
            'post_status' => 'draft',
            'post_type' => 'childcare'
        ), true);

        // Check for errors
        if (is_wp_error($post_id)) {
            return '<div class="dynamic_post_type-submission-error">Es ist ein Fehler beim Senden Ihres Beitrags aufgetreten. Bitte versuchen Sie es später erneut.</div>';
        }

        // Add the custom fields
        update_post_meta($post_id, 'childcare_address', $address);
        update_post_meta($post_id, 'childcare_phone', $phone);
        update_post_meta($post_id, 'childcare_email', $email);
        update_post_meta($post_id, 'childcare_hours', $hours);
        update_post_meta($post_id, 'childcare_website', $website);

        // Display a success message
        return '<div class="dynamic_post_type-submission-success">Ihr Beitrag wurde erfolgreich eingereicht und befindet sich nun in der Überprüfung.</div>';
    }

    // Display the form
    $form = '<form method="post" class="dynamic_post_type-submission-form">';
    foreach ($fields as $name => $label) {
        $form .= '<label for="' . $name . '">' . $label . '</label>';
        $form .= '<input type="text" id="' . $name . '" name="' . $name . '" required>';
    }
    $form .= '<button type="submit">Absenden</button>';
    $form .= '</form>';

    return $form;
}




