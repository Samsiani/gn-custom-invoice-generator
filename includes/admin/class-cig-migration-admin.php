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

        $result = $this->migrator->migrate_invoices_to_table(50);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
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
}
