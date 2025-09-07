<?php
/*
Plugin Name: Multi Secure Dynamic CPT
Description: Create multiple dynamic custom post types with individual field configurations and auto-page generation.
Version: 2.3 (Stable)
Author: Volkansah
*/

// Security check
if (!defined('WPINC')) {
    die;
}

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
                error_log('MSD-CPT PDO Connection Error: ' . $e->getMessage());
                return false;
            }
        }
        return $this->pdo;
    }

    public static function plugin_activation() {
        $instance = new self();
        $instance->create_tables();
        $instance->register_all_cpts();
        flush_rewrite_rules();
    }
    
    public static function plugin_deactivation() {
        flush_rewrite_rules();
    }

    private function create_tables() {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return false;
        try {
            // Alle 'JSON' Typen zu 'TEXT' ändern für maximale Kompatibilität.
            $sql1 = "CREATE TABLE IF NOT EXISTS {$this->cpt_table} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cpt_key VARCHAR(50) NOT NULL UNIQUE,
                cpt_name VARCHAR(255) NOT NULL,
                cpt_singular VARCHAR(255) NOT NULL,
                cpt_slug VARCHAR(100) NOT NULL,
                form_fields TEXT,
                settings TEXT,
                archive_page_id BIGINT(20) UNSIGNED DEFAULT NULL,
                form_page_id BIGINT(20) UNSIGNED DEFAULT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_key (cpt_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            $sql2 = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cpt_key VARCHAR(50) NOT NULL,
                wp_post_id INT UNSIGNED DEFAULT NULL,
                title VARCHAR(255) NOT NULL,
                content TEXT,
                meta_data TEXT,
                status ENUM('draft', 'published', 'pending', 'trash') DEFAULT 'pending',
                author_ip VARCHAR(45),
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_cpt_status (cpt_key, status),
                INDEX idx_wp_post_id (wp_post_id),
                FOREIGN KEY (cpt_key) REFERENCES {$this->cpt_table}(cpt_key) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $pdo->exec($sql1);
            $pdo->exec($sql2);
            return true;
        } catch (PDOException $e) {
            error_log('MSD-CPT Table Creation Error: ' . $e->getMessage());
            return false;
        }
    }

    public function load_textdomain() {
        load_plugin_textdomain('multi-secure-cpt', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    private function get_all_cpts() {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) return [];
        try {
            $stmt = $pdo->prepare("SELECT * FROM {$this->cpt_table} WHERE is_active = 1");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('MSD-CPT Get CPTs Error: ' . $e->getMessage());
            return [];
        }
    }

    public function register_all_cpts() {
        $cpts = $this->get_all_cpts();
        foreach ($cpts as $cpt) {
            $this->register_single_cpt($cpt);
        }
    }

    private function register_single_cpt($cpt_data) {
        $settings = json_decode($cpt_data['settings'] ?? '{}', true);
        $labels = [
            'name' => $cpt_data['cpt_name'], 'singular_name' => $cpt_data['cpt_singular'], 'menu_name' => $cpt_data['cpt_name'],
            'add_new_item' => 'Neuen ' . $cpt_data['cpt_singular'] . ' erstellen', 'edit_item' => $cpt_data['cpt_singular'] . ' bearbeiten',
            'view_item' => $cpt_data['cpt_singular'] . ' ansehen', 'all_items' => 'Alle ' . $cpt_data['cpt_name'],
            'search_items' => $cpt_data['cpt_name'] . ' durchsuchen', 'not_found' => 'Keine Einträge gefunden',
        ];
        $args = [
            'labels' => $labels, 'public' => true, 'publicly_queryable' => true, 'show_ui' => true, 'show_in_menu' => true,
            'show_in_rest' => true, 'query_var' => true, 'rewrite' => ['slug' => $cpt_data['cpt_slug'], 'with_front' => false],
            'capability_type' => 'post', 'has_archive' => true, 'hierarchical' => false, 'menu_position' => 25,
            'supports' => ['title', 'editor', 'custom-fields', 'excerpt', 'thumbnail'], 'menu_icon' => $settings['menu_icon'] ?? 'dashicons-admin-post',
        ];
        register_post_type($cpt_data['cpt_key'], $args);
    }
    
    public function add_admin_menu() {
        add_menu_page('Dynamic CPTs', 'Dynamic CPTs', 'manage_options', 'multi-secure-cpt', [$this, 'main_admin_page'], 'dashicons-admin-settings', 30);
        add_submenu_page('multi-secure-cpt', 'Manage CPTs', 'Manage CPTs', 'manage_options', 'multi-secure-cpt', [$this, 'main_admin_page']);
        add_submenu_page('multi-secure-cpt', 'All Entries', 'All Entries', 'manage_options', 'multi-secure-cpt-entries', [$this, 'manage_entries_page']);
    }
    
    public function main_admin_page() {
        $cpts = $this->get_all_cpts();
        ?>
        <div class="wrap">
            <h1>Multi Secure Dynamic CPTs</h1>
            <div class="cpt-manager" style="margin-bottom: 2rem;">
                <h2>Neuen CPT erstellen</h2>
                <table class="form-table">
                    <tr><th>CPT Key</th><td><input type="text" id="new-cpt-key" placeholder="z_b_restaurants" class="regular-text"></td></tr>
                    <tr><th>CPT Name (Mehrzahl)</th><td><input type="text" id="new-cpt-name" placeholder="Restaurants" class="regular-text"></td></tr>
                    <tr><th>CPT Name (Einzahl)</th><td><input type="text" id="new-cpt-singular" placeholder="Restaurant" class="regular-text"></td></tr>
                    <tr><th>URL Slug</th><td><input type="text" id="new-cpt-slug" placeholder="restaurants" class="regular-text"></td></tr>
                    <tr><th>Formularfelder (JSON)</th><td><textarea id="new-cpt-fields" rows="5" class="large-text" placeholder='{"title":{"type":"text","label":"Name","required":true},"content":{"type":"textarea","label":"Beschreibung"},"email":{"type":"email","label":"Kontakt-E-Mail","required":true}}'></textarea></td></tr>
                </table>
                <button type="button" id="create-cpt-btn" class="button button-primary">CPT & Seiten erstellen</button>
            </div>
            
            <h2>Bestehende CPTs</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>CPT Name</th><th>CPT Key</th><th>Management Links</th><th>Aktionen</th></tr></thead>
                <tbody>
                    <?php if (empty($cpts)): ?>
                        <tr><td colspan="4">Noch keine CPTs erstellt.</td></tr>
                    <?php else: foreach ($cpts as $cpt): ?>
                        <tr>
                            <td><strong><?php echo esc_html($cpt['cpt_name']); ?></strong></td>
                            <td><code><?php echo esc_html($cpt['cpt_key']); ?></code></td>
                            <td>
                                <?php if (!empty($cpt['archive_page_id']) && get_post($cpt['archive_page_id'])): ?>
                                    <a href="<?php echo get_permalink($cpt['archive_page_id']); ?>" target="_blank">Archiv ansehen</a> | <a href="<?php echo get_edit_post_link($cpt['archive_page_id']); ?>" target="_blank">Seite bearbeiten</a><br>
                                <?php endif; ?>
                                <?php if (!empty($cpt['form_page_id']) && get_post($cpt['form_page_id'])): ?>
                                    <a href="<?php echo get_permalink($cpt['form_page_id']); ?>" target="_blank">Formular ansehen</a> | <a href="<?php echo get_edit_post_link($cpt['form_page_id']); ?>" target="_blank">Seite bearbeiten</a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=multi-secure-cpt-entries&cpt=' . $cpt['cpt_key']); ?>" class="button">Einträge ansehen</a>
                                <button class="button button-link-delete delete-cpt-btn" data-cpt="<?php echo esc_attr($cpt['cpt_key']); ?>">Löschen</button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#create-cpt-btn').click(function(e) {
                e.preventDefault();
                var btn = $(this);
                btn.prop('disabled', true).text('Erstelle...');
                var data = {
                    action: 'create_new_cpt', _wpnonce: '<?php echo wp_create_nonce('create_cpt_nonce'); ?>',
                    cpt_key: $('#new-cpt-key').val(), cpt_name: $('#new-cpt-name').val(),
                    cpt_singular: $('#new-cpt-singular').val(), cpt_slug: $('#new-cpt-slug').val(),
                    form_fields: $('#new-cpt-fields').val(),
                };
                $.post(ajaxurl, data, function(response) {
                    if (response.success) { location.reload(); } 
                    else { alert('Fehler: ' + response.data); btn.prop('disabled', false).text('CPT & Seiten erstellen'); }
                });
            });
            $('.delete-cpt-btn').click(function(e) {
                e.preventDefault();
                if (!confirm('Bist du sicher? Dies löscht den CPT, alle Einträge und die zugehörigen Seiten endgültig!')) return;
                var btn = $(this);
                btn.prop('disabled', true);
                var data = { action: 'delete_cpt', cpt_key: $(this).data('cpt'), _wpnonce: '<?php echo wp_create_nonce('delete_cpt_nonce'); ?>' };
                $.post(ajaxurl, data, function(response) {
                    if (response.success) { location.reload(); } 
                    else { alert('Fehler: ' + response.data); btn.prop('disabled', false); }
                });
            });
        });
        </script>
        <?php
    }

    public function ajax_create_cpt() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'create_cpt_nonce') || !current_user_can('manage_options')) { wp_send_json_error('Security check failed', 403); }
        $pdo = $this->get_pdo_connection();
        if (!$pdo) { wp_send_json_error('Database connection failed'); }
        $cpt_data = [
            'key' => sanitize_key($_POST['cpt_key']), 'name' => sanitize_text_field($_POST['cpt_name']),
            'singular' => sanitize_text_field($_POST['cpt_singular']), 'slug' => sanitize_title($_POST['cpt_slug']),
            'fields' => wp_unslash($_POST['form_fields'])
        ];
        if (empty($cpt_data['key']) || empty($cpt_data['name']) || empty($cpt_data['singular']) || empty($cpt_data['slug'])) { wp_send_json_error('All fields are required.'); }
        try {
            $stmt = $pdo->prepare("INSERT INTO {$this->cpt_table} (cpt_key, cpt_name, cpt_singular, cpt_slug, form_fields) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$cpt_data['key'], $cpt_data['name'], $cpt_data['singular'], $cpt_data['slug'], $cpt_data['fields']]);
            $page_ids = $this->create_cpt_pages($cpt_data);
            $stmt = $pdo->prepare("UPDATE {$this->cpt_table} SET archive_page_id = ?, form_page_id = ? WHERE cpt_key = ?");
            $stmt->execute([$page_ids['archive'], $page_ids['form'], $cpt_data['key']]);
            flush_rewrite_rules();
            wp_send_json_success('CPT and pages created successfully');
        } catch (PDOException $e) { wp_send_json_error('Database error: CPT key might already exist.'); }
    }

    private function create_cpt_pages($cpt_data) {
        $archive_page = ['post_title' => $cpt_data['name'], 'post_content' => '[secure_cpt_list cpt="' . $cpt_data['key'] . '"]', 'post_status' => 'publish', 'post_type' => 'page'];
        $archive_page_id = wp_insert_post($archive_page);
        $form_page = ['post_title' => 'Neuen ' . $cpt_data['singular'] . ' erstellen', 'post_content' => '[secure_cpt_form cpt="' . $cpt_data['key'] . '"]', 'post_status' => 'publish', 'post_type' => 'page'];
        $form_page_id = wp_insert_post($form_page);
        return ['archive' => $archive_page_id, 'form' => $form_page_id];
    }

    public function ajax_delete_cpt() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'delete_cpt_nonce') || !current_user_can('manage_options')) { wp_send_json_error('Security check failed', 403); }
        $pdo = $this->get_pdo_connection();
        if (!$pdo) { wp_send_json_error('Database connection failed'); }
        $cpt_key = sanitize_key($_POST['cpt_key']);
        try {
            $stmt = $pdo->prepare("SELECT archive_page_id, form_page_id FROM {$this->cpt_table} WHERE cpt_key = ?");
            $stmt->execute([$cpt_key]);
            $cpt_meta = $stmt->fetch();
            $stmt = $pdo->prepare("SELECT wp_post_id FROM {$this->table_name} WHERE cpt_key = ? AND wp_post_id IS NOT NULL");
            $stmt->execute([$cpt_key]);
            $entries = $stmt->fetchAll();
            if (!empty($entries)) {
                foreach ($entries as $entry) { wp_delete_post($entry['wp_post_id'], true); }
            }
            if (!empty($cpt_meta['archive_page_id'])) { wp_delete_post($cpt_meta['archive_page_id'], true); }
            if (!empty($cpt_meta['form_page_id'])) { wp_delete_post($cpt_meta['form_page_id'], true); }
            $stmt = $pdo->prepare("DELETE FROM {$this->cpt_table} WHERE cpt_key = ?");
            if ($stmt->execute([$cpt_key])) {
                flush_rewrite_rules();
                wp_send_json_success('CPT and all associated content deleted successfully.');
            } else {
                wp_send_json_error('Failed to delete CPT definition.');
            }
        } catch (PDOException $e) {
            error_log('MSD-CPT Delete Error: ' . $e->getMessage());
            wp_send_json_error('Database error during deletion.');
        }
    }
    
    public function manage_entries_page() {
        $pdo = $this->get_pdo_connection();
        if (!$pdo) { echo '<div class="error"><p>Database connection failed.</p></div>'; return; }
        try {
            $current_cpt = isset($_GET['cpt']) ? sanitize_key($_GET['cpt']) : '';
            $cpts = $this->get_all_cpts();
            if (isset($_POST['action']) && $_POST['action'] === 'update_status' && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'update_entry_status')) {
                $this->update_entry_status((int)$_POST['entry_id'], sanitize_text_field($_POST['status']));
            } ?>
            <div class="wrap">
                <h1>Alle Einträge verwalten</h1>
                <div class="cpt-filter">
                    <form method="get">
                        <input type="hidden" name="page" value="multi-secure-cpt-entries">
                        <select name="cpt" onchange="this.form.submit()"><option value="">Alle CPTs</option><?php foreach ($cpts as $cpt): ?><option value="<?php echo esc_attr($cpt['cpt_key']); ?>" <?php selected($current_cpt, $cpt['cpt_key']); ?>><?php echo esc_html($cpt['cpt_name']); ?></option><?php endforeach; ?></select>
                    </form>
                </div>
                <?php
                $where_clause = $current_cpt ? "WHERE cpt_key = ?" : "";
                $params = $current_cpt ? [$current_cpt] : [];
                $stmt = $pdo->prepare("SELECT * FROM {$this->table_name} {$where_clause} ORDER BY created_at DESC LIMIT 100");
                $stmt->execute($params);
                $entries = $stmt->fetchAll();
                ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>ID</th><th>CPT</th><th>Titel</th><th>Status</th><th>WP Post ID</th><th>Erstellt</th><th>Aktionen</th></tr></thead>
                    <tbody>
                        <?php if (empty($entries)): ?>
                            <tr><td colspan="7">Keine Einträge gefunden.</td></tr>
                        <?php else: foreach ($entries as $entry): ?>
                            <tr>
                                <td><?php echo esc_html($entry['id']); ?></td><td><code><?php echo esc_html($entry['cpt_key']); ?></code></td>
                                <td><?php echo esc_html($entry['title']); ?></td><td><?php echo esc_html(ucfirst($entry['status'])); ?></td>
                                <td><?php if (!empty($entry['wp_post_id']) && get_post($entry['wp_post_id'])): ?><a href="<?php echo get_edit_post_link($entry['wp_post_id']); ?>"><?php echo esc_html($entry['wp_post_id']); ?></a><?php else: echo '-'; endif; ?></td>
                                <td><?php echo esc_html($entry['created_at']); ?></td>
                                <td>
                                    <form method="post" style="display: inline;">
                                        <?php wp_nonce_field('update_entry_status'); ?>
                                        <input type="hidden" name="action" value="update_status"><input type="hidden" name="entry_id" value="<?php echo esc_attr($entry['id']); ?>">
                                        <select name="status" onchange="this.form.submit()"><option value="pending" <?php selected($entry['status'], 'pending'); ?>>Pending</option><option value="published" <?php selected($entry['status'], 'published'); ?>>Published</option><option value="draft" <?php selected($entry['status'], 'draft'); ?>>Draft</option><option value="trash" <?php selected($entry['status'], 'trash'); ?>>Trash</option></select>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div> <?php
        } catch (PDOException $e) {
            error_log('MSD-CPT Query Error: ' . $e->getMessage());
            echo '<div class="error"><p>Fehler beim Laden der Einträge.</p></div>';
        }
    }
    
    private function update_entry_status($entry_id, $status) {
        $pdo = $this->get_pdo_connection(); if (!$pdo) return false;
        $allowed_statuses = ['draft', 'published', 'pending', 'trash'];
        if (!in_array($status, $allowed_statuses)) return false;
        try {
            $stmt = $pdo->prepare("UPDATE {$this->table_name} SET status = ? WHERE id = ?");
            if ($stmt->execute([$status, $entry_id]) && $status === 'published') {
                $this->create_wp_post_from_entry($entry_id);
            }
            return true;
        } catch (PDOException $e) { return false; }
    }
    
    private function create_wp_post_from_entry($entry_id) {
        $pdo = $this->get_pdo_connection(); if (!$pdo) return false;
        try {
            $stmt = $pdo->prepare("SELECT * FROM {$this->table_name} WHERE id = ?");
            $stmt->execute([(int)$entry_id]);
            $entry = $stmt->fetch();
            if (!$entry) return false;
            $post_id = $entry['wp_post_id'];
            $meta_data = json_decode($entry['meta_data'], true);
            $post_data = ['post_title' => $entry['title'], 'post_content' => $entry['content'], 'post_status' => 'publish', 'post_type' => $entry['cpt_key']];
            if ($post_id && get_post($post_id)) {
                $post_data['ID'] = $post_id; wp_update_post($post_data);
            } else {
                $post_id = wp_insert_post($post_data);
                if ($post_id && !is_wp_error($post_id)) {
                    $update_stmt = $pdo->prepare("UPDATE {$this->table_name} SET wp_post_id = ? WHERE id = ?");
                    $update_stmt->execute([$post_id, $entry_id]);
                }
            }
            if ($post_id && !is_wp_error($post_id) && is_array($meta_data)) {
                foreach ($meta_data as $key => $value) { update_post_meta($post_id, sanitize_key($key), $value); }
            }
            return $post_id;
        } catch (PDOException $e) { return false; }
    }

    public function render_frontend_form($atts) {
        $atts = shortcode_atts(['cpt' => ''], $atts, 'secure_cpt_form');
        if (empty($atts['cpt'])) return '<p>CPT parameter missing for form.</p>';
        $cpt_data = $this->get_cpt_by_key($atts['cpt']);
        if (!$cpt_data) return '<p>CPT not found.</p>';
        $form_fields = json_decode($cpt_data['form_fields'] ?? '{}', true);
        if (empty($form_fields)) return '<p>No form fields configured.</p>';
        ob_start(); ?>
        <div id="secure-cpt-form-container-<?php echo esc_attr($atts['cpt']); ?>">
            <form class="secure-cpt-form" data-cpt="<?php echo esc_attr($atts['cpt']); ?>" onsubmit="submitSecureCptForm(event)">
                <?php wp_nonce_field('secure_cpt_form_' . $atts['cpt'], 'secure_cpt_nonce'); ?>
                <?php foreach ($form_fields as $field_name => $config): ?>
                <p><label for="<?php echo esc_attr($field_name); ?>"><?php echo esc_html($config['label'] ?? $field_name); echo !empty($config['required']) ? '*' : ''; ?></label><br>
                    <?php $attrs = 'name="'.esc_attr($field_name).'" id="'.esc_attr($field_name).'"' . (!empty($config['required']) ? ' required' : '');
                    if (($config['type'] ?? 'text') === 'textarea') echo '<textarea '.$attrs.'></textarea>';
                    else echo '<input type="'.esc_attr($config['type'] ?? 'text').'" '.$attrs.'>'; ?>
                </p>
                <?php endforeach; ?>
                <button type="submit">Senden</button>
            </form>
            <div class="form-message" style="display:none; margin-top:15px; padding:10px;"></div>
        </div>
        <script>
        function submitSecureCptForm(event){event.preventDefault();var form=event.target,msgDiv=form.nextElementSibling,formData=new FormData(form);formData.append('action','secure_cpt_submit');formData.append('cpt_key',form.dataset.cpt);fetch('<?php echo admin_url('admin-ajax.php'); ?>',{method:'POST',body:formData}).then(res=>res.json()).then(data=>{msgDiv.style.display='block';msgDiv.textContent=data.data.message||'Fehler';if(data.success){form.reset();}}).catch(err=>console.error(err));}
        </script>
        <?php return ob_get_clean();
    }

    private function get_cpt_by_key($cpt_key) {
        $pdo = $this->get_pdo_connection(); if (!$pdo) return null;
        try {
            $stmt = $pdo->prepare("SELECT * FROM {$this->cpt_table} WHERE cpt_key = ? AND is_active = 1");
            $stmt->execute([$cpt_key]); return $stmt->fetch();
        } catch (PDOException $e) { return null; }
    }

    public function ajax_form_submit() {
        $cpt_key = isset($_POST['cpt_key']) ? sanitize_key($_POST['cpt_key']) : '';
        if (empty($cpt_key) || !wp_verify_nonce($_POST['secure_cpt_nonce'], 'secure_cpt_form_' . $cpt_key)) { wp_send_json_error(['message' => 'Security fail.'], 403); }
        $cpt_data = $this->get_cpt_by_key($cpt_key);
        if (!$cpt_data) { wp_send_json_error(['message' => 'Invalid CPT.'], 400); }
        $form_fields = json_decode($cpt_data['form_fields'], true);
        $title = ''; $content = ''; $meta_data = [];
        foreach ($form_fields as $key => $config) {
            $value = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
            if (!empty($config['required']) && empty($value)) { wp_send_json_error(['message' => $config['label'] . ' ist ein Pflichtfeld.'], 400); }
            if ($key === 'title') $title = $value; elseif ($key === 'content') $content = $value; else $meta_data[$key] = $value;
        }
        if (empty($title)) $title = 'Eintrag ' . time();
        $pdo = $this->get_pdo_connection();
        if (!$pdo) { wp_send_json_error(['message' => 'DB error.'], 500); }
        try {
            $stmt = $pdo->prepare("INSERT INTO {$this->table_name} (cpt_key, title, content, meta_data, author_ip, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$cpt_key, $title, $content, json_encode($meta_data), $_SERVER['REMOTE_ADDR']??'?', $_SERVER['HTTP_USER_AGENT']??'?']);
            wp_send_json_success(['message' => 'Eintrag erfolgreich übermittelt!']);
        } catch (PDOException $e) { wp_send_json_error(['message' => 'Eintrag konnte nicht gespeichert werden.'], 500); }
    }

    public function render_entries_list($atts) {
        $atts = shortcode_atts(['cpt' => ''], $atts, 'secure_cpt_list');
        if (empty($atts['cpt'])) return '<p>CPT parameter missing for list.</p>';
        $query = new WP_Query(['post_type' => $atts['cpt'], 'post_status' => 'publish', 'posts_per_page' => 10]);
        if (!$query->have_posts()) return '<p>Keine Einträge gefunden.</p>';
        ob_start();
        echo '<div class="secure-cpt-list">';
        while ($query->have_posts()) {
            $query->the_post();
            echo '<div class="cpt-list-item" style="margin-bottom: 1.5em;"><h4><a href="'.get_permalink().'">'.get_the_title().'</a></h4><div>'.get_the_excerpt().'</div></div>';
        }
        echo '</div>';
        wp_reset_postdata();
        return ob_get_clean();
    }
}

new MultiSecureDynamicCPT();
