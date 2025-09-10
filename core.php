<?php
/**
 * Plugin Name: Multi Secure Dynamic CPTs
 * Description: A plugin to create dynamic CPTs with secure form submission to a custom database table.
 * Version: 2.5.0
 * Author: Volkan Kücükbudak
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SDCPT_PATH', plugin_dir_path(__FILE__));
define('SDCPT_URL', plugin_dir_url(__FILE__));

class MultiSecureDynamicCPT {

    private $db_version = '1.0';
    private $table_name;
    private $cpt_table;
    private $pdo = null;

    public function load_modules() {
        global $multi_secure_cpt_plugin;
        $multi_secure_cpt_plugin = $this;

        $modules_dir = SDCPT_PATH . 'modules/';
        if (is_dir($modules_dir)) {
            $modules = glob($modules_dir . '*.php');
            if (!empty($modules)) {
                foreach ($modules as $module_file) {
                    require_once $module_file;
                }
            }
        }
    }

    public $default_fields = [
        'title' => [
            'label' => 'Title',
            'type' => 'text',
            'required' => true,
            'is_title' => true
        ],
        'content' => [
            'label' => 'Content',
            'type' => 'textarea',
            'required' => false,
            'is_content' => true
        ],
        'email' => [
            'label' => 'Contact Email',
            'type' => 'email',
            'required' => true
        ],
        'phone' => [
            'label' => 'Phone Number',
            'type' => 'tel',
            'required' => false
        ]
    ];

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'secure_entries';
        $this->cpt_table = $wpdb->prefix . 'dynamic_cpts';

        register_activation_hook(__FILE__, [$this, 'activate_plugin']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate_plugin']);

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('init', [$this, 'register_cpts']);
        add_action('init', [$this, 'upgrade_tables']);
        
        add_action('wp_enqueue_scripts', [$this, 'enqueue_form_styles']);

        add_action('wp_ajax_create_new_cpt', [$this, 'ajax_create_cpt']);
        add_action('wp_ajax_delete_cpt', [$this, 'ajax_delete_cpt']);
        add_action('wp_ajax_secure_cpt_submit', [$this, 'ajax_form_submit']);
        add_action('wp_ajax_nopriv_secure_cpt_submit', [$this, 'ajax_form_submit']);

        add_shortcode('secure_cpt_form', [$this, 'render_frontend_form']);
        add_shortcode('secure_cpt_list', [$this, 'render_entries_list']);

        add_action('add_meta_boxes', [$this, 'add_custom_meta_boxes']);
        add_action('save_post', [$this, 'save_custom_meta_data']);
        
        add_action('plugins_loaded', [$this, 'load_modules']);
    }
    
    public function activate_plugin() {
        $this->create_tables();
        $this->save_styles_to_options();
    }

    public function create_tables() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $sql = "CREATE TABLE `{$this->table_name}` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            cpt_key VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            content TEXT,
            meta_data JSON,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            author_ip VARCHAR(50),
            user_agent TEXT,
            wp_post_id INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_cpt_key (cpt_key),
            INDEX idx_wp_post_id (wp_post_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        dbDelta($sql);

        $sql_cpt = "CREATE TABLE `{$this->cpt_table}` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            cpt_key VARCHAR(50) NOT NULL,
            cpt_name VARCHAR(255) NOT NULL,
            cpt_singular VARCHAR(255) NOT NULL,
            cpt_slug VARCHAR(255) NOT NULL,
            form_fields JSON,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_cpt_key (cpt_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        dbDelta($sql_cpt);

        update_option('multi_secure_cpt_db_version', $this->db_version);
    }
    
    private function save_styles_to_options() {
        $css_content = "
/* Minimalist Styles for SDCPT Forms & Lists */
.secure-cpt-form { padding: 1.5em; border: 1px solid #e0e0e0; background: #fcfcfc; border-radius: 4px; }
.secure-cpt-form .form-field { margin-bottom: 1rem; }
.secure-cpt-form label { display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9em; color: #333; }
.secure-cpt-form input[type=\"text\"], .secure-cpt-form input[type=\"email\"], .secure-cpt-form input[type=\"tel\"], .secure-cpt-form input[type=\"url\"], .secure-cpt-form input[type=\"number\"], .secure-cpt-form textarea, .secure-cpt-form select { width: 100%; box-sizing: border-box; padding: 0.75em; border: 1px solid #ccc; border-radius: 3px; background: #fff; transition: border-color 0.2s ease; }
.secure-cpt-form input:focus, .secure-cpt-form textarea:focus, .secure-cpt-form select:focus { border-color: #0073aa; outline: none; }
.secure-cpt-form select[multiple] { height: auto; min-height: 150px; }
.secure-cpt-form .checkbox-label, .secure-cpt-form .radio-label { display: flex; align-items: center; margin-bottom: 0.5rem; font-weight: normal; }
.secure-cpt-form input[type=\"checkbox\"], .secure-cpt-form input[type=\"radio\"] { width: auto; margin-right: 0.75rem; }
.secure-cpt-form button[type=\"submit\"] { display: inline-block; padding: 0.8em 1.5em; border: 1px solid #0073aa; background-color: #0073aa; color: #fff; border-radius: 3px; cursor: pointer; font-weight: 600; transition: background-color 0.2s ease; }
.secure-cpt-form button[type=\"submit\"]:hover { background-color: #005177; border-color: #005177; }
.secure-cpt-list .cpt-list-item { margin-bottom: 2em; padding-bottom: 1.5em; border-bottom: 1px solid #eee; }
.secure-cpt-list .cpt-list-item h3 a { text-decoration: none; color: #111; }
.secure-cpt-list .cpt-list-item h3 a:hover { color: #0073aa; }
.secure-cpt-list .cpt-meta-list { list-style: none; padding: 0; margin-top: 1em; font-size: 0.9em; color: #555; }
.secure-cpt-list .cpt-meta-list li { margin-bottom: 0.5em; }
        ";
        update_option('sdcpt_form_styles', trim(preg_replace('/\s+/', ' ', $css_content)));
    }
    
    public function enqueue_form_styles() {
        wp_register_style('sdcpt-form-handle', false);
        wp_enqueue_style('sdcpt-form-handle');
        $custom_css = get_option('sdcpt_form_styles');
        if ($custom_css) {
            wp_add_inline_style('sdcpt-form-handle', $custom_css);
        }
    }
    
    private function get_pdo_connection() {
        if ($this->pdo) {
            return $this->pdo;
        }
        try {
            global $wpdb;
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ];
            $this->pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
            return $this->pdo;
        } catch (PDOException $e) {
            error_log('Database Connection Error: ' . $e->getMessage());
            return false;
        }
    }

    public function add_admin_menu() {
        add_menu_page(
            __('Multi Secure CPTs', 'multi-secure-cpt'),
            __('Secure CPTs', 'multi-secure-cpt'),
            'manage_options',
            'multi-secure-cpt',
            [$this, 'main_admin_page'],
            'dashicons-forms',
            6
        );
        add_submenu_page(
            'multi-secure-cpt',
            __('Manage Entries', 'multi-secure-cpt'),
            __('Manage Entries', 'multi-secure-cpt'),
            'manage_options',
            'multi-secure-cpt-entries',
            [$this, 'manage_entries_page']
        );
        add_submenu_page(
            'multi-secure-cpt',
            __('Addons', 'multi-secure-cpt'),
            __('Addons', 'multi-secure-cpt'),
            'manage_options',
            'multi-secure-cpt-addons',
            [$this, 'addons_page']
        );
    }

    private function get_all_cpts() {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return [];
        try {
            $stmt = $pdo->query("SELECT * FROM {$this->cpt_table} WHERE is_active = 1 ORDER BY cpt_name ASC");
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }
    
    public function register_cpts() {
        $cpts = $this->get_all_cpts();
        foreach ($cpts as $cpt) {
            $labels = [
                'name' => _x($cpt['cpt_name'], 'Post Type General Name', 'multi-secure-cpt'),
                'singular_name' => _x($cpt['cpt_singular'], 'Post Type Singular Name', 'multi-secure-cpt'),
                'menu_name' => $cpt['cpt_name'],
                'name_admin_bar' => $cpt['cpt_singular'],
                'archives' => $cpt['cpt_singular'] . ' Archives',
                'attributes' => $cpt['cpt_singular'] . ' Attributes',
                'parent_item_colon' => 'Parent ' . $cpt['cpt_singular'] . ':',
                'all_items' => 'All ' . $cpt['cpt_name'],
                'add_new_item' => 'Add New ' . $cpt['cpt_singular'],
                'add_new' => 'Add New',
                'new_item' => 'New ' . $cpt['cpt_singular'],
                'edit_item' => 'Edit ' . $cpt['cpt_singular'],
                'update_item' => 'Update ' . $cpt['cpt_singular'],
                'view_item' => 'View ' . $cpt['cpt_singular'],
                'view_items' => 'View ' . $cpt['cpt_name'],
                'search_items' => 'Search ' . $cpt['cpt_name'],
                'not_found' => 'Not found',
                'not_found_in_trash' => 'Not found in Trash',
                'featured_image' => 'Featured Image',
                'set_featured_image' => 'Set featured image',
                'remove_featured_image' => 'Remove featured image',
                'use_featured_image' => 'Use as featured image',
                'insert_into_item' => 'Insert into ' . strtolower($cpt['cpt_singular']),
                'uploaded_to_this_item' => 'Uploaded to this ' . strtolower($cpt['cpt_singular']),
                'items_list' => $cpt['cpt_name'] . ' list',
                'items_list_navigation' => $cpt['cpt_name'] . ' list navigation',
                'filter_items_list' => 'Filter ' . strtolower($cpt['cpt_name']) . ' list',
            ];

            $args = [
                'labels' => $labels,
                'description' => 'Custom post type: ' . $cpt['cpt_name'],
                'public' => true,
                'publicly_queryable' => true,
                'show_ui' => true,
                'show_in_menu' => true,
                'query_var' => true,
                'rewrite' => [
                    'slug' => $cpt['cpt_slug'],
                    'with_front' => false,
                    'pages' => true,
                    'feeds' => true,
                ],
                'capability_type' => 'post',
                'has_archive' => true,
                'hierarchical' => false,
                'menu_position' => null,
                'menu_icon' => 'dashicons-admin-post',
                
                'supports' => [
                    'title',
                    'editor',
                    'author', 
                    'thumbnail',
                    'excerpt',
                    'trackbacks',
                    'custom-fields',
                    'comments',
                    'revisions',
                    'page-attributes',
                    'post-formats'
                ],
                
                'show_in_rest' => true,
                'rest_base' => $cpt['cpt_slug'],
                'rest_controller_class' => 'WP_REST_Posts_Controller',
                
                'show_in_admin_bar' => true,
                'show_in_nav_menus' => true,
                'can_export' => true,
                'exclude_from_search' => false,
                
                'has_archive' => $cpt['cpt_slug'],
                'delete_with_user' => false,
                
                'taxonomies' => ['category', 'post_tag'],
                
                'template' => [],
            ];
            
            $args = apply_filters('multi_secure_cpt_args', $args, $cpt);
            register_post_type($cpt['cpt_key'], $args);
        }
        
        if (get_option('multi_secure_cpt_flush_rewrite_rules', false)) {
            flush_rewrite_rules();
            delete_option('multi_secure_cpt_flush_rewrite_rules');
        }
    }

    public function main_admin_page() {
        $cpts = $this->get_all_cpts();
        ?>
        <div class="wrap">
            <h1><?php _e('Multi Secure Dynamic CPTs', 'multi-secure-cpt'); ?></h1>

            <div class="cpt-manager">
                <h2><?php _e('Create New CPT', 'multi-secure-cpt'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="new-cpt-key"><?php _e('CPT Key', 'multi-secure-cpt'); ?> *</label></th>
                        <td>
                            <input type="text" id="new-cpt-key" placeholder="business_listings" class="regular-text" required>
                            <p class="description"><?php _e('Unique identifier (letters, numbers, underscores only)', 'multi-secure-cpt'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="new-cpt-name"><?php _e('CPT Name (Plural)', 'multi-secure-cpt'); ?> *</label></th>
                        <td>
                            <input type="text" id="new-cpt-name" placeholder="Business Listings" class="regular-text" required>
                            <p class="description"><?php _e('Display name in plural form', 'multi-secure-cpt'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="new-cpt-singular"><?php _e('CPT Singular', 'multi-secure-cpt'); ?> *</label></th>
                        <td>
                            <input type="text" id="new-cpt-singular" placeholder="Business Listing" class="regular-text" required>
                            <p class="description"><?php _e('Display name in singular form', 'multi-secure-cpt'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="new-cpt-slug"><?php _e('URL Slug', 'multi-secure-cpt'); ?> *</label></th>
                        <td>
                            <input type="text" id="new-cpt-slug" placeholder="businesses" class="regular-text" required>
                            <p class="description"><?php _e('URL-friendly slug for this CPT', 'multi-secure-cpt'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="new-cpt-fields"><?php _e('Form Fields (JSON)', 'multi-secure-cpt'); ?></label></th>
                        <td>
                            <textarea id="new-cpt-fields" rows="8" cols="60" placeholder='<?php echo esc_attr(json_encode($this->default_fields, JSON_PRETTY_PRINT)); ?>'></textarea>
                            <p class="description"><?php _e('Define custom fields. Use "is_title": true and "is_content": true to map fields to the post title and content.', 'multi-secure-cpt'); ?></p>
                            <p>
                                <button type="button" id="load-default-fields" class="button"><?php _e('Load Default Fields', 'multi-secure-cpt'); ?></button>
                                <button type="button" id="validate-json" class="button"><?php _e('Validate JSON', 'multi-secure-cpt'); ?></button>
                            </p>
                        </td>
                    </tr>
                </table>
                <button type="button" id="create-cpt-btn" class="button button-primary"><?php _e('Create CPT', 'multi-secure-cpt'); ?></button>
            </div>

            <hr>

            <h2><?php _e('Existing CPTs', 'multi-secure-cpt'); ?></h2>
            <?php if (empty($cpts)): ?>
                <p><?php _e('No custom post types created yet.', 'multi-secure-cpt'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('CPT Key', 'multi-secure-cpt'); ?></th>
                            <th><?php _e('Name', 'multi-secure-cpt'); ?></th>
                            <th><?php _e('Slug', 'multi-secure-cpt'); ?></th>
                            <th><?php _e('Pending DB Entries', 'multi-secure-cpt'); ?></th>
                            <th><?php _e('Actions', 'multi-secure-cpt'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cpts as $cpt): ?>
                            <?php $entry_count = $this->get_entry_count($cpt['cpt_key']); ?>
                            <tr>
                                <td class="cpt-data" data-cpt-key="<?php echo esc_attr($cpt['cpt_key']); ?>" data-cpt-name="<?php echo esc_attr($cpt['cpt_name']); ?>" data-cpt-singular="<?php echo esc_attr($cpt['cpt_singular']); ?>" data-cpt-slug="<?php echo esc_attr($cpt['cpt_slug']); ?>" data-form-fields="<?php echo esc_attr($cpt['form_fields']); ?>"><code><?php echo esc_html($cpt['cpt_key']); ?></code></td>
                                <td><?php echo esc_html($cpt['cpt_name']); ?></td>
                                <td><code><?php echo esc_html($cpt['cpt_slug']); ?></code></td>
                                <td><?php echo number_format($entry_count); ?></td>
                                <td>
                                    <button class="button edit-cpt-btn"><?php _e('Edit', 'multi-secure-cpt'); ?></button>
                                    <a href="<?php echo admin_url('edit.php?post_type=' . $cpt['cpt_key']); ?>" class="button">
                                        <?php _e('View WP Posts', 'multi-secure-cpt'); ?>
                                    </a>
                                    <a href="<?php echo admin_url('admin.php?page=multi-secure-cpt-entries&cpt=' . $cpt['cpt_key']); ?>" class="button">
                                        <?php _e('View DB Entries', 'multi-secure-cpt'); ?>
                                    </a>
                                    <button class="button show-shortcode-btn" data-cpt="<?php echo esc_attr($cpt['cpt_key']); ?>">
                                        <?php _e('Show Shortcode', 'multi-secure-cpt'); ?>
                                    </button>
                                    <button class="button button-link-delete delete-cpt-btn" data-cpt="<?php echo esc_attr($cpt['cpt_key']); ?>">
                                        <?php _e('Delete', 'multi-secure-cpt'); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#load-default-fields').click(function() {
                var defaultJson = <?php echo json_encode($this->default_fields, JSON_PRETTY_PRINT); ?>;
                $('#new-cpt-fields').val(JSON.stringify(defaultJson, null, 4));
            });

            $('#validate-json').click(function() {
                var jsonText = $('#new-cpt-fields').val();
                if (!jsonText) {
                    alert('<?php _e('Please enter some JSON to validate.', 'multi-secure-cpt'); ?>');
                    return;
                }
                
                try {
                    JSON.parse(jsonText);
                    alert('<?php _e('JSON is valid!', 'multi-secure-cpt'); ?>');
                } catch (e) {
                    alert('<?php _e('Invalid JSON format:', 'multi-secure-cpt'); ?> ' + e.message);
                }
            });

            $('#create-cpt-btn').click(function() {
                var cptKey = $('#new-cpt-key').val();
                var cptName = $('#new-cpt-name').val();
                var cptSingular = $('#new-cpt-singular').val();
                var cptSlug = $('#new-cpt-slug').val();
                
                if (!cptKey || !cptName || !cptSingular || !cptSlug) {
                    alert('<?php _e('Please fill all required fields.', 'multi-secure-cpt'); ?>');
                    return;
                }
                
                if (!/^[a-z0-9_]+$/.test(cptKey)) {
                    alert('<?php _e('CPT Key can only contain lowercase letters, numbers, and underscores.', 'multi-secure-cpt'); ?>');
                    return;
                }

                var jsonText = $('#new-cpt-fields').val();
                if (jsonText) {
                    try {
                        JSON.parse(jsonText);
                    } catch (e) {
                        alert('<?php _e('Invalid JSON format in Form Fields:', 'multi-secure-cpt'); ?> ' + e.message);
                        return;
                    }
                }

                var data = {
                    action: 'create_new_cpt',
                    cpt_key: cptKey,
                    cpt_name: cptName,
                    cpt_singular: cptSingular,
                    cpt_slug: cptSlug,
                    form_fields: jsonText,
                    _wpnonce: '<?php echo wp_create_nonce('create_cpt_nonce'); ?>'
                };

                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        alert(response.data);
                        location.reload();
                    } else {
                        alert('<?php _e('Error:', 'multi-secure-cpt'); ?> ' + (response.data || '<?php _e('Unknown error occurred', 'multi-secure-cpt'); ?>'));
                    }
                }).fail(function() {
                    alert('<?php _e('Request failed. Please try again.', 'multi-secure-cpt'); ?>');
                });
            });

            $('.edit-cpt-btn').click(function() {
                var $row = $(this).closest('tr');
                var $cptData = $row.find('.cpt-data');
                $('#new-cpt-key').val($cptData.data('cpt-key')).prop('disabled', true);
                $('#new-cpt-name').val($cptData.data('cpt-name'));
                $('#new-cpt-singular').val($cptData.data('cpt-singular'));
                $('#new-cpt-slug').val($cptData.data('cpt-slug'));
                var formFields = JSON.stringify($cptData.data('form-fields'), null, 4);
                $('#new-cpt-fields').val(formFields);
                
                alert('<?php _e('CPT data loaded. Key is disabled for editing. Press "Create CPT" to save changes.', 'multi-secure-cpt'); ?>');
                $('html, body').animate({
                    scrollTop: $('#new-cpt-key').offset().top
                }, 500);
            });

            $('.show-shortcode-btn').click(function() {
                var cptKey = $(this).data('cpt');
                var formShortcode = '[secure_cpt_form cpt="' + cptKey + '"]';
                var listShortcode = '[secure_cpt_list cpt="' + cptKey + '"]';
                
                var message = '<?php _e('Form Shortcode:', 'multi-secure-cpt'); ?>\n' + formShortcode + '\n\n<?php _e('List Shortcode:', 'multi-secure-cpt'); ?>\n' + listShortcode;
                alert(message);
            });

            $('.delete-cpt-btn').click(function() {
                if (!confirm('<?php _e('Are you sure? This will delete the CPT and all associated database entries and WP posts!', 'multi-secure-cpt'); ?>')) return;

                var cpt_key = $(this).data('cpt');
                var data = {
                    action: 'delete_cpt',
                    cpt_key: cpt_key,
                    _wpnonce: '<?php echo wp_create_nonce('delete_cpt_nonce'); ?>'
                };

                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        alert(response.data);
                        location.reload();
                    } else {
                        alert('<?php _e('Error:', 'multi-secure-cpt'); ?> ' + (response.data || '<?php _e('Unknown error occurred', 'multi-secure-cpt'); ?>'));
                    }
                }).fail(function() {
                    alert('<?php _e('Request failed. Please try again.', 'multi-secure-cpt'); ?>');
                });
            });
        });
        </script>

        <style>
        .cpt-manager { background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-bottom: 20px; }
        .form-table th { width: 200px; }
        .button-link-delete { color: #d63638; }
        .button-link-delete:hover { color: #ff0000; }
        </style>
        <?php
    }
    
    public function addons_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Multi Secure CPT Addons', 'multi-secure-cpt'); ?></h1>
            <p><?php _e('Addons will be managed here soon.', 'multi-secure-cpt'); ?></p>
        </div>
        <?php
    }

    private function get_entry_count($cpt_key) {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return 0;
        
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE cpt_key = ? AND status = 'pending'");
            $stmt->execute([$cpt_key]);
            return $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('Multi Secure CPT Count Error: ' . $e->getMessage());
            return 0;
        }
    }

    public function ajax_create_cpt() {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'create_cpt_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed', 403);
        }

        $pdo = $this->get_pdo_connection();
        if (!$pdo) {
            wp_send_json_error('Database connection failed');
        }

        $cpt_key = sanitize_key($_POST['cpt_key'] ?? '');
        $cpt_name = sanitize_text_field($_POST['cpt_name'] ?? '');
        $cpt_singular = sanitize_text_field($_POST['cpt_singular'] ?? '');
        $cpt_slug = sanitize_title($_POST['cpt_slug'] ?? '');
        $form_fields_json = wp_unslash($_POST['form_fields'] ?? '');

        if (empty($cpt_key) || empty($cpt_name) || empty($cpt_singular) || empty($cpt_slug)) {
            wp_send_json_error('All fields are required.');
        }

        if (strlen($cpt_key) > 50) {
            wp_send_json_error('CPT Key too long (max 50 characters).');
        }
        
        $form_fields_decoded = [];
        if (!empty($form_fields_json)) {
            $form_fields_decoded = json_decode($form_fields_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error('Invalid JSON in form fields: ' . json_last_error_msg());
            }

            if (is_array($form_fields_decoded) && count($form_fields_decoded) === 1 && isset($form_fields_decoded[0]) && is_array($form_fields_decoded[0])) {
                $form_fields_decoded = $form_fields_decoded[0];
            }
        }
        
        $form_fields = !empty($form_fields_decoded) ? json_encode($form_fields_decoded) : '';

        try {
            $pdo->beginTransaction();
            $check = $pdo->prepare("SELECT id, is_active FROM {$this->cpt_table} WHERE cpt_key = ?");
            $check->execute([$cpt_key]);
            $existing = $check->fetch();

            if ($existing) {
                $upd = $pdo->prepare("UPDATE {$this->cpt_table} SET cpt_name = ?, cpt_singular = ?, cpt_slug = ?, form_fields = ?, is_active = 1, updated_at = NOW() WHERE cpt_key = ?");
                $res = $upd->execute([$cpt_name, $cpt_singular, $cpt_slug, $form_fields, $cpt_key]);
                if (!$res) {
                    $pdo->rollBack();
                    wp_send_json_error('Failed to update CPT.');
                }
                $message = 'CPT updated successfully';
            } else {
                $stmt = $pdo->prepare("INSERT INTO {$this->cpt_table} (cpt_key, cpt_name, cpt_singular, cpt_slug, form_fields) VALUES (?, ?, ?, ?, ?)");
                $result = $stmt->execute([$cpt_key, $cpt_name, $cpt_singular, $cpt_slug, $form_fields]);
                if (!$result) {
                    $pdo->rollBack();
                    wp_send_json_error('Failed to create CPT');
                }
                $message = 'CPT created successfully';
            }
            
            $pdo->commit();
            update_option('multi_secure_cpt_flush_rewrite_rules', true);
            wp_send_json_success($message);

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Multi Secure CPT Create Error: ' . $e->getMessage());
            wp_send_json_error('Database error: ' . $e->getMessage());
        }
    }

    public function ajax_delete_cpt() {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'delete_cpt_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed', 403);
        }

        $cpt_key = sanitize_key($_POST['cpt_key'] ?? '');
        if (empty($cpt_key)) {
            wp_send_json_error('Invalid CPT key.');
        }

        $pdo = $this->get_pdo_connection();
        if (!$pdo) {
            wp_send_json_error('Database connection failed');
        }

        try {
            $pdo->beginTransaction();
            $postIds = [];
            $stmtPosts = $pdo->prepare("SELECT wp_post_id FROM {$this->table_name} WHERE cpt_key = ? AND wp_post_id IS NOT NULL");
            $stmtPosts->execute([$cpt_key]);
            $rows = $stmtPosts->fetchAll();
            foreach ($rows as $r) {
                if (!empty($r['wp_post_id'])) $postIds[] = (int)$r['wp_post_id'];
            }
            $wp_query = new WP_Query(['post_type' => $cpt_key, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1]);
            if ($wp_query && !is_wp_error($wp_query)) {
                foreach ($wp_query->posts as $pid) { $postIds[] = (int)$pid; }
            }
            $postIds = array_unique($postIds);
            $stmtEntries = $pdo->prepare("DELETE FROM {$this->table_name} WHERE cpt_key = ?");
            $stmtEntries->execute([$cpt_key]);
            if (!empty($postIds)) {
                foreach ($postIds as $pid) { if ($pid && get_post($pid)) { wp_delete_post($pid, true); } }
            }
            $stmt = $pdo->prepare("DELETE FROM {$this->cpt_table} WHERE cpt_key = ?");
            $result = $stmt->execute([$cpt_key]);

            if ($result) {
                $pdo->commit();
                update_option('multi_secure_cpt_flush_rewrite_rules', true);
                wp_send_json_success('CPT and all associated data deleted successfully');
            } else {
                $pdo->rollBack();
                wp_send_json_error('Failed to delete CPT');
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Multi Secure CPT Delete Error: ' . $e->getMessage());
            wp_send_json_error('Database error: ' . $e->getMessage());
        }
    }
    
    public function deactivate_plugin() {
        delete_option('sdcpt_form_styles');
        update_option('multi_secure_cpt_flush_rewrite_rules', true);
    }

    public function manage_entries_page() {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) {
            echo '<div class="error"><p>' . __('Database connection failed.', 'multi-secure-cpt') . '</p></div>';
            return;
        }

        $current_cpt = isset($_GET['cpt']) ? sanitize_key($_GET['cpt']) : '';
        $cpts = $this->get_all_cpts();

        if (isset($_POST['action']) && $_POST['action'] === 'update_status' && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'update_entry_status')) {
            $this->update_entry_status((int)$_POST['entry_id'], sanitize_text_field($_POST['status']));
        }

        ?>
        <div class="wrap">
            <h1><?php _e('Manage All Entries', 'multi-secure-cpt'); ?></h1>
            <p><?php _e('All entries pending review from the frontend form submissions.', 'multi-secure-cpt'); ?></p>

            <div class="cpt-filter">
                <form method="get">
                    <input type="hidden" name="page" value="multi-secure-cpt-entries">
                    <select name="cpt" onchange="this.form.submit()">
                        <option value=""><?php _e('All CPTs', 'multi-secure-cpt'); ?></option>
                        <?php foreach ($cpts as $cpt): ?>
                            <option value="<?php echo esc_attr($cpt['cpt_key']); ?>" <?php selected($current_cpt, $cpt['cpt_key']); ?>>
                                <?php echo esc_html($cpt['cpt_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php
            try {
                $where_clauses = ["status = 'pending'"];
                $params = [];
                if ($current_cpt) {
                    $where_clauses[] = "cpt_key = ?";
                    $params[] = $current_cpt;
                }
                $where_clause_sql = "WHERE " . implode(' AND ', $where_clauses);
                $stmt = $pdo->prepare("SELECT * FROM {$this->table_name} {$where_clause_sql} ORDER BY created_at DESC LIMIT 100");
                $stmt->execute($params);
                $entries = $stmt->fetchAll();
                ?>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('ID', 'multi-secure-cpt'); ?></th>
                            <th><?php _e('CPT', 'multi-secure-cpt'); ?></th>
                            <th><?php _e('Title', 'multi-secure-cpt'); ?></th>
                            <th><?php _e('Status', 'multi-secure-cpt'); ?></th>
                            <th><?php _e('Created', 'multi-secure-cpt'); ?></th>
                            <th><?php _e('Actions', 'multi-secure-cpt'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($entries)): ?>
                            <tr><td colspan="6"><?php _e('No pending entries found.', 'multi-secure-cpt'); ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($entries as $entry): ?>
                                <tr class="sdcpt-entry-row" 
                                    data-title="<?php echo esc_attr($entry['title']); ?>"
                                    data-content="<?php echo esc_attr($entry['content']); ?>"
                                    data-meta="<?php echo esc_attr($entry['meta_data']); ?>"
                                >
                                    <td><?php echo esc_html($entry['id']); ?></td>
                                    <td><code><?php echo esc_html($entry['cpt_key']); ?></code></td>
                                    <td><?php echo esc_html($entry['title']); ?></td>
                                    <td><?php echo esc_html(ucfirst($entry['status'])); ?></td>
                                    <td><?php echo esc_html($entry['created_at']); ?></td>
                                    <td>
                                        <button type="button" class="button show-preview-btn"><?php _e('Preview', 'multi-secure-cpt'); ?></button>
                                        <form method="post" style="display: inline-block; vertical-align: middle;">
                                            <?php wp_nonce_field('update_entry_status'); ?>
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="entry_id" value="<?php echo esc_attr($entry['id']); ?>">
                                            <select name="status" onchange="this.form.submit()">
                                                <option value="pending" <?php selected($entry['status'], 'pending'); ?>><?php _e('Pending', 'multi-secure-cpt'); ?></option>
                                                <option value="draft"><?php _e('Send to Draft', 'multi-secure-cpt'); ?></option>
                                                <option value="trash"><?php _e('Trash', 'multi-secure-cpt'); ?></option>
                                            </select>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php
            } catch (PDOException $e) {
                error_log('Multi Secure CPT Query Error: ' . $e->getMessage());
                echo '<div class="error"><p>' . __('Error loading entries.', 'multi-secure-cpt') . '</p></div>';
            }
            ?>
        </div>
        
        <div id="sdcpt-preview-modal" style="display:none;">
            <div id="sdcpt-preview-modal-content">
                <button id="sdcpt-preview-modal-close">&times;</button>
                <h2 id="sdcpt-preview-title"></h2>
                <div id="sdcpt-preview-body"></div>
                <h3><?php _e('Additional Data', 'multi-secure-cpt'); ?></h3>
                <div id="sdcpt-preview-meta"></div>
            </div>
        </div>

        <style>
            #sdcpt-preview-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 99999; display: flex; align-items: center; justify-content: center; }
            #sdcpt-preview-modal-content { background: #fff; width: 90%; max-width: 700px; max-height: 90vh; overflow-y: auto; padding: 20px 40px; position: relative; border-radius: 4px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
            #sdcpt-preview-modal-close { position: absolute; top: 10px; right: 15px; border: none; background: none; font-size: 24px; cursor: pointer; color: #666; }
            #sdcpt-preview-body, #sdcpt-preview-meta { background: #f9f9f9; border: 1px solid #eee; padding: 15px; margin-top: 10px; white-space: pre-wrap; word-wrap: break-word; }
            #sdcpt-preview-meta dl { margin: 0; } #sdcpt-preview-meta dt { font-weight: bold; } #sdcpt-preview-meta dd { margin-left: 15px; margin-bottom: 10px; }
        </style>

        <script>
            jQuery(document).ready(function($) {
                const modal = $('#sdcpt-preview-modal');
                const modalTitle = $('#sdcpt-preview-title');
                const modalBody = $('#sdcpt-preview-body');
                const modalMeta = $('#sdcpt-preview-meta');

                $('.show-preview-btn').on('click', function() {
                    const row = $(this).closest('tr');
                    const title = row.data('title');
                    const content = row.data('content');
                    const meta = row.data('meta');
                    
                    modalTitle.text(title);
                    modalBody.html(content.replace(/\n/g, '<br>'));
                    
                    let metaHtml = '<dl>';
                    if (meta && typeof meta === 'object') {
                        $.each(meta, function(key, value) {
                            metaHtml += `<dt>${key}</dt><dd>${Array.isArray(value) ? value.join(', ') : value}</dd>`;
                        });
                    }
                    metaHtml += '</dl>';
                    modalMeta.html(metaHtml);
                    modal.css('display', 'flex');
                });

                $('#sdcpt-preview-modal-close').on('click', function() {
                    modal.hide();
                });

                $(document).on('click', function(e) {
                    if ($(e.target).is(modal)) {
                        modal.hide();
                    }
                });
            });
        </script>
        <?php
    }

    private function update_entry_status($entry_id, $status) {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return false;

        $allowed_statuses = ['draft', 'pending', 'trash'];
        if (!in_array($status, $allowed_statuses)) return false;

        try {
            $stmt = $pdo->prepare("SELECT * FROM {$this->table_name} WHERE id = ?");
            $stmt->execute([(int)$entry_id]);
            $entry = $stmt->fetch();
            
            if (!$entry) return false;
            
            if ($status === 'draft') {
                $post_id = $this->create_or_update_wp_post($entry, $status);
                if ($post_id) { $this->delete_secure_entry($entry_id); }
            } else if ($status === 'trash') {
                $this->delete_secure_entry($entry_id);
            }

            return true;
        } catch (PDOException $e) {
            error_log('Multi Secure CPT Update Error: ' . $e->getMessage());
            return false;
        }
    }

    private function create_or_update_wp_post($entry, $post_status) {
        $meta_data = json_decode($entry['meta_data'], true);
        $post_data = [
            'post_title'    => sanitize_text_field($entry['title']),
            'post_content'  => wp_kses_post($entry['content']),
            'post_status'   => $post_status,
            'post_type'     => $entry['cpt_key'],
        ];
        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            error_log('Error creating/updating WP post: ' . $post_id->get_error_message());
            return false;
        }

        if ($post_id && is_array($meta_data)) {
            foreach ($meta_data as $key => $value) {
                update_post_meta($post_id, sanitize_key($key), $value);
            }
        }
        return $post_id;
    }
    
    private function delete_secure_entry($entry_id) {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return false;
        try {
            $stmt = $pdo->prepare("DELETE FROM {$this->table_name} WHERE id = ?");
            $stmt->execute([$entry_id]);
            return true;
        } catch (PDOException $e) {
            error_log('Error deleting secure entry: ' . $e->getMessage());
            return false;
        }
    }

    public function render_frontend_form($atts) {
        $atts = shortcode_atts(['cpt' => ''], $atts, 'secure_cpt_form');
        if (empty($atts['cpt'])) return '<p>'.__('CPT parameter missing.', 'multi-secure-cpt').'</p>';

        $cpt_data = $this->get_cpt_by_key($atts['cpt']);
        if (!$cpt_data) return '<p>'.__('CPT not found.', 'multi-secure-cpt').'</p>';

        $form_fields = json_decode($cpt_data['form_fields'] ?? '{}', true);
        if (empty($form_fields)) return '<p>'.__('No form fields configured.', 'multi-secure-cpt').'</p>';

        ob_start();
        ?>
        <div id="secure-cpt-form-container-<?php echo esc_attr($atts['cpt']); ?>">
            <form class="secure-cpt-form" data-cpt="<?php echo esc_attr($atts['cpt']); ?>" onsubmit="submitSecureCptForm(event)">
                <?php wp_nonce_field('secure_cpt_form_' . $atts['cpt'], 'secure_cpt_nonce'); ?>
                <?php foreach ($form_fields as $field_name => $field_config): ?>
                    <div class="form-field">
                        <label for="<?php echo esc_attr($field_name); ?>">
                            <?php echo esc_html($field_config['label'] ?? $field_name); ?>
                            <?php if (!empty($field_config['required'])): ?>*<?php endif; ?>
                        </label>
                        <?php
                        $field_type = $field_config['type'] ?? 'text';
                        $required = !empty($field_config['required']) ? ' required' : '';
                        $placeholder = !empty($field_config['placeholder']) ? ' placeholder="' . esc_attr($field_config['placeholder']) . '"' : '';
                        $attributes = 'name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '"' . $required . $placeholder;
                        switch ($field_type) {
                            case 'textarea': echo '<textarea ' . $attributes . '></textarea>'; break;
                            case 'select':
                                $select_attributes = 'name="' . esc_attr($field_name) . (!empty($field_config['multiple']) ? '[]' : '') . '" id="' . esc_attr($field_name) . '"' . $required . (!empty($field_config['multiple']) ? ' multiple' : '');
                                echo '<select ' . $select_attributes . '>';
                                if (!empty($field_config['options'])) {
                                    foreach ($field_config['options'] as $option) { echo '<option value="' . esc_attr($option['value']) . '">' . esc_html($option['label']) . '</option>'; }
                                }
                                echo '</select>';
                                break;
                            case 'checkbox':
                                if (!empty($field_config['options'])) {
                                    foreach ($field_config['options'] as $option) { echo '<label class="checkbox-label"><input type="checkbox" name="' . esc_attr($field_name) . '[]" value="' . esc_attr($option['value']) . '"' . $required . '> ' . esc_html($option['label']) . '</label>'; }
                                }
                                break;
                            case 'radio':
                                if (!empty($field_config['options'])) {
                                    foreach ($field_config['options'] as $option) { echo '<label class="radio-label"><input type="radio" name="' . esc_attr($field_name) . '" value="' . esc_attr($option['value']) . '"' . $required . '> ' . esc_html($option['label']) . '</label>'; }
                                }
                                break;
                            default: echo '<input type="' . esc_attr($field_type) . '" ' . $attributes . '>'; break;
                        }
                        ?>
                    </div>
                <?php endforeach; ?>
                <button type="submit"><?php _e('Submit', 'multi-secure-cpt'); ?></button>
            </form>
            <div class="form-message" style="display:none; margin-top:15px; padding:10px; border-radius:4px;"></div>
        </div>
        <script>
        function submitSecureCptForm(event) {
            event.preventDefault();
            var form = event.target;
            var container = form.closest('[id^="secure-cpt-form-container-"]');
            var messageDiv = container.querySelector('.form-message');
            var formData = new FormData(form);
            formData.append('action', 'secure_cpt_submit');
            formData.append('cpt_key', form.dataset.cpt);
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                messageDiv.style.display = 'block';
                if (data.success) {
                    messageDiv.style.backgroundColor = '#d4edda';
                    messageDiv.style.color = '#155724';
                    messageDiv.textContent = data.data.message;
                    form.reset();
                } else {
                    messageDiv.style.backgroundColor = '#f8d7da';
                    messageDiv.style.color = '#721c24';
                    messageDiv.textContent = 'Error: ' + (data.data.message || data.data);
                }
            })
            .catch(error => {
                messageDiv.style.display = 'block';
                messageDiv.style.backgroundColor = '#f8d7da';
                messageDiv.style.color = '#721c24';
                messageDiv.textContent = 'An unexpected error occurred.';
                console.error('Error:', error);
            });
        }
        </script>
        <?php
        return ob_get_clean();
    }

    private function get_cpt_by_key($cpt_key) {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return null;
        try {
            $stmt = $pdo->prepare("SELECT * FROM {$this->cpt_table} WHERE cpt_key = ? AND is_active = 1");
            $stmt->execute([$cpt_key]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            return null;
        }
    }

    public function ajax_form_submit() {
        $cpt_key = isset($_POST['cpt_key']) ? sanitize_key($_POST['cpt_key']) : '';
        if (empty($cpt_key) || !isset($_POST['secure_cpt_nonce']) || !wp_verify_nonce($_POST['secure_cpt_nonce'], 'secure_cpt_form_' . $cpt_key)) {
            wp_send_json_error(['message' => 'Security check failed.'], 403);
        }

        $cpt_data = $this->get_cpt_by_key($cpt_key);
        if (!$cpt_data) {
            wp_send_json_error(['message' => 'Invalid CPT.'], 400);
        }

        $form_fields = json_decode($cpt_data['form_fields'], true);
        $title_key = 'title';
        $content_key = 'content';
        foreach ($form_fields as $key => $config) {
            if (!empty($config['is_title'])) { $title_key = $key; }
            if (!empty($config['is_content'])) { $content_key = $key; }
        }

        $title = '';
        $content = '';
        $meta_data = [];
        foreach ($form_fields as $key => $config) {
            $value = null;
            if (isset($_POST[$key])) {
                if (is_array($_POST[$key])) {
                    $value = array_map('sanitize_text_field', wp_unslash($_POST[$key]));
                } else {
                    $value = ($key === $content_key) ? wp_kses_post(wp_unslash($_POST[$key])) : sanitize_text_field(wp_unslash($_POST[$key]));
                }
            }
            if (!empty($config['required']) && empty($value)) {
                wp_send_json_error(['message' => ($config['label'] ?? $key) . ' is required.'], 400);
            }
            if ($key === $title_key) { $title = $value; } 
            elseif ($key === $content_key) { $content = $value; } 
            else { if ($value !== null) { $meta_data[$key] = $value; } }
        }

        if (empty($title)) { $title = 'Submission from ' . date('Y-m-d H:i'); }

        $pdo = $this->get_pdo_connection();
        if (!$pdo) {
            wp_send_json_error(['message' => 'Database error: Could not connect.'], 500);
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO {$this->table_name} (cpt_key, title, content, meta_data, author_ip, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([ $cpt_key, $title, $content, json_encode($meta_data), $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN', $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN' ]);
            wp_send_json_success(['message' => 'Submission successful! Your entry is pending review.']);
        } catch (PDOException $e) {
            error_log('Form Submit Error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Database error: ' . $e->getMessage()], 500);
        }
    }

    public function render_entries_list($atts) {
        $atts = shortcode_atts(['cpt' => ''], $atts, 'secure_cpt_list');
        if (empty($atts['cpt'])) return '<p>'.__('CPT parameter missing.', 'multi-secure-cpt').'</p>';

        $args = [ 'post_type' => $atts['cpt'], 'post_status' => 'publish', 'posts_per_page' => 10, ];
        $query = new WP_Query($args);

        if (!$query->have_posts()) { return '<p>'.__('No entries found.', 'multi-secure-cpt').'</p>'; }

        ob_start();
        echo '<div class="secure-cpt-list">';
        while ($query->have_posts()) {
            $query->the_post();
            echo '<div class="cpt-list-item">';
            echo '<h3><a href="' . get_permalink() . '">' . get_the_title() . '</a></h3>';
            echo '<div class="cpt-list-content">' . get_the_excerpt() . '</div>';

            $cpt_data = $this->get_cpt_by_key($atts['cpt']);
            if ($cpt_data) {
                $form_fields = json_decode($cpt_data['form_fields'], true);
                $title_key = 'title';
                $content_key = 'content';
                 foreach ($form_fields as $key => $config) {
                    if (!empty($config['is_title'])) $title_key = $key;
                    if (!empty($config['is_content'])) $content_key = $key;
                }
                echo '<ul class="cpt-meta-list">';
                foreach ($form_fields as $key => $config) {
                    if ($key === $title_key || $key === $content_key) continue;
                    $value = get_post_meta(get_the_ID(), $key, true);
                    if ($value) {
                         if (is_array($value)) { $value = implode(', ', $value); }
                        echo '<li><strong>' . esc_html($config['label'] ?? ucfirst(str_replace('_', ' ', $key))) . ':</strong> ' . esc_html($value) . '</li>';
                    }
                }
                echo '</ul>';
            }
            echo '</div>';
        }
        echo '</div>';
        wp_reset_postdata();
        return ob_get_clean();
    }

    public function display_entry_meta_on_frontend($content) {
        if (!is_singular()) { return $content; }
        $post_id = get_the_ID();
        $post_type = get_post_type($post_id);
        $cpt_data = $this->get_cpt_by_key($post_type);
        if (!$cpt_data) return $content;
        $form_fields = json_decode($cpt_data['form_fields'], true);
        if (empty($form_fields)) return $content;
        $title_key = 'title';
        $content_key = 'content';
        foreach ($form_fields as $key => $config) {
            if (!empty($config['is_title'])) $title_key = $key;
            if (!empty($config['is_content'])) $content_key = $key;
        }
        ob_start();
        echo '<div class="secure-entry-meta">';
        echo '<h2>' . __('Additional Information', 'multi-secure-cpt') . '</h2>';
        echo '<ul>';
        foreach ($form_fields as $key => $config) {
            if ($key === $title_key || $key === $content_key) continue;
            $value = get_post_meta($post_id, $key, true);
            if ($value) {
                if (is_array($value)) { $value = implode(', ', $value); }
                echo '<li><strong>' . esc_html($config['label'] ?? ucfirst(str_replace('_', ' ', $key))) . ':</strong> ' . esc_html($value) . '</li>';
            }
        }
        echo '</ul>';
        echo '</div>';
        return $content . ob_get_clean();
    }

    public function add_custom_meta_boxes() {
        $cpts = $this->get_all_cpts();
        foreach ($cpts as $cpt) {
            add_meta_box( $cpt['cpt_key'] . '_custom_fields', __('Additional Fields', 'multi-secure-cpt'), [$this, 'render_custom_meta_box'], $cpt['cpt_key'], 'normal', 'high' );
        }
    }

    public function render_custom_meta_box($post) {
        $cpt_key = $post->post_type;
        $cpt_data = $this->get_cpt_by_key($cpt_key);
        if (!$cpt_data) return;
        $form_fields = json_decode($cpt_data['form_fields'], true);
        $title_key = 'title';
        $content_key = 'content';
        foreach ($form_fields as $key => $config) {
            if (!empty($config['is_title'])) $title_key = $key;
            if (!empty($config['is_content'])) $content_key = $key;
        }
        wp_nonce_field('save_custom_meta', 'custom_meta_nonce');
        foreach ($form_fields as $field_name => $field_config) {
            if ($field_name === $title_key || $field_name === $content_key) continue;
            $value = get_post_meta($post->ID, $field_name, true);
            $field_type = $field_config['type'] ?? 'text';
            $placeholder = !empty($field_config['placeholder']) ? ' placeholder="' . esc_attr($field_config['placeholder']) . '"' : '';
            ?>
            <div class="form-field" style="margin-bottom:10px;">
                <label for="<?php echo esc_attr($field_name); ?>" style="display:block; font-weight:bold; margin-bottom:5px;"><?php echo esc_html($field_config['label'] ?? $field_name); ?></label>
                <?php switch ($field_type) {
                    case 'textarea': echo '<textarea name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '" class="widefat" rows="4"' . $placeholder . '>' . esc_textarea(is_array($value) ? implode(', ', $value) : $value) . '</textarea>'; break;
                    case 'select':
                        $is_multiple = !empty($field_config['multiple']);
                        $select_name = esc_attr($field_name) . ($is_multiple ? '[]' : '');
                        $current_values = is_array($value) ? $value : [$value];
                        echo '<select name="' . $select_name . '" id="' . esc_attr($field_name) . '" class="widefat"' . ($is_multiple ? ' multiple' : '') . '>';
                        if (!empty($field_config['options'])) {
                            foreach ($field_config['options'] as $option) {
                                $selected = in_array($option['value'], $current_values) ? ' selected' : '';
                                echo '<option value="' . esc_attr($option['value']) . '"' . $selected . '>' . esc_html($option['label']) . '</option>';
                            }
                        }
                        echo '</select>';
                        break;
                    case 'checkbox':
                        $current_values = is_array($value) ? $value : [$value];
                        if (!empty($field_config['options'])) {
                            foreach ($field_config['options'] as $option) {
                                $checked = in_array($option['value'], $current_values) ? ' checked' : '';
                                echo '<label class="checkbox-label" style="display: block; margin-bottom: 5px; font-weight:normal;"><input type="checkbox" name="' . esc_attr($field_name) . '[]" value="' . esc_attr($option['value']) . '"' . $checked . '> ' . esc_html($option['label']) . '</label>';
                            }
                        }
                        break;
                    case 'radio':
                        if (!empty($field_config['options'])) {
                            foreach ($field_config['options'] as $option) {
                                $checked = ($value === $option['value']) ? ' checked' : '';
                                echo '<label class="radio-label" style="display: block; margin-bottom: 5px; font-weight:normal;"><input type="radio" name="' . esc_attr($field_name) . '" value="' . esc_attr($option['value']) . '"' . $checked . '> ' . esc_html($option['label']) . '</label>';
                            }
                        }
                        break;
                    default: echo '<input type="' . esc_attr($field_type) . '" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '" value="' . esc_attr(is_array($value) ? implode(', ', $value) : $value) . '" class="widefat"' . $placeholder . '>'; break;
                } ?>
            </div>
            <?php
        }
    }

    public function save_custom_meta_data($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['custom_meta_nonce']) || !wp_verify_nonce($_POST['custom_meta_nonce'], 'save_custom_meta')) return;
        if (!current_user_can('edit_post', $post_id)) return;
        $cpt_key = get_post_type($post_id);
        $cpt_data = $this->get_cpt_by_key($cpt_key);
        if (!$cpt_data) return;
        $form_fields = json_decode($cpt_data['form_fields'], true);
        $title_key = 'title';
        $content_key = 'content';
        foreach ($form_fields as $key => $config) {
            if (!empty($config['is_title'])) $title_key = $key;
            if (!empty($config['is_content'])) $content_key = $key;
        }
        foreach ($form_fields as $field_name => $field_config) {
            if ($field_name === $title_key || $field_name === $content_key) continue;
            if (isset($_POST[$field_name])) {
                $value = is_array($_POST[$field_name]) ? array_map('sanitize_text_field', wp_unslash($_POST[$field_name])) : sanitize_text_field(wp_unslash($_POST[$field_name]));
                update_post_meta($post_id, $field_name, $value);
            } else {
                if (($field_config['type'] ?? 'text') === 'checkbox') {
                    delete_post_meta($post_id, $field_name);
                }
            }
        }
    }
    
    public function upgrade_tables() {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return false;
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM {$this->table_name} LIKE 'wp_post_id'");
            $stmt->execute();
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec("ALTER TABLE {$this->table_name} ADD COLUMN wp_post_id INT UNSIGNED DEFAULT NULL");
            }
            $stmt = $pdo->prepare("SHOW INDEX FROM {$this->table_name} WHERE Key_name = 'idx_wp_post_id'");
            $stmt->execute();
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec("ALTER TABLE {$this->table_name} ADD INDEX idx_wp_post_id (wp_post_id)");
            }
        } catch (PDOException $e) {
            error_log('Multi Secure CPT Upgrade Error: ' . $e->getMessage());
        }
    }

    private function get_entry_by_post_id($post_id) {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return null;
        try {
            $stmt = $pdo->prepare("SELECT * FROM {$this->table_name} WHERE wp_post_id = ?");
            $stmt->execute([$post_id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            return null;
        }
    }
}

new MultiSecureDynamicCPT();
