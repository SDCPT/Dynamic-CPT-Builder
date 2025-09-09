<?php
/**
 * Export module for Multi Secure Dynamic CPTs.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SDCPT_Export {
    private $parent;
    private $pdo;

    public function __construct($parent) {
        $this->parent = $parent;
        $this->pdo = $this->get_pdo_connection();
        add_action('admin_menu', [$this, 'add_export_menu']);
        add_action('admin_post_sdcpt_export', [$this, 'handle_export']);
    }

    private function get_pdo_connection() {
        if ($this->pdo) {
            return $this->pdo;
        }
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ];
            $this->pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
            return $this->pdo;
        } catch (PDOException $e) {
            error_log('Database Connection Error in Export: ' . $e->getMessage());
            return false;
        }
    }
    
    public function add_export_menu() {
        add_submenu_page(
            'multi-secure-cpt',
            __('Export Data', 'multi-secure-cpt'),
            __('Export', 'multi-secure-cpt'),
            'manage_options',
            'multi-secure-cpt-export',
            [$this, 'render_export_page']
        );
    }
    
    public function render_export_page() {
        $cpts = $this->get_all_cpts();
        ?>
        <div class="wrap">
            <h1><?php _e('Export CPT Data', 'multi-secure-cpt'); ?></h1>
            <p><?php _e('Select a Custom Post Type to export its form submissions as a CSV file.', 'multi-secure-cpt'); ?></p>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="sdcpt_export">
                <?php wp_nonce_field('sdcpt_export_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="cpt_key"><?php _e('Select CPT', 'multi-secure-cpt'); ?></label></th>
                        <td>
                            <select name="cpt_key" id="cpt_key" required>
                                <option value=""><?php _e('-- Select a CPT --', 'multi-secure-cpt'); ?></option>
                                <?php foreach ($cpts as $cpt): ?>
                                    <option value="<?php echo esc_attr($cpt['cpt_key']); ?>">
                                        <?php echo esc_html($cpt['cpt_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Download CSV', 'multi-secure-cpt'); ?>">
                </p>
            </form>
        </div>
        <?php
    }

    private function get_all_cpts() {
        if (!$this->pdo) return [];
        try {
            $stmt = $this->pdo->query("SELECT cpt_key, cpt_name FROM {$this->parent->cpt_table} WHERE is_active = 1 ORDER BY cpt_name ASC");
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('SDCPT_Export Error: Could not get CPTs: ' . $e->getMessage());
            return [];
        }
    }
    
    public function handle_export() {
        if (!current_user_can('manage_options') || !isset($_POST['cpt_key']) || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'sdcpt_export_nonce')) {
            wp_die(__('You do not have sufficient permissions to access this page or the security check failed.', 'multi-secure-cpt'));
        }

        $cpt_key = sanitize_key($_POST['cpt_key']);
        if (empty($cpt_key)) {
            wp_die(__('Invalid CPT key provided.', 'multi-secure-cpt'));
        }

        if (!$this->pdo) {
            wp_die(__('Database connection failed.', 'multi-secure-cpt'));
        }
        
        $entries = [];
        try {
            $stmt = $this->pdo->prepare("SELECT title, content, meta_data, status, created_at, wp_post_id FROM {$this->parent->table_name} WHERE cpt_key = ? ORDER BY created_at DESC");
            $stmt->execute([$cpt_key]);
            $entries = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('SDCPT_Export Error: ' . $e->getMessage());
            wp_die(__('Error fetching data from the database.', 'multi-secure-cpt'));
        }

        if (empty($entries)) {
            wp_die(__('No entries found for this CPT.', 'multi-secure-cpt'));
        }

        // Get all unique meta keys to form the CSV header
        $header_fields = ['ID', 'Title', 'Content', 'Status', 'WP Post ID', 'Created At'];
        $meta_keys = [];
        foreach ($entries as $entry) {
            $meta_data = json_decode($entry['meta_data'], true);
            if (is_array($meta_data)) {
                $meta_keys = array_merge($meta_keys, array_keys($meta_data));
            }
        }
        $meta_keys = array_unique($meta_keys);
        sort($meta_keys);
        $header = array_merge($header_fields, $meta_keys);

        // Prepare for output
        $filename = 'export-' . $cpt_key . '-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, array_map('ucfirst', array_map(function($v) {
            return str_replace('_', ' ', $v);
        }, $header)));

        foreach ($entries as $entry) {
            $meta_data = json_decode($entry['meta_data'], true);
            $row = [
                $entry['id'] ?? '',
                $entry['title'],
                $entry['content'],
                $entry['status'],
                $entry['wp_post_id'],
                $entry['created_at'],
            ];
            foreach ($meta_keys as $meta_key) {
                $value = $meta_data[$meta_key] ?? '';
                // If the value is a JSON string (e.g., from a multiple select), decode it
                if (is_string($value) && ($decoded = json_decode($value)) !== null && json_last_error() === JSON_ERROR_NONE) {
                    $row[] = implode(', ', (array) $decoded);
                } else {
                    $row[] = $value;
                }
            }
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }
}

// Initialize the module
add_action('plugins_loaded', function() {
    $parent = new MultiSecureDynamicCPT();
    new SDCPT_Export($parent);
});
