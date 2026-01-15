<?php
/**
 * Migration Panel Template
 * Admin interface for migrating data from postmeta to custom tables
 *
 * @package CIG
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get migration progress
$migrator = CIG()->migrator;
$progress = $migrator->get_migration_progress();
$is_migrated = $migrator->is_migrated();
$health_status = CIG()->database->get_health_status();
?>

<div class="wrap cig-migration-panel">
    <h1><?php echo esc_html__('CIG v5.0.0 - Database Migration', 'cig'); ?></h1>
    
    <div class="cig-migration-container">
        <!-- Status Card -->
        <div class="cig-card cig-status-card">
            <h2><?php echo esc_html__('Migration Status', 'cig'); ?></h2>
            
            <div class="cig-status-info">
                <div class="cig-status-item">
                    <strong><?php echo esc_html__('Custom Tables:', 'cig'); ?></strong>
                    <span class="cig-badge <?php echo $health_status['tables_exist'] ? 'cig-badge-success' : 'cig-badge-error'; ?>">
                        <?php echo $health_status['tables_exist'] ? __('Created', 'cig') : __('Not Created', 'cig'); ?>
                    </span>
                </div>
                
                <div class="cig-status-item">
                    <strong><?php echo esc_html__('Database Version:', 'cig'); ?></strong>
                    <span><?php echo esc_html($health_status['version']); ?></span>
                </div>
                
                <div class="cig-status-item">
                    <strong><?php echo esc_html__('Total Invoices:', 'cig'); ?></strong>
                    <span><?php echo esc_html($progress['total']); ?></span>
                </div>
                
                <div class="cig-status-item">
                    <strong><?php echo esc_html__('Migrated:', 'cig'); ?></strong>
                    <span><?php echo esc_html($progress['migrated']); ?></span>
                </div>
                
                <div class="cig-status-item">
                    <strong><?php echo esc_html__('Remaining:', 'cig'); ?></strong>
                    <span><?php echo esc_html($progress['remaining']); ?></span>
                </div>
                
                <div class="cig-status-item">
                    <strong><?php echo esc_html__('Progress:', 'cig'); ?></strong>
                    <span><?php echo esc_html($progress['percentage']); ?>%</span>
                </div>
            </div>
            
            <!-- Progress Bar -->
            <div class="cig-progress-bar">
                <div class="cig-progress-fill" style="width: <?php echo esc_attr($progress['percentage']); ?>%"></div>
            </div>
        </div>

        <!-- Actions Card -->
        <div class="cig-card cig-actions-card">
            <h2><?php echo esc_html__('Migration Actions', 'cig'); ?></h2>
            
            <?php if (!$health_status['tables_exist']): ?>
                <div class="notice notice-error">
                    <p><?php echo esc_html__('Custom tables are not created. Please deactivate and reactivate the plugin.', 'cig'); ?></p>
                </div>
            <?php elseif ($is_migrated && $progress['remaining'] === 0): ?>
                <div class="notice notice-success">
                    <p><?php echo esc_html__('Migration completed! All invoices have been migrated to custom tables.', 'cig'); ?></p>
                </div>
                
                <!-- Post-migration actions -->
                <p style="margin-top: 15px;">
                    <button type="button" class="button button-secondary cig-verify-btn">
                        <?php echo esc_html__('Verify Data Integrity', 'cig'); ?>
                    </button>
                    <button type="button" class="button button-secondary cig-view-logs-btn" style="margin-left: 10px;">
                        <?php echo esc_html__('View Migration Logs', 'cig'); ?>
                    </button>
                    <button type="button" class="button button-link-delete cig-rollback-btn" style="margin-left: 10px;">
                        <?php echo esc_html__('Rollback Migration', 'cig'); ?>
                    </button>
                </p>
            <?php else: ?>
                <?php 
                // Show resume message if there's partial progress
                $has_partial_progress = $progress['migrated'] > 0 && $progress['remaining'] > 0;
                ?>
                
                <?php if ($has_partial_progress): ?>
                    <div class="notice notice-warning inline" style="margin: 0 0 15px 0;">
                        <p>
                            <strong><?php echo esc_html__('Migration in progress', 'cig'); ?></strong><br>
                            <?php 
                            printf(
                                esc_html__('%d of %d invoices migrated (%s%%). Click Resume to continue.', 'cig'),
                                esc_html($progress['migrated']),
                                esc_html($progress['total']),
                                esc_html($progress['percentage'])
                            );
                            ?>
                        </p>
                    </div>
                <?php endif; ?>
                
                <p><?php echo esc_html__('Click the button below to start migrating your invoice data from postmeta to custom database tables.', 'cig'); ?></p>
                
                <p>
                    <button type="button" class="button button-primary button-hero cig-migrate-btn">
                        <?php echo $has_partial_progress ? esc_html__('Resume Migration', 'cig') : esc_html__('Start Migration', 'cig'); ?>
                    </button>
                    <button type="button" class="button button-secondary cig-test-single-btn" style="margin-left: 10px;">
                        <?php echo esc_html__('Test Single Invoice', 'cig'); ?>
                    </button>
                    <button type="button" class="button button-secondary cig-view-logs-btn" style="margin-left: 10px;">
                        <?php echo esc_html__('View Migration Logs', 'cig'); ?>
                    </button>
                    <?php if ($has_partial_progress): ?>
                        <button type="button" class="button button-link-delete cig-rollback-btn" style="margin-left: 10px;">
                            <?php echo esc_html__('Reset & Start Over', 'cig'); ?>
                        </button>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            
            <!-- Migration Log -->
            <div class="cig-migration-log" style="display: none;">
                <h3><?php echo esc_html__('Migration Log', 'cig'); ?></h3>
                <div class="cig-log-content"></div>
            </div>
            
            <!-- Debug Instructions -->
            <div class="cig-debug-instructions" style="margin-top: 20px; border-top: 1px solid #ddd; padding-top: 20px;">
                <h3><?php echo esc_html__('Debug Instructions', 'cig'); ?></h3>
                <details>
                    <summary style="cursor: pointer; font-weight: 600; padding: 10px; background: #f5f5f5; border-radius: 4px;">
                        <?php echo esc_html__('How to enable WordPress debug logging', 'cig'); ?>
                    </summary>
                    <div style="padding: 15px; background: #fff; border: 1px solid #ddd; margin-top: 10px;">
                        <p><?php echo esc_html__('To view detailed error logs, enable WordPress debug mode:', 'cig'); ?></p>
                        <ol>
                            <li><?php echo esc_html__('Open your wp-config.php file (in your WordPress root directory)', 'cig'); ?></li>
                            <li><?php echo esc_html__('Add or update these lines before "/* That\'s all, stop editing! */":', 'cig'); ?>
                                <pre style="background: #f5f5f5; padding: 10px; margin: 10px 0; overflow-x: auto;">define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
@ini_set('display_errors', 0);</pre>
                            </li>
                            <li><?php echo esc_html__('Save the file. Errors will now be logged to:', 'cig'); ?>
                                <code><?php echo esc_html(WP_CONTENT_DIR . '/debug.log'); ?></code>
                            </li>
                            <li><?php echo esc_html__('After debugging, set WP_DEBUG back to false', 'cig'); ?></li>
                        </ol>
                        <p><strong><?php echo esc_html__('CIG Logs Location:', 'cig'); ?></strong></p>
                        <ul>
                            <li><strong>WordPress Log:</strong> <code><?php echo esc_html(WP_CONTENT_DIR . '/debug.log'); ?></code></li>
                            <li><strong>WooCommerce Logs:</strong> <?php echo esc_html__('WooCommerce > Status > Logs > Select "cig-*.log"', 'cig'); ?></li>
                        </ul>
                    </div>
                </details>
            </div>
        </div>
    </div>
</div>

<style>
.cig-migration-container {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-top: 20px;
}
.cig-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    padding: 20px;
}
.cig-status-item {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f0;
}
.cig-badge-success {
    background: #d4edda;
    color: #155724;
    padding: 4px 12px;
    border-radius: 3px;
}
.cig-progress-bar {
    height: 30px;
    background: #f0f0f0;
    border-radius: 4px;
    overflow: hidden;
    margin-top: 20px;
}
.cig-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #2271b1, #135e96);
    transition: width 0.3s ease;
    color: #fff;
    font-weight: 600;
}
.cig-migration-log {
    margin-top: 20px;
    background: #fff;
    border: 1px solid #ccd0d4;
    padding: 15px;
    border-radius: 4px;
}
.cig-log-content {
    font-family: monospace;
    font-size: 12px;
    background: #f9f9f9;
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 3px;
    max-height: 500px;
    overflow-y: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
}
.log-success {
    color: #155724;
    background: #d4edda;
    padding: 8px;
    margin: 5px 0;
    border-left: 4px solid #28a745;
    border-radius: 3px;
}
.log-error {
    color: #721c24;
    background: #f8d7da;
    padding: 8px;
    margin: 5px 0;
    border-left: 4px solid #dc3545;
    border-radius: 3px;
}
.log-error-detail {
    color: #856404;
    background: #fff3cd;
    padding: 6px 8px;
    margin: 3px 0 3px 20px;
    border-left: 3px solid #ffc107;
    border-radius: 3px;
    font-size: 11px;
}
.log-warning {
    color: #856404;
    background: #fff3cd;
    padding: 8px;
    margin: 5px 0;
    border-left: 4px solid #ffc107;
    border-radius: 3px;
}
.log-info {
    color: #004085;
    background: #cce5ff;
    padding: 8px;
    margin: 5px 0;
    border-left: 4px solid #0056b3;
    border-radius: 3px;
}
.log-line {
    padding: 2px 0;
    line-height: 1.5;
}
.cig-debug-instructions details {
    margin-top: 10px;
}
.cig-debug-instructions code {
    background: #f5f5f5;
    padding: 2px 6px;
    border-radius: 3px;
    font-family: monospace;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Helper function to safely escape and format JSON
    function escapeAndFormatJson(data) {
        var jsonStr = JSON.stringify(data, null, 2);
        return $('<div/>').text(jsonStr).html();
    }
    
    // Start full migration
    $('.cig-migrate-btn').on('click', function() {
        if (!confirm('<?php echo esc_js(__('Start migration process?', 'cig')); ?>')) {
            return;
        }
        $(this).prop('disabled', true).text('<?php echo esc_js(__('Migrating...', 'cig')); ?>');
        $('.cig-migration-log').show();
        
        function runBatch() {
            $.post(ajaxurl, {
                action: 'cig_migrate_batch',
                nonce: '<?php echo wp_create_nonce('cig_migration'); ?>'
            }, function(response) {
                if (response.success) {
                    $('.cig-log-content').append('<div class="log-success">' + response.data.message + '</div>\n');
                    if (!response.data.completed) {
                        setTimeout(runBatch, 500);
                    } else {
                        $('.cig-log-content').append('<div class="log-success"><strong>Migration completed!</strong></div>');
                        setTimeout(function() { location.reload(); }, 2000);
                    }
                } else {
                    $('.cig-log-content').append('<div class="log-error"><strong>Error:</strong> ' + response.data.message + '</div>\n');
                    
                    // Display detailed error information if available
                    if (response.data.error_type) {
                        $('.cig-log-content').append('<div class="log-error-detail"><strong>Error Type:</strong> ' + response.data.error_type + '</div>\n');
                    }
                    if (response.data.error_file) {
                        $('.cig-log-content').append('<div class="log-error-detail"><strong>File:</strong> ' + response.data.error_file + ':' + response.data.error_line + '</div>\n');
                    }
                    if (response.data.trace) {
                        // Escape HTML in trace before displaying
                        var escapedTrace = $('<div/>').text(response.data.trace).html();
                        $('.cig-log-content').append('<details style="margin-top: 10px;"><summary style="cursor: pointer;"><strong>Stack Trace</strong></summary><pre style="background: #f5f5f5; padding: 10px; overflow-x: auto; font-size: 11px;">' + escapedTrace + '</pre></details>\n');
                    }
                    
                    $('.cig-migrate-btn').prop('disabled', false).text('<?php echo esc_js(__('Retry Migration', 'cig')); ?>');
                }
            }).fail(function(xhr, status, error) {
                $('.cig-log-content').append('<div class="log-error"><strong>AJAX Error:</strong> ' + status + ' - ' + error + '</div>\n');
                $('.cig-log-content').append('<div class="log-error-detail"><strong>Response:</strong> ' + xhr.responseText + '</div>\n');
                $('.cig-migrate-btn').prop('disabled', false).text('<?php echo esc_js(__('Retry Migration', 'cig')); ?>');
            });
        }
        runBatch();
    });
    
    // Test single invoice migration
    $('.cig-test-single-btn').on('click', function() {
        $(this).prop('disabled', true).text('<?php echo esc_js(__('Testing...', 'cig')); ?>');
        $('.cig-migration-log').show();
        $('.cig-log-content').html('<div class="log-info">Testing migration of single invoice...</div>\n');
        
        $.post(ajaxurl, {
            action: 'cig_test_single_invoice',
            nonce: '<?php echo wp_create_nonce('cig_migration'); ?>'
        }, function(response) {
            if (response.success) {
                $('.cig-log-content').append('<div class="log-success"><strong>Success!</strong> ' + response.data.message + '</div>\n');
                $('.cig-log-content').append('<div class="log-info"><strong>Post ID:</strong> ' + response.data.post_id + '</div>\n');
                
                if (response.data.dto_data) {
                    // Use helper function for JSON escaping
                    var escapedJson = escapeAndFormatJson(response.data.dto_data);
                    $('.cig-log-content').append('<details style="margin-top: 10px;"><summary style="cursor: pointer;"><strong>Invoice Data</strong></summary><pre style="background: #f5f5f5; padding: 10px; overflow-x: auto; font-size: 11px;">' + escapedJson + '</pre></details>\n');
                }
            } else {
                $('.cig-log-content').append('<div class="log-error"><strong>Error:</strong> ' + response.data.message + '</div>\n');
                
                if (response.data.post_id) {
                    $('.cig-log-content').append('<div class="log-error-detail"><strong>Post ID:</strong> ' + response.data.post_id + '</div>\n');
                }
                
                if (response.data.validation_errors) {
                    $('.cig-log-content').append('<div class="log-error-detail"><strong>Validation Errors:</strong></div>');
                    $.each(response.data.validation_errors, function(i, error) {
                        $('.cig-log-content').append('<div class="log-error-detail">  - ' + error + '</div>\n');
                    });
                }
                
                if (response.data.dto_data) {
                    // Use helper function for JSON escaping
                    var escapedJson = escapeAndFormatJson(response.data.dto_data);
                    $('.cig-log-content').append('<details style="margin-top: 10px;"><summary style="cursor: pointer;"><strong>Invoice Data (with issues)</strong></summary><pre style="background: #fff3cd; padding: 10px; overflow-x: auto; font-size: 11px;">' + escapedJson + '</pre></details>\n');
                }
                
                if (response.data.error_type) {
                    $('.cig-log-content').append('<div class="log-error-detail"><strong>Error Type:</strong> ' + response.data.error_type + '</div>\n');
                }
                if (response.data.error_file) {
                    $('.cig-log-content').append('<div class="log-error-detail"><strong>File:</strong> ' + response.data.error_file + ':' + response.data.error_line + '</div>\n');
                }
                if (response.data.trace) {
                    // Escape HTML in trace array before displaying
                    var traceText = response.data.trace.join('\n');
                    var escapedTrace = $('<div/>').text(traceText).html();
                    $('.cig-log-content').append('<details style="margin-top: 10px;"><summary style="cursor: pointer;"><strong>Stack Trace</strong></summary><pre style="background: #f5f5f5; padding: 10px; overflow-x: auto; font-size: 11px;">' + escapedTrace + '</pre></details>\n');
                }
            }
            
            $('.cig-test-single-btn').prop('disabled', false).text('<?php echo esc_js(__('Test Single Invoice', 'cig')); ?>');
        }).fail(function(xhr, status, error) {
            $('.cig-log-content').append('<div class="log-error"><strong>AJAX Error:</strong> ' + status + ' - ' + error + '</div>\n');
            $('.cig-log-content').append('<div class="log-error-detail"><strong>Response:</strong> ' + xhr.responseText + '</div>\n');
            $('.cig-test-single-btn').prop('disabled', false).text('<?php echo esc_js(__('Test Single Invoice', 'cig')); ?>');
        });
    });
    
    // View migration logs
    $('.cig-view-logs-btn').on('click', function() {
        $(this).prop('disabled', true).text('<?php echo esc_js(__('Loading...', 'cig')); ?>');
        $('.cig-migration-log').show();
        $('.cig-log-content').html('<div class="log-info">Loading migration logs...</div>\n');
        
        $.post(ajaxurl, {
            action: 'cig_get_migration_logs',
            nonce: '<?php echo wp_create_nonce('cig_migration'); ?>'
        }, function(response) {
            if (response.success && response.data.logs) {
                $('.cig-log-content').html('');
                
                $.each(response.data.logs, function(i, logSource) {
                    $('.cig-log-content').append('<h4 style="margin-top: 20px; margin-bottom: 10px; color: #2271b1;">' + logSource.source + '</h4>');
                    
                    if (logSource.entries && logSource.entries.length > 0) {
                        $('.cig-log-content').append('<div class="log-box" style="background: #f5f5f5; padding: 10px; border: 1px solid #ddd; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px; white-space: pre-wrap;">');
                        
                        $.each(logSource.entries, function(j, entry) {
                            var cssClass = 'log-line';
                            if (entry.includes('[error]') || entry.includes('Error') || entry.includes('ERROR')) {
                                cssClass = 'log-error';
                            } else if (entry.includes('[warning]') || entry.includes('Warning')) {
                                cssClass = 'log-warning';
                            }
                            
                            // Safely append log entry with HTML escaping
                            $('.cig-log-content').append($('<div/>').addClass(cssClass).text(entry));
                        });
                        
                        $('.cig-log-content').append('</div>');
                    }
                });
            } else {
                $('.cig-log-content').append('<div class="log-error">Failed to load logs</div>\n');
            }
            
            $('.cig-view-logs-btn').prop('disabled', false).text('<?php echo esc_js(__('View Migration Logs', 'cig')); ?>');
        }).fail(function(xhr, status, error) {
            $('.cig-log-content').append('<div class="log-error"><strong>AJAX Error:</strong> ' + status + ' - ' + error + '</div>\n');
            $('.cig-view-logs-btn').prop('disabled', false).text('<?php echo esc_js(__('View Migration Logs', 'cig')); ?>');
        });
    });
    
    // Verify migration integrity
    $('.cig-verify-btn').on('click', function() {
        $(this).prop('disabled', true).text('<?php echo esc_js(__('Verifying...', 'cig')); ?>');
        $('.cig-migration-log').show();
        $('.cig-log-content').html('<div class="log-info">Verifying data integrity...</div>\n');
        
        $.post(ajaxurl, {
            action: 'cig_verify_migration',
            nonce: '<?php echo wp_create_nonce('cig_migration'); ?>'
        }, function(response) {
            if (response.success) {
                var data = response.data;
                if (data.status === 'ok') {
                    $('.cig-log-content').append('<div class="log-success"><strong>✓ Verification passed!</strong> All data integrity checks passed.</div>\n');
                } else if (data.status === 'warning') {
                    $('.cig-log-content').append('<div class="log-warning"><strong>⚠ Verification completed with warnings:</strong></div>\n');
                    $.each(data.warnings || [], function(i, warning) {
                        $('.cig-log-content').append('<div class="log-warning">  - ' + warning + '</div>\n');
                    });
                } else {
                    $('.cig-log-content').append('<div class="log-error"><strong>✗ Verification failed:</strong></div>\n');
                    $.each(data.errors || [], function(i, error) {
                        $('.cig-log-content').append('<div class="log-error">  - ' + error + '</div>\n');
                    });
                }
            } else {
                $('.cig-log-content').append('<div class="log-error"><strong>Error:</strong> ' + (response.data.message || 'Verification failed') + '</div>\n');
            }
            
            $('.cig-verify-btn').prop('disabled', false).text('<?php echo esc_js(__('Verify Data Integrity', 'cig')); ?>');
        }).fail(function(xhr, status, error) {
            $('.cig-log-content').append('<div class="log-error"><strong>AJAX Error:</strong> ' + status + ' - ' + error + '</div>\n');
            $('.cig-verify-btn').prop('disabled', false).text('<?php echo esc_js(__('Verify Data Integrity', 'cig')); ?>');
        });
    });
    
    // Rollback migration
    $('.cig-rollback-btn').on('click', function() {
        if (!confirm('<?php echo esc_js(__('WARNING: This will delete all migrated data from custom tables. Postmeta data will be preserved. Are you sure?', 'cig')); ?>')) {
            return;
        }
        
        $(this).prop('disabled', true).text('<?php echo esc_js(__('Rolling back...', 'cig')); ?>');
        $('.cig-migration-log').show();
        $('.cig-log-content').html('<div class="log-warning">Rolling back migration...</div>\n');
        
        $.post(ajaxurl, {
            action: 'cig_rollback_migration',
            nonce: '<?php echo wp_create_nonce('cig_migration'); ?>'
        }, function(response) {
            if (response.success) {
                $('.cig-log-content').append('<div class="log-success"><strong>✓ Rollback completed!</strong> ' + response.data.message + '</div>\n');
                $('.cig-log-content').append('<div class="log-info">Page will reload in 2 seconds...</div>\n');
                setTimeout(function() { location.reload(); }, 2000);
            } else {
                $('.cig-log-content').append('<div class="log-error"><strong>Error:</strong> ' + (response.data.message || 'Rollback failed') + '</div>\n');
                $('.cig-rollback-btn').prop('disabled', false).text('<?php echo esc_js(__('Rollback Migration', 'cig')); ?>');
            }
        }).fail(function(xhr, status, error) {
            $('.cig-log-content').append('<div class="log-error"><strong>AJAX Error:</strong> ' + status + ' - ' + error + '</div>\n');
            $('.cig-rollback-btn').prop('disabled', false).text('<?php echo esc_js(__('Rollback Migration', 'cig')); ?>');
        });
    });
});
</script>
