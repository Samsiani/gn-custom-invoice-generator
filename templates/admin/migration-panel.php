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
            <?php elseif ($is_migrated): ?>
                <div class="notice notice-success">
                    <p><?php echo esc_html__('Migration completed! All invoices have been migrated to custom tables.', 'cig'); ?></p>
                </div>
            <?php else: ?>
                <p><?php echo esc_html__('Click the button below to start migrating your invoice data from postmeta to custom database tables.', 'cig'); ?></p>
                
                <p>
                    <button type="button" class="button button-primary button-hero cig-migrate-btn">
                        <?php echo esc_html__('Start Migration', 'cig'); ?>
                    </button>
                </p>
            <?php endif; ?>
            
            <!-- Migration Log -->
            <div class="cig-migration-log" style="display: none;">
                <h3><?php echo esc_html__('Migration Log', 'cig'); ?></h3>
                <div class="cig-log-content"></div>
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
</style>

<script>
jQuery(document).ready(function($) {
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
                    $('.cig-log-content').append(response.data.message + '\n');
                    if (!response.data.completed) {
                        setTimeout(runBatch, 500);
                    } else {
                        location.reload();
                    }
                }
            });
        }
        runBatch();
    });
});
</script>
