<?php
/**
 * Data Migrator - Migrates invoice data from postmeta to custom tables
 *
 * @package CIG
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Migrator {

    /**
     * @var CIG_Database
     */
    private $database;

    /**
     * @var CIG_Invoice_Repository
     */
    private $invoice_repo;

    /**
     * @var CIG_Invoice_Items_Repository
     */
    private $items_repo;

    /**
     * @var CIG_Payment_Repository
     */
    private $payment_repo;

    /**
     * @var CIG_Logger
     */
    private $logger;

    /**
     * Migration status option key
     */
    const MIGRATION_STATUS_OPTION = 'cig_migration_status';
    const MIGRATION_PROGRESS_OPTION = 'cig_migration_progress';

    /**
     * Constructor
     */
    public function __construct($database, $invoice_repo, $items_repo, $payment_repo, $logger = null) {
        $this->database = $database;
        $this->invoice_repo = $invoice_repo;
        $this->items_repo = $items_repo;
        $this->payment_repo = $payment_repo;
        $this->logger = $logger;
    }

    /**
     * Migrate all invoices from postmeta to custom tables
     *
     * @param int $batch_size Number of invoices to process per batch
     * @return array Migration results
     */
    public function migrate_invoices_to_table($batch_size = 50) {
        if (!$this->database->tables_exist()) {
            return [
                'success' => false,
                'message' => 'Custom tables do not exist. Please activate the plugin first.',
            ];
        }

        // Get all invoice posts
        $args = [
            'post_type' => 'invoice',
            'posts_per_page' => $batch_size,
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
            // Migration complete
            update_option(self::MIGRATION_STATUS_OPTION, 'completed', false);
            return [
                'success' => true,
                'message' => 'Migration completed successfully.',
                'migrated' => 0,
                'total' => 0,
                'completed' => true,
            ];
        }

        $migrated = 0;
        $errors = 0;

        foreach ($invoices as $post) {
            $result = $this->migrate_single_invoice($post->ID);
            if ($result) {
                $migrated++;
                // Mark as migrated
                update_post_meta($post->ID, '_cig_migrated_v5', 1);
            } else {
                $errors++;
            }
        }

        // Update progress
        $progress = $this->get_migration_progress();
        update_option(self::MIGRATION_PROGRESS_OPTION, $progress, false);

        return [
            'success' => true,
            'message' => sprintf('Batch completed: %d migrated, %d errors', $migrated, $errors),
            'migrated' => $migrated,
            'errors' => $errors,
            'progress' => $progress,
            'completed' => false,
        ];
    }

    /**
     * Migrate a single invoice
     *
     * @param int $post_id Invoice post ID
     * @return bool Success status
     */
    public function migrate_single_invoice($post_id) {
        try {
            $this->log_info('Starting migration for invoice', ['post_id' => $post_id]);
            
            // Create invoice DTO from postmeta
            $invoice_dto = CIG_Invoice_DTO::from_postmeta($post_id);
            
            if (!$invoice_dto) {
                $this->log_error('Failed to create DTO from postmeta', ['post_id' => $post_id]);
                return false;
            }
            
            // Validate the DTO
            $validation_errors = $invoice_dto->validate();
            if (!empty($validation_errors)) {
                $this->log_error('Invoice validation failed', [
                    'post_id' => $post_id,
                    'errors' => $validation_errors,
                    'invoice_data' => $invoice_dto->to_array()
                ]);
                return false;
            }

            // Check if already migrated
            $existing = $this->invoice_repo->find_by_post_id($post_id);
            if ($existing) {
                $this->log_info('Invoice already exists, updating', ['post_id' => $post_id, 'existing_id' => $existing->id]);
                // Update instead of insert
                return $this->invoice_repo->update($existing->id, $invoice_dto);
            }

            // Create invoice in custom table
            $this->log_info('Creating invoice in custom table', ['post_id' => $post_id]);
            $invoice_id = $this->invoice_repo->create($invoice_dto);
            
            if (!$invoice_id) {
                $this->log_error('Failed to create invoice in custom table', ['post_id' => $post_id]);
                return false;
            }
            
            $this->log_info('Invoice created successfully', ['post_id' => $post_id, 'invoice_id' => $invoice_id]);

            // Migrate items
            $items_data = get_post_meta($post_id, '_cig_invoice_items', true);
            if (is_array($items_data) && !empty($items_data)) {
                $this->log_info('Migrating invoice items', ['post_id' => $post_id, 'item_count' => count($items_data)]);
                
                $items = [];
                foreach ($items_data as $item) {
                    if (empty($item['name'])) {
                        continue;
                    }
                    
                    $items[] = [
                        'product_id' => $item['product_id'] ?? 0,
                        'product_name' => $item['name'] ?? '',
                        'product_sku' => $item['sku'] ?? '',
                        'quantity' => $item['qty'] ?? 1,
                        'unit_price' => $item['price'] ?? 0,
                        'line_total' => ($item['qty'] ?? 1) * ($item['price'] ?? 0),
                        'warranty' => $item['warranty'] ?? null,
                        'item_note' => $item['note'] ?? '',
                    ];
                }
                
                if (!empty($items)) {
                    $items_result = $this->items_repo->bulk_insert($invoice_id, $items);
                    if ($items_result) {
                        $this->log_info('Invoice items migrated successfully', ['post_id' => $post_id, 'items_migrated' => count($items)]);
                    } else {
                        $this->log_error('Failed to migrate invoice items', ['post_id' => $post_id, 'items' => $items]);
                    }
                }
            } else {
                $this->log_info('No items to migrate', ['post_id' => $post_id]);
            }

            // Migrate payment history
            $payment_history = get_post_meta($post_id, '_cig_payment_history', true);
            if (is_array($payment_history) && !empty($payment_history)) {
                $this->log_info('Migrating payment history', ['post_id' => $post_id, 'payment_count' => count($payment_history)]);
                
                foreach ($payment_history as $payment) {
                    $payment_dto = CIG_Payment_DTO::from_array([
                        'invoice_id' => $invoice_id,
                        'payment_date' => $payment['date'] ?? current_time('mysql'),
                        'payment_method' => $payment['method'] ?? '',
                        'amount' => $payment['amount'] ?? 0,
                        'note' => $payment['comment'] ?? '',
                        'created_by' => $payment['user_id'] ?? 0,
                    ]);
                    
                    $payment_result = $this->payment_repo->add_payment($payment_dto);
                    if (!$payment_result) {
                        $this->log_error('Failed to migrate payment', ['post_id' => $post_id, 'payment' => $payment]);
                    }
                }
            } else {
                $this->log_info('No payment history to migrate', ['post_id' => $post_id]);
            }

            $this->log_info('Invoice migrated successfully', ['post_id' => $post_id, 'invoice_id' => $invoice_id]);
            
            return true;

        } catch (Exception $e) {
            $this->log_error('Migration exception', [
                'post_id' => $post_id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        } catch (Error $e) {
            // Catch PHP 7+ errors
            $this->log_error('Migration fatal error', [
                'post_id' => $post_id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Get migration progress
     *
     * @return array Progress information
     */
    public function get_migration_progress() {
        $total_args = [
            'post_type' => 'invoice',
            'posts_per_page' => -1,
            'post_status' => 'any',
            'fields' => 'ids',
        ];
        $total = count(get_posts($total_args));

        $migrated_args = [
            'post_type' => 'invoice',
            'posts_per_page' => -1,
            'post_status' => 'any',
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_cig_migrated_v5',
                    'value' => 1,
                ],
            ],
        ];
        $migrated = count(get_posts($migrated_args));

        $percentage = $total > 0 ? round(($migrated / $total) * 100, 2) : 0;

        return [
            'total' => $total,
            'migrated' => $migrated,
            'remaining' => $total - $migrated,
            'percentage' => $percentage,
        ];
    }

    /**
     * Check if migration has been completed
     *
     * @return bool True if migrated
     */
    public function is_migrated() {
        $progress = $this->get_migration_progress();
        return $progress['remaining'] === 0;
    }

    /**
     * Rollback migration (delete custom table data, keep postmeta)
     *
     * @return bool Success status
     */
    public function rollback_migration() {
        // Truncate custom tables
        $result = $this->database->truncate_tables();

        if ($result) {
            // Clear migration flags
            $args = [
                'post_type' => 'invoice',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => '_cig_migrated_v5',
                        'compare' => 'EXISTS',
                    ],
                ],
            ];
            
            $post_ids = get_posts($args);
            foreach ($post_ids as $post_id) {
                delete_post_meta($post_id, '_cig_migrated_v5');
            }

            // Reset migration status
            delete_option(self::MIGRATION_STATUS_OPTION);
            delete_option(self::MIGRATION_PROGRESS_OPTION);

            $this->log_info('Migration rolled back successfully');
        }

        return $result;
    }

    /**
     * Verify data integrity after migration
     *
     * @return array Verification results
     */
    public function verify_migration_integrity() {
        $results = [
            'status' => 'ok',
            'errors' => [],
            'warnings' => [],
        ];

        // Check if tables have data
        if (!$this->database->tables_have_data()) {
            $results['status'] = 'error';
            $results['errors'][] = 'Custom tables are empty';
            return $results;
        }

        // Get sample of invoices and compare with postmeta
        $args = [
            'post_type' => 'invoice',
            'posts_per_page' => 10,
            'orderby' => 'rand',
            'fields' => 'ids',
        ];
        
        $sample_posts = get_posts($args);
        
        foreach ($sample_posts as $post_id) {
            $table_data = $this->invoice_repo->find_by_post_id($post_id);
            $postmeta_data = CIG_Invoice_DTO::from_postmeta($post_id);
            
            if (!$table_data) {
                $results['warnings'][] = "Post ID {$post_id} not found in custom table";
                continue;
            }

            // Compare key fields
            if ($table_data->invoice_number !== $postmeta_data->invoice_number) {
                $results['warnings'][] = "Invoice number mismatch for post ID {$post_id}";
            }

            if ($table_data->buyer_name !== $postmeta_data->buyer_name) {
                $results['warnings'][] = "Buyer name mismatch for post ID {$post_id}";
            }
        }

        if (!empty($results['warnings'])) {
            $results['status'] = 'warning';
        }

        return $results;
    }

    /**
     * Reset migration (for testing)
     *
     * @return bool Success status
     */
    public function reset_migration() {
        $this->rollback_migration();
        return true;
    }

    /**
     * Log error
     *
     * @param string $message Error message
     * @param array $context Context data
     */
    private function log_error($message, $context = []) {
        if ($this->logger) {
            $this->logger->error($message, $context);
        }
    }

    /**
     * Log info
     *
     * @param string $message Info message
     * @param array $context Context data
     */
    private function log_info($message, $context = []) {
        if ($this->logger) {
            $this->logger->info($message, $context);
        }
    }
}
