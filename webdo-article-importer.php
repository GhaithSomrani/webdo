<?php
/**
 * Plugin Name: WebDo Article Importer
 * Description: Import articles from webdo02.json file into WordPress
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 */

// Prevent direct access
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WAI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WAI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WAI_VERSION', '1.0.0');

// Main plugin class
class WebDoArticleImporter {
    
    private $importer;
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wai_import_log';
        
        // Hook into WordPress
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_wai_import_batch', array($this, 'ajax_import_batch'));
        add_action('wp_ajax_wai_check_status', array($this, 'ajax_check_status'));
        add_action('wp_ajax_wai_reset_import', array($this, 'ajax_reset_import'));
        add_action('wp_ajax_wai_create_table', array($this, 'ajax_create_table'));
        
        // Include the importer class
        require_once WAI_PLUGIN_DIR . 'includes/class-importer.php';
        $this->importer = new WAI_Importer();
    }
    
    public function init() {
        // Create database table on activation
        register_activation_hook(__FILE__, array($this, 'create_log_table'));
        
        // Also check if table exists on every page load (failsafe)
        $this->maybe_create_table();
    }
    
    private function maybe_create_table() {
        global $wpdb;
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        
        if (!$table_exists) {
            $this->create_log_table();
        }
    }
    
    public function create_log_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id int(11) NOT NULL AUTO_INCREMENT,
            article_id int(11) NOT NULL,
            title varchar(255) NOT NULL,
            status varchar(20) NOT NULL,
            message text,
            imported_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY article_id (article_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Verify table was created
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        if (!$table_exists) {
            // Try alternative approach
            $wpdb->query($sql);
        }
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'WebDo Article Importer',
            'Article Importer',
            'manage_options',
            'webdo-importer',
            array($this, 'admin_page'),
            'dashicons-download',
            30
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'toplevel_page_webdo-importer') {
            return;
        }
        
        wp_enqueue_script(
            'wai-admin',
            WAI_PLUGIN_URL . 'assets/admin.js',
            array('jquery'),
            WAI_VERSION,
            true
        );
        
        wp_enqueue_style(
            'wai-admin',
            WAI_PLUGIN_URL . 'assets/admin.css',
            array(),
            WAI_VERSION
        );
        
        wp_localize_script('wai-admin', 'wai_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wai_import_nonce')
        ));
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>WebDo Article Importer</h1>
            
            <?php
            // Check if table exists and show installation button if needed
            global $wpdb;
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
            
            if (!$table_exists) {
                ?>
                <div class="notice notice-warning">
                    <p>Database table not found. Please click the button below to create it.</p>
                    <p>
                        <button type="button" id="create-table" class="button button-primary">Install Database Table</button>
                    </p>
                </div>
                <?php
            }
            ?>
            
            <div class="wai-container">
                <div class="wai-section">
                    <h2>Import Configuration</h2>
                    
                    <div class="notice notice-info" style="margin: 20px 0;">
                        <p><strong>Important:</strong> If an article exists with a different ID than in the JSON file, the plugin will automatically change the post ID to match the JSON file.</p>
                    </div>
                    
                    <form id="wai-import-form">
                        <table class="form-table">
                            <tr>
                                <th>JSON File Path</th>
                                <td>
                                    <input type="text" id="json-path" name="json_path" 
                                           value="<?php echo esc_attr(WP_CONTENT_DIR . '/uploads/webdo02.json'); ?>" 
                                           class="regular-text" />
                                    <p class="description">Full path to your webdo02.json file</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Import Options</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="skip_images" id="skip-images" value="1" />
                                        Skip image downloads
                                    </label><br>
                                    <label>
                                        <input type="checkbox" name="test_mode" id="test-mode" value="1" />
                                        Test mode (import only first 5 articles)
                                    </label><br>
                                    <label>
                                        <input type="checkbox" name="preserve_authors" id="preserve-authors" value="1" checked />
                                        Preserve existing authors (don't overwrite)
                                    </label><br>
                                    <label>
                                        Skip first <input type="number" name="skip_lines" id="skip-lines" value="0" min="0" max="999999" style="width: 80px;" /> articles
                                        <span class="description">(Start from line #<span id="start-line">1</span>)</span>
                                    </label><br>
                                    <label>
                                        <input type="number" name="batch_size" id="batch-size" value="10" min="1" max="50" />
                                        Articles per batch
                                    </label>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <button type="button" id="start-import" class="button button-primary">Start Import</button>
                            <button type="button" id="pause-import" class="button" disabled>Pause Import</button>
                            <button type="button" id="reset-import" class="button">Reset Import</button>
                        </p>
                    </form>
                </div>
                
                <div class="wai-section">
                    <h2>Import Progress</h2>
                    
                    <div id="import-progress" style="display: none;">
                        <div class="progress-bar-container">
                            <div class="progress-bar" style="width: 0%;"></div>
                        </div>
                        <p class="progress-text">
                            <span id="progress-current">0</span> / <span id="progress-total">0</span> articles processed
                        </p>
                        <p class="progress-status">
                            Status: <span id="status-text">Ready</span>
                        </p>
                    </div>
                    
                    <div id="import-log">
                        <h3>Recent Import Activity</h3>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Article ID</th>
                                    <th>Title</th>
                                    <th>Status</th>
                                    <th>Message</th>
                                    <th>Imported At</th>
                                </tr>
                            </thead>
                            <tbody id="log-entries">
                                <?php $this->display_recent_logs(); ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="wai-section">
                    <h2>Import Statistics</h2>
                    <div id="import-stats">
                        <?php $this->display_import_stats(); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function display_recent_logs() {
        global $wpdb;
        
        $logs = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} ORDER BY imported_at DESC LIMIT 20"
        );
        
        if (empty($logs)) {
            echo '<tr><td colspan="5">No import logs yet.</td></tr>';
            return;
        }
        
        foreach ($logs as $log) {
            $status_class = $log->status === 'success' ? 'success' : 'error';
            if (strpos($log->message, 'changing to ID') !== false) {
                $status_class = 'updated';
            }
            echo '<tr>';
            echo '<td>' . esc_html($log->article_id) . '</td>';
            echo '<td>' . esc_html($log->title) . '</td>';
            echo '<td><span class="status-' . $status_class . '">' . esc_html($log->status) . '</span></td>';
            echo '<td>' . esc_html($log->message) . '</td>';
            echo '<td>' . esc_html($log->imported_at) . '</td>';
            echo '</tr>';
        }
    }
    
    private function display_import_stats() {
        global $wpdb;
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $success = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'success'");
        $failed = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'failed'");
        
        ?>
        <div class="stats-grid">
            <div class="stat-item">
                <h4>Total Processed</h4>
                <p class="stat-number"><?php echo intval($total); ?></p>
            </div>
            <div class="stat-item">
                <h4>Successfully Imported</h4>
                <p class="stat-number success"><?php echo intval($success); ?></p>
            </div>
            <div class="stat-item">
                <h4>Failed</h4>
                <p class="stat-number error"><?php echo intval($failed); ?></p>
            </div>
        </div>
        <?php
    }
    
    public function ajax_import_batch() {
        check_ajax_referer('wai_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $json_path = sanitize_text_field($_POST['json_path']);
        $batch_size = intval($_POST['batch_size']);
        $offset = intval($_POST['offset']);
        $skip_images = isset($_POST['skip_images']) && $_POST['skip_images'] === '1';
        $test_mode = isset($_POST['test_mode']) && $_POST['test_mode'] === '1';
        $preserve_authors = isset($_POST['preserve_authors']) && $_POST['preserve_authors'] === '1';
        $skip_lines = intval($_POST['skip_lines'] ?? 0);
        
        // Import configuration
        $this->importer->set_config(array(
            'skip_images' => $skip_images,
            'test_mode' => $test_mode,
            'preserve_authors' => $preserve_authors,
            'batch_size' => $batch_size,
            'skip_lines' => $skip_lines
        ));
        
        $result = $this->importer->import_batch($json_path, $offset, $batch_size);
        
        // Log results
        foreach ($result['processed'] as $article_result) {
            $this->log_import(
                $article_result['id'],
                $article_result['title'],
                $article_result['status'],
                $article_result['message']
            );
        }
        
        wp_send_json_success($result);
    }
    
    public function ajax_check_status() {
        check_ajax_referer('wai_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        // Get latest stats
        global $wpdb;
        
        $stats = array(
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}"),
            'success' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'success'"),
            'failed' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'failed'"),
            'recent_logs' => $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY imported_at DESC LIMIT 5")
        );
        
        wp_send_json_success($stats);
    }
    
    public function ajax_reset_import() {
        check_ajax_referer('wai_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        
        wp_send_json_success(array('message' => 'Import reset successfully'));
    }
    
    public function ajax_create_table() {
        check_ajax_referer('wai_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $this->create_log_table();
        
        wp_send_json_success(array('message' => 'Table created successfully'));
    }
    
    private function log_import($article_id, $title, $status, $message) {
        global $wpdb;
        
        $wpdb->insert(
            $this->table_name,
            array(
                'article_id' => $article_id,
                'title' => $title,
                'status' => $status,
                'message' => $message
            ),
            array('%d', '%s', '%s', '%s')
        );
    }
}

// Initialize the plugin
new WebDoArticleImporter();