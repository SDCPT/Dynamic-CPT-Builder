<?php
/*
Plugin Name: Secure Dynamic CPT
Description: Lightweight dynamic custom post type with PDO security approach
Version: 1.0
Author: Volkan Sah
*/

// Security check
if (!defined('WPINC')) {
    die;
}

class SecureDynamicCPT {
    
    private $pdo;
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'secure_cpt_entries';
        
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('init', [$this, 'register_cpt']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_shortcode('secure_cpt_form', [$this, 'render_frontend_form']);
        add_shortcode('secure_cpt_list', [$this, 'render_entries_list']);
        
        register_activation_hook(__FILE__, [$this, 'create_table']);
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
                error_log('Secure CPT PDO Connection Error: ' . $e->getMessage());
                return false;
            }
        }
        return $this->pdo;
    }
    
    /**
     * Load text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain('secure-dynamic-cpt', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Create database table
     */
    public function create_table() {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return false;
        
        try {
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                content TEXT,
                meta_data JSON,
                status ENUM('draft', 'published', 'pending') DEFAULT 'pending',
                author_ip VARCHAR(45),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $pdo->exec($sql);
            return true;
        } catch (PDOException $e) {
            error_log('Secure CPT Table Creation Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Register dynamic CPT
     */
    public function register_cpt() {
        $settings = get_option('secure_cpt_settings', []);
        
        $labels = [
            'name' => $settings['cpt_name'] ?? __('Secure Entries', 'secure-dynamic-cpt'),
            'singular_name' => $settings['cpt_singular'] ?? __('Entry', 'secure-dynamic-cpt'),
            'menu_name' => $settings['cpt_menu'] ?? __('Secure Entries', 'secure-dynamic-cpt'),
            'add_new_item' => __('Add New Entry', 'secure-dynamic-cpt'),
            'edit_item' => __('Edit Entry', 'secure-dynamic-cpt'),
            'view_item' => __('View Entry', 'secure-dynamic-cpt'),
            'all_items' => __('All Entries', 'secure-dynamic-cpt'),
        ];
        
        $args = [
            'labels' => $labels,
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'query_var' => true,
            'rewrite' => ['slug' => $settings['cpt_slug'] ?? 'secure-entry'],
            'capability_type' => 'post',
            'has_archive' => true,
            'hierarchical' => false,
            'menu_position' => 20,
            'supports' => ['title', 'editor', 'custom-fields'],
            'menu_icon' => 'dashicons-lock',
        ];
        
        register_post_type('secure_entry', $args);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            __('Secure CPT Settings', 'secure-dynamic-cpt'),
            __('Secure CPT', 'secure-dynamic-cpt'),
            'manage_options',
            'secure-cpt-settings',
            [$this, 'settings_page']
        );
        
        add_submenu_page(
            'edit.php?post_type=secure_entry',
            __('Manage Entries', 'secure-dynamic-cpt'),
            __('Manage Entries', 'secure-dynamic-cpt'),
            'manage_options',
            'secure-cpt-entries',
            [$this, 'manage_entries_page']
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('secure_cpt_settings', 'secure_cpt_settings', [$this, 'sanitize_settings']);
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = [];
        $sanitized['cpt_name'] = sanitize_text_field($input['cpt_name'] ?? '');
        $sanitized['cpt_singular'] = sanitize_text_field($input['cpt_singular'] ?? '');
        $sanitized['cpt_menu'] = sanitize_text_field($input['cpt_menu'] ?? '');
        $sanitized['cpt_slug'] = sanitize_title($input['cpt_slug'] ?? '');
        $sanitized['form_fields'] = $this->sanitize_form_fields($input['form_fields'] ?? '');
        
        return $sanitized;
    }
    
    /**
     * Sanitize form fields JSON
     */
    private function sanitize_form_fields($fields) {
        if (empty($fields)) return '';
        
        $decoded = json_decode($fields, true);
        if (json_last_error() !== JSON_ERROR_NONE) return '';
        
        return wp_json_encode($decoded);
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        $settings = get_option('secure_cpt_settings', []);
        ?>
        <div class="wrap">
            <h1><?php _e('Secure CPT Settings', 'secure-dynamic-cpt'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('secure_cpt_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('CPT Name (Plural)', 'secure-dynamic-cpt'); ?></th>
                        <td><input type="text" name="secure_cpt_settings[cpt_name]" value="<?php echo esc_attr($settings['cpt_name'] ?? ''); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('CPT Name (Singular)', 'secure-dynamic-cpt'); ?></th>
                        <td><input type="text" name="secure_cpt_settings[cpt_singular]" value="<?php echo esc_attr($settings['cpt_singular'] ?? ''); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Menu Name', 'secure-dynamic-cpt'); ?></th>
                        <td><input type="text" name="secure_cpt_settings[cpt_menu]" value="<?php echo esc_attr($settings['cpt_menu'] ?? ''); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('URL Slug', 'secure-dynamic-cpt'); ?></th>
                        <td><input type="text" name="secure_cpt_settings[cpt_slug]" value="<?php echo esc_attr($settings['cpt_slug'] ?? ''); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Form Fields (JSON)', 'secure-dynamic-cpt'); ?></th>
                        <td>
                            <textarea name="secure_cpt_settings[form_fields]" rows="10" cols="50" class="large-text"><?php echo esc_textarea($settings['form_fields'] ?? ''); ?></textarea>
                            <p class="description"><?php _e('Define form fields as JSON. Example: {"name": {"type": "text", "label": "Name", "required": true}}', 'secure-dynamic-cpt'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Manage entries page
     */
    public function manage_entries_page() {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) {
            echo '<div class="error"><p>' . __('Database connection failed.', 'secure-dynamic-cpt') . '</p></div>';
            return;
        }
        
        // Handle status updates
        if (isset($_POST['action']) && $_POST['action'] === 'update_status' && wp_verify_nonce($_POST['_wpnonce'], 'update_entry_status')) {
            $this->update_entry_status((int)$_POST['entry_id'], sanitize_text_field($_POST['status']));
        }
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT 50");
            $stmt->execute();
            $entries = $stmt->fetchAll();
            
            ?>
            <div class="wrap">
                <h1><?php _e('Manage Secure Entries', 'secure-dynamic-cpt'); ?></h1>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('ID', 'secure-dynamic-cpt'); ?></th>
                            <th><?php _e('Title', 'secure-dynamic-cpt'); ?></th>
                            <th><?php _e('Status', 'secure-dynamic-cpt'); ?></th>
                            <th><?php _e('Created', 'secure-dynamic-cpt'); ?></th>
                            <th><?php _e('Actions', 'secure-dynamic-cpt'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td><?php echo esc_html($entry['id']); ?></td>
                                <td><?php echo esc_html($entry['title']); ?></td>
                                <td>
                                    <span class="status-<?php echo esc_attr($entry['status']); ?>">
                                        <?php echo esc_html(ucfirst($entry['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($entry['created_at']); ?></td>
                                <td>
                                    <form method="post" style="display: inline;">
                                        <?php wp_nonce_field('update_entry_status'); ?>
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="entry_id" value="<?php echo esc_attr($entry['id']); ?>">
                                        <select name="status" onchange="this.form.submit()">
                                            <option value="pending" <?php selected($entry['status'], 'pending'); ?>><?php _e('Pending', 'secure-dynamic-cpt'); ?></option>
                                            <option value="published" <?php selected($entry['status'], 'published'); ?>><?php _e('Published', 'secure-dynamic-cpt'); ?></option>
                                            <option value="draft" <?php selected($entry['status'], 'draft'); ?>><?php _e('Draft', 'secure-dynamic-cpt'); ?></option>
                                        </select>
                                    </form>
                                    <button onclick="viewEntry(<?php echo esc_attr($entry['id']); ?>)" class="button button-small"><?php _e('View', 'secure-dynamic-cpt'); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
        } catch (PDOException $e) {
            error_log('Secure CPT Query Error: ' . $e->getMessage());
            echo '<div class="error"><p>' . __('Error loading entries.', 'secure-dynamic-cpt') . '</p></div>';
        }
    }
    
    /**
     * Update entry status
     */
    private function update_entry_status($entry_id, $status) {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return false;
        
        $allowed_statuses = ['draft', 'published', 'pending'];
        if (!in_array($status, $allowed_statuses)) return false;
        
        try {
            $stmt = $pdo->prepare("UPDATE {$this->table_name} SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $entry_id]);
            return true;
        } catch (PDOException $e) {
            error_log('Secure CPT Update Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Render frontend form
     */
    public function render_frontend_form($atts) {
        $atts = shortcode_atts(['fields' => ''], $atts);
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['secure_cpt_submit'])) {
            return $this->process_form_submission();
        }
        
        $settings = get_option('secure_cpt_settings', []);
        $form_fields = json_decode($settings['form_fields'] ?? '{}', true);
        
        if (empty($form_fields)) {
            return '<p>' . __('No form fields configured.', 'secure-dynamic-cpt') . '</p>';
        }
        
        ob_start();
        ?>
        <form method="post" class="secure-cpt-form">
            <?php wp_nonce_field('secure_cpt_form', 'secure_cpt_nonce'); ?>
            
            <?php foreach ($form_fields as $field_name => $field_config): ?>
                <div class="form-field">
                    <label for="<?php echo esc_attr($field_name); ?>">
                        <?php echo esc_html($field_config['label'] ?? $field_name); ?>
                        <?php if (!empty($field_config['required'])): ?>*<?php endif; ?>
                    </label>
                    
                    <?php
                    $field_type = $field_config['type'] ?? 'text';
                    $field_value = $_POST[$field_name] ?? '';
                    
                    switch ($field_type) {
                        case 'textarea':
                            echo '<textarea name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '"' . 
                                 (!empty($field_config['required']) ? ' required' : '') . '>' . 
                                 esc_textarea($field_value) . '</textarea>';
                            break;
                        case 'email':
                            echo '<input type="email" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . 
                                 '" value="' . esc_attr($field_value) . '"' . 
                                 (!empty($field_config['required']) ? ' required' : '') . '>';
                            break;
                        default:
                            echo '<input type="text" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . 
                                 '" value="' . esc_attr($field_value) . '"' . 
                                 (!empty($field_config['required']) ? ' required' : '') . '>';
                    }
                    ?>
                </div>
            <?php endforeach; ?>
            
            <button type="submit" name="secure_cpt_submit"><?php _e('Submit Entry', 'secure-dynamic-cpt'); ?></button>
        </form>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Process form submission
     */
    private function process_form_submission() {
        if (!wp_verify_nonce($_POST['secure_cpt_nonce'], 'secure_cpt_form')) {
            return '<div class="error">' . __('Security check failed.', 'secure-dynamic-cpt') . '</div>';
        }
        
        $pdo = $this->get_pdo_connection();
        if (!$pdo) {
            return '<div class="error">' . __('Database connection failed.', 'secure-dynamic-cpt') . '</div>';
        }
        
        $settings = get_option('secure_cpt_settings', []);
        $form_fields = json_decode($settings['form_fields'] ?? '{}', true);
        
        $sanitized_data = [];
        $title = '';
        
        foreach ($form_fields as $field_name => $field_config) {
            $value = $_POST[$field_name] ?? '';
            
            // Sanitize based on field type
            switch ($field_config['type'] ?? 'text') {
                case 'email':
                    $sanitized_data[$field_name] = sanitize_email($value);
                    break;
                case 'textarea':
                    $sanitized_data[$field_name] = sanitize_textarea_field($value);
                    break;
                case 'url':
                    $sanitized_data[$field_name] = esc_url_raw($value);
                    break;
                default:
                    $sanitized_data[$field_name] = sanitize_text_field($value);
            }
            
            // Use first field as title if no specific title field
            if (empty($title)) {
                $title = $sanitized_data[$field_name];
            }
        }
        
        try {
            $stmt = $pdo->prepare("INSERT INTO {$this->table_name} (title, content, meta_data, author_ip, created_at) VALUES (?, ?, ?, ?, NOW())");
            $result = $stmt->execute([
                $title,
                '', // Can be used for rich content
                json_encode($sanitized_data),
                $this->get_client_ip()
            ]);
            
            if ($result) {
                return '<div class="success">' . __('Entry submitted successfully!', 'secure-dynamic-cpt') . '</div>';
            } else {
                throw new Exception('Insert failed');
            }
        } catch (Exception $e) {
            error_log('Secure CPT Form Submission Error: ' . $e->getMessage());
            return '<div class="error">' . __('Error submitting entry. Please try again.', 'secure-dynamic-cpt') . '</div>';
        }
    }
    
    /**
     * Render entries list
     */
    public function render_entries_list($atts) {
        $atts = shortcode_atts([
            'limit' => 10,
            'status' => 'published'
        ], $atts);
        
        $pdo = $this->get_pdo_connection();
        if (!$pdo) {
            return '<p>' . __('Unable to load entries.', 'secure-dynamic-cpt') . '</p>';
        }
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM {$this->table_name} WHERE status = ? ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([sanitize_text_field($atts['status']), (int)$atts['limit']]);
            $entries = $stmt->fetchAll();
            
            if (empty($entries)) {
                return '<p>' . __('No entries found.', 'secure-dynamic-cpt') . '</p>';
            }
            
            ob_start();
            echo '<div class="secure-cpt-entries">';
            
            foreach ($entries as $entry) {
                $meta_data = json_decode($entry['meta_data'], true) ?? [];
                ?>
                <div class="entry">
                    <h3><?php echo esc_html($entry['title']); ?></h3>
                    <div class="entry-meta">
                        <small><?php echo esc_html($entry['created_at']); ?></small>
                    </div>
                    <div class="entry-data">
                        <?php foreach ($meta_data as $key => $value): ?>
                            <p><strong><?php echo esc_html(ucfirst($key)); ?>:</strong> <?php echo esc_html($value); ?></p>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php
            }
            
            echo '</div>';
            return ob_get_clean();
            
        } catch (PDOException $e) {
            error_log('Secure CPT List Error: ' . $e->getMessage());
            return '<p>' . __('Error loading entries.', 'secure-dynamic-cpt') . '</p>';
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
        // Optional: Remove table on deactivation
        // $pdo = $this->get_pdo_connection();
        // if ($pdo) {
        //     $pdo->exec("DROP TABLE IF EXISTS {$this->table_name}");
        // }
    }
}

// Initialize the plugin
new SecureDynamicCPT();
?>
