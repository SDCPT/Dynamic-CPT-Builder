<?php
/*
Plugin Name: Multi Secure Dynamic CPT
Description: Create multiple dynamic custom post types with individual field configurations
Version: 2.2
Author: Volkansah
*/

// Security check
if (!defined('WPINC')) {
    die;
}

// Aktivierungs-/Deaktivierungs-Hooks sollten außerhalb der Klasse registriert werden.
register_activation_hook(__FILE__, ['MultiSecureDynamicCPT', 'plugin_activation']);
register_deactivation_hook(__FILE__, ['MultiSecureDynamicCPT', 'plugin_deactivation']);

class MultiSecureDynamicCPT {

    private $pdo;
    private $table_name;
    private $cpt_table;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'secure_cpt_entries';
        $this->cpt_table = $wpdb->prefix . 'secure_cpt_types';

        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('init', [$this, 'register_all_cpts'], 0);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_ajax_create_new_cpt', [$this, 'ajax_create_cpt']);
        add_action('wp_ajax_delete_cpt', [$this, 'ajax_delete_cpt']);
        add_action('wp_ajax_nopriv_secure_cpt_submit', [$this, 'ajax_form_submit']);
        add_action('wp_ajax_secure_cpt_submit', [$this, 'ajax_form_submit']);

        add_shortcode('secure_cpt_form', [$this, 'render_frontend_form']);
        add_shortcode('secure_cpt_list', [$this, 'render_entries_list']);
    }

    /**
     * Get secure PDO connection
     */
    private function get_pdo_connection() {
        if ($this->pdo === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ];
                $this->pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
            } catch (PDOException $e) {
                error_log('Multi Secure CPT PDO Connection Error: ' . $e->getMessage());
                return false;
            }
        }
        return $this->pdo;
    }

    /**
     * Load text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain('multi-secure-cpt', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Plugin activation (static context)
     */
    public static function plugin_activation() {
        // Create an instance to call non-static methods
        $instance = new self();
        $instance->create_tables();
        $instance->register_all_cpts();
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation (static context)
     */
    public static function plugin_deactivation() {
        flush_rewrite_rules();
    }

    /**
     * Create database tables
     */
    private function create_tables() {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return false;

        try {
            // CPT definitions table
            $sql1 = "CREATE TABLE IF NOT EXISTS {$this->cpt_table} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cpt_key VARCHAR(50) NOT NULL UNIQUE,
                cpt_name VARCHAR(255) NOT NULL,
                cpt_singular VARCHAR(255) NOT NULL,
                cpt_slug VARCHAR(100) NOT NULL,
                form_fields JSON,
                settings JSON,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_active (is_active),
                INDEX idx_key (cpt_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            // Entries table
            $sql2 = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cpt_key VARCHAR(50) NOT NULL,
                wp_post_id INT UNSIGNED DEFAULT NULL,
                title VARCHAR(255) NOT NULL,
                content TEXT,
                meta_data JSON,
                status ENUM('draft', 'published', 'pending', 'trash') DEFAULT 'pending',
                author_ip VARCHAR(45),
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_cpt_status (cpt_key, status),
                INDEX idx_created (created_at),
                INDEX idx_wp_post_id (wp_post_id),
                FOREIGN KEY (cpt_key) REFERENCES {$this->cpt_table}(cpt_key) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            $pdo->exec($sql1);
            $pdo->exec($sql2);
            return true;

        } catch (PDOException $e) {
            error_log('Multi Secure CPT Table Creation Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all CPT definitions
     */
    private function get_all_cpts() {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return [];

        try {
            $stmt = $pdo->prepare("SELECT * FROM {$this->cpt_table} WHERE is_active = 1");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('Multi Secure CPT Get CPTs Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Register all active CPTs
     */
    public function register_all_cpts() {
        $cpts = $this->get_all_cpts();
        foreach ($cpts as $cpt) {
            $this->register_single_cpt($cpt);
        }
    }

    /**
     * Register single CPT
     */
    private function register_single_cpt($cpt_data) {
        $settings = json_decode($cpt_data['settings'] ?? '{}', true);
        $labels = [
            'name' => $cpt_data['cpt_name'],
            'singular_name' => $cpt_data['cpt_singular'],
            'menu_name' => $cpt_data['cpt_name'],
            'add_new_item' => __('Add New', 'multi-secure-cpt') . ' ' . $cpt_data['cpt_singular'],
            'edit_item' => __('Edit', 'multi-secure-cpt') . ' ' . $cpt_data['cpt_singular'],
            'view_item' => __('View', 'multi-secure-cpt') . ' ' . $cpt_data['cpt_singular'],
            'all_items' => __('All', 'multi-secure-cpt') . ' ' . $cpt_data['cpt_name'],
            'search_items' => __('Search', 'multi-secure-cpt') . ' ' . $cpt_data['cpt_name'],
            'not_found' => __('No entries found', 'multi-secure-cpt'),
        ];
        $args = [
            'labels' => $labels,
            'public' => $settings['public'] ?? true,
            'publicly_queryable' => $settings['publicly_queryable'] ?? true,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => true,
            'query_var' => true,
            'rewrite' => ['slug' => $cpt_data['cpt_slug'], 'with_front' => false],
            'capability_type' => 'post',
            'has_archive' => true,
            'hierarchical' => false,
            'menu_position' => 25,
            'supports' => ['title', 'editor', 'custom-fields', 'excerpt', 'thumbnail'],
            'menu_icon' => $settings['menu_icon'] ?? 'dashicons-admin-post',
        ];
        register_post_type($cpt_data['cpt_key'], $args);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Multi Secure CPT', 'multi-secure-cpt'),
            __('Dynamic CPTs', 'multi-secure-cpt'),
            'manage_options',
            'multi-secure-cpt',
            [$this, 'main_admin_page'],
            'dashicons-admin-settings',
            30
        );
        add_submenu_page(
            'multi-secure-cpt',
            __('Manage CPTs', 'multi-secure-cpt'),
            __('Manage CPTs', 'multi-secure-cpt'),
            'manage_options',
            'multi-secure-cpt',
            [$this, 'main_admin_page']
        );
        add_submenu_page(
            'multi-secure-cpt',
            __('All Entries', 'multi-secure-cpt'),
            __('All Entries', 'multi-secure-cpt'),
            'manage_options',
            'multi-secure-cpt-entries',
            [$this, 'manage_entries_page']
        );
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
                        <th><?php _e('CPT Key', 'multi-secure-cpt'); ?></th>
                        <td><input type="text" id="new-cpt-key" placeholder="restaurant_listings" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php _e('CPT Name (Plural)', 'multi-secure-cpt'); ?></th>
                        <td><input type="text" id="new-cpt-name" placeholder="Restaurant Listings" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php _e('CPT Singular', 'multi-secure-cpt'); ?></th>
                        <td><input type="text" id="new-cpt-singular" placeholder="Restaurant Listing" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php _e('URL Slug', 'multi-secure-cpt'); ?></th>
                        <td><input type="text" id="new-cpt-slug" placeholder="restaurants" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php _e('Form Fields (JSON)', 'multi-secure-cpt'); ?></th>
                        <td>
                            <textarea id="new-cpt-fields" rows="8" cols="60" placeholder='{"title":{"type":"text","label":"Title","required":true},"content":{"type":"textarea","label":"Content","required":false},"email":{"type":"email","label":"Contact Email","required":true}}'></textarea>
                            <p class="description"><?php _e('Define the fields for the frontend submission form.', 'multi-secure-cpt'); ?></p>
                            <p>
                                <button type="button" id="load-default-fields" class="button"><?php _e('Load Default Fields', 'multi-secure-cpt'); ?></button>
                            </p>
                        </td>
                    </tr>
                </table>
                <button type="button" id="create-cpt-btn" class="button button-primary"><?php _e('Create CPT', 'multi-secure-cpt'); ?></button>
            </div>
            
            <hr>
            
            <h2><?php _e('Existing CPTs', 'multi-secure-cpt'); ?></h2>
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
                            <td><code><?php echo esc_html($cpt['cpt_key']); ?></code></td>
                            <td><?php echo esc_html($cpt['cpt_name']); ?></td>
                            <td><code><?php echo esc_html($cpt['cpt_slug']); ?></code></td>
                            <td><?php echo $entry_count; ?></td>
                            <td>
                                <a href="<?php echo admin_url('edit.php?post_type=' . $cpt['cpt_key']); ?>" class="button"><?php _e('View WP Posts', 'multi-secure-cpt'); ?></a>
                                <a href="<?php echo admin_url('admin.php?page=multi-secure-cpt-entries&cpt=' . $cpt['cpt_key']); ?>" class="button"><?php _e('View DB Entries', 'multi-secure-cpt'); ?></a>
                                <button class="button button-link-delete delete-cpt-btn" data-cpt="<?php echo esc_attr($cpt['cpt_key']); ?>"><?php _e('Delete', 'multi-secure-cpt'); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#load-default-fields').click(function() {
                var defaultJson = {
                    "title": {"type":"text","label":"Title","required":true},
                    "content": {"type":"textarea","label":"Content","required":false},
                    "email": {"type":"email","label":"Contact Email","required":true}
                };
                $('#new-cpt-fields').val(JSON.stringify(defaultJson, null, 4));
            });

            $('#create-cpt-btn').click(function() {
                var jsonText = $('#new-cpt-fields').val();
                if (jsonText) {
                    try {
                        JSON.parse(jsonText);
                    } catch (e) {
                        alert('Invalid JSON format in Form Fields: ' + e.message);
                        return;
                    }
                }
                
                var data = {
                    action: 'create_new_cpt',
                    cpt_key: $('#new-cpt-key').val(),
                    cpt_name: $('#new-cpt-name').val(),
                    cpt_singular: $('#new-cpt-singular').val(),
                    cpt_slug: $('#new-cpt-slug').val(),
                    form_fields: jsonText,
                    _wpnonce: '<?php echo wp_create_nonce('create_cpt_nonce'); ?>'
                };
                
                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            });
            
            $('.delete-cpt-btn').click(function() {
                if (!confirm('<?php _e('Are you sure? This will delete the CPT and all associated database entries!', 'multi-secure-cpt'); ?>')) return;
                
                var cpt_key = $(this).data('cpt');
                var data = {
                    action: 'delete_cpt',
                    cpt_key: cpt_key,
                    _wpnonce: '<?php echo wp_create_nonce('delete_cpt_nonce'); ?>'
                };
                
                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Get entry count for CPT
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
     *
     * Behavior:
     * - If cpt_key exists and is_active = 1 -> error (duplicate)
     * - If cpt_key exists and is_active = 0 -> UPDATE the record and set is_active = 1
     * - Else -> INSERT new record
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
        
        // JSON-String von Slashes bereinigen
        $form_fields = wp_unslash($_POST['form_fields'] ?? '');

        if (empty($cpt_key) || empty($cpt_name) || empty($cpt_singular) || empty($cpt_slug)) {
            wp_send_json_error('All fields are required.');
        }

        // Limit key length to match DB
        if (strlen($cpt_key) > 50) {
            wp_send_json_error('CPT Key too long (max 50 characters).');
        }

        // Validate JSON
        if (!empty($form_fields)) {
            json_decode($form_fields);
            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error('Invalid JSON in form fields: ' . json_last_error_msg());
            }
        }

        try {
            $pdo->beginTransaction();

            // Prüfe vorhandenen Eintrag
            $check = $pdo->prepare("SELECT id, is_active FROM {$this->cpt_table} WHERE cpt_key = ?");
            $check->execute([$cpt_key]);
            $existing = $check->fetch();

            if ($existing) {
                if ((int)$existing['is_active'] === 1) {
                    $pdo->rollBack();
                    wp_send_json_error('CPT key already exists and is active.');
                } else {
                    // Wieder aktivieren / updaten
                    $upd = $pdo->prepare("UPDATE {$this->cpt_table} SET cpt_name = ?, cpt_singular = ?, cpt_slug = ?, form_fields = ?, is_active = 1, updated_at = NOW() WHERE cpt_key = ?");
                    $res = $upd->execute([$cpt_name, $cpt_singular, $cpt_slug, $form_fields, $cpt_key]);
                    if (!$res) {
                        $pdo->rollBack();
                        wp_send_json_error('Failed to reactivate CPT.');
                    }
                    $pdo->commit();
                    flush_rewrite_rules();
                    wp_send_json_success('CPT reactivated successfully');
                }
            } else {
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
            wp_send_json_error('Database error: CPT key might already exist or other DB error.');
        }
    }

    /**
     * AJAX: Delete CPT
     *
     * Verhalten:
     * - Löscht alle Einträge in der entries-Tabelle für cpt_key
     * - Löscht zugehörige WP-Posts (sofern vorhanden)
     * - Löscht den CPT-Eintrag in der cpt_table
     * - Alles in einer Transaction
     */
    public function ajax_delete_cpt() {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'delete_cpt_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed', 403);
        }
        $pdo = $this->get_pdo_connection();
        if (!$pdo) {
            wp_send_json_error('Database connection failed');
        }
        $cpt_key = sanitize_key($_POST['cpt_key'] ?? '');
        if (empty($cpt_key)) {
            wp_send_json_error('Invalid CPT key.');
        }

        try {
            $pdo->beginTransaction();

            // 1) lösche Einträge in eigener Tabelle
            $stmtEntries = $pdo->prepare("DELETE FROM {$this->table_name} WHERE cpt_key = ?");
            $stmtEntries->execute([$cpt_key]);

            // 2) lösche zugehörige WP-Posts (inkl. Meta)
            // Hol die Post-IDs aus unserer Tabelle (falls wp_post_id gesetzt) und zusätzlich aus WP falls nötig
            $postIds = [];
            $stmtPosts = $pdo->prepare("SELECT wp_post_id FROM {$this->table_name} WHERE cpt_key = ? AND wp_post_id IS NOT NULL");
            $stmtPosts->execute([$cpt_key]);
            $rows = $stmtPosts->fetchAll();
            foreach ($rows as $r) {
                if (!empty($r['wp_post_id'])) $postIds[] = (int)$r['wp_post_id'];
            }

            // zusätzlich: falls keine wp_post_id, dann suche WP-Posts mit diesem post_type
            $wp_query = new WP_Query([
                'post_type' => $cpt_key,
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => -1,
            ]);
            if ($wp_query && !is_wp_error($wp_query) && $wp_query->have_posts()) {
                foreach ($wp_query->posts as $pid) {
                    $postIds[] = (int)$pid;
                }
            }

            // dedupe
            $postIds = array_values(array_unique($postIds));

            if (!empty($postIds)) {
                foreach ($postIds as $pid) {
                    // force delete to remove from WP completely
                    wp_delete_post($pid, true);
                }
            }

            // 3) delete CPT row
            $stmt = $pdo->prepare("DELETE FROM {$this->cpt_table} WHERE cpt_key = ?");
            $result = $stmt->execute([$cpt_key]);

            if ($result) {
                $pdo->commit();
                // Hinweis: unregister_post_type ist nicht zuverlässig in allen Kontexten.
                // flush rewrite rules damit alte slugs weg sind
                flush_rewrite_rules();
                wp_send_json_success('CPT deleted successfully');
            } else {
                $pdo->rollBack();
                wp_send_json_error('Failed to delete CPT');
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Multi Secure CPT Delete Error: ' . $e->getMessage());
            wp_send_json_error('Database error during delete');
        }
    }

    /**
     * Manage entries page
     */
    public function manage_entries_page() {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) {
            echo '<div class="error"><p>' . __('Database connection failed.', 'multi-secure-cpt') . '</p></div>';
            return;
        }
        
        $current_cpt = isset($_GET['cpt']) ? sanitize_key($_GET['cpt']) : '';
        $cpts = $this->get_all_cpts();
        
        // Handle status updates
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

    /**
     * Update entry status
     */
    private function update_entry_status($entry_id, $status) {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return false;

        $allowed_statuses = ['draft', 'published', 'pending', 'trash'];
        if (!in_array($status, $allowed_statuses)) return false;

        try {
            $stmt = $pdo->prepare("UPDATE {$this->table_name} SET status = ?, updated_at = NOW() WHERE id = ?");
            $result = $stmt->execute([$status, $entry_id]);
            
            // When published, create/update WP post
            if ($result && $status === 'published') {
                $this->create_wp_post_from_entry($entry_id);
            }
            
            return true;
        } catch (PDOException $e) {
            error_log('Multi Secure CPT Update Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create WP post from entry
     */
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

    /**
     * Render frontend form
     */
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
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get CPT definition by key
     */
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

    /**
     * AJAX: Handle form submission
     */
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
            wp_send_json_error(['message' => 'Database error.'], 500);
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
            wp_send_json_error(['message' => 'Could not save the entry.'], 500);
        }
    }

    /**
     * Render entries list
     */
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
            echo '<div class="cpt-list-item">';
            echo '<h3><a href="' . get_permalink() . '">' . get_the_title() . '</a></h3>';
            echo '<div>' . get_the_excerpt() . '</div>';
            echo '</div>';
        }
        echo '</div>';
        wp_reset_postdata();
        return ob_get_clean();
    }
}

// Plugin starten
new MultiSecureDynamicCPT();
