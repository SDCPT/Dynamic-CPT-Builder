<?php
/**
 * Plugin Name: Multi Secure Dynamic CPTs
 * Description: A plugin to create dynamic CPTs with secure form submission to a custom database table.
 * Version: 1.0.2
 * Author: Volkan Kücükbudak
 */

if (!defined('ABSPATH')) {
    exit;
}

// HOW TO USE THE NEW TEMPLATING FUNCTION:
// To display the custom fields on a single post page,
// place the following code in your theme's single-{cpt-key}.php file
// or hook it into 'the_content' filter:
//
// add_filter('the_content', function($content) {
//     $plugin = new MultiSecureDynamicCPT();
//     return $content . $plugin->display_entry_meta_on_frontend($content);
// });

class MultiSecureDynamicCPT {

    private $db_version = '1.0';
    private $table_name;
    private $cpt_table;
    private $pdo = null;

    public $default_fields = [
        'title' => [
            'label' => 'Title',
            'type' => 'text',
            'required' => true
        ],
        'content' => [
            'label' => 'Content',
            'type' => 'textarea',
            'required' => false
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

        register_activation_hook(__FILE__, [$this, 'create_tables']);

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('init', [$this, 'register_cpts']);
        add_action('init', [$this, 'upgrade_tables']);

        add_action('wp_ajax_create_new_cpt', [$this, 'ajax_create_cpt']);
        add_action('wp_ajax_delete_cpt', [$this, 'ajax_delete_cpt']);
        add_action('wp_ajax_secure_cpt_submit', [$this, 'ajax_form_submit']);
        add_action('wp_ajax_nopriv_secure_cpt_submit', [$this, 'ajax_form_submit']);

        add_shortcode('secure_cpt_form', [$this, 'render_frontend_form']);
        add_shortcode('secure_cpt_list', [$this, 'render_entries_list']);

        add_action('add_meta_boxes', [$this, 'add_custom_meta_boxes']);
        add_action('save_post', [$this, 'save_custom_meta_data']);
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
            ];
            $args = [
                'labels' => $labels,
                'public' => true,
                'publicly_queryable' => true,
                'show_ui' => true,
                'show_in_menu' => true,
                'query_var' => true,
                'rewrite' => ['slug' => $cpt['cpt_slug']],
                'capability_type' => 'post',
                'has_archive' => true,
                'hierarchical' => false,
                'menu_position' => null,
                'supports' => ['title', 'editor', 'author', 'thumbnail', 'excerpt'],
                'show_in_rest' => true
            ];
            register_post_type($cpt['cpt_key'], $args);
        }
    }

    /**
     * Main admin page
     */
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
                            <p class="description"><?php _e('Define custom fields in JSON format or use default fields', 'multi-secure-cpt'); ?></p>
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
                            <th><?php _e('DB Entries', 'multi-secure-cpt'); ?></th>
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
                                        <?php _e('Delete', 'multi-secure-cpt'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Load default fields
            $('#load-default-fields').click(function() {
                var defaultJson = <?php echo json_encode($this->default_fields, JSON_PRETTY_PRINT); ?>;
                $('#new-cpt-fields').val(JSON.stringify(defaultJson, null, 4));
            });

            // Validate JSON
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

            // Create CPT
            $('#create-cpt-btn').click(function() {
                var cptKey = $('#new-cpt-key').val();
                var cptName = $('#new-cpt-name').val();
                var cptSingular = $('#new-cpt-singular').val();
                var cptSlug = $('#new-cpt-slug').val();
                
                // Basic validation
                if (!cptKey || !cptName || !cptSingular || !cptSlug) {
                    alert('<?php _e('Please fill all required fields.', 'multi-secure-cpt'); ?>');
                    return;
                }
                
                // Validate CPT key format
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

            // Edit CPT
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


            // Show shortcode
            $('.show-shortcode-btn').click(function() {
                var cptKey = $(this).data('cpt');
                var formShortcode = '[secure_cpt_form cpt="' + cptKey + '"]';
                var listShortcode = '[secure_cpt_list cpt="' + cptKey + '"]';
                
                var message = '<?php _e('Form Shortcode:', 'multi-secure-cpt'); ?>\n' + formShortcode + '\n\n<?php _e('List Shortcode:', 'multi-secure-cpt'); ?>\n' + listShortcode;
                alert(message);
            });

            // Delete CPT
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
        .cpt-manager {
            background: #fff;
            padding: 20px;
            border: 1px solid #ccd0d4;
            margin-bottom: 20px;
        }
        .form-table th {
            width: 200px;
        }
        .button-link-delete {
            color: #d63638;
        }
        .button-link-delete:hover {
            color: #ff0000;
        }
        </style>
        <?php
    }

    /**
     * Get entry count for a CPT
     */
    private function get_entry_count($cpt_key) {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return 0;
        
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE cpt_key = ?");
            $stmt->execute([$cpt_key]);
            return $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('Multi Secure CPT Count Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * AJAX: Create new CPT
     */
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
        $form_fields = wp_unslash($_POST['form_fields'] ?? '');

        // Validation
        if (empty($cpt_key) || empty($cpt_name) || empty($cpt_singular) || empty($cpt_slug)) {
            wp_send_json_error('All fields are required.');
        }

        if (strlen($cpt_key) > 50) {
            wp_send_json_error('CPT Key too long (max 50 characters).');
        }

        if (!empty($form_fields)) {
            json_decode($form_fields);
            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error('Invalid JSON in form fields: ' . json_last_error_msg());
            }
        }

        try {
            $pdo->beginTransaction();

            // Check if CPT key already exists
            $check = $pdo->prepare("SELECT id, is_active FROM {$this->cpt_table} WHERE cpt_key = ?");
            $check->execute([$cpt_key]);
            $existing = $check->fetch();

            if ($existing) {
                // Update existing CPT
                $upd = $pdo->prepare("UPDATE {$this->cpt_table} SET cpt_name = ?, cpt_singular = ?, cpt_slug = ?, form_fields = ?, is_active = 1, updated_at = NOW() WHERE cpt_key = ?");
                $res = $upd->execute([$cpt_name, $cpt_singular, $cpt_slug, $form_fields, $cpt_key]);
                if (!$res) {
                    $pdo->rollBack();
                    wp_send_json_error('Failed to update CPT.');
                }
                $pdo->commit();
                flush_rewrite_rules();
                wp_send_json_success('CPT updated successfully');
            } else {
                // Create new CPT
                $stmt = $pdo->prepare("INSERT INTO {$this->cpt_table} (cpt_key, cpt_name, cpt_singular, cpt_slug, form_fields) VALUES (?, ?, ?, ?, ?)");
                $result = $stmt->execute([$cpt_key, $cpt_name, $cpt_singular, $cpt_slug, $form_fields]);

                if ($result) {
                    $pdo->commit();
                    flush_rewrite_rules();
                    wp_send_json_success('CPT created successfully');
                } else {
                    $pdo->rollBack();
                    wp_send_json_error('Failed to create CPT');
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Multi Secure CPT Create Error: ' . $e->getMessage());
            wp_send_json_error('Database error: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: Delete CPT
     */
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

            // 1) Collect post IDs before deletion
            $postIds = [];
            
            // From our table
            $stmtPosts = $pdo->prepare("SELECT wp_post_id FROM {$this->table_name} WHERE cpt_key = ? AND wp_post_id IS NOT NULL");
            $stmtPosts->execute([$cpt_key]);
            $rows = $stmtPosts->fetchAll();
            foreach ($rows as $r) {
                if (!empty($r['wp_post_id'])) $postIds[] = (int)$r['wp_post_id'];
            }

            // From WordPress by post type
            $wp_query = new WP_Query([
                'post_type' => $cpt_key,
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => -1,
            ]);
            
            if ($wp_query && !is_wp_error($wp_query)) {
                foreach ($wp_query->posts as $pid) {
                    $postIds[] = (int)$pid;
                }
            }

            $postIds = array_unique($postIds);

            // 2) Delete entries from our table
            $stmtEntries = $pdo->prepare("DELETE FROM {$this->table_name} WHERE cpt_key = ?");
            $stmtEntries->execute([$cpt_key]);

            // 3) Delete WordPress posts
            if (!empty($postIds)) {
                foreach ($postIds as $pid) {
                    if ($pid && get_post($pid)) {
                        wp_delete_post($pid, true);
                    }
                }
            }

            // 4) Delete CPT definition
            $stmt = $pdo->prepare("DELETE FROM {$this->cpt_table} WHERE cpt_key = ?");
            $result = $stmt->execute([$cpt_key]);

            if ($result) {
                $pdo->commit();
                flush_rewrite_rules();
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
                $where_clause = $current_cpt ? "WHERE cpt_key = ?" : "";
                $params = $current_cpt ? [$current_cpt] : [];

                $stmt = $pdo->prepare("SELECT * FROM {$this->table_name} {$where_clause} ORDER BY created_at DESC LIMIT 100");
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
                            <th><?php _e('WP Post ID', 'multi-secure-cpt'); ?></th>
                            <th><?php _e('Created', 'multi-secure-cpt'); ?></th>
                            <th><?php _e('Actions', 'multi-secure-cpt'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($entries)): ?>
                            <tr><td colspan="7"><?php _e('No entries found.', 'multi-secure-cpt'); ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($entries as $entry): ?>
                                <tr>
                                    <td><?php echo esc_html($entry['id']); ?></td>
                                    <td><code><?php echo esc_html($entry['cpt_key']); ?></code></td>
                                    <td><?php echo esc_html($entry['title']); ?></td>
                                    <td><?php echo esc_html(ucfirst($entry['status'])); ?></td>
                                    <td>
                                        <?php if (!empty($entry['wp_post_id']) && get_post($entry['wp_post_id'])): ?>
                                            <a href="<?php echo get_edit_post_link($entry['wp_post_id']); ?>"><?php echo esc_html($entry['wp_post_id']); ?></a>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($entry['created_at']); ?></td>
                                    <td>
                                        <form method="post" style="display: inline;">
                                            <?php wp_nonce_field('update_entry_status'); ?>
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="entry_id" value="<?php echo esc_attr($entry['id']); ?>">
                                            <select name="status" onchange="this.form.submit()">
                                                <option value="pending" <?php selected($entry['status'], 'pending'); ?>><?php _e('Pending', 'multi-secure-cpt'); ?></option>
                                                <option value="published" <?php selected($entry['status'], 'published'); ?>><?php _e('Published', 'multi-secure-cpt'); ?></option>
                                                <option value="draft" <?php selected($entry['status'], 'draft'); ?>><?php _e('Draft', 'multi-secure-cpt'); ?></option>
                                                <option value="trash" <?php selected($entry['status'], 'trash'); ?>><?php _e('Trash', 'multi-secure-cpt'); ?></option>
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
        <?php
    }

    private function update_entry_status($entry_id, $status) {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return false;

        $allowed_statuses = ['draft', 'published', 'pending', 'trash'];
        if (!in_array($status, $allowed_statuses)) return false;

        try {
            $stmt = $pdo->prepare("UPDATE {$this->table_name} SET status = ?, updated_at = NOW() WHERE id = ?");
            $result = $stmt->execute([$status, $entry_id]);

            if ($result && $status === 'published') {
                $this->create_wp_post_from_entry($entry_id);
            }

            return true;
        } catch (PDOException $e) {
            error_log('Multi Secure CPT Update Error: ' . $e->getMessage());
            return false;
        }
    }

    private function create_wp_post_from_entry($entry_id) {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return false;

        try {
            $stmt = $pdo->prepare("SELECT * FROM {$this->table_name} WHERE id = ?");
            $stmt->execute([(int)$entry_id]);
            $entry = $stmt->fetch();

            if (!$entry) return false;

            $post_id = $entry['wp_post_id'];
            $meta_data = json_decode($entry['meta_data'], true);

            $post_data = [
                'post_title'    => sanitize_text_field($entry['title']),
                'post_content'  => wp_kses_post($entry['content']),
                'post_status'   => 'publish',
                'post_type'     => $entry['cpt_key'],
            ];

            if ($post_id && get_post($post_id)) {
                $post_data['ID'] = $post_id;
                wp_update_post($post_data);
            } else {
                $post_id = wp_insert_post($post_data);
                if ($post_id && !is_wp_error($post_id)) {
                    $update_stmt = $pdo->prepare("UPDATE {$this->table_name} SET wp_post_id = ? WHERE id = ?");
                    $update_stmt->execute([$post_id, $entry_id]);
                }
            }

            if ($post_id && !is_wp_error($post_id) && is_array($meta_data)) {
                foreach ($meta_data as $key => $value) {
                    update_post_meta($post_id, sanitize_key($key), sanitize_text_field($value));
                }
            }
            return $post_id;
        } catch (PDOException $e) {
            error_log('Error creating WP post from entry: ' . $e->getMessage());
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
                        $attributes = 'name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '"' . $required;
                        switch ($field_type) {
                            case 'textarea':
                                echo '<textarea ' . $attributes . '></textarea>';
                                break;
                            case 'email':
                            case 'url':
                            case 'tel':
                            case 'number':
                                echo '<input type="' . esc_attr($field_type) . '" ' . $attributes . '>';
                                break;
                            default:
                                echo '<input type="text" ' . $attributes . '>';
                                break;
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

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData
            })
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
        <style>
            .secure-cpt-form {
                border: 1px solid #ccc;
                padding: 20px;
                border-radius: 5px;
                background-color: #f9f9f9;
            }
            .secure-cpt-form .form-field {
                margin-bottom: 15px;
            }
            .secure-cpt-form label {
                display: block;
                font-weight: bold;
                margin-bottom: 5px;
            }
            .secure-cpt-form input[type="text"],
            .secure-cpt-form input[type="email"],
            .secure-cpt-form input[type="tel"],
            .secure-cpt-form input[type="url"],
            .secure-cpt-form input[type="number"],
            .secure-cpt-form textarea {
                width: 100%;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
                box-sizing: border-box;
            }
            .secure-cpt-form button {
                background-color: #0073aa;
                color: white;
                border: none;
                padding: 10px 15px;
                border-radius: 4px;
                cursor: pointer;
            }
            .secure-cpt-form button:hover {
                background-color: #005177;
            }
        </style>
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
        $title = '';
        $content = '';
        $meta_data = [];

        foreach ($form_fields as $key => $config) {
            $value = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
            if (!empty($config['required']) && empty($value)) {
                wp_send_json_error(['message' => ($config['label'] ?? $key) . ' is required.'], 400);
            }
            if ($key === 'title') {
                $title = $value;
            } elseif ($key === 'content') {
                $content = $value;
            } else {
                $meta_data[$key] = $value;
            }
        }

        if (empty($title)) {
            $title = 'Submission from ' . date('Y-m-d H:i');
        }

        $pdo = $this->get_pdo_connection();
        if (!$pdo) {
            wp_send_json_error(['message' => 'Database error: Could not connect.'], 500);
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO {$this->table_name} (cpt_key, title, content, meta_data, author_ip, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $cpt_key,
                $title,
                $content,
                json_encode($meta_data),
                $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN',
                $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN'
            ]);
            wp_send_json_success(['message' => 'Submission successful! Your entry is pending review.']);
        } catch (PDOException $e) {
            error_log('Form Submit Error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Database error: ' . $e->getMessage()], 500);
        }
    }

    public function render_entries_list($atts) {
        $atts = shortcode_atts(['cpt' => ''], $atts, 'secure_cpt_list');
        if (empty($atts['cpt'])) return '<p>'.__('CPT parameter missing.', 'multi-secure-cpt').'</p>';

        $args = [
            'post_type' => $atts['cpt'],
            'post_status' => 'publish',
            'posts_per_page' => 10,
        ];
        $query = new WP_Query($args);

        if (!$query->have_posts()) {
            return '<p>'.__('No entries found.', 'multi-secure-cpt').'</p>';
        }

        ob_start();
        echo '<div class="secure-cpt-list">';
        while ($query->have_posts()) {
            $query->the_post();
            
            $entry = $this->get_entry_by_post_id(get_the_ID());
            $meta_data = $entry ? json_decode($entry['meta_data'], true) : [];

            echo '<div class="cpt-list-item">';
            echo '<h3><a href="' . get_permalink() . '">' . get_the_title() . '</a></h3>';
            echo '<div class="cpt-list-content">' . get_the_excerpt() . '</div>';

            if (!empty($meta_data)) {
                echo '<ul class="cpt-meta-list">';
                foreach ($meta_data as $key => $value) {
                    echo '<li><strong>' . esc_html(ucfirst(str_replace('_', ' ', $key))) . ':</strong> ' . esc_html($value) . '</li>';
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
        if (!is_singular()) {
            return $content;
        }

        $post_id = get_the_ID();
        $entry = $this->get_entry_by_post_id($post_id);
        if (!$entry) {
            return $content;
        }

        $meta_data = json_decode($entry['meta_data'], true);
        if (empty($meta_data)) {
            return $content;
        }

        ob_start();
        echo '<div class="secure-entry-meta">';
        echo '<h2>' . __('Additional Information', 'multi-secure-cpt') . '</h2>';
        echo '<ul>';
        foreach ($meta_data as $key => $value) {
            echo '<li><strong>' . esc_html(ucfirst(str_replace('_', ' ', $key))) . ':</strong> ' . esc_html($value) . '</li>';
        }
        echo '</ul>';
        echo '</div>';
        
        $meta_html = ob_get_clean();
        return $content . $meta_html;
    }

    // ---------- Metaboxes & save handlers ----------
    public function add_custom_meta_boxes() {
        $cpts = $this->get_all_cpts();
        foreach ($cpts as $cpt) {
            add_meta_box(
                $cpt['cpt_key'] . '_custom_fields',
                __('Additional Fields', 'multi-secure-cpt'),
                [$this, 'render_custom_meta_box'],
                $cpt['cpt_key'],
                'normal',
                'high'
            );
        }
    }

    public function render_custom_meta_box($post) {
        $cpt_key = $post->post_type;
        $entry = $this->get_entry_by_post_id($post->ID);
        
        $cpt_data = $this->get_cpt_by_key($cpt_key);
        if (!$cpt_data) return;
        $form_fields = json_decode($cpt_data['form_fields'], true);
        
        $meta_data = [];

        // If no entry exists, create a new one to link it to the WP post
        if (!$entry) {
            $pdo = $this->get_pdo_connection();
            if ($pdo) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO {$this->table_name} (cpt_key, title, content, status, wp_post_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$cpt_key, $post->post_title, $post->post_content, 'published', $post->ID]);
                    
                    // Fetch the newly created entry
                    $entry = $this->get_entry_by_post_id($post->ID);
                } catch (PDOException $e) {
                    error_log('Error creating entry for post: ' . $e->getMessage());
                    echo '<p>' . __('Failed to create a database entry for this post. Please check the logs.', 'multi-secure-cpt') . '</p>';
                }
            }
        }
        
        if ($entry) {
            $meta_data = json_decode($entry['meta_data'], true);
        }

        wp_nonce_field('save_custom_meta', 'custom_meta_nonce');
        
        foreach ($form_fields as $field_name => $field_config) {
            if ($field_name === 'title' || $field_name === 'content') continue;
            $value = $meta_data[$field_name] ?? '';
            ?>
            <div class="form-field" style="margin-bottom:10px;">
                <label for="<?php echo esc_attr($field_name); ?>">
                    <strong><?php echo esc_html($field_config['label'] ?? $field_name); ?></strong>
                </label>
                <?php if (($field_config['type'] ?? 'text') === 'textarea'): ?>
                    <textarea name="<?php echo esc_attr($field_name); ?>" id="<?php echo esc_attr($field_name); ?>" class="widefat" rows="4"><?php echo esc_textarea($value); ?></textarea>
                <?php else: ?>
                    <input type="text" name="<?php echo esc_attr($field_name); ?>" id="<?php echo esc_attr($field_name); ?>" value="<?php echo esc_attr($value); ?>" class="widefat">
                <?php endif; ?>
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
        $meta_data = [];

        foreach ($form_fields as $field_name => $field_config) {
            if ($field_name === 'title' || $field_name === 'content') continue;
            if (isset($_POST[$field_name])) {
                $meta_data[$field_name] = sanitize_text_field(wp_unslash($_POST[$field_name]));
            }
        }

        $pdo = $this->get_pdo_connection();
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("UPDATE {$this->table_name} SET meta_data = ?, updated_at = NOW() WHERE wp_post_id = ?");
                $stmt->execute([json_encode($meta_data), $post_id]);
            } catch (PDOException $e) {
                error_log('Error saving custom meta: ' . $e->getMessage());
            }
        }
    }
    
    private function upgrade_tables() {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return false;

        try {
            // Prüfen, ob die Spalte existiert
            $stmt = $pdo->prepare("SHOW COLUMNS FROM {$this->table_name} LIKE 'wp_post_id'");
            $stmt->execute();
            $column_exists = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$column_exists) {
                $pdo->exec("ALTER TABLE {$this->table_name} ADD COLUMN wp_post_id INT UNSIGNED DEFAULT NULL");
            }

            // Prüfen, ob der Index existiert
            $stmt = $pdo->prepare("SHOW INDEX FROM {$this->table_name} WHERE Key_name = 'idx_wp_post_id'");
            $stmt->execute();
            $index_exists = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$index_exists) {
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
