<?php
/**
 * Migration Admin Handler
 * Handles admin menu and AJAX for migration
 *
 * @package CIG
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Migration_Admin {

    /**
     * @var CIG_Migrator
     */
    private $migrator;

    /**
     * @var CIG_Security
     */
    private $security;

    /**
     * Constructor
     */
    public function __construct($migrator, $security) {
        $this->migrator = $migrator;
        $this->security = $security;

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_notices', [$this, 'show_migration_notice']);
        
        // AJAX handlers
        add_action('wp_ajax_cig_migrate_batch', [$this, 'ajax_migrate_batch']);
        add_action('wp_ajax_cig_verify_migration', [$this, 'ajax_verify_migration']);
        add_action('wp_ajax_cig_rollback_migration', [$this, 'ajax_rollback_migration']);
        add_action('wp_ajax_cig_get_migration_logs', [$this, 'ajax_get_migration_logs']);
        add_action('wp_ajax_cig_test_single_invoice', [$this, 'ajax_test_single_invoice']);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=invoice',
            __('Database Migration', 'cig'),
            __('Migration', 'cig'),
            'manage_options',
            'cig-migration',
            [$this, 'render_migration_page']
        );
    }

    /**
     * Show migration notice
     */
    public function show_migration_notice() {
        $screen = get_current_screen();
        
        // Only show on invoice admin pages
        if (!$screen || strpos($screen->id, 'invoice') === false) {
            return;
        }

        // Don't show on migration page
        if (isset($_GET['page']) && $_GET['page'] === 'cig-migration') {
            return;
        }

        if (!$this->migrator->is_migrated()) {
            $progress = $this->migrator->get_migration_progress();
            
            if ($progress['total'] > 0) {
                ?>
                <div class="notice notice-warning is-dismissible">
                    <p>
                        <strong><?php echo esc_html__('CIG v5.0.0 - Database Migration Required', 'cig'); ?></strong><br>
                        <?php 
                        printf(
                            __('You have %d invoices that need to be migrated to the new high-performance database tables. ', 'cig'),
                            esc_html($progress['total'])
                        );
                        ?>
                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=invoice&page=cig-migration')); ?>" class="button button-primary">
                            <?php echo esc_html__('Start Migration', 'cig'); ?>
                        </a>
                    </p>
                </div>
                <?php
            }
        }
    }

    /**
     * Render migration page
     */
    public function render_migration_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'cig'));
        }

        include CIG_TEMPLATES_DIR . 'admin/migration-panel.php';
    }

    /**
     * AJAX: Migrate batch
     */
    public function ajax_migrate_batch() {
        check_ajax_referer('cig_migration', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        try {
            // Enable error logging for this request
            $this->enable_detailed_error_logging();
            
            $result = $this->migrator->migrate_invoices_to_table(50);

            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } catch (Exception $e) {
            // Log the exception details
            error_log('[CIG Migration Error] ' . $e->getMessage());
            error_log('[CIG Migration Error Trace] ' . $e->getTraceAsString());
            
            wp_send_json_error([
                'message' => 'Migration failed with exception: ' . $e->getMessage(),
                'error_type' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
        } catch (Error $e) {
            // Catch PHP errors (PHP 7+)
            error_log('[CIG Migration Fatal Error] ' . $e->getMessage());
            error_log('[CIG Migration Fatal Error Trace] ' . $e->getTraceAsString());
            
            wp_send_json_error([
                'message' => 'Migration failed with fatal error: ' . $e->getMessage(),
                'error_type' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * Enable detailed error logging for migration
     */
    private function enable_detailed_error_logging() {
        // Temporarily enable WordPress debug mode for this request if not already enabled
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            error_log('[CIG Migration] Debug mode not enabled. Consider enabling WP_DEBUG for better error tracking.');
        }
    }

    /**
     * AJAX: Verify migration
     */
    public function ajax_verify_migration() {
        check_ajax_referer('cig_migration', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $results = $this->migrator->verify_migration_integrity();
        wp_send_json_success($results);
    }

    /**
     * AJAX: Rollback migration
     */
    public function ajax_rollback_migration() {
        check_ajax_referer('cig_migration', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $result = $this->migrator->rollback_migration();

        if ($result) {
            wp_send_json_success(['message' => 'Migration rolled back successfully']);
        } else {
            wp_send_json_error(['message' => 'Rollback failed']);
        }
    }
    
    /**
     * AJAX: Get migration logs
     * Returns recent log entries for debugging
     */
    public function ajax_get_migration_logs() {
        check_ajax_referer('cig_migration', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $logs = $this->get_migration_logs();
        wp_send_json_success(['logs' => $logs]);
    }
    
    /**
     * AJAX: Test single invoice migration
     * Migrates a single invoice with detailed error reporting
     */
    public function ajax_test_single_invoice() {
        check_ajax_referer('cig_migration', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Get the first unmigrated invoice
        $args = [
            'post_type' => 'invoice',
            'posts_per_page' => 1,
            'post_status' => 'any',
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => [
                [
                    'key' => '_cig_migrated_v5',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ];

        $invoices = get_posts($args);
        
        if (empty($invoices)) {
            wp_send_json_error(['message' => 'No unmigrated invoices found']);
        }

        $post_id = $invoices[0]->ID;
        
        try {
            // Try to create DTO
            $invoice_dto = CIG_Invoice_DTO::from_postmeta($post_id);
            
            if (!$invoice_dto) {
                wp_send_json_error([
                    'message' => 'Failed to create DTO from postmeta',
                    'post_id' => $post_id
                ]);
            }
            
            // Validate the DTO
            $validation_errors = $invoice_dto->validate();
            
            if (!empty($validation_errors)) {
                wp_send_json_error([
                    'message' => 'Validation errors found',
                    'post_id' => $post_id,
                    'validation_errors' => $validation_errors,
                    'dto_data' => $invoice_dto->to_array()
                ]);
            }
            
            // Try to migrate
            $result = $this->migrator->migrate_single_invoice($post_id);
            
            if ($result) {
                wp_send_json_success([
                    'message' => 'Invoice migrated successfully',
                    'post_id' => $post_id,
                    'dto_data' => $invoice_dto->to_array()
                ]);
            } else {
                wp_send_json_error([
                    'message' => 'Migration failed (returned false)',
                    'post_id' => $post_id,
                    'dto_data' => $invoice_dto->to_array()
                ]);
            }
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => 'Exception during test migration: ' . $e->getMessage(),
                'post_id' => $post_id,
                'error_type' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString())
            ]);
        }
    }
    
    /**
     * Get migration logs from WordPress debug.log and WooCommerce logs
     *
     * @return array Log entries
     */
    private function get_migration_logs() {
        $logs = [];
        
        // 1. Try to read WooCommerce logs
        if (class_exists('WC_Logger')) {
            $log_files = $this->get_wc_log_files('cig');
            
            foreach ($log_files as $log_file) {
                $log_content = $this->read_log_file($log_file, 100);
                if (!empty($log_content)) {
                    $logs[] = [
                        'source' => 'WooCommerce Log: ' . basename($log_file),
                        'entries' => $log_content
                    ];
                }
            }
        }
        
        // 2. Try to read WordPress debug.log
        $debug_log_path = WP_CONTENT_DIR . '/debug.log';
        if (file_exists($debug_log_path) && is_readable($debug_log_path)) {
            $log_content = $this->read_log_file($debug_log_path, 50, '[CIG');
            if (!empty($log_content)) {
                $logs[] = [
                    'source' => 'WordPress debug.log',
                    'entries' => $log_content
                ];
            }
        }
        
        // 3. Add instructions if no logs found
        if (empty($logs)) {
            $logs[] = [
                'source' => 'No Logs Found',
                'entries' => [
                    'No migration logs found. To enable logging:',
                    '1. Add to wp-config.php: define("WP_DEBUG", true); define("WP_DEBUG_LOG", true);',
                    '2. Logs will be written to ' . WP_CONTENT_DIR . '/debug.log',
                    '3. CIG also logs to WooCommerce logs (WooCommerce > Status > Logs)'
                ]
            ];
        }
        
        return $logs;
    }
    
    /**
     * Get WooCommerce log files for a specific source
     *
     * @param string $source Log source name
     * @return array Log file paths
     */
    private function get_wc_log_files($source) {
        $log_files = [];
        
        if (function_exists('wc_get_log_file_path')) {
            // WooCommerce 3.0+
            $log_files[] = wc_get_log_file_path($source);
        } else {
            // Fallback: manually find log files
            $log_dir = WP_CONTENT_DIR . '/uploads/wc-logs/';
            if (is_dir($log_dir)) {
                $files = glob($log_dir . $source . '-*.log');
                if ($files) {
                    // Sort by modification time, newest first
                    usort($files, function($a, $b) {
                        return filemtime($b) - filemtime($a);
                    });
                    $log_files = array_slice($files, 0, 3); // Get 3 most recent
                }
            }
        }
        
        return array_filter($log_files, 'file_exists');
    }
    
    /**
     * Read log file with optional filtering
     *
     * @param string $file_path Path to log file
     * @param int $max_lines Maximum number of lines to read
     * @param string $filter Optional filter string
     * @return array Log lines
     */
    private function read_log_file($file_path, $max_lines = 100, $filter = null) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return [];
        }
        
        $lines = [];
        $handle = fopen($file_path, 'r');
        
        if ($handle) {
            // Read from the end of the file
            fseek($handle, -1, SEEK_END);
            $position = ftell($handle);
            $line_count = 0;
            $current_line = '';
            
            // Read backwards
            while ($position >= 0 && $line_count < $max_lines * 2) { // Read more in case of filtering
                fseek($handle, $position, SEEK_SET);
                $char = fgetc($handle);
                
                if ($char === "\n" || $position === 0) {
                    if ($position === 0 && $char !== "\n") {
                        $current_line = $char . $current_line;
                    }
                    
                    if (trim($current_line) !== '') {
                        // Apply filter if specified
                        if ($filter === null || strpos($current_line, $filter) !== false) {
                            array_unshift($lines, $current_line);
                            $line_count++;
                            
                            if ($line_count >= $max_lines) {
                                break;
                            }
                        }
                    }
                    
                    $current_line = '';
                } else {
                    $current_line = $char . $current_line;
                }
                
                $position--;
            }
            
            fclose($handle);
        }
        
        return $lines;
    }
}
