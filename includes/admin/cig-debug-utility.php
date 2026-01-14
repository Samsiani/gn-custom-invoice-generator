<?php
/**
 * CIG Debug Utility - Standalone script for viewing migration logs
 * 
 * Access this file directly via:
 * wp-content/plugins/custom-woocommerce-invoice-generator/includes/admin/cig-debug-utility.php
 * 
 * @package CIG
 * @since 5.0.0
 */

// Load WordPress
require_once(dirname(__FILE__) . '/../../../../../../wp-load.php');

// Security check
if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to access this page.');
}

// Disable output buffering to show results immediately
if (ob_get_level()) {
    ob_end_flush();
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>CIG Migration Debug Utility</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            margin: 20px;
            background: #f0f0f1;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1d2327;
            border-bottom: 2px solid #2271b1;
            padding-bottom: 10px;
        }
        h2 {
            color: #2271b1;
            margin-top: 30px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 8px;
        }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #2271b1;
            padding: 15px;
            margin: 20px 0;
        }
        .error-box {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 15px;
            margin: 20px 0;
            color: #721c24;
        }
        .success-box {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin: 20px 0;
            color: #155724;
        }
        .log-section {
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
            max-height: 500px;
            overflow-y: auto;
        }
        .log-line {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            padding: 3px 0;
            line-height: 1.6;
        }
        .log-error {
            color: #dc3545;
            background: #fff5f5;
            padding: 5px;
            border-left: 3px solid #dc3545;
            margin: 2px 0;
        }
        .log-warning {
            color: #856404;
            background: #fff9e6;
            padding: 5px;
            border-left: 3px solid #ffc107;
            margin: 2px 0;
        }
        pre {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #f5f5f5;
            font-weight: 600;
        }
        .button {
            display: inline-block;
            padding: 10px 20px;
            background: #2271b1;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            margin: 5px;
        }
        .button:hover {
            background: #135e96;
        }
        .button-secondary {
            background: #6c757d;
        }
        .button-secondary:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 CIG Migration Debug Utility</h1>
        
        <div class="info-box">
            <strong>Debug Utility for Custom Invoice Generator (CIG) v5.0.0</strong><br>
            This page displays detailed information about the migration process and helps diagnose issues.
        </div>

        <?php
        // Get migration status
        $migrator = CIG()->migrator;
        $progress = $migrator->get_migration_progress();
        $is_migrated = $migrator->is_migrated();
        $database = CIG()->database;
        $health_status = $database->get_health_status();
        ?>

        <h2>📊 Migration Status</h2>
        <table>
            <tr>
                <th>Status</th>
                <td><?php echo $is_migrated ? '<span style="color: green;">✓ Completed</span>' : '<span style="color: orange;">⚠ In Progress or Pending</span>'; ?></td>
            </tr>
            <tr>
                <th>Total Invoices</th>
                <td><?php echo esc_html($progress['total']); ?></td>
            </tr>
            <tr>
                <th>Migrated</th>
                <td><?php echo esc_html($progress['migrated']); ?></td>
            </tr>
            <tr>
                <th>Remaining</th>
                <td><?php echo esc_html($progress['remaining']); ?></td>
            </tr>
            <tr>
                <th>Progress</th>
                <td><?php echo esc_html($progress['percentage']); ?>%</td>
            </tr>
            <tr>
                <th>Custom Tables</th>
                <td><?php echo $health_status['tables_exist'] ? '<span style="color: green;">✓ Created</span>' : '<span style="color: red;">✗ Not Created</span>'; ?></td>
            </tr>
            <tr>
                <th>Database Version</th>
                <td><?php echo esc_html($health_status['version']); ?></td>
            </tr>
        </table>

        <?php if ($progress['remaining'] > 0): ?>
            <div class="info-box">
                <strong>Next Steps:</strong><br>
                There are <?php echo esc_html($progress['remaining']); ?> invoices remaining to migrate.
                <a href="<?php echo admin_url('edit.php?post_type=invoice&page=cig-migration'); ?>" class="button">Go to Migration Panel</a>
            </div>
        <?php endif; ?>

        <h2>📝 WordPress Debug Log</h2>
        <?php
        $debug_log_path = WP_CONTENT_DIR . '/debug.log';
        if (file_exists($debug_log_path) && is_readable($debug_log_path)) {
            echo '<div class="log-section">';
            
            // Read last 100 lines with CIG filter
            $lines = [];
            $handle = fopen($debug_log_path, 'r');
            if ($handle) {
                // Get file size
                fseek($handle, 0, SEEK_END);
                $size = ftell($handle);
                
                // Read last 50KB or full file if smaller
                $read_size = min(50000, $size);
                fseek($handle, -$read_size, SEEK_END);
                $content = fread($handle, $read_size);
                fclose($handle);
                
                // Split into lines and filter
                $all_lines = explode("\n", $content);
                foreach ($all_lines as $line) {
                    if (stripos($line, 'CIG') !== false || stripos($line, 'migration') !== false) {
                        $lines[] = $line;
                    }
                }
                
                // Get last 100 matching lines
                $lines = array_slice($lines, -100);
                
                if (empty($lines)) {
                    echo '<p>No CIG-related entries found in debug.log</p>';
                } else {
                    foreach ($lines as $line) {
                        $class = 'log-line';
                        if (stripos($line, 'error') !== false || stripos($line, 'fatal') !== false) {
                            $class = 'log-error';
                        } elseif (stripos($line, 'warning') !== false) {
                            $class = 'log-warning';
                        }
                        echo '<div class="' . $class . '">' . esc_html($line) . '</div>';
                    }
                }
            }
            
            echo '</div>';
            echo '<p><small>Log file: ' . esc_html($debug_log_path) . '</small></p>';
        } else {
            echo '<div class="error-box">';
            echo '<strong>Debug log not found or not readable.</strong><br>';
            echo 'To enable WordPress debug logging, add these lines to your wp-config.php:<br>';
            echo '<pre>define(\'WP_DEBUG\', true);
define(\'WP_DEBUG_LOG\', true);
define(\'WP_DEBUG_DISPLAY\', false);
@ini_set(\'display_errors\', 0);</pre>';
            echo '<p>Expected location: <code>' . esc_html($debug_log_path) . '</code></p>';
            echo '</div>';
        }
        ?>

        <h2>📋 WooCommerce Logs</h2>
        <?php
        $wc_log_dir = WP_CONTENT_DIR . '/uploads/wc-logs/';
        if (is_dir($wc_log_dir)) {
            $log_files = glob($wc_log_dir . 'cig-*.log');
            
            if (!empty($log_files)) {
                // Sort by modification time, newest first
                usort($log_files, function($a, $b) {
                    return filemtime($b) - filemtime($a);
                });
                
                // Show only the most recent log file
                $latest_log = $log_files[0];
                echo '<h3>Latest Log: ' . basename($latest_log) . '</h3>';
                echo '<p><small>Modified: ' . date('Y-m-d H:i:s', filemtime($latest_log)) . '</small></p>';
                
                echo '<div class="log-section">';
                
                // Read last 100 lines
                $lines = [];
                $handle = fopen($latest_log, 'r');
                if ($handle) {
                    fseek($handle, 0, SEEK_END);
                    $size = ftell($handle);
                    $read_size = min(50000, $size);
                    fseek($handle, -$read_size, SEEK_END);
                    $content = fread($handle, $read_size);
                    fclose($handle);
                    
                    $lines = array_slice(explode("\n", $content), -100);
                    
                    foreach ($lines as $line) {
                        if (trim($line) === '') continue;
                        
                        $class = 'log-line';
                        if (stripos($line, 'error') !== false || stripos($line, 'fatal') !== false) {
                            $class = 'log-error';
                        } elseif (stripos($line, 'warning') !== false) {
                            $class = 'log-warning';
                        }
                        echo '<div class="' . $class . '">' . esc_html($line) . '</div>';
                    }
                }
                
                echo '</div>';
                
                // Show other available log files
                if (count($log_files) > 1) {
                    echo '<p><strong>Other log files available:</strong></p><ul>';
                    foreach (array_slice($log_files, 1, 5) as $log_file) {
                        echo '<li>' . basename($log_file) . ' (' . date('Y-m-d H:i:s', filemtime($log_file)) . ')</li>';
                    }
                    echo '</ul>';
                }
            } else {
                echo '<div class="info-box">No CIG log files found in WooCommerce logs directory.</div>';
            }
        } else {
            echo '<div class="info-box">WooCommerce logs directory not found at: <code>' . esc_html($wc_log_dir) . '</code></div>';
        }
        ?>

        <h2>🔍 Test Single Invoice Migration</h2>
        <div class="info-box">
            <p>Click the button below to test the migration of a single unmigrated invoice. This will help identify specific errors.</p>
            <a href="<?php echo admin_url('edit.php?post_type=invoice&page=cig-migration'); ?>" class="button">Go to Migration Panel (with Test Button)</a>
        </div>

        <h2>ℹ️ System Information</h2>
        <table>
            <tr>
                <th>PHP Version</th>
                <td><?php echo esc_html(PHP_VERSION); ?></td>
            </tr>
            <tr>
                <th>WordPress Version</th>
                <td><?php echo esc_html(get_bloginfo('version')); ?></td>
            </tr>
            <tr>
                <th>WooCommerce Version</th>
                <td><?php echo class_exists('WooCommerce') ? esc_html(WC()->version) : 'Not installed'; ?></td>
            </tr>
            <tr>
                <th>CIG Version</th>
                <td><?php echo esc_html(CIG_VERSION); ?></td>
            </tr>
            <tr>
                <th>WP_DEBUG</th>
                <td><?php echo defined('WP_DEBUG') && WP_DEBUG ? '<span style="color: green;">✓ Enabled</span>' : '<span style="color: orange;">✗ Disabled</span>'; ?></td>
            </tr>
            <tr>
                <th>WP_DEBUG_LOG</th>
                <td><?php echo defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? '<span style="color: green;">✓ Enabled</span>' : '<span style="color: orange;">✗ Disabled</span>'; ?></td>
            </tr>
        </table>

        <h2>🛠️ Quick Actions</h2>
        <a href="<?php echo admin_url('edit.php?post_type=invoice&page=cig-migration'); ?>" class="button">Migration Panel</a>
        <a href="<?php echo admin_url('admin.php?page=wc-status&tab=logs'); ?>" class="button button-secondary">WooCommerce Logs</a>
        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="button button-secondary">Refresh This Page</a>

        <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 12px;">
            <p><strong>Direct Access URL:</strong> <?php echo esc_url(plugins_url('includes/admin/cig-debug-utility.php', CIG_PLUGIN_FILE)); ?></p>
            <p>This debug utility provides a centralized view of migration logs and system status.</p>
        </div>
    </div>
</body>
</html>
