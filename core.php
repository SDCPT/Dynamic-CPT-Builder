<?php
/*
Plugin Name: Multi Secure Dynamic CPT
Description: Create multiple dynamic custom post types with individual field configurations
Version: 2.0
Author: Volkan Sah
*/

// Security check
if (!defined('WPINC')) {
    die;
}

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
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_create_new_cpt', [$this, 'ajax_create_cpt']);
        add_action('wp_ajax_delete_cpt', [$this, 'ajax_delete_cpt']);
        add_action('wp_ajax_nopriv_secure_cpt_submit', [$this, 'ajax_form_submit']);
        add_action('wp_ajax_secure_cpt_submit', [$this, 'ajax_form_submit']);
        
        add_shortcode('secure_cpt_form', [$this, 'render_frontend_form']);
        add_shortcode('secure_cpt_list', [$this, 'render_entries_list']);
        
        register_activation_hook(__FILE__, [$this, 'plugin_activation']);
        register_deactivation_hook(__FILE__, [$this, 'cleanup']);
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
     * Plugin activation
     */
    public function plugin_activation() {
        $this->create_tables();
        $this->register_all_cpts();
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
            'rewrite' => [
                'slug' => $cpt_data['cpt_slug'],
                'with_front' => false,
                'pages' => true,
                'feeds' => true,
            ],
            'capability_type' => 'post',
            'has_archive' => $cpt_data['cpt_slug'] . '-archive',
            'hierarchical' => false,
            'menu_position' => 25,
            'supports' => ['title', 'editor', 'custom-fields', 'excerpt'],
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
     * Register settings
     */
    public function register_settings() {
        register_setting('multi_secure_cpt_settings', 'multi_secure_cpt_global');
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
                            <textarea id="new-cpt-fields" rows="8" cols="60" placeholder='{"name": {"type": "text", "label": "Restaurant Name", "required": true}, "address": {"type": "text", "label": "Address", "required": true}, "phone": {"type": "text", "label": "Phone", "required": false}}'></textarea>
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
                        <th><?php _e('Entries', 'multi-secure-cpt'); ?></th>
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
                                <a href="<?php echo admin_url('edit.php?post_type=' . $cpt['cpt_key']); ?>" class="button"><?php _e('WP Posts', 'multi-secure-cpt'); ?></a>
                                <a href="<?php echo admin_url('admin.php?page=multi-secure-cpt-entries&cpt=' . $cpt['cpt_key']); ?>" class="button"><?php _e('DB Entries', 'multi-secure-cpt'); ?></a>
                                <button class="button button-secondary edit-cpt-btn" data-cpt="<?php echo esc_attr($cpt['cpt_key']); ?>"><?php _e('Edit', 'multi-secure-cpt'); ?></button>
                                <button class="button button-link-delete delete-cpt-btn" data-cpt="<?php echo esc_attr($cpt['cpt_key']); ?>"><?php _e('Delete', 'multi-secure-cpt'); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#create-cpt-btn').click(function() {
                var data = {
                    action: 'create_new_cpt',
                    cpt_key: $('#new-cpt-key').val(),
                    cpt_name: $('#new-cpt-name').val(),
                    cpt_singular: $('#new-cpt-singular').val(),
                    cpt_slug: $('#new-cpt-slug').val(),
                    form_fields: $('#new-cpt-fields').val(),
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
                if (!confirm('<?php _e('Are you sure? This will delete all entries!', 'multi-secure-cpt'); ?>')) return;
                
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
     */
    public function ajax_create_cpt() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'create_cpt_nonce') || !current_user_can('manage_options')) {
            wp_die('Security check failed');
        }
        
        $pdo = $this->get_pdo_connection();
        if (!$pdo) {
            wp_send_json_error('Database connection failed');
        }
        
        $cpt_key = sanitize_key($_POST['cpt_key']);
        $cpt_name = sanitize_text_field($_POST['cpt_name']);
        $cpt_singular = sanitize_text_field($_POST['cpt_singular']);
        $cpt_slug = sanitize_title($_POST['cpt_slug']);
        $form_fields = $_POST['form_fields'];
        
        // Validate JSON
        $fields_decoded = json_decode($form_fields, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('Invalid JSON in form fields');
        }
        
        try {
            $stmt = $pdo->prepare("INSERT INTO {$this->cpt_table} (cpt_key, cpt_name, cpt_singular, cpt_slug, form_fields) VALUES (?, ?, ?, ?, ?)");
            $result = $stmt->execute([$cpt_key, $cpt_name, $cpt_singular, $cpt_slug, $form_fields]);
            
            if ($result) {
                flush_rewrite_rules();
                wp_send_json_success('CPT created successfully');
            } else {
                wp_send_json_error('Failed to create CPT');
            }
        } catch (PDOException $e) {
            error_log('Multi Secure CPT Create Error: ' . $e->getMessage());
            wp_send_json_error('Database error');
        }
    }
    
    /**
     * AJAX: Delete CPT
     */
    public function ajax_delete_cpt() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'delete_cpt_nonce') || !current_user_can('manage_options')) {
            wp_die('Security check failed');
        }
        
        $pdo = $this->get_pdo_connection();
        if (!$pdo) {
            wp_send_json_error('Database connection failed');
        }
        
        $cpt_key = sanitize_key($_POST['cpt_key']);
        
        try {
            $stmt = $pdo->prepare("DELETE FROM {$this->cpt_table} WHERE cpt_key = ?");
            $result = $stmt->execute([$cpt_key]);
            
            if ($result) {
                flush_rewrite_rules();
                wp_send_json_success('CPT deleted successfully');
            } else {
                wp_send_json_error('Failed to delete CPT');
            }
        } catch (PDOException $e) {
            error_log('Multi Secure CPT Delete Error: ' . $e->getMessage());
            wp_send_json_error('Database error');
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
        
        $current_cpt = $_GET['cpt'] ?? '';
        $cpts = $this->get_all_cpts();
        
        // Handle status updates
        if (isset($_POST['action']) && $_POST['action'] === 'update_status' && wp_verify_nonce($_POST['_wpnonce'], 'update_entry_status')) {
            $this->update_entry_status((int)$_POST['entry_id'], sanitize_text_field($_POST['status']));
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('Manage All Entries', 'multi-secure-cpt'); ?></h1>
            
            <div class="cpt-filter">
                <select onchange="window.location.href='?page=multi-secure-cpt-entries&cpt=' + this.value">
                    <option value=""><?php _e('All CPTs', 'multi-secure-cpt'); ?></option>
                    <?php foreach ($cpts as $cpt): ?>
                        <option value="<?php echo esc_attr($cpt['cpt_key']); ?>" <?php selected($current_cpt, $cpt['cpt_key']); ?>>
                            <?php echo esc_html($cpt['cpt_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <?php
            try {
                $where_clause = $current_cpt ? "WHERE cpt_key = ?" : "";
                $params = $current_cpt ? [$current_cpt] : [];
                
                $stmt = $pdo->prepare("SELECT * FROM {$this->table_name} {$where_clause} ORDER BY created_at DESC LIMIT 50");
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
                            <th><?php _e('IP', 'multi-secure-cpt'); ?></th>
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
                                    <td>
                                        <span class="status-<?php echo esc_attr($entry['status']); ?>">
                                            <?php echo esc_html(ucfirst($entry['status'])); ?>
                                        </span>
                                    </td>
                                    <td><small><?php echo esc_html($entry['author_ip']); ?></small></td>
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
                                        <button onclick="viewEntryData(<?php echo esc_attr($entry['id']); ?>)" class="button button-small"><?php _e('View Data', 'multi-secure-cpt'); ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <script>
                function viewEntryData(id) {
                    // You can implement a modal or redirect to detailed view
                    console.log('View entry:', id);
                }
                </script>
                
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
            $stmt->execute([$status, $entry_id]);
            return true;
        } catch (PDOException $e) {
            error_log('Multi Secure CPT Update Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Render frontend form
     */
    public function render_frontend_form($atts) {
        $atts = shortcode_atts(['cpt' => ''], $atts);
        
        if (empty($atts['cpt'])) {
            return '<p>' . __('CPT parameter required.', 'multi-secure-cpt') . '</p>';
        }
        
        $cpt_data = $this->get_cpt_by_key($atts['cpt']);
        if (!$cpt_data) {
            return '<p>' . __('CPT not found.', 'multi-secure-cpt') . '</p>';
        }
        
        $form_fields = json_decode($cpt_data['form_fields'] ?? '{}', true);
        if (empty($form_fields)) {
            return '<p>' . __('No form fields configured.', 'multi-secure-cpt') . '</p>';
        }
        
        ob_start();
        ?>
        <div id="secure-cpt-form-<?php echo esc_attr($atts['cpt']); ?>">
            <form class="secure-cpt-form" data-cpt="<?php echo esc_attr($atts['cpt']); ?>">
                <?php wp_nonce_field('secure_cpt_form_' . $atts['cpt'], 'secure_cpt_nonce'); ?>
                
                <?php foreach ($form_fields as $field_name => $field_config): ?>
                    <div class="form-field">
                        <label for="<?php echo esc_attr($field_name); ?>">
                            <?php echo esc_html($field_config['label'] ?? $field_name); ?>
                            <?php if (!empty($field_config['required'])): ?>*<?php endif; ?>
                        </label>
                        
                        <?php
                        $field_type = $field_config['type'] ?? 'text';
                        
                        switch ($field_type) {
                            case 'textarea':
                                echo '<textarea name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '"' . 
                                     (!empty($field_config['required']) ? ' required' : '') . '></textarea>';
                                break;
                            case 'email':
                                echo '<input type="email" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '"' . 
                                     (!empty($field_config['required']) ? ' required' : '') . '>';
                                break;
                            case 'url':
                                echo '<input type="url" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '"' . 
                                     (!empty($field_config['required']) ? ' required' : '') . '>';
                                break;
                            case 'tel':
                                echo '<input type="tel" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '"' . 
                                     (!empty($field_config['required']) ? ' required' : '') . '>';
                                break;
                            default:
                                echo '<input type="text" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '"' . 
                                     (!empty($field_config['required']) ? ' required' : '') . '>';
                        }
                        ?>
                    </div>
                <?php endforeach; ?>
                
                <div class="form-submit">
                    <button type="submit"><?php _e('Submit Entry', 'multi-secure-cpt'); ?></button>
                    <div class="loading" style="display:none;"><?php _e('Submitting...', 'multi-secure-cpt'); ?></div>
                </div>
            </form>
            
            <div class="form-messages"></div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.secure-cpt-form').submit(function(e) {
                e.preventDefault();
                
                var form = $(this);
                var cpt = form.data('cpt');
                var submitBtn = form.find('button[type="submit"]');
                var loading = form.find('.loading');
                var messages = form.siblings('.form-messages');
                
                // Show loading
                submitBtn.prop('disabled', true);
                loading.show();
                messages.empty();
                
                // Collect form data
                var formData = {
                    action: 'secure_cpt_submit',
                    cpt_key: cpt,
                    _wpnonce: form.find('input[name="secure_cpt_nonce"]').val()
                };
                
                form.find('input, textarea, select').each(function() {
                    if ($(this).attr('name') && $(this).attr('name') !== 'secure_cpt_nonce') {
                        formData[$(this).attr('name')] = $(this).val();
                    }
                });
                
                // Submit via AJAX
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', formData, function(response) {
                    submitBtn.prop('disabled', false);
                    loading.hide();
                    
                    if (response.success) {
                        messages.html('<div class="success">' + response.data + '</div>');
                        form[0].reset();
                    } else {
                        messages.html('<div class="error">' + response.data + '</div>');
                    }
                }).fail(function() {
                    submitBtn.prop('disabled', false);
                    loading.hide();
                    messages.html('<div class="error"><?php _e('Network error. Please try again.', 'multi-secure-cpt'); ?></div>');
                });
            });
        });
        </script>
        
        <style>
        .secure-cpt-form .form-field {
            margin-bottom: 15px;
        }
        .secure-cpt-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .secure-cpt-form input,
        .secure-cpt-form textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .secure-cpt-form button {
            background: #0073aa;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .secure-cpt-form button:disabled {
            background: #ccc;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 10px;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
            margin: 10px 0;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 10px;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
            margin: 10px 0;
        }
        .loading {
            color: #666;
            font-style: italic;
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get CPT by key
     */
    private function get_cpt_by_key($cpt_key) {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return false;
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM {$this->cpt_table} WHERE cpt_key = ? AND is_active = 1");
            $stmt->execute([$cpt_key]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log('Multi Secure CPT Get CPT Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * AJAX: Form submission
     */
    public function ajax_form_submit() {
        $cpt_key = sanitize_key($_POST['cpt_key'] ?? '');
        
        if (!wp_verify_nonce($_POST['_wpnonce'], 'secure_cpt_form_' . $cpt_key)) {
            wp_send_json_error(__('Security check failed.', 'multi-secure-cpt'));
        }
        
        $pdo = $this->get_pdo_connection();
        if (!$pdo) {
            wp_send_json_error(__('Database connection failed.', 'multi-secure-cpt'));
        }
        
        $cpt_data = $this->get_cpt_by_key($cpt_key);
        if (!$cpt_data) {
            wp_send_json_error(__('CPT not found.', 'multi-secure-cpt'));
        }
        
        $form_fields = json_decode($cpt_data['form_fields'] ?? '{}', true);
        if (empty($form_fields)) {
            wp_send_json_error(__('No form fields configured.', 'multi-secure-cpt'));
        }
        
        // Security checks
        $ip = $this->get_client_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Rate limiting check (basic implementation)
        if (!$this->check_rate_limit($ip, $cpt_key)) {
            wp_send_json_error(__('Too many submissions. Please wait before submitting again.', 'multi-secure-cpt'));
        }
        
        $sanitized_data = [];
        $title = '';
        
        foreach ($form_fields as $field_name => $field_config) {
            $value = $_POST[$field_name] ?? '';
            
            // Required field check
            if (!empty($field_config['required']) && empty($value)) {
                wp_send_json_error(sprintf(__('Field "%s" is required.', 'multi-secure-cpt'), $field_config['label'] ?? $field_name));
            }
            
            // Sanitize based on field type
            switch ($field_config['type'] ?? 'text') {
                case 'email':
                    $sanitized_data[$field_name] = sanitize_email($value);
                    if (!empty($value) && empty($sanitized_data[$field_name])) {
                        wp_send_json_error(sprintf(__('Invalid email format in "%s".', 'multi-secure-cpt'), $field_config['label'] ?? $field_name));
                    }
                    break;
                case 'textarea':
                    $sanitized_data[$field_name] = sanitize_textarea_field($value);
                    break;
                case 'url':
                    $sanitized_data[$field_name] = esc_url_raw($value);
                    if (!empty($value) && empty($sanitized_data[$field_name])) {
                        wp_send_json_error(sprintf(__('Invalid URL format in "%s".', 'multi-secure-cpt'), $field_config['label'] ?? $field_name));
                    }
                    break;
                case 'tel':
                    $sanitized_data[$field_name] = preg_replace('/[^0-9+\-\s\(\)]/', '', $value);
                    break;
                default:
                    $sanitized_data[$field_name] = sanitize_text_field($value);
            }
            
            // Use first non-empty field as title
            if (empty($title) && !empty($sanitized_data[$field_name])) {
                $title = $sanitized_data[$field_name];
            }
        }
        
        if (empty($title)) {
            $title = __('Untitled Entry', 'multi-secure-cpt');
        }
        
        try {
            $stmt = $pdo->prepare("INSERT INTO {$this->table_name} (cpt_key, title, meta_data, author_ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $result = $stmt->execute([
                $cpt_key,
                $title,
                json_encode($sanitized_data),
                $ip,
                $user_agent
            ]);
            
            if ($result) {
                wp_send_json_success(__('Entry submitted successfully! It will be reviewed before publication.', 'multi-secure-cpt'));
            } else {
                throw new Exception('Insert failed');
            }
        } catch (Exception $e) {
            error_log('Multi Secure CPT Form Submission Error: ' . $e->getMessage());
            wp_send_json_error(__('Error submitting entry. Please try again.', 'multi-secure-cpt'));
        }
    }
    
    /**
     * Basic rate limiting check
     */
    private function check_rate_limit($ip, $cpt_key, $max_submissions = 5, $time_window = 3600) {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return true; // Allow if can't check
        
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE author_ip = ? AND cpt_key = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
            $stmt->execute([$ip, $cpt_key, $time_window]);
            $count = $stmt->fetchColumn();
            
            return $count < $max_submissions;
        } catch (PDOException $e) {
            error_log('Multi Secure CPT Rate Limit Error: ' . $e->getMessage());
            return true; // Allow if error checking
        }
    }
    
    /**
     * Render entries list
     */
    public function render_entries_list($atts) {
        $atts = shortcode_atts([
            'cpt' => '',
            'limit' => 10,
            'status' => 'published'
        ], $atts);
        
        if (empty($atts['cpt'])) {
            return '<p>' . __('CPT parameter required.', 'multi-secure-cpt') . '</p>';
        }
        
        $pdo = $this->get_pdo_connection();
        if (!$pdo) {
            return '<p>' . __('Unable to load entries.', 'multi-secure-cpt') . '</p>';
        }
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM {$this->table_name} WHERE cpt_key = ? AND status = ? ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([sanitize_key($atts['cpt']), sanitize_text_field($atts['status']), (int)$atts['limit']]);
            $entries = $stmt->fetchAll();
            
            if (empty($entries)) {
                return '<p>' . __('No entries found.', 'multi-secure-cpt') . '</p>';
            }
            
            $cpt_data = $this->get_cpt_by_key($atts['cpt']);
            $form_fields = json_decode($cpt_data['form_fields'] ?? '{}', true);
            
            ob_start();
            echo '<div class="secure-cpt-entries" data-cpt="' . esc_attr($atts['cpt']) . '">';
            
            foreach ($entries as $entry) {
                $meta_data = json_decode($entry['meta_data'], true) ?? [];
                ?>
                <article class="entry" data-id="<?php echo esc_attr($entry['id']); ?>">
                    <header class="entry-header">
                        <h3 class="entry-title"><?php echo esc_html($entry['title']); ?></h3>
                        <div class="entry-meta">
                            <time datetime="<?php echo esc_attr($entry['created_at']); ?>">
                                <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($entry['created_at']))); ?>
                            </time>
                        </div>
                    </header>
                    
                    <div class="entry-content">
                        <?php foreach ($form_fields as $field_key => $field_config): ?>
                            <?php if (isset($meta_data[$field_key]) && !empty($meta_data[$field_key])): ?>
                                <div class="field-<?php echo esc_attr($field_key); ?>">
                                    <strong><?php echo esc_html($field_config['label'] ?? ucfirst($field_key)); ?>:</strong>
                                    
                                    <?php
                                    $field_value = $meta_data[$field_key];
                                    $field_type = $field_config['type'] ?? 'text';
                                    
                                    switch ($field_type) {
                                        case 'email':
                                            echo '<a href="mailto:' . esc_attr($field_value) . '">' . esc_html($field_value) . '</a>';
                                            break;
                                        case 'url':
                                            echo '<a href="' . esc_url($field_value) . '" target="_blank" rel="noopener">' . esc_html($field_value) . '</a>';
                                            break;
                                        case 'tel':
                                            echo '<a href="tel:' . esc_attr($field_value) . '">' . esc_html($field_value) . '</a>';
                                            break;
                                        case 'textarea':
                                            echo '<div class="textarea-content">' . nl2br(esc_html($field_value)) . '</div>';
                                            break;
                                        default:
                                            echo '<span>' . esc_html($field_value) . '</span>';
                                    }
                                    ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </article>
                <?php
            }
            
            echo '</div>';
            
            // Add some basic styling
            ?>
            <style>
            .secure-cpt-entries .entry {
                border: 1px solid #e1e1e1;
                margin-bottom: 20px;
                padding: 20px;
                border-radius: 5px;
                background: #fff;
            }
            .secure-cpt-entries .entry-header {
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
                margin-bottom: 15px;
            }
            .secure-cpt-entries .entry-title {
                margin: 0 0 5px 0;
                color: #333;
            }
            .secure-cpt-entries .entry-meta {
                color: #666;
                font-size: 0.9em;
            }
            .secure-cpt-entries .entry-content > div {
                margin-bottom: 10px;
            }
            .secure-cpt-entries .entry-content strong {
                display: inline-block;
                min-width: 100px;
                color: #555;
            }
            .secure-cpt-entries .textarea-content {
                margin-top: 5px;
                line-height: 1.5;
            }
            .secure-cpt-entries a {
                color: #0073aa;
                text-decoration: none;
            }
            .secure-cpt-entries a:hover {
                text-decoration: underline;
            }
            </style>
            <?php
            
            return ob_get_clean();
            
        } catch (PDOException $e) {
            error_log('Multi Secure CPT List Error: ' . $e->getMessage());
            return '<p>' . __('Error loading entries.', 'multi-secure-cpt') . '</p>';
        }
    }
    
    /**
     * Get client IP address securely
     */
    private function get_client_ip() {
        $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(strtok($_SERVER[$key], ','));
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * Cleanup on deactivation
     */
    public function cleanup() {
        flush_rewrite_rules();
        // Optional: Remove tables on deactivation
        // $pdo = $this->get_pdo_connection();
        // if ($pdo) {
        //     $pdo->exec("DROP TABLE IF EXISTS {$this->table_name}");
        //     $pdo->exec("DROP TABLE IF EXISTS {$this->cpt_table}");
        // }
    }
}

// Initialize the plugin
new MultiSecureDynamicCPT();
?>
